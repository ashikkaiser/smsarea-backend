<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\PhoneNumber;

class CampaignAiInboundService
{
    /** Active campaign on this line with AI inbound handling enabled. */
    public function activeAiCampaignForPhone(PhoneNumber $phone): ?Campaign
    {
        return Campaign::query()
            ->where('status', 'active')
            ->where('ai_inbound_enabled', true)
            ->whereHas('phoneNumbers', static function ($query) use ($phone): void {
                $query->where('phone_numbers.id', $phone->id);
            })
            ->first();
    }

    /**
     * Build Ollama message list: system + recent conversation as user/assistant turns.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function buildChatMessages(Campaign $campaign, Conversation $conversation, int $maxTurns = 24): array
    {
        $system = trim((string) config('services.ollama.campaign_inbound_system_prompt', ''));
        if ($system === '') {
            return [];
        }

        $agent = trim((string) ($campaign->agent_name ?? ''));
        if ($agent !== '') {
            $system .= "\n\nYou are writing as: {$agent}.";
        }

        $rows = $conversation->messages()
            ->where('message_type', 'sms')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['direction', 'body']);

        $messages = [['role' => 'system', 'content' => $system]];

        foreach ($rows->slice(-$maxTurns)->values() as $row) {
            $text = trim((string) ($row->body ?? ''));
            if ($text === '') {
                continue;
            }
            $role = $row->direction === 'inbound' ? 'user' : 'assistant';
            $messages[] = ['role' => $role, 'content' => $text];
        }

        return $messages;
    }
}
