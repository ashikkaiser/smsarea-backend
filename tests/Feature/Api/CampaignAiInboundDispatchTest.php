<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessCampaignAiInboundReply;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CampaignAiInboundDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_receive_sms_dispatches_ai_job_when_active_campaign_with_flag_on_line(): void
    {
        Bus::fake();

        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $owner = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_campaign' => true,
            'can_chat' => true,
        ]);
        $pn = PhoneNumber::create([
            'phone_number' => '14157676262',
            'status' => 'active',
        ]);
        $owner->assignedPhoneNumbers()->attach($pn->id, [
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $campaign = Campaign::create([
            'user_id' => $owner->id,
            'name' => 'AI test',
            'status' => 'active',
            'entry_message_template' => 'hello',
            'ai_inbound_enabled' => true,
        ]);
        $campaign->phoneNumbers()->attach($pn->id, [
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
        ]);

        $payload = [
            'device_id' => 'gw-device-ai',
            'contact_number' => '+12185938880',
            'receiver_number' => '14157676262',
            'message' => 'need a reply',
            'timestamp' => 1712627739000,
            'type' => 'incoming',
            'message_type' => 'sms',
            'sim_slot' => 0,
            'sim_info' => ['slot' => 0, 'number' => '14157676262', 'carrier' => 'x'],
            'attachments' => [],
        ];

        $this->postJson('/api/sms/receive', $payload)->assertOk();

        $conversation = Conversation::query()->where('phone_number_id', $pn->id)->first();
        $this->assertNotNull($conversation);
        Bus::assertDispatched(ProcessCampaignAiInboundReply::class, function (ProcessCampaignAiInboundReply $job) use ($conversation) {
            return $job->conversationId === $conversation->id;
        });
    }

    public function test_each_inbound_dispatches_debounced_job_for_same_conversation(): void
    {
        Bus::fake();

        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $owner = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_campaign' => true,
            'can_chat' => true,
        ]);
        $pn = PhoneNumber::create([
            'phone_number' => '14157676263',
            'status' => 'active',
        ]);
        $owner->assignedPhoneNumbers()->attach($pn->id, [
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $campaign = Campaign::create([
            'user_id' => $owner->id,
            'name' => 'AI test',
            'status' => 'active',
            'entry_message_template' => 'hello',
            'ai_inbound_enabled' => true,
        ]);
        $campaign->phoneNumbers()->attach($pn->id, [
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
        ]);

        $base = [
            'device_id' => 'gw-device-ai-2',
            'contact_number' => '+12185938881',
            'receiver_number' => '14157676263',
            'type' => 'incoming',
            'message_type' => 'sms',
            'sim_slot' => 0,
            'sim_info' => ['slot' => 0, 'number' => '14157676263', 'carrier' => 'x'],
            'attachments' => [],
        ];

        $this->postJson('/api/sms/receive', array_merge($base, [
            'message' => 'first',
            'timestamp' => 1712627740000,
        ]))->assertOk();
        $this->postJson('/api/sms/receive', array_merge($base, [
            'message' => 'second',
            'timestamp' => 1712627741000,
        ]))->assertOk();

        $conversation = Conversation::query()->where('phone_number_id', $pn->id)->first();
        $this->assertNotNull($conversation);
        Bus::assertDispatchedTimes(ProcessCampaignAiInboundReply::class, 2);
        Bus::assertDispatched(ProcessCampaignAiInboundReply::class, function (ProcessCampaignAiInboundReply $job) use ($conversation) {
            return $job->conversationId === $conversation->id;
        });
    }

    public function test_receive_sms_does_not_dispatch_when_ai_flag_off(): void
    {
        Bus::fake();

        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $owner = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_campaign' => true,
            'can_chat' => true,
        ]);
        $pn = PhoneNumber::create([
            'phone_number' => '14157676262',
            'status' => 'active',
        ]);
        $owner->assignedPhoneNumbers()->attach($pn->id, [
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $campaign = Campaign::create([
            'user_id' => $owner->id,
            'name' => 'No AI',
            'status' => 'active',
            'entry_message_template' => 'hello',
            'ai_inbound_enabled' => false,
        ]);
        $campaign->phoneNumbers()->attach($pn->id, [
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
        ]);

        $payload = [
            'device_id' => 'gw-device-ai',
            'contact_number' => '+12185938880',
            'receiver_number' => '14157676262',
            'message' => 'hello',
            'timestamp' => 1712627739100,
            'type' => 'incoming',
            'message_type' => 'sms',
            'sim_slot' => 0,
            'sim_info' => ['slot' => 0, 'number' => '14157676262', 'carrier' => 'x'],
            'attachments' => [],
        ];

        $this->postJson('/api/sms/receive', $payload)->assertOk();

        Bus::assertNotDispatched(ProcessCampaignAiInboundReply::class);
    }
}
