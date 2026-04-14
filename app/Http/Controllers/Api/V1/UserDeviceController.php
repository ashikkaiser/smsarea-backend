<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Device;
use App\Models\UserDeviceEntitlement;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;

class UserDeviceController extends Controller
{
    use ApiResponse;

    public function issueDeviceClaimCode(): JsonResponse
    {
        $user = request()->user();

        $entitlement = UserDeviceEntitlement::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $entitlement || ! $entitlement->isUsable() || $entitlement->availableSlots() <= 0) {
            $message = ! $entitlement || ! $entitlement->isUsable()
                ? 'Your device slot access is not active. Buy or renew slots, or wait until your entitlement is valid again.'
                : 'You need at least one available device slot before generating a pairing code. Buy slots or remove a claimed device first.';

            return $this->failure($message, 422);
        }

        ApiToken::query()
            ->where('type', DeviceService::API_TOKEN_TYPE_USER_DEVICE_CLAIM)
            ->where('created_by', $user->id)
            ->whereNull('used_at')
            ->delete();

        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $plain = '';
        for ($i = 0; $i < 8; $i++) {
            $plain .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $plainUpper = strtoupper($plain);
        $expiresAt = now()->addMinutes(15);

        ApiToken::query()->create([
            'name' => 'user-device-claim',
            'token' => hash('sha256', $plainUpper),
            'type' => DeviceService::API_TOKEN_TYPE_USER_DEVICE_CLAIM,
            'created_by' => $user->id,
            'expires_at' => $expiresAt,
            'meta' => ['owner_user_id' => $user->id],
        ]);

        $display = substr($plainUpper, 0, 4).'-'.substr($plainUpper, 4, 4);

        return $this->success([
            'code' => $display,
            'code_raw' => $plainUpper,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 'Pairing code issued. Enter this as the registration token in the Android app (one device per code).');
    }

    public function myDevices(): JsonResponse
    {
        $user = request()->user();

        $entitlement = UserDeviceEntitlement::query()
            ->where('user_id', $user->id)
            ->first();

        $devices = Device::query()
            ->where('owner_user_id', $user->id)
            ->with(['phoneNumbers' => function ($query): void {
                $query->orderBy('sim_slot')->orderBy('id')
                    ->select(['id', 'device_id', 'sim_slot', 'phone_number', 'carrier_name', 'status']);
            }])
            ->orderByDesc('id')
            ->get()
            ->map(function (Device $device): array {
                return [
                    'id' => $device->id,
                    'device_uid' => $device->device_uid,
                    'custom_name' => $device->custom_name,
                    'model' => $device->model,
                    'os' => $device->os,
                    'status' => $device->status,
                    'last_seen_at' => $device->last_seen_at,
                    'claimed_at' => $device->claimed_at,
                    'phone_numbers' => $device->phoneNumbers->map(static fn ($p): array => [
                        'id' => $p->id,
                        'sim_slot' => $p->sim_slot,
                        'phone_number' => $p->phone_number,
                        'carrier_name' => $p->carrier_name,
                        'status' => $p->status,
                    ])->values()->all(),
                ];
            });

        return $this->success([
            'entitlement' => $entitlement ? [
                'slots_purchased' => (int) $entitlement->slots_purchased,
                'slots_used' => (int) $entitlement->slots_used,
                'slots_available' => $entitlement->isUsable() ? $entitlement->availableSlots() : 0,
                'status' => $entitlement->effectiveStatus(),
                'valid_until' => $entitlement->valid_until,
            ] : null,
            'devices' => $devices,
        ], 'Your devices fetched.');
    }
}
