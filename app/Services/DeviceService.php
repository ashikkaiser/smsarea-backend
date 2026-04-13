<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\DeviceSimSnapshot;
use App\Models\PhoneNumber;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceService
{
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

            return $device;
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

        if (! in_array($record->type, ['register_device', 'register_device_otp'], true)) {
            return false;
        }

        if ($record->expires_at && $record->expires_at->isPast()) {
            return false;
        }

        if ($record->type === 'register_device_otp' && $record->used_at) {
            return false;
        }

        return true;
    }

    public function consumeRegistrationToken(string $token): void
    {
        $normalized = $this->normalizeRegistrationTokenInput($token);
        $record = ApiToken::query()->where('token', hash('sha256', $normalized))->first();
        if ($record && $record->type === 'register_device_otp') {
            $record->forceFill(['used_at' => now()])->save();
        }
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
}
