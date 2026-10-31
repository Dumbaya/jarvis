<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rag\Embedder;
use App\Rag\MemoIndexer;
use App\Rag\MemoSearch;
use App\Rag\VectorStore;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 메모 검색 품질 측정. 더미 한국어 메모와 질문(database/fixtures/rag_eval.php)으로 키워드, 의미, 하이브리드를 비교하고,
 * 유사도 임계값을 정할 근거(관련 메모와 무관한 메모의 유사도 분포)를 보여 준다.
 *
 * 실제 임베딩 제공자를 호출한다(메모 한 번 + 질문당 한 번). 임시 사용자를 만들어 쓰고 끝나면 지운다.
 */
class EvaluateMemoSearch extends Command
{
    protected $signature = 'memos:eval {--keep : 평가가 끝나도 임시 사용자와 데이터를 지우지 않는다} {--force : 운영 환경에서도 실행한다}';

    protected $description = '메모 검색 품질을 더미 데이터로 측정한다(키워드 vs 의미 vs 하이브리드). 임베딩 API를 호출한다';

    public function handle(Embedder $embedder, VectorStore $store): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('운영 환경에서는 --force 없이 실행하지 않아요. 임베딩 호출 한도를 쓰는 작업이에요.');

            return self::FAILURE;
        }

        // 같은 질문을 키워드/의미/하이브리드가 각각 임베딩하므로 결과를 기억해서 호출을 한 번으로 줄인다.
        $cached = $this->cachingEmbedder($embedder);
        app()->instance(Embedder::class, $cached);
        $indexer = app(MemoIndexer::class);
        $search = app(MemoSearch::class);

        $data = require database_path('fixtures/rag_eval.php');
        $user = User::factory()->create(['name' => '평가용', 'email' => 'rag-eval-'.Str::random(8).'@example.test']);
        $user->forceFill(['memo_ai_indexing' => true])->save();

        try {
            $keys = [];
            foreach ($data['memos'] as $key => [$title, $body]) {
                $keys[$user->memos()->create(['title' => $title, 'body' => $body])->id] = $key;
            }

            $this->info('메모 '.count($keys).'개를 색인하는 중... (모델: '.$embedder->model().')');
            $indexed = $indexer->indexMany($user->memos()->get());
            if ($indexed !== count($keys)) {
                $this->error("색인에 실패했어요 ({$indexed}/".count($keys).'). 임베딩 한도나 키 설정을 확인하세요.');

                return self::FAILURE;
            }

            $rows = [];
            foreach ($data['queries'] as $item) {
                $rows[] = $this->evaluate($item, $user, $search, $store, $cached, $keys);
            }
            $this->report($rows);
        } finally {
            if (! $this->option('keep')) {
                $user->delete(); // 메모, 태그, 벡터가 모두 함께 지워진다
            }
        }

        return self::SUCCESS;
    }

    private function evaluate(array $item, User $user, MemoSearch $search, VectorStore $store, Embedder $embedder, array $keys): array
    {
        $q = $item['q'];
        $toKeys = fn (array $ids) => array_values(array_map(fn ($id) => $keys[$id], $ids));

        // 키워드만
        $keyword = $toKeys($user->memos()->searchTerms($q)->orderByDesc('id')->limit(3)->pluck('id')->all());

        // 의미만(임계값 없이 전체 순위와 유사도를 본다. 임계값을 정하려면 필요하다)
        $vector = $embedder->embed([$q], Embedder::QUERY)[0];
        $best = [];
        foreach ($store->nearest($user->id, $embedder->model(), $vector, 24) as $hit) {
            $best[$hit['memo_id']] ??= $hit['similarity'];
        }
        $ranked = [];
        foreach ($best as $memoId => $sim) {
            $ranked[$keys[$memoId]] = $sim;
        }

        // 하이브리드(현재 설정의 임계값 적용)
        $hybrid = array_column($search->search($user, $q, null, 3)['data'], 'id');

        return [
            'q' => $q, 'kind' => $item['kind'], 'expect' => $item['expect'],
            'keyword' => $keyword, 'semantic_ranked' => $ranked, 'hybrid' => $toKeys($hybrid),
        ];
    }

    private function report(array $rows): void
    {
        $positive = array_filter($rows, fn ($r) => $r['kind'] !== 'negative');
        $negative = array_filter($rows, fn ($r) => $r['kind'] === 'negative');
        $threshold = (float) config('jarvis.embedding.min_similarity');

        // recall@3: 정답 중 상위 3개 안에서 찾은 비율의 평균, hit@1: 1위가 정답인 질문의 비율
        $metrics = function (array $subset, callable $top3) {
            if (! $subset) {
                return ['recall' => null, 'hit1' => null];
            }
            $recall = $hit1 = 0;
            foreach ($subset as $r) {
                $found = $top3($r);
                $recall += count(array_intersect($r['expect'], $found)) / count($r['expect']);
                $hit1 += ($found && in_array($found[0], $r['expect'], true)) ? 1 : 0;
            }

            return ['recall' => $recall / count($subset), 'hit1' => $hit1 / count($subset)];
        };
        $semanticAt = fn (float $min) => fn ($r) => array_slice(array_keys(array_filter($r['semantic_ranked'], fn ($s) => $s >= $min)), 0, 3);

        $methods = [
            '키워드' => fn ($r) => $r['keyword'],
            "의미(임계값 {$threshold})" => $semanticAt($threshold),
            "하이브리드(임계값 {$threshold})" => fn ($r) => $r['hybrid'],
        ];

        $this->newLine();
        // 배열 키로 null을 쓰면 빈 문자열이 되어 "전체"가 비어 버리므로 [종류, 이름] 쌍으로 둔다.
        foreach ([['paraphrase', '같은 뜻, 다른 표현'], ['keyword', '단어가 그대로 있음'], [null, '전체(정답이 있는 질문)']] as [$kind, $label]) {
            $subset = $kind === null ? $positive : array_filter($positive, fn ($r) => $r['kind'] === $kind);
            $this->line("<options=bold>{$label}</> — 질문 ".count($subset).'개');
            $table = [];
            foreach ($methods as $name => $fn) {
                $m = $metrics($subset, $fn);
                $table[] = [$name, sprintf('%.0f%%', $m['recall'] * 100), sprintf('%.0f%%', $m['hit1'] * 100)];
            }
            $this->table(['방식', 'recall@3', '1위 적중'], $table);
        }

        // 오탐: 관련 메모가 없는 질문에 얼마나 돌려주는가
        $this->line('<options=bold>관련 메모가 없는 질문 — 오탐(적을수록 좋음)</> — 질문 '.count($negative).'개');
        $table = [];
        foreach ($methods as $name => $fn) {
            $counts = array_map(fn ($r) => count($fn($r)), $negative);
            $table[] = [$name, sprintf('평균 %.1f개', array_sum($counts) / max(1, count($counts))), implode(', ', $counts)];
        }
        $this->table(['방식', '돌려준 결과 수', '질문별'], $table);

        // 임계값을 정할 근거: 정답 메모의 최고 유사도와 오답/무관 질문의 1위 유사도
        $relevant = [];
        $irrelevantTop = [];
        foreach ($positive as $r) {
            foreach ($r['expect'] as $key) {
                if (isset($r['semantic_ranked'][$key])) {
                    $relevant[] = $r['semantic_ranked'][$key];
                }
            }
        }
        foreach ($negative as $r) {
            $irrelevantTop[] = max($r['semantic_ranked'] ?: [0]);
        }
        sort($relevant);
        sort($irrelevantTop);
        $this->line('<options=bold>유사도 분포</> (임계값을 정하는 근거)');
        $this->line(sprintf('  정답 메모의 유사도:           최소 %.3f · 중앙값 %.3f · 최대 %.3f  (n=%d)', $relevant[0] ?? 0, $relevant[intdiv(count($relevant), 2)] ?? 0, end($relevant) ?: 0, count($relevant)));
        $this->line(sprintf('  관련 없는 질문의 1위 유사도:   최소 %.3f · 최대 %.3f  (n=%d)', $irrelevantTop[0] ?? 0, end($irrelevantTop) ?: 0, count($irrelevantTop)));

        $this->newLine();
        $this->line('<options=bold>임계값별 의미 검색</> (정답 recall@3 / 관련 없는 질문에 돌려준 평균 개수)');
        $sweep = [];
        foreach ([0.4, 0.5, 0.55, 0.6, 0.65, 0.7, 0.75] as $min) {
            $m = $metrics($positive, $semanticAt($min));
            $falsePositives = array_sum(array_map(fn ($r) => count($semanticAt($min)($r)), $negative)) / max(1, count($negative));
            $sweep[] = [number_format($min, 2), sprintf('%.0f%%', $m['recall'] * 100), sprintf('%.1f개', $falsePositives)];
        }
        $this->table(['임계값', 'recall@3', '오탐'], $sweep);

        // 상대 기준: 절대 임계값을 넘은 결과 중에서도 1위 유사도보다 margin 넘게 낮은 것은 버린다.
        // 정답 뒤에 "겨우 임계값을 넘긴 관련 약한 결과"가 따라붙는 군더더기를 줄이는 방법이라서, 정답을 잃지 않는지 함께 본다.
        $relative = fn (float $margin) => function ($r) use ($threshold, $margin) {
            $top = max($r['semantic_ranked'] ?: [0]);
            $cut = max($threshold, $top - $margin);

            return array_slice(array_keys(array_filter($r['semantic_ranked'], fn ($s) => $s >= $cut)), 0, 3);
        };
        $this->newLine();
        $this->line("<options=bold>상대 기준</> (절대 임계값 {$threshold}에 더해, 1위 유사도보다 margin 넘게 낮은 결과는 버림)");
        $table = [];
        foreach ([null, 0.15, 0.12, 0.10, 0.07, 0.05] as $margin) {
            $fn = $margin === null ? $semanticAt($threshold) : $relative($margin);
            $m = $metrics($positive, $fn);
            $returned = array_sum(array_map(fn ($r) => count($fn($r)), $positive)) / max(1, count($positive));
            $extras = array_sum(array_map(fn ($r) => count(array_diff($fn($r), $r['expect'])), $positive)) / max(1, count($positive));
            $fp = array_sum(array_map(fn ($r) => count($fn($r)), $negative)) / max(1, count($negative));
            $table[] = [$margin === null ? '(없음)' : number_format($margin, 2), sprintf('%.0f%%', $m['recall'] * 100), sprintf('%.0f%%', $m['hit1'] * 100), sprintf('%.2f개', $returned), sprintf('%.2f개', $extras), sprintf('%.2f개', $fp)];
        }
        $this->table(['margin', 'recall@3', '1위 적중', '질문당 반환', '정답 아닌 결과', '무관 질문 오탐'], $table);

        $this->newLine();
        $this->line('<options=bold>질문별 결과</> (정답이 상위 3개 안에 있으면 O)');
        foreach ($rows as $r) {
            $mark = fn (array $found) => $r['kind'] === 'negative' ? (count($found) === 0 ? 'O' : 'X') : (array_intersect($r['expect'], $found) === $r['expect'] ? 'O' : 'X');
            $this->line(sprintf(
                '  키워드 %s · 의미 %s · 하이브리드 %s   [%s] %s',
                $mark($r['keyword']),
                $mark($semanticAt($threshold)($r)),
                $mark($r['hybrid']),
                $r['kind'],
                $r['q'],
            ));
        }
    }

    /** 같은 (텍스트, 용도)는 한 번만 호출하는 래퍼. */
    private function cachingEmbedder(Embedder $inner): Embedder
    {
        return new class($inner) implements Embedder
        {
            private array $cache = [];

            public function __construct(private readonly Embedder $inner) {}

            public function model(): string
            {
                return $this->inner->model();
            }

            public function embed(array $texts, string $task): array
            {
                $missing = [];
                foreach ($texts as $t) {
                    if (! isset($this->cache[$task][$t])) {
                        $missing[$t] = true;
                    }
                }
                if ($missing) {
                    $vectors = $this->inner->embed(array_keys($missing), $task);
                    foreach (array_keys($missing) as $i => $t) {
                        $this->cache[$task][$t] = $vectors[$i];
                    }
                }

                return array_map(fn ($t) => $this->cache[$task][$t], $texts);
            }
        };
    }
}
