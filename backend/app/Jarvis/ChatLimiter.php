<?php

namespace App\Jarvis;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 채팅 요청만 세는 사용자별 호출 제한(분당, 일일). 무료 LLM 한도를 한 사람이 다 쓰지 못하게 한다.
 *
 * 라우트 미들웨어(throttle) 대신 직접 관리하는 이유는 두 가지다.
 *  - 호출 로그 조회나 확인/취소 같은 다른 요청이 같은 한도를 깎지 않게 하려고
 *  - 남은 횟수를 화면에 보여 주려고 (미들웨어는 내부 키를 노출하지 않는다)
 */
class ChatLimiter
{
    /** 둘 다 여유가 있을 때만 한 번씩 소비하고 true. 하나라도 가득 찼으면 아무것도 소비하지 않고 false. */
    public function attempt(User $user): bool
    {
        $windows = $this->windows($user);

        foreach ($windows as $w) {
            if (RateLimiter::tooManyAttempts($w['key'], $w['limit'])) {
                return false;
            }
        }
        foreach ($windows as $w) {
            RateLimiter::hit($w['key'], $w['decay']);
        }

        return true;
    }

    /** @return array{minute: array{limit: int, remaining: int, reset_in: int}, day: array{limit: int, remaining: int, reset_in: int}} */
    public function status(User $user): array
    {
        return array_map(function ($w) {
            $remaining = max(0, RateLimiter::remaining($w['key'], $w['limit']));

            return [
                'limit' => $w['limit'],
                'remaining' => $remaining,
                // 아직 한 번도 안 썼으면 초기화할 것도 없다.
                'reset_in' => $remaining < $w['limit'] ? RateLimiter::availableIn($w['key']) : 0,
            ];
        }, $this->windows($user));
    }

    /** 가득 찬 창 중 가장 늦게 풀리는 것까지 걸리는 시간(초). */
    public function retryAfter(User $user): int
    {
        $waits = array_map(
            fn ($s) => $s['remaining'] === 0 ? $s['reset_in'] : 0,
            $this->status($user),
        );

        return max($waits);
    }

    private function windows(User $user): array
    {
        return [
            'minute' => ['key' => "jarvis:min:{$user->id}", 'limit' => (int) config('jarvis.rate_limit.per_minute'), 'decay' => 60],
            'day' => ['key' => "jarvis:day:{$user->id}", 'limit' => (int) config('jarvis.rate_limit.per_day'), 'decay' => 86400],
        ];
    }
}
