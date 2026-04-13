<?php

namespace Tests\Feature\Api;

use App\Models\BillingSetting;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberPurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_record_purchase_for_self_via_legacy_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('purchase-test', ['user'])->plainTextToken;

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550001',
            'status' => 'active',
            'sim_slot' => 1,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/numbers/purchase', [
            'phone_number_id' => $phoneNumber->id,
            'amount_minor' => 999,
            'currency' => 'USD',
            'duration_days' => 30,
            'auto_renew' => false,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('phone_number_purchases', [
            'phone_number_id' => $phoneNumber->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_user_cannot_use_legacy_purchase_endpoint(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('purchase-test', ['user'])->plainTextToken;

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550002',
            'status' => 'active',
            'sim_slot' => 2,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/numbers/purchase', [
            'phone_number_id' => $phoneNumber->id,
            'amount_minor' => 999,
            'currency' => 'USD',
            'duration_days' => 30,
            'auto_renew' => false,
        ]);

        $response->assertForbidden();
    }

    public function test_my_numbers_includes_checkout_flags_in_meta(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('purchase-test', ['user'])->plainTextToken;

        $billing = BillingSetting::current();
        $billing->self_checkout_enabled = false;
        $billing->nowpayments_api_key = 'np-test-key';
        $billing->save();

        $response = $this->withToken($token)->getJson('/api/v1/numbers/my');

        $response->assertOk();
        $response->assertJsonPath('meta.self_checkout_enabled', false);
        $response->assertJsonPath('meta.nowpayments_configured', true);
    }
}
