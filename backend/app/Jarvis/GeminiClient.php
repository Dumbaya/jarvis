<?php

namespace App\Jarvis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        if ($response->status() === 429) {
            $quota = $this->quotaError($response);
            Log::warning("Gemini 한도 초과 ({$this->model}, ".($quota->daily ? '일일' : '분당').", {$quota->retryAfter}초 후 재시도 가능): ".mb_substr((string) $response->json('error.message', ''), 0, 200));
            throw $quota;
        }
        if ($response->serverError()) {
            // 대체 동작으로 넘어가기 전에 흔적을 남겨야 한도 초과인지 장애인지 나중에 구분할 수 있다.
            // 한도 종류(분당 요청, 분당 토큰, 일일 등)를 알 수 있도록 오류 사유를 짧게 남긴다. 응답에 API 키는 들어 있지 않다.
            Log::warning("Gemini 일시 오류 {$response->status()}: ".mb_substr((string) $response->json('error.message', $response->body()), 0, 300));
            throw new LlmUnavailable("Gemini가 일시적으로 응답하지 않습니다 ({$response->status()}).");
        }
        if ($response->failed()) {
            // 400/403 등은 설정 문제라 대체 동작으로 넘기되, 원인은 로그로 남긴다.
            report(new LlmUnavailable('Gemini 오류 '.$response->status().': '.$response->body()));
            throw new LlmUnavailable('Gemini 요청이 거부되었습니다.');
        }

        $parts = $response->json('candidates.0.content.parts') ?? [];

        // 인자가 없는 호출은 args가 빈 배열로 디코딩된다. 이 parts를 다음 요청에 되돌려 보낼 때 []로 인코딩되면
        // Gemini가 400으로 거절하므로, 빈 args는 빈 객체({})로 바꿔 둔다.
        $parts = array_map(function ($p) {
            if (isset($p['functionCall']) && empty($p['functionCall']['args'])) {
                $p['functionCall']['args'] = new \stdClass;
            }

            return $p;
        }, $parts);

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

        // 추론(thinking) 토큰도 출력 토큰으로 과금되므로, 후보 토큰만 쓰지 않고 전체에서 입력을 뺀다.
        $meta = $response->json('usageMetadata') ?? [];
        $input = (int) ($meta['promptTokenCount'] ?? 0);
        $total = (int) ($meta['totalTokenCount'] ?? ($input + (int) ($meta['candidatesTokenCount'] ?? 0)));
        $usage = ['input' => $input, 'output' => max(0, $total - $input)];

        return new LlmReply($text !== '' ? $text : null, $calls, $parts, $usage, $this->model, 'gemini');
    }

    /**
     * 429 본문에서 언제 풀리는지(retryDelay, 예: "8693s")와 일일 한도인지(quotaId에 PerDay)를 읽는다.
     * 본문에 정보가 없으면 60초 뒤에 다시 시도한다.
     */
    private function quotaError(Response $response): LlmQuotaExceeded
    {
        $retry = 60;
        $daily = false;

        foreach ($response->json('error.details') ?? [] as $detail) {
            if (isset($detail['retryDelay'])) {
                $retry = max(1, (int) ceil((float) $detail['retryDelay']));
            }
            foreach ($detail['violations'] ?? [] as $violation) {
                $daily = $daily || str_contains($violation['quotaId'] ?? '', 'PerDay');
            }
        }

        return new LlmQuotaExceeded("Gemini 한도 초과 ({$this->model})", $retry, $daily);
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
