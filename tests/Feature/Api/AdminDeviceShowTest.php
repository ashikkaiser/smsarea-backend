<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDeviceShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_fetch_device_details_with_numbers(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $device = Device::create([
            'device_uid' => 'test-uid-1',
            'device_token' => 'secret-token-value-abc',
            'model' => 'Pixel 8',
            'os' => 'Android 14',
            'status' => 'online',
        ]);

        PhoneNumber::create([
            'device_id' => $device->id,
            'sim_slot' => 0,
            'phone_number' => '+12025550100',
            'carrier_name' => 'T-Mobile',
            'status' => 'active',
        ]);

        $response = $this->withToken($token)->getJson("/api/v1/admin/devices/{$device->id}");

        $response->assertOk();
        $response->assertJsonPath('data.device_uid', 'test-uid-1');
        $response->assertJsonPath('data.model', 'Pixel 8');
        $response->assertJsonPath('data.device_token_masked', 'secr…-abc');
        $response->assertJsonMissing(['device_token' => 'secret-token-value-abc']);
        $response->assertJsonPath('data.phone_numbers.0.phone_number', '+12025550100');
    }

    public function test_non_admin_cannot_fetch_device_details(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('user', ['user'])->plainTextToken;

        $device = Device::create([
            'device_uid' => 'test-uid-2',
            'device_token' => 'token',
            'status' => 'offline',
        ]);

        $response = $this->withToken($token)->getJson("/api/v1/admin/devices/{$device->id}");
        $response->assertForbidden();
    }
}
