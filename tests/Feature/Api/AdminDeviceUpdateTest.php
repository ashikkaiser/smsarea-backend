<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDeviceUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_and_clear_device_custom_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $bearer = $admin->createToken('test')->plainTextToken;

        $device = Device::query()->create([
            'device_uid' => 'uid-name-test',
            'device_token' => (string) Str::uuid(),
            'model' => 'Pixel',
            'os' => 'Android 16',
            'status' => 'offline',
        ]);

        $set = $this->withHeader('Authorization', "Bearer {$bearer}")
            ->patchJson('/api/v1/admin/devices/'.$device->id, [
                'custom_name' => 'Warehouse gateway',
            ]);

        $set->assertOk();
        $device->refresh();
        $this->assertSame('Warehouse gateway', $device->custom_name);

        $clear = $this->withHeader('Authorization', "Bearer {$bearer}")
            ->patchJson('/api/v1/admin/devices/'.$device->id, [
                'custom_name' => null,
            ]);

        $clear->assertOk();
        $device->refresh();
        $this->assertNull($device->custom_name);
    }
}
