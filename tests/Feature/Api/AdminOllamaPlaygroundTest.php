<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminOllamaPlaygroundTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_use_ollama_playground(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('test', ['user'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/ollama/chat', [
                'messages' => [
                    ['role' => 'user', 'content' => 'Hi'],
                ],
            ])
            ->assertForbidden();
    }

    public function test_admin_receives_ollama_reply_when_http_succeeds(): void
    {
        Config::set('services.ollama.url', 'http://fake-ollama.test');

        Http::fake([
            'http://fake-ollama.test/api/chat' => Http::response([
                'message' => ['role' => 'assistant', 'content' => 'Hello from tests'],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/ollama/chat', [
                'messages' => [
                    ['role' => 'user', 'content' => 'Hi'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.reply', 'Hello from tests');
    }
}
