<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiChatRequest;
use App\Models\AiUsageLog;
use App\Services\AiChatService;
use Illuminate\Http\JsonResponse;

class AiPlaygroundController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AiChatService $aiChatService) {}

    public function chat(AiChatRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $model = isset($validated['model']) ? (string) $validated['model'] : null;
        $completion = $this->aiChatService->chatCompletion($validated['messages'], $model);
        $reply = $completion?->replyText();

        $resolvedModel = is_string($model) && trim($model) !== ''
            ? trim($model)
            : (string) config('services.ai.model', 'qwen2.5:7b');

        if ($completion !== null && $reply !== null) {
            AiUsageLog::query()->create([
                'user_id' => (int) $request->user()->id,
                'campaign_id' => null,
                'source' => AiUsageLog::SOURCE_ADMIN_PLAYGROUND,
                'conversation_id' => null,
                'message_id' => null,
                'model' => $completion->model,
                'prompt_tokens' => $completion->promptTokens,
                'completion_tokens' => $completion->completionTokens,
                'total_tokens' => $completion->totalTokens,
            ]);
        }

        return $this->success([
            'reply' => $reply,
            'ok' => $reply !== null,
            'model' => $resolvedModel,
            'usage' => $completion === null ? null : [
                'prompt_tokens' => $completion->promptTokens,
                'completion_tokens' => $completion->completionTokens,
                'total_tokens' => $completion->totalTokens,
            ],
        ], $reply !== null ? 'Reply received.' : 'No reply from the AI service (check URL, model, and logs).');
    }
}
