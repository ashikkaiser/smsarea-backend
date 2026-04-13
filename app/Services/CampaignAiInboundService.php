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
     * Build chat API message list: system + recent conversation as user/assistant turns.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function buildChatMessages(Campaign $campaign, Conversation $conversation, ?int $maxTurns = null): array
    {
        $perCampaign = trim((string) ($campaign->ai_inbound_system_prompt ?? ''));
        $global = trim((string) config('services.ai.campaign_inbound_system_prompt', ''));
        $system = $perCampaign !== '' ? $perCampaign : $global;
        if ($system === '') {
            return [];
        }

        $agent = trim((string) ($campaign->agent_name ?? ''));
        if ($agent !== '') {
            $system .= "\n\nYou are writing as: {$agent}.";
        }

        $guardrails = trim((string) config('services.ai.campaign_inbound_guardrails', ''));
        if ($guardrails !== '') {
            $system .= "\n\n---\n".$guardrails;
        }

        $limit = $maxTurns ?? (int) config('services.ai.campaign_inbound_max_context_messages', 48);
        $limit = max(4, min(200, $limit));

        $rows = $conversation->messages()
            ->where('message_type', 'sms')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['direction', 'body']);

        $messages = [['role' => 'system', 'content' => $system]];

        foreach ($rows->slice(-$limit)->values() as $row) {
            $text = trim((string) ($row->body ?? ''));
            if ($text === '') {
                continue;
            }
            $role = $row->direction === 'inbound' ? 'user' : 'assistant';
            $messages[] = ['role' => $role, 'content' => $text];
        }

        return $this->mergeConsecutiveUserTurns($messages);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function mergeConsecutiveUserTurns(array $messages): array
    {
        if ($messages === []) {
            return $messages;
        }

        $out = [$messages[0]];
        for ($i = 1, $c = count($messages); $i < $c; $i++) {
            $m = $messages[$i];
            $lastIdx = count($out) - 1;
            $prev = $out[$lastIdx];
            if ($m['role'] === 'user' && $prev['role'] === 'user') {
                $out[$lastIdx]['content'] = $prev['content']."\n\n".$m['content'];
            } else {
                $out[] = $m;
            }
        }

        return $out;
    }
}
