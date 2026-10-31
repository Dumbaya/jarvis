<?php

namespace App\Jarvis;

use Illuminate\Support\Facades\Cache;

/**
 * 한도를 넘긴 모델을 풀릴 때까지 건너뛰게 하는 차단기.
 * 한도가 소진된 걸 알면서 요청마다 실패할 호출을 보내지 않고, 곧바로 다음 모델이나 대체 동작으로 넘어간다.
 */
class LlmBreaker
{
    private const MIN_SECONDS = 5;

    private const MAX_SECONDS = 86400;

    public function block(string $id, int $seconds): void
    {
        $seconds = max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds));

        Cache::put($this->key($id), now()->addSeconds($seconds)->getTimestamp(), $seconds);
    }

    /** 이 모델이 막혀 있는 남은 시간(초). 막혀 있지 않으면 0. */
    public function remaining(string $id): int
    {
        $until = Cache::get($this->key($id));

        return $until ? max(0, $until - now()->getTimestamp()) : 0;
    }

    public function isBlocked(string $id): bool
    {
        return $this->remaining($id) > 0;
    }

    private function key(string $id): string
    {
        return 'llm:blocked:'.$id;
    }
}
