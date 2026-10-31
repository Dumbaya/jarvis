<?php

namespace App\Rag;

use App\Jarvis\LlmBreaker;
use App\Jarvis\LlmQuotaExceeded;
use App\Jarvis\LlmUnavailable;
use App\Models\Memo;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 메모를 조각내 임베딩하고 저장한다. 사용자가 의미 검색을 켠 경우에만 쓴다(옵트인).
 *
 * 실패해도 메모 저장 자체에는 영향이 없다. 색인이 안 된 메모는 키워드 검색으로는 계속 찾을 수 있고,
 * 이후 검색이나 `php artisan memos:index` 때 다시 시도한다. 한도를 넘기면 풀릴 때까지 호출하지 않는다(차단기).
 */
class MemoIndexer
{
    public function __construct(
        private readonly Embedder $embedder,
        private readonly VectorStore $store,
        private readonly LlmBreaker $breaker,
    ) {}

    public function model(): string
    {
        return $this->embedder->model();
    }

    private function breakerId(): string
    {
        return 'embed/'.$this->embedder->model();
    }

    /** 임베딩을 지금 시도해도 되는지(한도로 막혀 있지 않은지). */
    public function available(): bool
    {
        return ! $this->breaker->isBlocked($this->breakerId());
    }

    public function retryAfter(): int
    {
        return $this->breaker->remaining($this->breakerId());
    }

    public function index(Memo $memo): bool
    {
        return $this->indexMany(collect([$memo])) === 1;
    }

    /**
     * 사용자의 아직 색인되지 않은(현재 모델 기준) 메모를 최대 $limit개 색인한다. 색인한 메모 수를 돌려준다.
     */
    public function indexPending(User $user, int $limit = 20): int
    {
        if (! $user->memo_ai_indexing || ! $this->available()) {
            return 0;
        }

        $model = $this->model();
        $pending = $user->memos()
            ->whereDoesntHave('chunks', fn ($q) => $q->where('model', $model))
            ->orderBy('id')->limit($limit)->get();

        return $this->indexMany($pending);
    }

    public function pendingCount(User $user): int
    {
        $model = $this->model();

        return $user->memos()->whereDoesntHave('chunks', fn ($q) => $q->where('model', $model))->count();
    }

    /**
     * 여러 메모의 조각을 모아 배치로 임베딩한다(요청 횟수를 줄이려고). 앞쪽 배치까지 성공한 메모는 저장하고,
     * 실패하면 그 뒤는 멈춘다. 색인된 메모 수를 돌려준다.
     *
     * @param  Collection<int, Memo>  $memos
     */
    public function indexMany(Collection $memos): int
    {
        if ($memos->isEmpty() || ! $this->available()) {
            return 0;
        }

        // [메모, 임베딩할 텍스트들]
        $jobs = [];
        foreach ($memos as $memo) {
            $title = trim((string) $memo->title);
            $chunks = Chunker::split((string) $memo->body, (int) config('jarvis.embedding.chunk_size'), (int) config('jarvis.embedding.chunk_overlap'), (int) config('jarvis.embedding.max_chunks'));
            if (! $chunks) {
                continue;
            }
            // 제목을 각 조각 앞에 붙여서 본문만으로는 맥락이 모호한 조각도 찾기 쉽게 한다. 저장하는 근거 문단에는 붙이지 않는다.
            $jobs[] = [$memo, $chunks, array_map(fn ($c) => $title === '' ? $c : "{$title}\n{$c}", $chunks)];
        }

        $done = 0;
        // 메모 단위로 묶어 한 번에 보낸다. 한 메모의 조각이 일부만 색인되는 일이 없게 메모를 쪼개지 않는다.
        $batch = [];
        $size = 0;
        $flush = function () use (&$batch, &$size, &$done) {
            if (! $batch) {
                return true;
            }
            $texts = array_merge(...array_map(fn ($j) => $j[2], $batch));
            try {
                $vectors = $this->embedder->embed($texts, Embedder::DOCUMENT);
            } catch (LlmQuotaExceeded $e) {
                $this->breaker->block($this->breakerId(), $e->retryAfter);

                return false;
            } catch (LlmUnavailable) {
                return false;
            }

            $offset = 0;
            foreach ($batch as [$memo, $chunks]) {
                $rows = [];
                foreach ($chunks as $i => $content) {
                    $rows[] = ['content' => $content, 'vector' => $vectors[$offset + $i]];
                }
                $offset += count($chunks);
                $this->store->replace($memo, $this->model(), $rows);
                $done++;
            }
            $batch = [];
            $size = 0;

            return true;
        };

        foreach ($jobs as $job) {
            if ($batch && $size + count($job[1]) > 40) {
                if (! $flush()) {
                    return $done;
                }
            }
            $batch[] = $job;
            $size += count($job[1]);
        }
        $flush();

        return $done;
    }
}
