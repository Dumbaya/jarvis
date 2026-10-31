<?php

namespace App\Rag;

use App\Jarvis\LlmQuotaExceeded;
use App\Jarvis\LlmUnavailable;

/**
 * 텍스트를 의미를 담은 숫자 벡터로 바꾸는 제공자. LLM과 마찬가지로 인터페이스 뒤에 두어 제공자를 바꿀 수 있다
 * (Gemini, 로컬 Ollama 등). 같은 제공자의 같은 모델로 만든 벡터끼리만 비교할 수 있다.
 */
interface Embedder
{
    public const DOCUMENT = 'document';

    public const QUERY = 'query';

    /**
     * @param  array<int, string>  $texts
     * @param  string  $task  document(저장할 글) | query(찾는 질문). 모델이 둘을 다르게 처리하면 검색 품질이 오른다.
     * @return array<int, array<int, float>> 입력과 같은 순서의 벡터들
     *
     * @throws LlmQuotaExceeded 한도 초과
     * @throws LlmUnavailable 장애, 설정 없음, 응답 형식 오류
     */
    public function embed(array $texts, string $task): array;

    /** 벡터를 만든 모델 이름. 저장할 때 함께 기록해서 모델이 바뀌면 옛 벡터를 걸러 낸다. */
    public function model(): string;
}
