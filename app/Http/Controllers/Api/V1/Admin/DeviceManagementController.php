<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateDeviceRequest;
use App\Http\Requests\Admin\UpdateDeviceRequest;
use App\Models\ApiToken;
use App\Models\Device;
use App\Models\PhoneNumber;
use App\Models\UserDeviceEntitlement;
use App\Services\DeviceService;
use App\Services\UpstashPresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DeviceManagementController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DeviceService $deviceService,
        private readonly UpstashPresenceService $upstashPresence,
    ) {}

    public function index(): JsonResponse
    {
        $rows = Device::query()->with('owner:id,name,email')->latest()->paginate(20);
        $prefix = (string) config('services.sms_gateway.presence_prefix', 'sms_gateway:presence:');

        $keys = $rows->getCollection()
            ->map(fn (Device $device): string => $prefix.$device->device_uid)
            ->values()
            ->all();
        $presenceByKey = $this->upstashPresence->getMany($keys);

        $rows->getCollection()->transform(function (Device $device) use ($prefix, $presenceByKey): Device {
            $raw = $presenceByKey[$prefix.$device->device_uid] ?? null;
            $status = null;
            $lastSeenEpoch = null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $status = isset($decoded['status']) && is_string($decoded['status']) ? $decoded['status'] : null;
                    $lastSeenEpoch = isset($decoded['last_seen_epoch']) && is_numeric($decoded['last_seen_epoch'])
                        ? (int) $decoded['last_seen_epoch']
                        : null;
                }
            }

            $device->setAttribute('realtime_connected', $status === 'online');
            if ($lastSeenEpoch) {
                $fromRedis = Carbon::createFromTimestamp($lastSeenEpoch);
                $db = $device->last_seen_at ? Carbon::parse($device->last_seen_at) : null;
                if ($db === null || $fromRedis->greaterThan($db)) {
                    $device->setAttribute('last_seen_at', $fromRedis->toIso8601String());
                }
            }

            $ownerId = $device->owner_user_id ? (int) $device->owner_user_id : null;
            if ($ownerId !== null) {
                $entitlement = UserDeviceEntitlement::query()->where('user_id', $ownerId)->first();
                $device->setAttribute('owner_slot_summary', $entitlement ? [
                    'slots_purchased' => (int) $entitlement->slots_purchased,
                    'slots_used' => (int) $entitlement->slots_used,
                    'slots_available' => $entitlement->availableSlots(),
                ] : null);
            } else {
                $device->setAttribute('owner_slot_summary', null);
            }

            return $device;
        });

        return $this->success($rows, 'Devices fetched.');
    }

    public function show(Device $device): JsonResponse
    {
        $device->load([
            'owner:id,name,email',
            'phoneNumbers' => function ($query): void {
                $query->with(['users:id,name,email'])
                    ->orderBy('sim_slot')
                    ->orderBy('id');
            },
            'snapshots' => function ($query): void {
                $query->orderByDesc('observed_at')->orderByDesc('id')->limit(100);
            },
        ]);

        $plainToken = $device->device_token;
        $device->makeHidden(['device_token']);

        $payload = $device->toArray();
        $payload['device_token_masked'] = $this->maskDeviceToken($plainToken);
        $payload['phone_numbers'] = $device->phoneNumbers->map(function (PhoneNumber $phone): array {
            $row = $phone->toArray();
            $row['users'] = $phone->users->map(function ($user): array {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'assignment_status' => $user->pivot->status,
                    'assigned_at' => $user->pivot->assigned_at,
                    'unassigned_at' => $user->pivot->unassigned_at,
                ];
            })->values()->all();

            return $row;
        })->values()->all();

        return $this->success($payload, 'Device details fetched.');
    }

    public function store(CreateDeviceRequest $request): JsonResponse
    {
        $device = $this->deviceService->createDevice($request->validated());

        return $this->success($device, 'Device created.', 201);
    }

    public function update(UpdateDeviceRequest $request, Device $device): JsonResponse
    {
        $device->update($request->validated());

        return $this->success($device->fresh(), 'Device updated.');
    }

    public function updateOwner(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $updated = $this->deviceService->updateDeviceOwner($device, $data['owner_user_id'] ?? null);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 422);
        }

        return $this->success($updated->load('owner:id,name,email'), 'Device owner updated.');
    }

    public function destroy(Device $device): JsonResponse
    {
        $device->delete();

        return $this->success(null, 'Device deleted.');
    }

    public function issueRegistrationToken(): JsonResponse
    {
        $plainToken = Str::random(48);
        ApiToken::create([
            'name' => 'device-registration',
            'token' => hash('sha256', $plainToken),
            'type' => 'register_device',
            'created_by' => request()->user()?->id,
            'expires_at' => now()->addHours(6),
        ]);

        return $this->success(['token' => $plainToken], 'Device registration token issued.');
    }

    public function issueRegistrationOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
        ]);
        $ttlMinutes = $data['ttl_minutes'] ?? 15;
        $expiresAt = now()->addMinutes($ttlMinutes);

        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $plain = '';
        for ($i = 0; $i < 8; $i++) {
            $plain .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        ApiToken::create([
            'name' => 'device-registration-otp',
            'token' => hash('sha256', $plain),
            'type' => 'register_device_otp',
            'created_by' => request()->user()?->id,
            'expires_at' => $expiresAt,
        ]);

        $display = substr($plain, 0, 4).'-'.substr($plain, 4, 4);

        return $this->success([
            'otp' => $display,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 'Pairing code issued.');
    }

    public function phoneNumbers(): JsonResponse
    {
        $rows = PhoneNumber::query()
            ->with('device:id,device_uid,model,custom_name')
            ->latest()
            ->paginate(20);

        return $this->success($rows, 'Phone numbers fetched.');
    }

    private function maskDeviceToken(?string $token): string
    {
        if ($token === null || $token === '') {
            return '';
        }

        $length = strlen($token);
        if ($length <= 8) {
            return str_repeat('•', min(8, $length));
        }

        return substr($token, 0, 4).'…'.substr($token, -4);
    }
}
