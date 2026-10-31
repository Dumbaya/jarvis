<?php

namespace App\Jarvis;

/**
 * 여러 모델/제공자를 순서대로 시도하는 LLM 클라이언트. 앞 모델이 한도를 넘기거나 장애면 다음 모델로 넘어간다.
 * 무료 티어 한도는 모델마다 따로 계산되므로, 모델을 여러 개 이어 두면 쓸 수 있는 횟수가 늘어난다.
 *
 * 한도를 넘긴 모델은 풀릴 때까지 차단기(LlmBreaker)에 올려 요청마다 실패할 호출을 보내지 않는다.
 */
class LlmChain implements LlmClient
{
    /** @param  array<int, array{0: string, 1: LlmClient}>  $entries  [id("제공자/모델"), 클라이언트] 순서대로 시도한다 */
    public function __construct(private readonly array $entries, private readonly LlmBreaker $breaker) {}

    /**
     * JARVIS_LLM_CHAIN("gemini/gemini-3.5-flash,groq/모델명")을 읽어 체인을 만든다.
     * 값이 없으면 기존 설정(GEMINI_MODEL)으로 한 개짜리 체인을 만들고, 키가 없는 제공자는 건너뛴다.
     * 쓸 수 있는 항목이 하나도 없으면 키 없이 동작하는 규칙 기반 클라이언트를 돌려준다.
     */
    public static function fromConfig(LlmBreaker $breaker): LlmClient
    {
        if (config('jarvis.provider') === 'rule') {
            return new RuleBasedClient;
        }

        $entries = [];
        foreach (self::specs() as $spec) {
            [$provider, $model] = array_pad(explode('/', $spec, 2), 2, null);
            if ($client = self::build($provider, $model)) {
                $entries[] = [$spec, $client];
            }
        }

        return $entries ? new self($entries, $breaker) : new RuleBasedClient;
    }

    /** @return array<int, string> 설정된 "제공자/모델" 목록(키 유무와 상관없이) */
    public static function specs(): array
    {
        $raw = (string) config('jarvis.chain') ?: 'gemini/'.config('jarvis.gemini.model');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function generate(string $system, array $conversation, array $tools): LlmReply
    {
        $quota = null;
        $failure = null;

        foreach ($this->entries as [$id, $client]) {
            if ($this->breaker->isBlocked($id)) {
                continue;
            }

            try {
                return $client->generate($system, $conversation, $tools);
            } catch (LlmQuotaExceeded $e) {
                $this->breaker->block($id, $e->retryAfter);
                $quota = $e;
            } catch (LlmUnavailable $e) {
                // 일시 장애나 요청 거부는 이번 요청만 다음 모델로 넘기고, 차단하지는 않는다.
                $failure = $e;
            }
        }

        // 하나라도 일반 장애였다면 그것이 원인이다. 전부 한도 때문이거나 이미 차단 중이면 한도 초과다.
        throw $failure ?? $quota ?? new LlmQuotaExceeded('사용할 수 있는 AI가 없습니다.', $this->status()['retry_after'] ?? 60);
    }

    /** @return array{available: bool, retry_after: int|null, models: array<int, array{id: string, blocked_for: int}>} */
    public function status(): array
    {
        $models = array_map(fn ($e) => ['id' => $e[0], 'blocked_for' => $this->breaker->remaining($e[0])], $this->entries);
        $open = array_filter($models, fn ($m) => $m['blocked_for'] === 0);

        return [
            'available' => (bool) $open,
            'retry_after' => $open ? null : min(array_column($models, 'blocked_for')),
            'models' => $models,
        ];
    }

    private static function build(string $provider, ?string $model): ?LlmClient
    {
        $config = config("jarvis.providers.{$provider}");
        if (! $config || ! $model) {
            return null;
        }

        return match ($provider) {
            'gemini' => $config['api_key'] ? new GeminiClient($config['api_key'], $model, $config['timeout']) : null,
            // Ollama는 로컬이라 키가 없다. 나머지는 키가 있어야 한다.
            'groq', 'openrouter', 'ollama' => ($provider === 'ollama' || $config['api_key'])
                ? new OpenAiCompatibleClient($provider, $config['base_url'], $config['api_key'] ?? null, $model, $config['timeout'])
                : null,
            default => null,
        };
    }
}
