<?php

namespace App\Jarvis;

use App\Models\LlmUsage;
use App\Models\User;

/** 채팅 요청 1건 동안의 LLM 호출 횟수와 토큰을 모아 한 행으로 기록한다. */
class UsageTracker
{
    private int $calls = 0;

    private int $input = 0;

    private int $output = 0;

    /** @var array<string, array{provider: ?string, tokens: int}> 모델별 토큰. 체인이 도중에 모델을 바꿀 수 있다. */
    private array $perModel = [];

    public function add(LlmReply $reply): void
    {
        $this->calls++;
        $tokens = ($reply->usage['input'] ?? 0) + ($reply->usage['output'] ?? 0);
        $this->input += $reply->usage['input'] ?? 0;
        $this->output += $reply->usage['output'] ?? 0;

        if ($reply->model) {
            $this->perModel[$reply->model] ??= ['provider' => $reply->provider, 'tokens' => 0];
            $this->perModel[$reply->model]['tokens'] += $tokens;
        }
    }

    public function record(User $user, string $status): LlmUsage
    {
        // 요청 1건은 행 1개로 센다. 도중에 모델이 바뀐 드문 경우에는 토큰을 가장 많이 쓴 모델로 기록한다.
        $top = $this->perModel;
        uasort($top, fn ($a, $b) => $b['tokens'] <=> $a['tokens']);
        $model = array_key_first($top);

        // LLM 응답을 한 번도 못 받은 요청(장애, 한도, 예산 초과)도 어떤 모델을 쓰려던 것인지 남긴다.
        [$fallbackProvider, $fallbackModel] = self::configured();

        return LlmUsage::create([
            'user_id' => $user->id,
            'provider' => $model ? ($top[$model]['provider'] ?? $fallbackProvider) : $fallbackProvider,
            'model' => $model ?? $fallbackModel,
            'status' => $status,
            'llm_calls' => $this->calls,
            'input_tokens' => $this->input,
            'output_tokens' => $this->output,
            'total_tokens' => $this->input + $this->output,
        ]);
    }

    /** 설정상 가장 먼저 쓰려는 [제공자, 모델]. */
    public static function configured(): array
    {
        if (config('jarvis.provider') === 'rule') {
            return ['rule', 'rule-based'];
        }

        $first = LlmChain::specs()[0] ?? null;
        if (! $first) {
            return ['rule', 'rule-based'];
        }
        [$provider, $model] = array_pad(explode('/', $first, 2), 2, null);

        return [$provider, $model ?? 'unknown'];
    }

    public static function configuredModel(): string
    {
        return self::configured()[1];
    }
}
