<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\DeviceSimSnapshot;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\UserDeviceEntitlement;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DeviceService
{
    public const API_TOKEN_TYPE_USER_DEVICE_CLAIM = 'user_device_claim';

    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
    ) {}

    public function createDevice(array $data): Device
    {
        $device = Device::create([
            'device_uid' => $data['device_uid'],
            'device_token' => $data['device_token'] ?? Str::uuid()->toString(),
            'model' => $data['model'] ?? null,
            'os' => $data['os'] ?? null,
            'status' => 'offline',
        ]);

        $simInfo = $this->normalizeSimInfo($data['sim_info'] ?? []);
        foreach ($simInfo as $sim) {
            $slot = (int) Arr::get($sim, 'slot', 0);
            $this->recordSimSnapshotIfChanged(
                $device,
                $slot,
                Arr::get($sim, 'number'),
                Arr::get($sim, 'carrier'),
            );
            $this->upsertPhoneNumberForSim($device, $sim, $device->device_uid);
        }

        return $device;
    }

    public function registerFromAndroid(array $payload): Device
    {
        $simInfo = $this->normalizeSimInfo($payload['sim_info'] ?? []);

        return DB::transaction(function () use ($payload, $simInfo) {
            $device = Device::query()->updateOrCreate(
                ['device_uid' => $payload['device_uid']],
                [
                    'model' => $payload['model'] ?? null,
                    'os' => $payload['os'] ?? null,
                    'status' => 'online',
                    'last_seen_at' => now(),
                    'device_token' => Device::query()
                        ->where('device_uid', $payload['device_uid'])
                        ->value('device_token') ?? Str::uuid()->toString(),
                ],
            );

            $effectiveOwnerId = $this->resolveOwnerUserIdFromAndroidPayload($payload);
            if ($effectiveOwnerId !== null) {
                $this->claimDeviceForOwner($device, $effectiveOwnerId);
            }

            foreach ($simInfo as $sim) {
                $slot = (int) Arr::get($sim, 'slot', 0);
                $this->recordSimSnapshotIfChanged(
                    $device,
                    $slot,
                    Arr::get($sim, 'number'),
                    Arr::get($sim, 'carrier'),
                );
                $this->upsertPhoneNumberForSim($device, $sim, $payload['device_uid']);
            }

            $this->autoAssignActiveNumbersToOwner($device);

            return $device;
        });
    }

    public function updateDeviceOwner(Device $device, ?int $ownerUserId): Device
    {
        return DB::transaction(function () use ($device, $ownerUserId): Device {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $currentOwnerId = $device->owner_user_id ? (int) $device->owner_user_id : null;
            $nextOwnerId = $ownerUserId !== null ? (int) $ownerUserId : null;

            if ($currentOwnerId === $nextOwnerId) {
                return $device;
            }

            if ($currentOwnerId !== null) {
                /** @var UserDeviceEntitlement|null $currentEntitlement */
                $currentEntitlement = UserDeviceEntitlement::query()
                    ->where('user_id', $currentOwnerId)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();
                if ($currentEntitlement) {
                    $currentEntitlement->forceFill([
                        'slots_used' => max(0, (int) $currentEntitlement->slots_used - 1),
                    ])->save();
                }
            }

            if ($nextOwnerId !== null) {
                $this->claimDeviceForOwner($device, $nextOwnerId, false);
            } else {
                $device->forceFill([
                    'owner_user_id' => null,
                    'claimed_at' => null,
                ])->save();
            }

            return $device->fresh();
        });
    }

    public function normalizeRegistrationTokenInput(string $token): string
    {
        $t = str_replace(["\t", ' ', '-'], '', trim($token));
        // 8-char pairing codes are case-insensitive; long QR tokens stay exact (case-sensitive).
        if (strlen($t) === 8) {
            return strtoupper($t);
        }

        return $t;
    }

    public function validateRegistrationToken(string $token): bool
    {
        $normalized = $this->normalizeRegistrationTokenInput($token);
        $record = ApiToken::query()->where('token', hash('sha256', $normalized))->first();
        if (! $record) {
            return false;
        }

        if (! in_array($record->type, ['register_device', 'register_device_otp', self::API_TOKEN_TYPE_USER_DEVICE_CLAIM], true)) {
            return false;
        }

        if ($record->expires_at && $record->expires_at->isPast()) {
            return false;
        }

        if (in_array($record->type, ['register_device_otp', self::API_TOKEN_TYPE_USER_DEVICE_CLAIM], true) && $record->used_at) {
            return false;
        }

        return true;
    }

    public function consumeRegistrationToken(string $token): void
    {
        $normalized = $this->normalizeRegistrationTokenInput($token);
        $record = ApiToken::query()->where('token', hash('sha256', $normalized))->first();
        if ($record && in_array($record->type, ['register_device_otp', self::API_TOKEN_TYPE_USER_DEVICE_CLAIM], true)) {
            $record->forceFill(['used_at' => now()])->save();
        }
    }

    /**
     * When the registration token is a user-issued pairing code, owner comes from the token (not from a raw user id).
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveOwnerUserIdFromAndroidPayload(array $payload): ?int
    {
        $claimOwner = $this->ownerUserIdFromUserDeviceClaimToken((string) ($payload['token'] ?? ''));
        if ($claimOwner !== null) {
            if (isset($payload['owner_user_id']) && $payload['owner_user_id'] !== null && (int) $payload['owner_user_id'] !== $claimOwner) {
                throw new RuntimeException('owner_user_id does not match the account linked to this pairing code.');
            }

            return $claimOwner;
        }

        if (isset($payload['owner_user_id']) && $payload['owner_user_id'] !== null) {
            return (int) $payload['owner_user_id'];
        }

        return null;
    }

    private function ownerUserIdFromUserDeviceClaimToken(string $token): ?int
    {
        if ($token === '') {
            return null;
        }
        $normalized = $this->normalizeRegistrationTokenInput($token);
        $record = ApiToken::query()->where('token', hash('sha256', $normalized))->first();
        if (! $record || $record->type !== self::API_TOKEN_TYPE_USER_DEVICE_CLAIM) {
            return null;
        }
        if ($record->expires_at && $record->expires_at->isPast()) {
            return null;
        }
        if ($record->used_at) {
            return null;
        }
        $id = $record->meta['owner_user_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    public function normalizeSimInfo(mixed $simInfo): array
    {
        if (is_string($simInfo)) {
            $decoded = json_decode($simInfo, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($simInfo) ? $simInfo : [];
    }

    /**
     * Re-use the existing line on this device when the dialable number matches, so re-register
     * with a different reported SIM slot does not create a second phone_numbers row.
     */
    private function upsertPhoneNumberForSim(Device $device, array $sim, string $deviceUidForImei): void
    {
        $slot = (int) Arr::get($sim, 'slot', 0);
        $rawNumber = Arr::get($sim, 'number');
        $carrier = Arr::get($sim, 'carrier');

        /** @var PhoneNumber|null $match */
        $match = null;
        if (is_string($rawNumber) && trim($rawNumber) !== '') {
            $match = PhoneNumber::query()
                ->where('device_id', $device->id)
                ->whereNotNull('phone_number')
                ->where('phone_number', '!=', '')
                ->get()
                ->first(static fn (PhoneNumber $pn) => PhoneNumber::dialableMatches((string) $pn->phone_number, $rawNumber));
        }

        if ($match !== null) {
            $match->forceFill([
                'imei_or_device_uid' => $deviceUidForImei,
                'phone_number' => $rawNumber,
                'carrier_name' => $carrier,
                'status' => 'active',
            ])->save();

            return;
        }

        PhoneNumber::query()->updateOrCreate(
            [
                'device_id' => $device->id,
                'sim_slot' => $slot,
            ],
            [
                'imei_or_device_uid' => $deviceUidForImei,
                'phone_number' => $rawNumber,
                'carrier_name' => $carrier,
                'status' => 'active',
            ],
        );
    }

    /** Skip duplicate snapshot rows when nothing changed since the last observation for this device + slot. */
    private function recordSimSnapshotIfChanged(Device $device, int $slot, mixed $phoneNumber, mixed $carrierName): void
    {
        $norm = static fn (mixed $s): string => $s === null || $s === '' ? '' : trim((string) $s);
        $nPhone = $norm($phoneNumber);
        $nCarrier = $norm($carrierName);

        $last = DeviceSimSnapshot::query()
            ->where('device_id', $device->id)
            ->where('sim_slot', $slot)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        if ($last !== null && $norm($last->phone_number) === $nPhone && $norm($last->carrier_name) === $nCarrier) {
            return;
        }

        DeviceSimSnapshot::create([
            'device_id' => $device->id,
            'sim_slot' => $slot,
            'phone_number' => $phoneNumber === null || $phoneNumber === '' ? null : (is_scalar($phoneNumber) ? (string) $phoneNumber : null),
            'carrier_name' => $carrierName === null || $carrierName === '' ? null : (is_scalar($carrierName) ? (string) $carrierName : null),
            'observed_at' => now(),
        ]);
    }

    private function claimDeviceForOwner(Device $device, int $ownerUserId, bool $requireDevicePermission = true): void
    {
        $owner = User::query()->findOrFail($ownerUserId);
        if ($requireDevicePermission && ! $owner->can_device) {
            throw new RuntimeException('Device workspace is disabled for this account.');
        }
        $entitlement = UserDeviceEntitlement::query()
            ->where('user_id', $owner->id)
            ->where('status', 'active')
            ->first();
        if (! $entitlement || ! $entitlement->isUsable()) {
            throw new RuntimeException('No active device-slot entitlement found for this user.');
        }

        $ownsThisAlready = (int) $device->owner_user_id === (int) $owner->id;
        if (! $ownsThisAlready) {
            if ($entitlement->availableSlots() <= 0) {
                throw new RuntimeException('No available device slots for this user.');
            }
            $entitlement->forceFill([
                'slots_used' => (int) $entitlement->slots_used + 1,
            ])->save();
        }

        $device->forceFill([
            'owner_user_id' => $owner->id,
            'claimed_at' => $device->claimed_at ?? now(),
        ])->save();
    }

    private function autoAssignActiveNumbersToOwner(Device $device): void
    {
        if (! $device->owner_user_id || $device->status !== 'online') {
            return;
        }

        $owner = User::query()->find($device->owner_user_id);
        if (! $owner) {
            return;
        }

        $device->loadMissing('phoneNumbers');
        foreach ($device->phoneNumbers as $phoneNumber) {
            if ((string) $phoneNumber->status !== 'active') {
                continue;
            }
            $alreadyAssigned = $phoneNumber->users()
                ->where('users.id', $owner->id)
                ->where('phone_number_user.status', 'active')
                ->exists();
            if ($alreadyAssigned) {
                continue;
            }

            $this->phoneNumberService->assignToUser($phoneNumber, $owner, $owner);
        }
    }
}
