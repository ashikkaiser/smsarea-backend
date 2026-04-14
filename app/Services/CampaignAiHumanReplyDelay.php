<?php

namespace App\Services;

/**
 * Computes a plausible "human" pause after the model returns text and before the SMS is sent.
 * Longer replies wait longer (typing time); everything is capped.
 */
final class CampaignAiHumanReplyDelay
{
    public static function secondsBeforeSend(string $reply): int
    {
        $trimmed = trim($reply);
        if ($trimmed === '') {
            return 0;
        }

        $maxTotal = max(0, min(60, (int) config('services.ai.campaign_inbound_human_delay_max_seconds', 30)));
        if ($maxTotal === 0) {
            return 0;
        }

        $base = max(0, min(30, (int) config('services.ai.campaign_inbound_human_delay_base_seconds', 2)));
        $cps = (int) config('services.ai.campaign_inbound_human_chars_per_second', 12);

        if ($cps <= 0) {
            return min($maxTotal, $base);
        }

        $chars = mb_strlen($trimmed);
        $typing = (int) ceil($chars / $cps);

        return min($maxTotal, $base + $typing);
    }
}
