<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaChatService
{
    /**
     * Non-streaming chat completion against a local or remote Ollama server.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chatCompletion(array $messages, ?string $modelOverride = null): ?string
    {
        $base = rtrim((string) config('services.ollama.url', ''), '/');
        $model = is_string($modelOverride) && trim($modelOverride) !== ''
            ? trim($modelOverride)
            : (string) config('services.ollama.model', 'qwen2.5:7b');

        if ($base === '') {
            Log::warning('ollama.chat_skipped', ['reason' => 'missing_base_url']);

            return null;
        }

        $url = $base.'/api/chat';

        try {
            $response = Http::timeout((int) config('services.ollama.timeout_seconds', 120))
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                ]);
        } catch (\Throwable $e) {
            Log::warning('ollama.chat_exception', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('ollama.chat_http_error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $content = data_get($response->json(), 'message.content');
        if (! is_string($content)) {
            Log::warning('ollama.chat_bad_response', ['json' => $response->json()]);

            return null;
        }

        $trimmed = trim($content);

        return $trimmed === '' ? null : $trimmed;
    }
}
