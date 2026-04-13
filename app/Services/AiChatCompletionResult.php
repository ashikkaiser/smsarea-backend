<?php

namespace App\Services;

final class AiChatCompletionResult
{
    public function __construct(
        public ?string $content,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public ?int $totalTokens,
        public string $model,
    ) {}

    public function replyText(): ?string
    {
        if ($this->content === null) {
            return null;
        }
        $trimmed = trim($this->content);

        return $trimmed === '' ? null : $trimmed;
    }
}
