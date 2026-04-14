<?php

namespace Tests\Unit;

use App\Services\CampaignAiHumanReplyDelay;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CampaignAiHumanReplyDelayTest extends TestCase
{
    public function test_max_zero_disables_delay(): void
    {
        Config::set('services.ai.campaign_inbound_human_delay_max_seconds', 0);
        Config::set('services.ai.campaign_inbound_human_delay_base_seconds', 5);
        Config::set('services.ai.campaign_inbound_human_chars_per_second', 5);

        $this->assertSame(0, CampaignAiHumanReplyDelay::secondsBeforeSend('hello there'));
    }

    public function test_base_plus_typing_from_length(): void
    {
        Config::set('services.ai.campaign_inbound_human_delay_max_seconds', 60);
        Config::set('services.ai.campaign_inbound_human_delay_base_seconds', 2);
        Config::set('services.ai.campaign_inbound_human_chars_per_second', 10);

        // 25 chars -> ceil(25/10)=3 typing + 2 base = 5
        $this->assertSame(5, CampaignAiHumanReplyDelay::secondsBeforeSend(str_repeat('a', 25)));
    }

    public function test_respects_cap(): void
    {
        Config::set('services.ai.campaign_inbound_human_delay_max_seconds', 8);
        Config::set('services.ai.campaign_inbound_human_delay_base_seconds', 2);
        Config::set('services.ai.campaign_inbound_human_chars_per_second', 1);

        $this->assertSame(8, CampaignAiHumanReplyDelay::secondsBeforeSend(str_repeat('x', 100)));
    }

    public function test_chars_per_second_zero_uses_base_only(): void
    {
        Config::set('services.ai.campaign_inbound_human_delay_max_seconds', 30);
        Config::set('services.ai.campaign_inbound_human_delay_base_seconds', 4);
        Config::set('services.ai.campaign_inbound_human_chars_per_second', 0);

        $this->assertSame(4, CampaignAiHumanReplyDelay::secondsBeforeSend('any length string'));
    }
}
