<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessCampaignAiInboundReply;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignAiInboundDebouncedReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_single_reply_after_two_rapid_inbound_lines(): void
    {
        Config::set('services.ai.url', 'http://fake-ai.test');
        Config::set('services.ai.campaign_inbound_human_delay_max_seconds', 0);
        Http::fake([
            'http://fake-ai.test/api/chat' => Http::response([
                'message' => ['role' => 'assistant', 'content' => 'One combined reply.'],
                'prompt_eval_count' => 1,
                'eval_count' => 2,
            ], 200),
        ]);

        $owner = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_campaign' => true,
            'can_chat' => true,
        ]);
        $pn = PhoneNumber::create([
            'phone_number' => '14155550100',
            'status' => 'active',
        ]);
        $owner->assignedPhoneNumbers()->attach($pn->id, [
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $campaign = Campaign::create([
            'user_id' => $owner->id,
            'name' => 'Debounced AI',
            'status' => 'active',
            'entry_message_template' => 'hi',
            'ai_inbound_enabled' => true,
            'ai_inbound_system_prompt' => 'You are a helpful assistant for SMS.',
        ]);
        $campaign->phoneNumbers()->attach($pn->id, [
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
        ]);

        $conversation = Conversation::create([
            'phone_number_id' => $pn->id,
            'contact_number' => '+12185550199',
            'assigned_user_id' => $owner->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'phone_number_id' => $pn->id,
            'direction' => 'inbound',
            'message_type' => 'sms',
            'body' => 'Hello',
            'status' => 'received',
            'occurred_at' => now()->subSeconds(5),
            'meta' => [],
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'phone_number_id' => $pn->id,
            'direction' => 'inbound',
            'message_type' => 'sms',
            'body' => 'I need room rent',
            'status' => 'received',
            'occurred_at' => now(),
            'meta' => [],
        ]);

        Cache::forget('campaign_ai_debounce_until:'.$conversation->id);

        Bus::dispatchSync(new ProcessCampaignAiInboundReply($conversation->id));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'One combined reply.',
        ]);

        $outboundCount = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->count();
        $this->assertSame(1, $outboundCount);

        $aiCount = Http::recorded()->filter(static function (array $pair): bool {
            return str_contains($pair[0]->url(), 'fake-ai.test');
        })->count();
        $this->assertSame(1, $aiCount, 'Expected exactly one AI /api/chat request.');
    }
}
