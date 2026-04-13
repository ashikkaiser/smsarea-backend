<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDeviceDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_device(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $bearer = $admin->createToken('test')->plainTextToken;

        $device = Device::query()->create([
            'device_uid' => 'test-uid-del',
            'device_token' => (string) Str::uuid(),
            'model' => 'Test',
            'os' => 'Android 16',
            'status' => 'offline',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$bearer}")
            ->deleteJson('/api/v1/admin/devices/'.$device->id);

        $response->assertOk();
        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }
}
