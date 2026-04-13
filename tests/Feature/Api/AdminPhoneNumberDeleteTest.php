<?php

namespace Tests\Feature\Api;

use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPhoneNumberDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_impact_returns_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550155',
            'status' => 'active',
            'sim_slot' => 0,
        ]);

        $response = $this->withToken($token)
            ->getJson("/api/v1/admin/phone-numbers/{$phoneNumber->id}/delete-impact");

        $response->assertOk();
        $response->assertJsonPath('data.phone_number_id', $phoneNumber->id);
        $this->assertIsArray($response->json('data.impact'));
        $this->assertSame(0, $response->json('data.impact.messages'));
        $response->assertJsonPath('data.deletion_blocked', false);
        $response->assertJsonPath('data.deletion_blocked_reason', null);
    }

    public function test_delete_impact_flags_blocked_when_actively_assigned(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $assignee = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550156',
            'status' => 'active',
            'sim_slot' => 0,
        ]);

        $phoneNumber->users()->syncWithoutDetaching([
            $assignee->id => [
                'assigned_by' => $admin->id,
                'assigned_at' => now(),
                'status' => 'active',
            ],
        ]);

        $response = $this->withToken($token)
            ->getJson("/api/v1/admin/phone-numbers/{$phoneNumber->id}/delete-impact");

        $response->assertOk();
        $response->assertJsonPath('data.deletion_blocked', true);
        $this->assertNotNull($response->json('data.deletion_blocked_reason'));
    }

    public function test_admin_can_delete_phone_number_with_confirm(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550177',
            'status' => 'active',
            'sim_slot' => 0,
        ]);

        $response = $this->withToken($token)
            ->deleteJson("/api/v1/admin/phone-numbers/{$phoneNumber->id}", [
                'confirm' => true,
            ]);

        $response->assertOk();
        $this->assertDatabaseMissing('phone_numbers', ['id' => $phoneNumber->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'phone_number.deleted',
            'entity_type' => 'phone_number',
            'entity_id' => (string) $phoneNumber->id,
        ]);
    }

    public function test_delete_removes_messages_conversations_pivots_and_purchases(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $assignee = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550999',
            'status' => 'active',
            'sim_slot' => 0,
        ]);

        $campaignOwner = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $campaign = Campaign::create([
            'user_id' => $campaignOwner->id,
            'name' => 'Camp',
            'status' => 'draft',
        ]);
        $campaign->phoneNumbers()->attach($phoneNumber->id, [
            'assigned_at' => now(),
            'assigned_by' => $admin->id,
        ]);

        $phoneNumber->users()->syncWithoutDetaching([
            $assignee->id => [
                'assigned_by' => $admin->id,
                'assigned_at' => now(),
                'unassigned_at' => now(),
                'status' => 'inactive',
            ],
        ]);

        PhoneNumberPurchase::create([
            'phone_number_id' => $phoneNumber->id,
            'user_id' => $assignee->id,
            'purchase_date' => now(),
            'expiry_date' => now()->addYear(),
            'amount_minor' => 1000,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'phone_number_id' => $phoneNumber->id,
            'contact_number' => '+12025550100',
            'assigned_user_id' => $assignee->id,
            'last_message_at' => now(),
            'status' => 'open',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'phone_number_id' => $phoneNumber->id,
            'direction' => 'inbound',
            'body' => 'hello',
            'occurred_at' => now(),
        ]);

        $response = $this->withToken($token)
            ->deleteJson("/api/v1/admin/phone-numbers/{$phoneNumber->id}", [
                'confirm' => true,
            ]);

        $response->assertOk();
        $this->assertDatabaseMissing('phone_numbers', ['id' => $phoneNumber->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('campaign_phone_number', [
            'phone_number_id' => $phoneNumber->id,
            'campaign_id' => $campaign->id,
        ]);
        $this->assertDatabaseMissing('phone_number_user', ['phone_number_id' => $phoneNumber->id]);
        $this->assertDatabaseMissing('phone_number_purchases', ['phone_number_id' => $phoneNumber->id]);
    }

    public function test_delete_without_confirm_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550188',
            'status' => 'active',
            'sim_slot' => 0,
        ]);

        $response = $this->withToken($token)
            ->deleteJson("/api/v1/admin/phone-numbers/{$phoneNumber->id}", [
                'confirm' => false,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('phone_numbers', ['id' => $phoneNumber->id]);
    }

    public function test_delete_rejected_when_active_user_assignment(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $assignee = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550166',
            'status' => 'active',
            'sim_slot' => 0,
        ]);

        $phoneNumber->users()->syncWithoutDetaching([
            $assignee->id => [
                'assigned_by' => $admin->id,
                'assigned_at' => now(),
                'status' => 'active',
            ],
        ]);

        $response = $this->withToken($token)
            ->deleteJson("/api/v1/admin/phone-numbers/{$phoneNumber->id}", [
                'confirm' => true,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('phone_numbers', ['id' => $phoneNumber->id]);
    }
}
