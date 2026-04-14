<?php

namespace Tests\Feature\Api;

use App\Models\BillingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserDevicePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_devices_dashboard_requires_can_device(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_device' => false,
        ]);
        $token = $user->createToken('t', ['user'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/devices/my')->assertForbidden();
    }

    public function test_device_slot_checkout_requires_can_device(): void
    {
        Http::fake([
            'api-sandbox.nowpayments.io/*' => Http::response([
                'payment_id' => '777010',
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

        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_device' => false,
        ]);
        $token = $user->createToken('t', ['user'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/orders', [
            'product_type' => 'device_slot',
            'quantity' => 1,
        ])->assertForbidden();
    }

    public function test_catalog_omits_device_slot_when_can_device_disabled(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_device' => false,
        ]);
        $token = $user->createToken('t', ['user'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/orders/catalog');
        $response->assertOk();
        $response->assertJsonMissingPath('data.device_slot_product');
        $response->assertJsonMissingPath('data.pricing.device_slot');
    }

    public function test_pricing_preview_device_slot_requires_can_device(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_device' => false,
        ]);
        $token = $user->createToken('t', ['user'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/orders/pricing-preview?product_type=device_slot')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
