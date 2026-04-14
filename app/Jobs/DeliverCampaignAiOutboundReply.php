<?php

namespace App\Jobs;

use App\Models\AiUsageLog;
use App\Models\Conversation;
use App\Services\ChatService;
use App\Services\SmsGatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends the campaign AI outbound SMS after optional human-delay (scheduled separately so workers
 * are not blocked and other conversations/campaigns can run).
 */
class DeliverCampaignAiOutboundReply implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 90;

    public int $tries = 3;

    public function __construct(
        public int $conversationId,
        public int $campaignId,
        public int $campaignOwnerUserId,
        public string $reply,
        public ?int $anchorInboundMessageId,
        public string $model,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public ?int $totalTokens,
    ) {}

    public function handle(
        ChatService $chatService,
        SmsGatewayService $smsGatewayService,
    ): void {
        $conversation = Conversation::query()->with(['phoneNumber.device'])->find($this->conversationId);
        if (! $conversation) {
            return;
        }

        $last = $conversation->messages()
            ->orderByDesc('id')
            ->first(['id', 'direction', 'message_type']);

        if (
            ! $last
            || $last->direction !== 'inbound'
            || ($last->message_type ?? 'sms') !== 'sms'
        ) {
            Log::info('campaign_ai.deliver_skipped', [
                'reason' => 'last_message_not_inbound_sms',
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        if ($this->anchorInboundMessageId !== null && (int) $last->id !== (int) $this->anchorInboundMessageId) {
            Log::info('campaign_ai.deliver_skipped', [
                'reason' => 'stale_anchor_newer_inbound',
                'conversation_id' => $this->conversationId,
                'expected_inbound_id' => $this->anchorInboundMessageId,
                'actual_last_id' => $last->id,
            ]);

            return;
        }

        $phone = $conversation->phoneNumber;
        if (! $phone) {
            return;
        }

        AiUsageLog::query()->create([
            'user_id' => $this->campaignOwnerUserId,
            'campaign_id' => $this->campaignId,
            'source' => AiUsageLog::SOURCE_CAMPAIGN_INBOUND,
            'conversation_id' => $conversation->id,
            'message_id' => $this->anchorInboundMessageId,
            'model' => $this->model,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
        ]);

        $outbound = $chatService->addOutboundMessage($conversation, [
            'contact_number' => $conversation->contact_number,
            'message' => $this->reply,
            'message_type' => 'sms',
        ]);

        $outbound->forceFill([
            'meta' => array_merge(is_array($outbound->meta) ? $outbound->meta : [], [
                'source' => 'campaign_ai',
                'campaign_id' => $this->campaignId,
                'inbound_message_id' => $this->anchorInboundMessageId,
            ]),
        ])->save();

        $phone->loadMissing('device');
        $smsGatewayService->pushOutboundToDevice($outbound, $phone, $conversation);

        $uid = (int) ($conversation->assigned_user_id ?? 0);
        if ($uid > 0) {
            $smsGatewayService->pushChatUpdateToUser($uid, [
                'event' => 'chat_updated',
                'conversation_id' => (int) $conversation->id,
            ]);
        }

        Log::info('campaign_ai.inbound_replied', [
            'conversation_id' => $conversation->id,
            'outbound_message_id' => $outbound->id,
            'campaign_id' => $this->campaignId,
            'inbound_anchor_message_id' => $this->anchorInboundMessageId,
        ]);
    }
}
