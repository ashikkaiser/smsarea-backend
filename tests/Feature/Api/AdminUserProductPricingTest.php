<?php

namespace Tests\Feature\Api;

use App\Models\BillingSetting;
use App\Models\EsimCarrierPlan;
use App\Models\User;
use App\Models\UserEsimCarrierPriceOverride;
use App\Models\UserPhonePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserProductPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_device_slot_and_esim_overrides_for_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        BillingSetting::current()->update([
            'device_slot_price_minor' => 1000,
            'esim_price_minor' => 2000,
        ]);
        $plan = EsimCarrierPlan::query()->firstOrFail();
        $token = $admin->createToken('a', ['admin'])->plainTextToken;

        $this->withToken($token)->putJson("/api/v1/admin/users/{$user->id}/phone-pricing", [
            'price_minor_per_period' => null,
            'currency' => null,
            'duration_days' => null,
            'device_slot_price_minor' => 777,
            'esim_carrier_overrides' => [
                ['esim_carrier_plan_id' => $plan->id, 'price_minor' => 888],
            ],
        ])->assertOk();

        $row = UserPhonePrice::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(777, (int) $row->device_slot_price_minor);
        $this->assertNull($row->price_minor_per_period);
        $this->assertSame(888, (int) UserEsimCarrierPriceOverride::query()
            ->where('user_id', $user->id)
            ->where('esim_carrier_plan_id', $plan->id)
            ->value('price_minor'));
    }

    public function test_catalog_reflects_user_esim_and_device_slot_overrides(): void
    {
        BillingSetting::current()->update([
            'device_slot_price_minor' => 1000,
            'esim_price_minor' => 2000,
            'self_checkout_enabled' => true,
        ]);
        $user = User::factory()->create(['role' => 'user', 'status' => 'active', 'can_device' => true]);
        $plan = EsimCarrierPlan::query()->orderBy('sort_order')->orderBy('id')->firstOrFail();
        UserPhonePrice::query()->create([
            'user_id' => $user->id,
            'device_slot_price_minor' => 1500,
        ]);
        UserEsimCarrierPriceOverride::query()->create([
            'user_id' => $user->id,
            'esim_carrier_plan_id' => $plan->id,
            'price_minor' => 2500,
        ]);
        $token = $user->createToken('u', ['user'])->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/v1/orders/catalog');
        $res->assertOk();
        $res->assertJsonPath('data.pricing.device_slot.amount_minor', 1500);
        $res->assertJsonPath('data.pricing.esim_carriers.0.amount_minor', 2500);
    }

    public function test_partial_phone_pricing_payload_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $admin->createToken('a', ['admin'])->plainTextToken;

        $this->withToken($token)->putJson("/api/v1/admin/users/{$user->id}/phone-pricing", [
            'price_minor_per_period' => 1000,
            'currency' => null,
            'duration_days' => 30,
        ])->assertStatus(422);
    }
}
