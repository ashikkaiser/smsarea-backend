<?php

namespace Tests\Feature\Api;

use App\Models\AiUsageLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_view_ai_usage(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('test', ['user'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/ai-usage')
            ->assertForbidden();
    }

    public function test_admin_sees_summary_and_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $owner = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        AiUsageLog::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'source' => AiUsageLog::SOURCE_ADMIN_PLAYGROUND,
            'model' => 'test-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'created_at' => Carbon::parse('2026-04-10 12:00:00'),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/ai-usage?from=2026-04-01&to=2026-04-30');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.request_count', 1)
            ->assertJsonPath('data.summary.sum_tokens_approx', 30)
            ->assertJsonPath('data.by_user.0.user_id', $owner->id)
            ->assertJsonPath('data.logs.data.0.source', AiUsageLog::SOURCE_ADMIN_PLAYGROUND);
    }
}
