<?php

namespace App\Jarvis;

/**
 * 호출 한도(분당, 일일)를 넘겨서 지금은 쓸 수 없는 경우. 일반 장애(LlmUnavailable)와 달리
 * 언제 다시 쓸 수 있는지 알 수 있으므로, 그때까지 이 모델을 건너뛰는 근거가 된다.
 */
class LlmQuotaExceeded extends LlmUnavailable
{
    public function __construct(string $message, public readonly int $retryAfter = 60, public readonly bool $daily = false)
    {
        parent::__construct($message);
    }
}
