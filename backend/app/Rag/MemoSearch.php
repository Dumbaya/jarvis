<?php

namespace App\Rag;

use App\Jarvis\LlmBreaker;
use App\Jarvis\LlmQuotaExceeded;
use App\Jarvis\LlmUnavailable;
use App\Models\User;

/**
 * 메모 하이브리드 검색: 키워드 검색과 의미(벡터) 검색을 RRF(Reciprocal Rank Fusion)로 합친다.
 *
 * 키워드는 정확한 단어에 강하고 의미 검색은 표현이 달라도 찾는다. 두 목록의 "순위"만 합치기 때문에
 * 점수 척도가 서로 달라도 되고, 양쪽에서 모두 높은 메모가 맨 위로 온다.
 * 의미 검색을 못 쓰는 경우(꺼짐, 한도, 장애, 색인 전)에는 키워드로만 찾고 그 이유를 함께 돌려준다.
 */
class MemoSearch
{
    /** RRF 상수. 클수록 순위 차이의 영향이 완만해진다. 논문과 대부분의 구현이 쓰는 값이다. */
    private const RRF_K = 60;

    private const KEYWORD_POOL = 30;

    public function __construct(
        private readonly Embedder $embedder,
        private readonly VectorStore $store,
        private readonly MemoIndexer $indexer,
        private readonly LlmBreaker $breaker,
    ) {}

    /**
     * @return array{data: array<int, array>, total: int, semantic: array{used: bool, reason: ?string}}
     */
    public function search(User $user, string $query, ?string $tag = null, int $limit = 5): array
    {
        $keyword = $user->memos()->searchTerms($query)->tagged($tag)->orderByDesc('id')->limit(self::KEYWORD_POOL)->pluck('id')->all();

        [$semantic, $info, $excerpts] = $this->semantic($user, $query);

        // 순위만 합친다.
        $scores = [];
        foreach ([$keyword, array_keys($semantic)] as $ranking) {
            foreach (array_values($ranking) as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / (self::RRF_K + $rank + 1);
            }
        }
        arsort($scores);

        // 사용자 범위로 다시 불러온다. 태그 필터는 의미 검색 결과에도 똑같이 적용한다.
        $memos = $user->memos()->with('tags')->tagged($tag)->whereIn('id', array_keys($scores))->get()->keyBy('id');

        $rows = [];
        foreach (array_keys($scores) as $id) {
            if (! $memo = $memos->get($id)) {
                continue;
            }
            $inKeyword = in_array($id, $keyword, true);
            $inSemantic = isset($semantic[$id]);

            $rows[] = [
                'id' => $memo->id,
                'title' => $memo->title,
                // 결과를 LLM에도 보내므로 본문은 앞부분만 준다. 근거가 된 문단은 excerpt로 따로 준다.
                'body' => mb_strimwidth((string) $memo->body, 0, 600, '…'),
                'body_truncated' => mb_strlen((string) $memo->body) > 600,
                'created_at' => $memo->created_at,
                'tags' => $memo->tags->map->only(['id', 'name'])->values(),
                'match' => $inKeyword && $inSemantic ? 'both' : ($inSemantic ? 'semantic' : 'keyword'),
                'similarity' => $inSemantic ? $semantic[$id] : null,
                'excerpt' => $inSemantic ? mb_strimwidth($excerpts[$id], 0, 600, '…') : null,
            ];
        }

        $rows = array_slice($rows, 0, $limit);

        return ['data' => $rows, 'total' => count($rows), 'semantic' => $info];
    }

    /**
     * @return array{0: array<int, float>, 1: array{used: bool, reason: ?string}, 2: array<int, string>}
     *                                                                                                   [메모 id => 최고 유사도(내림차순), 사용 여부와 못 쓴 이유, 메모 id => 근거 문단]
     */
    private function semantic(User $user, string $query): array
    {
        $none = fn (string $reason) => [[], ['used' => false, 'reason' => $reason], []];

        if (! $user->memo_ai_indexing) {
            return $none('disabled');
        }
        if (! $this->indexer->available()) {
            return $none('quota');
        }
        if ($this->store->indexedMemoCount($user->id, $this->embedder->model()) === 0) {
            return $none($user->memos()->exists() ? 'indexing' : 'disabled');
        }

        try {
            $vector = $this->embedder->embed([$query], Embedder::QUERY)[0];
        } catch (LlmQuotaExceeded $e) {
            $this->breaker->block('embed/'.$this->embedder->model(), $e->retryAfter);

            return $none('quota');
        } catch (LlmUnavailable) {
            return $none('unavailable');
        }

        $min = (float) config('jarvis.embedding.min_similarity');
        $margin = config('jarvis.embedding.relative_margin');
        $best = [];
        $excerpts = [];
        $cut = $min;
        foreach ($this->store->nearest($user->id, $this->embedder->model(), $vector, (int) config('jarvis.embedding.top_chunks')) as $hit) {
            // 임계값보다 덜 비슷한 조각은 버린다. 그러지 않으면 관련 없는 질문에도 "가장 덜 안 비슷한" 메모가 항상 나온다.
            if ($hit['similarity'] < $cut || isset($best[$hit['memo_id']])) {
                continue;
            }
            // 상대 기준: 유사도 내림차순이라 처음 통과한 조각이 1위다. 그보다 margin 넘게 낮은 결과는 버려서,
            // 정답 뒤에 "겨우 임계값을 넘긴 관련 약한 메모"가 따라붙는 것을 줄인다(memos:eval 측정치는 config 참고).
            if ($best === [] && is_numeric($margin)) {
                $cut = max($min, $hit['similarity'] - (float) $margin);
            }
            $best[$hit['memo_id']] = $hit['similarity'];   // 유사도 내림차순이라 처음 나온 조각이 그 메모의 최고 점수다
            $excerpts[$hit['memo_id']] = $hit['content'];
        }

        return [$best, ['used' => true, 'reason' => null], $excerpts];
    }
}
