<?php

namespace Tests\Feature\Api;

use App\Models\BillingSetting;
use App\Models\EsimInventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UniversalOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_start_number_checkout_from_universal_orders_endpoint(): void
    {
        Http::fake([
            'api-sandbox.nowpayments.io/*' => Http::response([
                'payment_id' => '777000',
                'pay_address' => 'test_addr',
                'pay_currency' => 'btc',
                'pay_amount' => '0.001',
            ], 200),
        ]);

        BillingSetting::current()->update([
            'nowpayments_api_key' => 'test-api-key',
            'nowpayments_ipn_secret' => 'test-secret',
            'self_checkout_enabled' => true,
        ]);

        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('m', ['user'])->plainTextToken;
        $number = PhoneNumber::query()->create([
            'phone_number' => '+12025550001',
            'status' => 'active',
            'sim_slot' => 1,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/orders', [
            'product_type' => 'number',
            'phone_number_id' => $number->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.order.provider_payment_id', '777000');
        $this->assertDatabaseHas('order_items', [
            'product_type' => 'number',
            'product_id' => $number->id,
        ]);
    }

    public function test_user_can_start_esim_checkout_from_universal_orders_endpoint(): void
    {
        Http::fake([
            'api-sandbox.nowpayments.io/*' => Http::response([
                'payment_id' => '777001',
                'pay_address' => 'test_addr',
                'pay_currency' => 'btc',
                'pay_amount' => '0.001',
            ], 200),
        ]);

        BillingSetting::current()->update([
            'nowpayments_api_key' => 'test-api-key',
            'nowpayments_ipn_secret' => 'test-secret',
            'self_checkout_enabled' => true,
        ]);

        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('m', ['user'])->plainTextToken;
        $esim = EsimInventory::query()->create([
            'iccid' => '8901000000000000001',
            'phone_number' => '+12025550199',
            'zip_code' => '10001',
            'area_code' => '212',
            'status' => 'available',
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/orders', [
            'product_type' => 'esim',
            'esim_inventory_id' => $esim->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.order.provider_payment_id', '777001');
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'provider_payment_id' => '777001',
        ]);
        $this->assertDatabaseHas('order_items', [
            'product_type' => 'esim',
            'product_id' => $esim->id,
        ]);
    }

    public function test_user_can_reveal_owned_esim(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('m', ['user'])->plainTextToken;
        $order = Order::query()->create([
            'user_id' => $user->id,
            'amount_minor' => 1500,
            'currency' => 'USD',
            'status' => Order::STATUS_FULFILLED,
        ]);
        $esim = EsimInventory::query()->create([
            'iccid' => '8901000000000000002',
            'phone_number' => '+12025550222',
            'status' => 'sold',
        ]);
        $userEsim = $user->esims()->create([
            'esim_inventory_id' => $esim->id,
            'order_id' => $order->id,
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_type' => 'esim',
            'product_id' => $esim->id,
            'quantity' => 1,
            'unit_amount_minor' => 1500,
            'line_amount_minor' => 1500,
            'currency' => 'USD',
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/esim/'.$userEsim->id.'/reveal');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('user_esims', [
            'id' => $userEsim->id,
            'user_id' => $user->id,
        ]);
        $this->assertNotNull($userEsim->fresh()->revealed_at);
    }
}
