<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\DeviceSimSnapshot;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndroidCompatTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_register_accepts_stringified_sim_info(): void
    {
        $plainToken = 'abc123';
        ApiToken::create([
            'name' => 'reg',
            'token' => hash('sha256', $plainToken),
            'type' => 'register_device',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/device/register', [
            'token' => $plainToken,
            'device_uid' => 'SERIAL123',
            'model' => 'Pixel',
            'os' => 'Android 15',
            'sim_info' => json_encode([['slot' => 1, 'number' => '+12025550100', 'carrier' => 'Carrier']]),
        ]);

        $response->assertOk()->assertJsonStructure(['device_uid', 'ws_url', 'device_token']);
    }

    public function test_device_reregister_same_number_different_slot_does_not_create_second_phone_row(): void
    {
        $plainToken = 'abc123';
        ApiToken::create([
            'name' => 'reg',
            'token' => hash('sha256', $plainToken),
            'type' => 'register_device',
            'expires_at' => now()->addHour(),
        ]);

        $uid = 'SERIAL-DEDUPE-1';
        $number = '+12025550100';

        $this->postJson('/api/device/register', [
            'token' => $plainToken,
            'device_uid' => $uid,
            'model' => 'Pixel',
            'os' => 'Android 15',
            'sim_info' => json_encode([['slot' => 0, 'number' => $number, 'carrier' => 'A']]),
        ])->assertOk();

        $device = Device::query()->where('device_uid', $uid)->firstOrFail();
        $this->assertSame(1, PhoneNumber::query()->where('device_id', $device->id)->count());

        ApiToken::create([
            'name' => 'reg2',
            'token' => hash('sha256', 'def456'),
            'type' => 'register_device',
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/device/register', [
            'token' => 'def456',
            'device_uid' => $uid,
            'model' => 'Pixel',
            'os' => 'Android 15',
            'sim_info' => json_encode([['slot' => 1, 'number' => $number, 'carrier' => 'A']]),
        ])->assertOk();

        $this->assertSame(1, PhoneNumber::query()->where('device_id', $device->id)->count());
    }

    public function test_device_reregister_identical_sim_skips_extra_snapshot(): void
    {
        $plainToken = 'abc123';
        ApiToken::create([
            'name' => 'reg',
            'token' => hash('sha256', $plainToken),
            'type' => 'register_device',
            'expires_at' => now()->addHour(),
        ]);

        $uid = 'SERIAL-SNAP-1';
        $payload = [
            'token' => $plainToken,
            'device_uid' => $uid,
            'model' => 'Pixel',
            'os' => 'Android 15',
            'sim_info' => json_encode([['slot' => 0, 'number' => '+12025550199', 'carrier' => 'Same']]),
        ];

        $this->postJson('/api/device/register', $payload)->assertOk();

        ApiToken::create([
            'name' => 'reg2',
            'token' => hash('sha256', 'xyz789'),
            'type' => 'register_device',
            'expires_at' => now()->addHour(),
        ]);
        $payload['token'] = 'xyz789';

        $this->postJson('/api/device/register', $payload)->assertOk();

        $device = Device::query()->where('device_uid', $uid)->firstOrFail();
        $this->assertSame(1, DeviceSimSnapshot::query()->where('device_id', $device->id)->where('sim_slot', 0)->count());
    }
}
