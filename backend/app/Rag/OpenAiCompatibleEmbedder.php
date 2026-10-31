<?php

namespace App\Rag;

use App\Jarvis\LlmQuotaExceeded;
use App\Jarvis\LlmUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI 호환 /embeddings API용 임베딩 클라이언트. 로컬 Ollama(예: nomic-embed-text, 768차원)를 쓰면
 * 메모 내용이 내 PC 밖으로 나가지 않는다. 이 모델들은 질문/문서 구분(task)을 받지 않으므로 무시한다.
 */
class OpenAiCompatibleEmbedder implements Embedder
{
    private const BATCH = 32;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly int $timeout = 60,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function embed(array $texts, string $task): array
    {
        $vectors = [];
        foreach (array_chunk(array_values($texts), self::BATCH) as $batch) {
            $vectors = array_merge($vectors, $this->batch($batch));
        }

        return $vectors;
    }

    private function batch(array $texts): array
    {
        try {
            $request = Http::timeout($this->timeout)->acceptJson();
            if ($this->apiKey) {
                $request = $request->withToken($this->apiKey);
            }
            $response = $request->post(rtrim($this->baseUrl, '/').'/embeddings', ['model' => $this->model, 'input' => $texts]);
        } catch (ConnectionException $e) {
            throw new LlmUnavailable('임베딩 서버에 연결하지 못했어요.', 0, $e);
        }

        if ($response->status() === 429) {
            throw new LlmQuotaExceeded('임베딩 한도 초과', max(1, (int) ceil((float) ($response->header('Retry-After') ?: 60))));
        }
        if ($response->failed()) {
            throw new LlmUnavailable("임베딩 요청이 실패했어요 ({$response->status()}).");
        }

        $rows = $response->json('data') ?? [];
        if (count($rows) !== count($texts)) {
            throw new LlmUnavailable('임베딩 응답의 개수가 요청과 달라요.');
        }

        // 응답 순서가 보장되지 않는 서버가 있어서 index로 정렬한다.
        usort($rows, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(function ($row) {
            $values = $row['embedding'] ?? [];
            if (count($values) !== $this->dimensions) {
                throw new LlmUnavailable("임베딩 차원이 설정({$this->dimensions})과 달라요: ".count($values));
            }

            return array_map('floatval', $values);
        }, $rows);
    }
}
