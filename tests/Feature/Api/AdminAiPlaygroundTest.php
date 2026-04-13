<?php

namespace Tests\Feature\Api;

use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAiPlaygroundTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_use_ai_playground(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('test', ['user'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/ai/chat', [
                'messages' => [
                    ['role' => 'user', 'content' => 'Hi'],
                ],
            ])
            ->assertForbidden();
    }

    public function test_admin_receives_ai_reply_when_http_succeeds(): void
    {
        Config::set('services.ai.url', 'http://fake-ai-service.test');

        Http::fake([
            'http://fake-ai-service.test/api/chat' => Http::response([
                'message' => ['role' => 'assistant', 'content' => 'Hello from tests'],
                'prompt_eval_count' => 5,
                'eval_count' => 7,
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/ai/chat', [
                'messages' => [
                    ['role' => 'user', 'content' => 'Hi'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.reply', 'Hello from tests')
            ->assertJsonPath('data.usage.prompt_tokens', 5)
            ->assertJsonPath('data.usage.completion_tokens', 7)
            ->assertJsonPath('data.usage.total_tokens', 12);

        $this->assertDatabaseHas('ai_usage_logs', [
            'user_id' => $admin->id,
            'source' => AiUsageLog::SOURCE_ADMIN_PLAYGROUND,
            'prompt_tokens' => 5,
            'completion_tokens' => 7,
            'total_tokens' => 12,
        ]);
    }
}
