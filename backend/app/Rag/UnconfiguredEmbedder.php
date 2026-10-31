<?php

namespace App\Rag;

use App\Jarvis\LlmUnavailable;

/** 임베딩 제공자(API 키 등)가 설정되지 않았을 때. 호출하면 "쓸 수 없음"으로 응답해서 키워드 검색으로 대체되게 한다. */
class UnconfiguredEmbedder implements Embedder
{
    public function __construct(private readonly string $model = 'unconfigured') {}

    public function model(): string
    {
        return $this->model;
    }

    public function embed(array $texts, string $task): array
    {
        throw new LlmUnavailable('임베딩 제공자가 설정되지 않았어요.');
    }
}
