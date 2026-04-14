<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\User;
use App\Models\UserDeviceEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDeviceOwnerUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_transfer_device_owner_and_slot_usage_is_updated(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $ownerA = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $ownerB = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $bearer = $admin->createToken('test')->plainTextToken;

        UserDeviceEntitlement::query()->create([
            'user_id' => $ownerA->id,
            'slots_purchased' => 3,
            'slots_used' => 1,
            'status' => 'active',
        ]);
        UserDeviceEntitlement::query()->create([
            'user_id' => $ownerB->id,
            'slots_purchased' => 2,
            'slots_used' => 0,
            'status' => 'active',
        ]);

        $device = Device::query()->create([
            'device_uid' => 'owner-transfer-uid',
            'device_token' => (string) Str::uuid(),
            'owner_user_id' => $ownerA->id,
            'claimed_at' => now(),
            'status' => 'offline',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$bearer}")
            ->patchJson('/api/v1/admin/devices/'.$device->id.'/owner', [
                'owner_user_id' => $ownerB->id,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'owner_user_id' => $ownerB->id,
        ]);
        $this->assertDatabaseHas('user_device_entitlements', [
            'user_id' => $ownerA->id,
            'slots_used' => 0,
        ]);
        $this->assertDatabaseHas('user_device_entitlements', [
            'user_id' => $ownerB->id,
            'slots_used' => 1,
        ]);
    }
}
