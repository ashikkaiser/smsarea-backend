<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OllamaChatRequest;
use App\Services\OllamaChatService;
use Illuminate\Http\JsonResponse;

class OllamaPlaygroundController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OllamaChatService $ollamaChatService) {}

    public function chat(OllamaChatRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $model = isset($validated['model']) ? (string) $validated['model'] : null;
        $reply = $this->ollamaChatService->chatCompletion($validated['messages'], $model);

        $resolvedModel = is_string($model) && trim($model) !== ''
            ? trim($model)
            : (string) config('services.ollama.model', 'qwen2.5:7b');

        return $this->success([
            'reply' => $reply,
            'ok' => $reply !== null,
            'model' => $resolvedModel,
        ], $reply !== null ? 'Reply received.' : 'No reply from Ollama (check URL, model, and logs).');
    }
}
