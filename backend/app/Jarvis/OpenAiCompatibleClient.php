<?php

namespace App\Jarvis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI 호환 /chat/completions API용 클라이언트. Groq, OpenRouter, Ollama가 같은 형식을 쓴다.
 *
 * 도구 이름 주의: 이 API들은 이름에 점(.)을 허용하지 않는다(영문, 숫자, _, -만). 우리 도구 이름은
 * memo.search처럼 점을 쓰므로 보낼 때 "__"로 바꾸고, 응답에서 다시 "."로 되돌린다.
 */
class OpenAiCompatibleClient implements LlmClient
{
    public function __construct(
        private readonly string $provider,
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 30,
    ) {}

    public function generate(string $system, array $conversation, array $tools): LlmReply
    {
        $payload = [
            'model' => $this->model,
            'messages' => $this->toMessages($system, $conversation),
        ];
        if ($tools) {
            $payload['tools'] = array_map(fn ($t) => ['type' => 'function', 'function' => [
                'name' => self::encodeName($t['name']),
                'description' => $t['description'],
                // 인자가 없는 도구도 객체 스키마가 있어야 한다. 빈 배열은 []로 인코딩되므로 빈 객체를 쓴다.
                'parameters' => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass],
            ]], $tools);
        }

        try {
            $request = Http::timeout($this->timeout)->acceptJson();
            if ($this->apiKey) {
                $request = $request->withToken($this->apiKey);
            }
            $response = $request->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new LlmUnavailable("{$this->provider}에 연결하지 못했습니다.", 0, $e);
        }

        if ($response->status() === 429) {
            $retry = max(1, (int) ceil((float) ($response->header('Retry-After') ?: 60)));
            Log::warning("{$this->provider} 한도 초과 ({$this->model}, {$retry}초 후 재시도 가능): ".mb_substr((string) $response->json('error.message', ''), 0, 200));
            throw new LlmQuotaExceeded("{$this->provider} 한도 초과 ({$this->model})", $retry);
        }
        if ($response->serverError()) {
            Log::warning("{$this->provider} 일시 오류 {$response->status()}");
            throw new LlmUnavailable("{$this->provider}가 일시적으로 응답하지 않습니다 ({$response->status()}).");
        }
        if ($response->failed()) {
            report(new LlmUnavailable("{$this->provider} 오류 {$response->status()}: ".mb_substr($response->body(), 0, 500)));
            throw new LlmUnavailable("{$this->provider} 요청이 거부되었습니다.");
        }

        $message = $response->json('choices.0.message') ?? [];
        $text = trim((string) ($message['content'] ?? ''));
        $calls = array_values(array_map(fn ($c) => [
            'name' => self::decodeName($c['function']['name'] ?? ''),
            // 모델이 깨진 JSON을 만들 수 있다. 그 경우 빈 인자로 두면 필수 인자 검증이 걸러낸다.
            'args' => (array) (json_decode($c['function']['arguments'] ?? '{}', true) ?: []),
        ], $message['tool_calls'] ?? []));

        if ($text === '' && ! $calls) {
            throw new LlmUnavailable("{$this->provider}가 빈 응답을 반환했습니다.");
        }

        $in = (int) $response->json('usage.prompt_tokens', 0);
        $total = (int) $response->json('usage.total_tokens', $in + (int) $response->json('usage.completion_tokens', 0));

        return new LlmReply($text !== '' ? $text : null, $calls, null, ['input' => $in, 'output' => max(0, $total - $in)], $this->model, $this->provider);
    }

    public static function encodeName(string $name): string
    {
        return str_replace('.', '__', $name);
    }

    public static function decodeName(string $name): string
    {
        return str_replace('__', '.', $name);
    }

    private function toMessages(string $system, array $conversation): array
    {
        $messages = [['role' => 'system', 'content' => $system]];
        $pendingIds = []; // 직전 assistant가 요청한 도구 호출 id를 결과가 오는 순서대로 소비한다
        $n = 0;

        foreach ($conversation as $m) {
            if ($m['role'] === 'tool') {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => array_shift($pendingIds) ?? 'call_unknown',
                    'content' => json_encode($m['result'], JSON_UNESCAPED_UNICODE),
                ];

                continue;
            }

            if ($m['role'] === 'assistant' && ! empty($m['tool_calls'])) {
                $calls = [];
                foreach ($m['tool_calls'] as $c) {
                    $id = 'call_'.(++$n);
                    $pendingIds[] = $id;
                    $calls[] = ['id' => $id, 'type' => 'function', 'function' => [
                        'name' => self::encodeName($c['name']),
                        'arguments' => json_encode((object) $c['args'], JSON_UNESCAPED_UNICODE),
                    ]];
                }
                $messages[] = ['role' => 'assistant', 'content' => $m['text'] ?? null, 'tool_calls' => $calls];

                continue;
            }

            $messages[] = ['role' => $m['role'], 'content' => $m['text'] ?? ''];
        }

        return $messages;
    }
}
