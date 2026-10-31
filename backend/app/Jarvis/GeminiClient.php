<?php

namespace App\Jarvis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiClient implements LlmClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 30,
    ) {}

    public function generate(string $system, array $conversation, array $tools): LlmReply
    {
        if (! $this->apiKey) {
            throw new LlmUnavailable('GEMINI_API_KEY가 설정되지 않았습니다.');
        }

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => $this->toContents($conversation),
        ];
        if ($tools) {
            $payload['tools'] = [['functionDeclarations' => $tools]];
        }

        try {
            // 키는 URL이 아니라 헤더로 보내서 로그나 프록시에 남지 않게 한다.
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout($this->timeout)
                ->post(self::ENDPOINT."/{$this->model}:generateContent", $payload);
        } catch (ConnectionException $e) {
            throw new LlmUnavailable('Gemini에 연결하지 못했습니다.', 0, $e);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new LlmUnavailable("Gemini가 일시적으로 응답하지 않습니다 ({$response->status()}).");
        }
        if ($response->failed()) {
            // 400/403 등은 설정 문제라 대체 동작으로 넘기되, 원인은 로그로 남긴다.
            report(new LlmUnavailable('Gemini 오류 '.$response->status().': '.$response->body()));
            throw new LlmUnavailable('Gemini 요청이 거부되었습니다.');
        }

        $parts = $response->json('candidates.0.content.parts') ?? [];

        $text = collect($parts)->pluck('text')->filter()->implode('');
        $calls = collect($parts)
            ->filter(fn ($p) => isset($p['functionCall']))
            ->map(fn ($p) => [
                'name' => $p['functionCall']['name'],
                'args' => (array) ($p['functionCall']['args'] ?? []),
            ])
            ->values()
            ->all();

        if ($text === '' && ! $calls) {
            throw new LlmUnavailable('Gemini가 빈 응답을 반환했습니다.');
        }

        return new LlmReply($text !== '' ? $text : null, $calls, $parts);
    }

    private function toContents(array $conversation): array
    {
        $contents = [];

        foreach ($conversation as $m) {
            if ($m['role'] === 'tool') {
                $part = ['functionResponse' => ['name' => $m['name'], 'response' => ['result' => $m['result']]]];
                // 한 턴에 여러 도구를 호출했다면 응답을 하나의 content로 묶어야 한다.
                $last = array_key_last($contents);
                if ($last !== null && $contents[$last]['role'] === 'user' && isset($contents[$last]['parts'][0]['functionResponse'])) {
                    $contents[$last]['parts'][] = $part;
                } else {
                    $contents[] = ['role' => 'user', 'parts' => [$part]];
                }

                continue;
            }

            if ($m['role'] === 'assistant') {
                // 원본 parts가 있으면 그대로 되돌려 보낸다 (thought signature 보존).
                $parts = $m['raw'] ?? null;
                if (! $parts) {
                    $parts = [];
                    if (! empty($m['text'])) {
                        $parts[] = ['text' => $m['text']];
                    }
                    foreach ($m['tool_calls'] ?? [] as $c) {
                        $parts[] = ['functionCall' => ['name' => $c['name'], 'args' => (object) $c['args']]];
                    }
                }
                $contents[] = ['role' => 'model', 'parts' => $parts];

                continue;
            }

            $contents[] = ['role' => 'user', 'parts' => [['text' => $m['text']]]];
        }

        return $contents;
    }
}
