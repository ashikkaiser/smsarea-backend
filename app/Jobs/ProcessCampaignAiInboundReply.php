<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AiChatService;
use App\Services\CampaignAiHumanReplyDelay;
use App\Services\CampaignAiInboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessCampaignAiInboundReply implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int How many times the worker may attempt debounce `release()` before giving up. */
    public int $tries = 30;

    public int $timeout = 120;

    public function __construct(public int $conversationId) {}

    public function handle(
        CampaignAiInboundService $campaignAi,
        AiChatService $aiChat,
    ): void {
        $debounceSeconds = max(2, min(90, (int) config('services.ai.campaign_inbound_debounce_seconds', 10)));
        $debounceKey = 'campaign_ai_debounce_until:'.$this->conversationId;
        $until = Cache::get($debounceKey);
        if ($until !== null && (int) $until > now()->timestamp) {
            $wait = min(90, (int) $until - now()->timestamp + 1);
            $this->release(max(1, $wait));

            return;
        }

        $lock = Cache::lock('campaign_ai_reply:'.$this->conversationId, 120);
        try {
            $lock->block(20);
        } catch (LockTimeoutException) {
            Log::warning('campaign_ai.lock_timeout', ['conversation_id' => $this->conversationId]);
            $this->release(5);

            return;
        }

        try {
            $conversation = Conversation::query()->with(['phoneNumber.device'])->find($this->conversationId);
            if (! $conversation) {
                return;
            }

            $lastMessage = $conversation->messages()
                ->orderByDesc('id')
                ->first(['id', 'direction', 'message_type']);

            if (
                ! $lastMessage
                || $lastMessage->direction !== 'inbound'
                || ($lastMessage->message_type ?? 'sms') !== 'sms'
            ) {
                return;
            }

            $latestInbound = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->where('message_type', 'sms')
                ->orderByDesc('id')
                ->first(['id']);

            $phone = $conversation->phoneNumber;
            if (! $phone) {
                return;
            }

            $campaign = $campaignAi->activeAiCampaignForPhone($phone);
            if (! $campaign) {
                return;
            }

            $messages = $campaignAi->buildChatMessages($campaign, $conversation);
            if ($messages === []) {
                Log::info('campaign_ai.inbound_skipped', [
                    'reason' => 'no_system_prompt',
                    'conversation_id' => $conversation->id,
                    'campaign_id' => $campaign->id,
                    'hint' => 'Set campaign AI system prompt or AI_CAMPAIGN_INBOUND_SYSTEM_PROMPT on the server.',
                ]);

                return;
            }

            $completion = $aiChat->chatCompletion($messages);
            $reply = $completion?->replyText();
            if ($reply === null || $completion === null) {
                Log::warning('campaign_ai.inbound_no_reply', [
                    'conversation_id' => $conversation->id,
                    'campaign_id' => $campaign->id,
                ]);

                return;
            }

            $humanDelay = CampaignAiHumanReplyDelay::secondsBeforeSend($reply);

            $deliver = new DeliverCampaignAiOutboundReply(
                conversationId: $conversation->id,
                campaignId: $campaign->id,
                campaignOwnerUserId: $campaign->user_id,
                reply: $reply,
                anchorInboundMessageId: $latestInbound?->id,
                model: $completion->model,
                promptTokens: $completion->promptTokens,
                completionTokens: $completion->completionTokens,
                totalTokens: $completion->totalTokens,
            );

            if ($humanDelay <= 0) {
                Bus::dispatchSync($deliver);
            } else {
                DeliverCampaignAiOutboundReply::dispatch($deliver)->delay(now()->addSeconds($humanDelay));
            }
        } finally {
            $lock->release();
        }
    }
}
