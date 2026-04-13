<?php

namespace App\Jobs;

use App\Models\AiUsageLog;
use App\Models\Message;
use App\Services\AiChatService;
use App\Services\CampaignAiInboundService;
use App\Services\ChatService;
use App\Services\SmsGatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCampaignAiInboundReply implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $inboundMessageId) {}

    public function handle(
        CampaignAiInboundService $campaignAi,
        AiChatService $aiChat,
        ChatService $chatService,
        SmsGatewayService $smsGatewayService,
    ): void {
        $inbound = Message::query()->with(['conversation', 'phoneNumber'])->find($this->inboundMessageId);
        if (! $inbound || $inbound->direction !== 'inbound') {
            return;
        }

        if (($inbound->message_type ?? 'sms') !== 'sms') {
            return;
        }

        $conversation = $inbound->conversation;
        if (! $conversation) {
            return;
        }

        $phone = $inbound->phoneNumber;
        if (! $phone && $conversation->phone_number_id) {
            $phone = $conversation->phoneNumber()->first();
        }
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
                'message_id' => $inbound->id,
                'campaign_id' => $campaign->id,
                'hint' => 'Set campaign AI system prompt or AI_CAMPAIGN_INBOUND_SYSTEM_PROMPT on the server.',
            ]);

            return;
        }

        $completion = $aiChat->chatCompletion($messages);
        $reply = $completion?->replyText();
        if ($reply === null || $completion === null) {
            Log::warning('campaign_ai.inbound_no_reply', [
                'message_id' => $inbound->id,
                'campaign_id' => $campaign->id,
            ]);

            return;
        }

        AiUsageLog::query()->create([
            'user_id' => $campaign->user_id,
            'campaign_id' => $campaign->id,
            'source' => AiUsageLog::SOURCE_CAMPAIGN_INBOUND,
            'conversation_id' => $conversation->id,
            'message_id' => $inbound->id,
            'model' => $completion->model,
            'prompt_tokens' => $completion->promptTokens,
            'completion_tokens' => $completion->completionTokens,
            'total_tokens' => $completion->totalTokens,
        ]);

        $outbound = $chatService->addOutboundMessage($conversation, [
            'contact_number' => $conversation->contact_number,
            'message' => $reply,
            'message_type' => 'sms',
        ]);

        $outbound->forceFill([
            'meta' => array_merge(is_array($outbound->meta) ? $outbound->meta : [], [
                'source' => 'campaign_ai',
                'campaign_id' => $campaign->id,
                'inbound_message_id' => $inbound->id,
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
            'inbound_message_id' => $inbound->id,
            'outbound_message_id' => $outbound->id,
            'campaign_id' => $campaign->id,
        ]);
    }
}
