<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\User;
use App\Models\UserDeviceEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDeviceDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_owned_devices_and_entitlement(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active', 'can_device' => true]);
        $token = $user->createToken('t', ['user'])->plainTextToken;

        UserDeviceEntitlement::query()->create([
            'user_id' => $user->id,
            'slots_purchased' => 2,
            'slots_used' => 1,
            'status' => 'active',
        ]);

        $device = Device::query()->create([
            'device_uid' => 'test-device-uid-1',
            'device_token' => 'secret-token',
            'owner_user_id' => $user->id,
            'claimed_at' => now(),
            'status' => 'online',
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/devices/my');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.entitlement.slots_available', 1);
        $response->assertJsonPath('data.devices.0.id', $device->id);
        $response->assertJsonPath('data.devices.0.device_uid', 'test-device-uid-1');
    }
}
