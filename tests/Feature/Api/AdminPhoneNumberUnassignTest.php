<?php

namespace Tests\Feature\Api;

use App\Models\PhoneNumber;
use App\Models\PhoneNumberOrder;
use App\Models\PhoneNumberPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPhoneNumberUnassignTest extends TestCase
{
    use RefreshDatabase;

    public function test_unassign_deletes_pivot_revokes_purchase_and_allows_catalog_again(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $adminToken = $admin->createToken('admin', ['user'])->plainTextToken;
        $userToken = $user->createToken('user', ['user'])->plainTextToken;

        $phone = PhoneNumber::create([
            'phone_number' => '+12025559901',
            'status' => 'active',
            'sim_slot' => 0,
        ]);

        $phone->users()->attach($user->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        PhoneNumberPurchase::create([
            'phone_number_id' => $phone->id,
            'user_id' => $user->id,
            'purchase_date' => now(),
            'expiry_date' => now()->addDays(30),
            'amount_minor' => 0,
            'currency' => 'USD',
            'status' => 'active',
            'auto_renew' => false,
        ]);

        $order = PhoneNumberOrder::create([
            'user_id' => $user->id,
            'phone_number_id' => $phone->id,
            'duration_days' => 30,
            'amount_minor' => 1000,
            'currency' => 'USD',
            'status' => PhoneNumberOrder::STATUS_AWAITING_PAYMENT,
            'source' => PhoneNumberOrder::SOURCE_USER_SELF,
            'provider' => 'nowpayments',
        ]);

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/phone-numbers/{$phone->id}/unassign")
            ->assertOk();

        $this->assertDatabaseMissing('phone_number_user', ['phone_number_id' => $phone->id]);
        $this->assertDatabaseHas('phone_number_purchases', [
            'phone_number_id' => $phone->id,
            'user_id' => $user->id,
            'status' => 'revoked',
        ]);
        $this->assertSame(
            PhoneNumberOrder::STATUS_CANCELLED,
            $order->fresh()->status,
        );

        $catalog = $this->withToken($userToken)->getJson('/api/v1/numbers/catalog');
        $catalog->assertOk();
        $ids = collect($catalog->json('data.numbers'))->pluck('id')->all();
        $this->assertContains($phone->id, $ids);

        $my = $this->withToken($userToken)->getJson('/api/v1/numbers/my');
        $my->assertOk();
        $this->assertCount(0, $my->json('data'));
    }
}
