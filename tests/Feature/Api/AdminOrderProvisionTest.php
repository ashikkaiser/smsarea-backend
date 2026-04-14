<?php

namespace Tests\Feature\Api;

use App\Models\BillingSetting;
use App\Models\EsimInventory;
use App\Models\User;
use App\Models\UserDeviceEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminOrderProvisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_provision_device_slots_to_user_with_non_zero_order_amount(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $adminToken = $admin->createToken('admin')->plainTextToken;

        BillingSetting::current()->update(['device_slot_price_minor' => 1200]);

        $response = $this->withToken($adminToken)->postJson('/api/v1/admin/orders/provision/device-slots', [
            'user_id' => $user->id,
            'quantity' => 2,
        ]);
        $response->assertOk();

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'source' => 'admin_assign',
            'status' => 'fulfilled',
            'amount_minor' => 2400,
        ]);
        $this->assertDatabaseHas('order_items', [
            'product_type' => 'device_slot',
            'quantity' => 2,
            'line_amount_minor' => 2400,
        ]);

        $entitlement = UserDeviceEntitlement::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($entitlement);
        $this->assertSame(2, (int) $entitlement->slots_purchased);

    }

    public function test_admin_can_provision_esim_for_user_with_non_zero_order_amount(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $adminToken = $admin->createToken('admin')->plainTextToken;

        BillingSetting::current()->update(['esim_price_minor' => 3500]);
        $esim = EsimInventory::query()->create([
            'iccid' => '8901000000000000009',
            'phone_number' => '+12025550999',
            'status' => 'available',
        ]);

        $response = $this->withToken($adminToken)->postJson('/api/v1/admin/orders/provision/esim', [
            'user_id' => $user->id,
            'esim_inventory_id' => $esim->id,
        ]);
        $response->assertOk();

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'source' => 'admin_assign',
            'status' => 'fulfilled',
            'amount_minor' => 3500,
        ]);
        $this->assertDatabaseHas('order_items', [
            'product_type' => 'esim',
            'product_id' => $esim->id,
            'line_amount_minor' => 3500,
        ]);
        $this->assertDatabaseHas('user_esims', [
            'user_id' => $user->id,
            'esim_inventory_id' => $esim->id,
        ]);

    }
}
