<?php

namespace App\Jarvis;

final class LlmReply
{
    /**
     * @param  array<int, array{name: string, args: array}>  $toolCalls
     * @param  mixed  $raw  제공자가 다음 턴에 그대로 되돌려 받아야 하는 원본 데이터 (예: Gemini의 thought signature)
     * @param  array{input: int, output: int}|null  $usage  이번 호출의 토큰. output은 추론 토큰까지 포함한다.
     */
    public function __construct(
        public readonly ?string $text = null,
        public readonly array $toolCalls = [],
        public readonly mixed $raw = null,
        public readonly ?array $usage = null,
        public readonly ?string $model = null,
        public readonly ?string $provider = null,
    ) {}
}
