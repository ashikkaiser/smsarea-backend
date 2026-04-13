<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiChatService
{
    /**
     * Non-streaming chat completion (compatible HTTP API with /api/chat + message.content).
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chatCompletion(array $messages, ?string $modelOverride = null): ?AiChatCompletionResult
    {
        $base = rtrim((string) config('services.ai.url', ''), '/');
        $model = is_string($modelOverride) && trim($modelOverride) !== ''
            ? trim($modelOverride)
            : (string) config('services.ai.model', 'qwen2.5:7b');

        if ($base === '') {
            Log::warning('ai_chat.chat_skipped', ['reason' => 'missing_base_url']);

            return null;
        }

        $url = $base.'/api/chat';

        try {
            $response = Http::timeout((int) config('services.ai.timeout_seconds', 120))
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                ]);
        } catch (\Throwable $e) {
            Log::warning('ai_chat.chat_exception', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('ai_chat.chat_http_error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            Log::warning('ai_chat.chat_bad_response', ['json' => $json]);

            return null;
        }

        $content = data_get($json, 'message.content');
        if (! is_string($content)) {
            Log::warning('ai_chat.chat_bad_response', ['json' => $json]);

            return null;
        }

        $trimmed = trim($content);
        [$promptTokens, $completionTokens, $totalTokens] = $this->extractUsageFromJson($json);

        return new AiChatCompletionResult(
            $trimmed === '' ? null : $content,
            $promptTokens,
            $completionTokens,
            $totalTokens,
            $model,
        );
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private function extractUsageFromJson(array $json): array
    {
        $prompt = $this->intOrNull(data_get($json, 'prompt_eval_count'))
            ?? $this->intOrNull(data_get($json, 'prompt_tokens'));
        $completion = $this->intOrNull(data_get($json, 'eval_count'))
            ?? $this->intOrNull(data_get($json, 'completion_tokens'));
        $total = $this->intOrNull(data_get($json, 'total_tokens'));
        if ($total === null && $prompt !== null && $completion !== null) {
            $total = $prompt + $completion;
        }

        return [$prompt, $completion, $total];
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
