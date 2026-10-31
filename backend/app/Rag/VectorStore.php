<?php

namespace App\Rag;

use App\Models\Memo;
use App\Models\MemoChunk;
use Illuminate\Support\Facades\DB;

/**
 * 메모 조각과 벡터의 저장, 근접 검색.
 *
 * Postgres에서는 pgvector의 코사인 거리(<=>)로 DB가 직접 정렬한다. 테스트용 SQLite에는 벡터 타입이 없어서
 * 같은 계산을 PHP로 한다(nearestInPhp). 두 경로가 같은 순서를 내는지는 Postgres 테스트에서 직접 비교한다.
 * 모든 조회는 user_id와 model로 먼저 거른다. 다른 사용자의 조각이나 다른 모델의 벡터는 절대 섞이지 않는다.
 */
class VectorStore
{
    /**
     * 메모의 조각을 통째로 교체한다(옛 조각은 모두 지운다). 한 트랜잭션이라 중간에 실패해도 반쯤 바뀐 상태가 남지 않는다.
     *
     * @param  array<int, array{content: string, vector: array<int, float>}>  $rows
     */
    public function replace(Memo $memo, string $model, array $rows): void
    {
        DB::transaction(function () use ($memo, $model, $rows) {
            MemoChunk::query()->where('memo_id', $memo->id)->delete();

            foreach (array_values($rows) as $i => $row) {
                $values = [$memo->id, $memo->user_id, $i, $row['content'], $model, $this->literal($row['vector']), now(), now()];

                if (DB::getDriverName() === 'pgsql') {
                    DB::insert('INSERT INTO memo_chunks (memo_id, user_id, chunk_index, content, model, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, ?, ?)', $values);
                } else {
                    DB::insert('INSERT INTO memo_chunks (memo_id, user_id, chunk_index, content, model, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $values);
                }
            }
        });
    }

    public function forgetMemo(int $memoId): void
    {
        MemoChunk::query()->where('memo_id', $memoId)->delete();
    }

    /** 사용자의 모든 조각을 지운다(의미 검색을 끄거나 탈퇴할 때). 벡터는 원문의 일부 정보를 담고 있으므로 남기지 않는다. */
    public function purgeUser(int $userId): int
    {
        return MemoChunk::query()->where('user_id', $userId)->delete();
    }

    /** 현재 모델로 색인된 메모의 수. */
    public function indexedMemoCount(int $userId, string $model): int
    {
        return MemoChunk::query()->where('user_id', $userId)->where('model', $model)->distinct()->count('memo_id');
    }

    /**
     * 질문 벡터와 가장 가까운 조각들.
     *
     * @param  array<int, float>  $vector
     * @return array<int, array{chunk_id: int, memo_id: int, content: string, similarity: float}> 유사도 내림차순
     */
    public function nearest(int $userId, string $model, array $vector, int $k): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $this->nearestInPhp($userId, $model, $vector, $k);
        }

        $literal = $this->literal($vector);
        $rows = DB::select(
            'SELECT id, memo_id, content, 1 - (embedding <=> ?::vector) AS similarity
             FROM memo_chunks WHERE user_id = ? AND model = ?
             ORDER BY embedding <=> ?::vector LIMIT ?',
            [$literal, $userId, $model, $literal, $k],
        );

        return array_map(fn ($r) => [
            'chunk_id' => (int) $r->id, 'memo_id' => (int) $r->memo_id, 'content' => $r->content, 'similarity' => round((float) $r->similarity, 4),
        ], $rows);
    }

    /** SQL 없이 PHP로 같은 계산을 한다. SQLite 테스트와, Postgres 결과와의 비교 검증에 쓴다. */
    public function nearestInPhp(int $userId, string $model, array $vector, int $k): array
    {
        $scored = [];
        foreach (DB::table('memo_chunks')->where('user_id', $userId)->where('model', $model)->get(['id', 'memo_id', 'content', 'embedding']) as $row) {
            $scored[] = [
                'chunk_id' => (int) $row->id, 'memo_id' => (int) $row->memo_id, 'content' => $row->content,
                'similarity' => round(self::cosine($vector, json_decode($row->embedding, true)), 4),
            ];
        }
        usort($scored, fn ($a, $b) => $b['similarity'] <=> $a['similarity'] ?: $a['chunk_id'] <=> $b['chunk_id']);

        return array_slice($scored, 0, $k);
    }

    public static function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;
        foreach ($a as $i => $x) {
            $dot += $x * $b[$i];
            $normA += $x * $x;
            $normB += $b[$i] * $b[$i];
        }

        return ($normA > 0 && $normB > 0) ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }

    /** pgvector가 읽는 "[0.1,0.2,...]" 형식. SQLite에서는 같은 문자열이 JSON 배열로도 읽힌다. */
    private function literal(array $vector): string
    {
        return json_encode(array_map('floatval', array_values($vector)));
    }
}
