<?php

namespace App\Rag;

use App\Jarvis\LlmQuotaExceeded;
use App\Jarvis\LlmUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiEmbedder implements Embedder
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

    /** 한 번에 보내는 최대 개수. API 상한(100)보다 작게 잡아 요청 크기를 줄인다. */
    private const BATCH = 50;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly int $timeout = 30,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function embed(array $texts, string $task): array
    {
        $vectors = [];

        foreach (array_chunk(array_values($texts), self::BATCH) as $batch) {
            $vectors = array_merge($vectors, $this->batch($batch, $task));
        }

        return $vectors;
    }

    /** @param  array<int, string>  $texts */
    private function batch(array $texts, string $task): array
    {
        $requests = array_map(fn ($text) => [
            'model' => "models/{$this->model}",
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => $task === Embedder::QUERY ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT',
            'outputDimensionality' => $this->dimensions,
        ], $texts);

        try {
            // 키는 URL이 아니라 헤더로 보낸다.
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout($this->timeout)
                ->post(self::ENDPOINT."/{$this->model}:batchEmbedContents", ['requests' => $requests]);
        } catch (ConnectionException $e) {
            throw new LlmUnavailable('임베딩 제공자에 연결하지 못했어요.', 0, $e);
        }

        if ($response->status() === 429) {
            $retry = 60;
            foreach ($response->json('error.details') ?? [] as $detail) {
                if (isset($detail['retryDelay'])) {
                    $retry = max(1, (int) ceil((float) $detail['retryDelay']));
                }
            }
            Log::warning("Gemini 임베딩 한도 초과 ({$this->model}, {$retry}초 후 재시도 가능)");
            throw new LlmQuotaExceeded("임베딩 한도 초과 ({$this->model})", $retry);
        }
        if ($response->failed()) {
            Log::warning("Gemini 임베딩 오류 {$response->status()}: ".mb_substr((string) $response->json('error.message', ''), 0, 200));
            throw new LlmUnavailable("임베딩 요청이 실패했어요 ({$response->status()}).");
        }

        $embeddings = $response->json('embeddings') ?? [];
        if (count($embeddings) !== count($texts)) {
            throw new LlmUnavailable('임베딩 응답의 개수가 요청과 달라요.');
        }

        return array_map(function ($e) {
            $values = $e['values'] ?? [];
            // 차원이 다르면 DB 컬럼(고정 크기)에 넣을 수 없고, 다른 모델의 벡터와 섞이면 검색이 틀어진다.
            if (count($values) !== $this->dimensions) {
                throw new LlmUnavailable("임베딩 차원이 설정({$this->dimensions})과 달라요: ".count($values));
            }

            return array_map('floatval', $values);
        }, $embeddings);
    }
}
