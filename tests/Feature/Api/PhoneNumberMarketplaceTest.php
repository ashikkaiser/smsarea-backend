<?php

namespace Tests\Feature\Api;

use App\Models\BillingSetting;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneNumberMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_lists_unassigned_numbers(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('m', ['user'])->plainTextToken;

        PhoneNumber::create([
            'phone_number' => '+12025550901',
            'status' => 'active',
            'sim_slot' => 1,
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/numbers/catalog');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertGreaterThanOrEqual(1, count($response->json('data.numbers')));
    }

    public function test_user_can_start_checkout_when_nowpayments_configured(): void
    {
        Http::fake([
            'api-sandbox.nowpayments.io/*' => Http::response([
                'payment_id' => '999001',
                'pay_address' => 'test_addr',
                'pay_currency' => 'btc',
                'pay_amount' => '0.001',
            ], 200),
        ]);

        $settings = BillingSetting::current();
        $settings->update([
            'nowpayments_api_key' => 'test-api-key',
            'nowpayments_ipn_secret' => 'test-secret',
            'self_checkout_enabled' => true,
        ]);

        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('m', ['user'])->plainTextToken;

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550902',
            'status' => 'active',
            'sim_slot' => 2,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/numbers/orders', [
            'phone_number_id' => $phoneNumber->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.order.provider_payment_id', '999001');
        $this->assertDatabaseHas('phone_number_orders', [
            'user_id' => $user->id,
            'phone_number_id' => $phoneNumber->id,
            'provider_payment_id' => '999001',
        ]);
    }
}
