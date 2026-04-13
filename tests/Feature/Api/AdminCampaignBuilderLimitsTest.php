<?php

namespace Tests\Feature\Api;

use App\Models\CampaignBuilderLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCampaignBuilderLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_and_update_limits(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $get = $this->withToken($token)->getJson('/api/v1/admin/campaign-builder-limits');
        $get->assertOk();
        $get->assertJsonPath('data.max_reply_steps', 10);

        $put = $this->withToken($token)->putJson('/api/v1/admin/campaign-builder-limits', [
            'max_reply_steps' => 5,
            'max_followup_steps' => 7,
        ]);
        $put->assertOk();
        $this->assertSame(5, CampaignBuilderLimit::current()->max_reply_steps);
        $this->assertSame(7, CampaignBuilderLimit::current()->max_followup_steps);
    }

    public function test_campaign_user_can_read_builder_limits(): void
    {
        CampaignBuilderLimit::current()->update([
            'max_reply_steps' => 4,
            'max_followup_steps' => 6,
        ]);

        $user = User::factory()->create(['role' => 'user', 'status' => 'active', 'can_campaign' => true]);
        $token = $user->createToken('u', ['user'])->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/v1/campaigns/builder-limits');
        $res->assertOk();
        $res->assertJsonPath('data.max_reply_steps', 4);
        $res->assertJsonPath('data.max_followup_steps', 6);
    }
}
