<?php

namespace Tests\Feature;

use App\Jarvis\LlmClient;
use App\Jarvis\LlmQuotaExceeded;
use App\Jarvis\LlmReply;
use App\Jarvis\LlmUnavailable;
use App\Models\Memo;
use App\Models\MemoChunk;
use App\Models\User;
use App\Rag\Chunker;
use App\Rag\Embedder;
use App\Rag\GeminiEmbedder;
use App\Rag\MemoIndexer;
use App\Rag\OpenAiCompatibleEmbedder;
use App\Rag\UnconfiguredEmbedder;
use App\Rag\VectorStore;
use Database\Seeders\SecondBrainToolsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 주제별로 결정적인 벡터를 만드는 가짜 임베더. 같은 주제의 단어가 들어 있으면 같은 방향의 벡터가 되어
 * 네트워크 없이 "표현이 달라도 뜻이 비슷하면 가깝다"를 재현한다. 호출 기록을 남겨 프라이버시도 검증한다.
 */
class FakeEmbedder implements Embedder
{
    /** @var array<int, array{task: string, texts: array<int, string>}> */
    public array $calls = [];

    public ?\Throwable $fail = null;

    public function __construct(private readonly string $model = 'fake-embed') {}

    public function model(): string
    {
        return $this->model;
    }

    public function embed(array $texts, string $task): array
    {
        $this->calls[] = ['task' => $task, 'texts' => $texts];
        if ($this->fail) {
            throw $this->fail;
        }

        return array_map(fn ($t) => self::vector($t), $texts);
    }

    public static function vector(string $text): array
    {
        $topics = [
            ['배포', '서버', 'nginx', '502', 'php-fpm', '웹서버'],
            ['요리', '레시피', '두부', '계란', '우유'],
            ['운동', '헬스', '달리기'],
            ['여행', '비행기', '호텔'],
        ];
        $v = array_fill(0, 768, 0.0);
        foreach ($topics as $i => $words) {
            foreach ($words as $w) {
                if (mb_stripos($text, $w) !== false) {
                    $v[$i] += 1.0;
                }
            }
        }
        // 주제 단어가 없는 문장도 영벡터가 되지 않게(코사인이 정의되지 않으므로) 문장마다 다른 칸에 작은 값을 둔다.
        // 모든 문장에 같은 칸을 쓰면 주제 없는 두 문장의 유사도가 1.0이 되어 "관련 없는 것끼리 완전히 일치"하는 거짓 결과가 나온다.
        $v[100 + (crc32($text) % 600)] += 0.05;

        return $v;
    }

    public function totalTexts(): int
    {
        return array_sum(array_map(fn ($c) => count($c['texts']), $this->calls));
    }
}

class RagTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeEmbedder $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jarvis.embedding.min_similarity' => 0.6]);
        $this->fake = new FakeEmbedder;
        $this->app->instance(Embedder::class, $this->fake);
        $this->user = User::factory()->create();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function enable(?User $user = null): User
    {
        $user ??= $this->user;
        $user->forceFill(['memo_ai_indexing' => true])->save();

        return $user;
    }

    private function memo(string $body, ?string $title = null, ?User $owner = null, array $tags = []): Memo
    {
        $memo = ($owner ?? $this->user)->memos()->create(['title' => $title, 'body' => $body]);
        foreach ($tags as $name) {
            $memo->tags()->attach(($owner ?? $this->user)->tags()->firstOrCreate(['name' => $name])->id);
        }

        return $memo;
    }

    private function index(Memo ...$memos): int
    {
        return app(MemoIndexer::class)->indexMany(collect($memos));
    }

    private function search(string $q, string $extra = ''): TestResponse
    {
        return $this->as($this->user)->getJson('/api/memos/search?q='.urlencode($q).$extra);
    }

    // ───────────────────────── 조각 나누기 ─────────────────────────

    public function test_chunker_handles_empty_short_and_grouped_paragraphs(): void
    {
        $this->assertSame([], Chunker::split(''));
        $this->assertSame([], Chunker::split("  \n\n  "));
        $this->assertSame(['짧은 메모'], Chunker::split("  짧은 메모\n"));

        // 문단 3개(각 300자). 앞의 둘은 한 조각에 들어가고 셋째는 넘치므로 새 조각이 된다.
        [$a, $b, $c] = [str_repeat('가', 300), str_repeat('나', 300), str_repeat('다', 300)];
        $this->assertSame(["{$a}\n\n{$b}", $c], Chunker::split("{$a}\n\n{$b}\n\n{$c}", 800));
    }

    public function test_chunker_splits_a_long_paragraph_with_overlap_and_loses_nothing(): void
    {
        // 서로 다른 글자 2000개(공백 없음)
        $text = implode('', array_map(fn ($i) => mb_chr(0xAC00 + $i), range(0, 1999)));

        $chunks = Chunker::split($text, 800, 100);

        $this->assertSame([800, 800, 600], array_map('mb_strlen', $chunks));
        // 인접한 조각은 100자 겹친다
        $this->assertSame(mb_substr($chunks[0], -100), mb_substr($chunks[1], 0, 100));
        $this->assertSame(mb_substr($chunks[1], -100), mb_substr($chunks[2], 0, 100));
        // 이어 붙이면(겹침을 빼고) 원문이 그대로 복원된다
        $this->assertSame($text, $chunks[0].mb_substr($chunks[1], 100).mb_substr($chunks[2], 100));
        foreach ($chunks as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
        }
    }

    public function test_chunker_prefers_cutting_at_spaces_and_always_terminates(): void
    {
        $text = implode(' ', array_map(fn ($i) => "단어{$i}", range(1, 400)));

        $chunks = Chunker::split($text, 200, 30);

        $this->assertGreaterThan(5, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, mb_strlen($chunk));
            $this->assertMatchesRegularExpression('/단어\d+$/u', $chunk, '단어 중간에서 끊기면 안 된다');
        }

        // overlap이 size 이상이어도 무한 루프에 빠지지 않는다
        $this->assertNotEmpty(Chunker::split(str_repeat('가', 500), 100, 500));
    }

    public function test_chunker_caps_the_number_of_chunks(): void
    {
        $text = implode("\n\n", array_fill(0, 100, str_repeat('가', 700)));

        $this->assertCount(25, Chunker::split($text, 800, 100, 25));
        $this->assertCount(7, Chunker::split($text, 800, 100, 7));
    }

    // ───────────────────────── 벡터 저장소 ─────────────────────────

    public function test_cosine_similarity_edge_cases(): void
    {
        $this->assertEqualsWithDelta(1.0, VectorStore::cosine([1, 2, 3], [2, 4, 6]), 1e-9);   // 방향이 같으면 크기와 무관
        $this->assertEqualsWithDelta(0.0, VectorStore::cosine([1, 0], [0, 1]), 1e-9);
        $this->assertEqualsWithDelta(-1.0, VectorStore::cosine([1, 0], [-1, 0]), 1e-9);
        $this->assertSame(0.0, VectorStore::cosine([0, 0], [1, 1]));                           // 영벡터는 NaN이 아니라 0
    }

    public function test_store_replace_nearest_isolation_and_model_filter(): void
    {
        $store = app(VectorStore::class);
        $other = User::factory()->create();
        $mine = $this->memo('내 배포 메모');
        $theirs = $this->memo('남의 배포 메모', null, $other);
        $oldModel = $this->memo('옛 모델 메모');

        $deploy = FakeEmbedder::vector('배포 서버');
        $store->replace($mine, 'm1', [['content' => '내 조각 A', 'vector' => $deploy], ['content' => '내 조각 B', 'vector' => FakeEmbedder::vector('요리')]]);
        $store->replace($theirs, 'm1', [['content' => '남의 조각', 'vector' => $deploy]]);
        $store->replace($oldModel, 'm0', [['content' => '다른 모델 조각', 'vector' => $deploy]]);

        $hits = $store->nearest($this->user->id, 'm1', FakeEmbedder::vector('배포'), 10);

        $this->assertSame(['내 조각 A', '내 조각 B'], array_column($hits, 'content'));   // 남의 조각과 다른 모델의 조각은 안 나온다
        $this->assertGreaterThan($hits[1]['similarity'], $hits[0]['similarity']);
        $this->assertCount(1, $store->nearest($this->user->id, 'm1', FakeEmbedder::vector('배포'), 1));

        // 같은 메모를 다시 교체하면 옛 조각이 남지 않는다
        $store->replace($mine, 'm1', [['content' => '새 조각', 'vector' => $deploy]]);
        $this->assertSame(['새 조각'], MemoChunk::where('memo_id', $mine->id)->pluck('content')->all());
        $this->assertSame(1, $store->indexedMemoCount($this->user->id, 'm1'));
    }

    public function test_purging_and_cascades_remove_vectors(): void
    {
        $store = app(VectorStore::class);
        $other = User::factory()->create();
        $a = $this->memo('가');
        $b = $this->memo('나');
        $c = $this->memo('다', null, $other);
        foreach ([$a, $b, $c] as $m) {
            $store->replace($m, 'm', [['content' => 'x', 'vector' => FakeEmbedder::vector('배포')]]);
        }

        $a->delete();                                                  // 메모를 지우면 조각도 지워진다
        $this->assertSame(2, MemoChunk::count());

        $this->assertSame(1, $store->purgeUser($this->user->id));      // 한 사용자의 것만 지운다
        $this->assertSame(1, MemoChunk::where('user_id', $other->id)->count());

        $other->delete();                                              // 탈퇴하면 벡터도 남지 않는다
        $this->assertSame(0, MemoChunk::count());
    }

    public function test_database_and_php_similarity_agree_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector 거리 계산은 PostgreSQL에서만 비교한다.');
        }

        $store = app(VectorStore::class);
        mt_srand(42);
        $random = fn () => array_map(fn () => mt_rand(-1000, 1000) / 1000, range(1, 768));
        foreach (range(1, 25) as $i) {
            $store->replace($this->memo("메모 {$i}"), 'm', [['content' => "조각 {$i}", 'vector' => $random()]]);
        }
        $query = $random();

        $sql = $store->nearest($this->user->id, 'm', $query, 10);
        $php = $store->nearestInPhp($this->user->id, 'm', $query, 10);

        $this->assertSame(array_column($php, 'chunk_id'), array_column($sql, 'chunk_id'), 'SQL과 PHP의 순서가 같아야 한다');
        foreach ($sql as $i => $row) {
            $this->assertEqualsWithDelta($php[$i]['similarity'], $row['similarity'], 0.001);
        }
    }

    // ───────────────────────── 임베딩 제공자 ─────────────────────────

    private function geminiEmbedder(): GeminiEmbedder
    {
        return new GeminiEmbedder('test-key', 'gemini-embedding-2', 768);
    }

    public function test_gemini_embedder_request_shape_task_types_and_batching(): void
    {
        Http::fake(['*' => fn (HttpRequest $r) => Http::response(['embeddings' => array_map(fn () => ['values' => array_fill(0, 768, 0.1)], $r['requests'])])]);
        $texts = array_map(fn ($i) => "문장 {$i}", range(1, 120));

        $vectors = $this->geminiEmbedder()->embed($texts, Embedder::DOCUMENT);

        $this->assertCount(120, $vectors);
        $sizes = [];
        Http::assertSent(function (HttpRequest $r) use (&$sizes) {
            $sizes[] = count($r['requests']);

            return str_ends_with($r->url(), '/models/gemini-embedding-2:batchEmbedContents')
                && $r->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains($r->url(), 'test-key')
                && $r['requests'][0]['taskType'] === 'RETRIEVAL_DOCUMENT'
                && $r['requests'][0]['outputDimensionality'] === 768
                && $r['requests'][0]['model'] === 'models/gemini-embedding-2';
        });
        $this->assertSame([50, 50, 20], $sizes);                            // 50개씩 나눠 보낸다

        Http::fake(['*' => fn (HttpRequest $r) => Http::response(['embeddings' => [['values' => array_fill(0, 768, 0.1)]]])]);
        $this->geminiEmbedder()->embed(['질문'], Embedder::QUERY);
        Http::assertSent(fn (HttpRequest $r) => $r['requests'][0]['taskType'] === 'RETRIEVAL_QUERY');
    }

    public function test_gemini_embedder_preserves_order_and_validates_responses(): void
    {
        Http::fake(['*' => fn (HttpRequest $r) => Http::response(['embeddings' => array_map(
            fn ($req) => ['values' => array_merge([(float) mb_strlen($req['content']['parts'][0]['text'])], array_fill(0, 767, 0.0))],
            $r['requests'],
        )])]);

        $vectors = $this->geminiEmbedder()->embed(['가', '가나다', '가나'], Embedder::DOCUMENT);

        $this->assertSame([1.0, 3.0, 2.0], array_column($vectors, 0));   // 입력 순서 그대로
    }

    public function test_gemini_embedder_rejects_vectors_of_the_wrong_dimension(): void
    {
        // 차원이 다르면(다른 모델의 벡터가 섞이면) DB 컬럼에 넣을 수도 없고 검색도 틀어지므로 거절한다.
        // (Http::fake를 한 테스트에서 두 번 부르면 먼저 등록한 응답이 우선하므로 별도 테스트로 나눴다.)
        Http::fake(['*' => Http::response(['embeddings' => [['values' => [0.1, 0.2]]]])]);

        $this->expectException(LlmUnavailable::class);
        $this->expectExceptionMessage('차원');
        $this->geminiEmbedder()->embed(['x'], Embedder::DOCUMENT);
    }

    public function test_gemini_embedder_errors_map_to_quota_or_unavailable(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'quota', 'details' => [['retryDelay' => '37s']]]], 429)
            ->push(['error' => ['message' => 'x']], 500)
            ->push(['embeddings' => []], 200),
        ]);
        $embedder = $this->geminiEmbedder();
        $fail = function () use ($embedder) {
            try {
                $embedder->embed(['x'], Embedder::DOCUMENT);
                $this->fail('예외가 나야 합니다.');
            } catch (LlmUnavailable $e) {
                return $e;
            }
        };

        $quota = $fail();
        $this->assertInstanceOf(LlmQuotaExceeded::class, $quota);
        $this->assertSame(37, $quota->retryAfter);
        $this->assertNotInstanceOf(LlmQuotaExceeded::class, $fail());       // 500은 한도가 아니다
        $this->assertStringContainsString('개수', $fail()->getMessage());    // 요청과 응답의 개수가 다르다
    }

    public function test_openai_compatible_embedder_sorts_by_index_and_sends_no_key_when_local(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['index' => 1, 'embedding' => array_merge([2.0], array_fill(0, 767, 0.0))],
            ['index' => 0, 'embedding' => array_merge([1.0], array_fill(0, 767, 0.0))],
        ]])]);

        $vectors = (new OpenAiCompatibleEmbedder('http://ollama:11434/v1/', null, 'nomic-embed-text', 768))->embed(['첫째', '둘째'], Embedder::QUERY);

        $this->assertSame([1.0, 2.0], array_column($vectors, 0));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://ollama:11434/v1/embeddings' && $r['model'] === 'nomic-embed-text' && ! $r->hasHeader('Authorization'));
    }

    public function test_embedder_binding_follows_configuration(): void
    {
        // setUp에서 넣어 둔 가짜를 치우면 서비스 프로바이더의 실제 바인딩이 설정을 읽어 객체를 만든다.
        $this->app->forgetInstance(Embedder::class);

        config(['jarvis.embedding.provider' => 'gemini', 'jarvis.providers.gemini.api_key' => 'k', 'jarvis.embedding.model' => 'gemini-embedding-2']);
        $this->assertInstanceOf(GeminiEmbedder::class, $this->app->make(Embedder::class));

        config(['jarvis.embedding.provider' => 'ollama', 'jarvis.embedding.model' => 'nomic-embed-text']);
        $this->assertInstanceOf(OpenAiCompatibleEmbedder::class, $this->app->make(Embedder::class));
        $this->assertSame('nomic-embed-text', $this->app->make(Embedder::class)->model());

        // 키가 없으면 "쓸 수 없음"으로 응답해서 키워드 검색으로 대체된다
        config(['jarvis.embedding.provider' => 'gemini', 'jarvis.providers.gemini.api_key' => null]);
        $unconfigured = $this->app->make(Embedder::class);
        $this->assertInstanceOf(UnconfiguredEmbedder::class, $unconfigured);
        $this->expectException(LlmUnavailable::class);
        $unconfigured->embed(['x'], Embedder::QUERY);
    }

    // ───────────────────────── 색인 ─────────────────────────

    public function test_indexing_embeds_titled_chunks_as_documents_but_stores_the_plain_text(): void
    {
        $this->enable();
        $memo = $this->memo('nginx 502 에러', '배포 메모');

        $this->assertSame(1, $this->index($memo));

        $this->assertSame(Embedder::DOCUMENT, $this->fake->calls[0]['task']);
        $this->assertSame("배포 메모\nnginx 502 에러", $this->fake->calls[0]['texts'][0]);   // 임베딩에는 제목을 붙이고
        $chunk = MemoChunk::sole();
        $this->assertSame('nginx 502 에러', $chunk->content);                                  // 저장하는 근거 문단에는 붙이지 않는다
        $this->assertSame(['fake-embed', $this->user->id], [$chunk->model, $chunk->user_id]);
    }

    public function test_quota_blocks_further_embedding_until_it_clears_and_leaves_memos_unindexed(): void
    {
        $this->enable();
        $memo = $this->memo('nginx');
        $this->fake->fail = new LlmQuotaExceeded('한도', 300);
        $indexer = app(MemoIndexer::class);

        $this->assertSame(0, $indexer->indexPending($this->user));
        $this->assertSame(0, $indexer->indexPending($this->user));
        $this->assertCount(1, $this->fake->calls);                  // 두 번째부터는 호출 자체를 하지 않는다
        $this->assertFalse($indexer->available());
        $this->assertSame(1, $indexer->pendingCount($this->user));  // 색인되지 않은 채로 남는다(키워드 검색은 된다)

        $this->fake->fail = null;
        $this->travel(301)->seconds();
        $this->assertSame(1, $indexer->indexPending($this->user));  // 한도가 풀리면 이어서 한다
        $this->assertSame(0, $indexer->pendingCount($this->user));
    }

    public function test_a_generic_failure_does_not_block_and_is_retried_next_time(): void
    {
        $this->enable();
        $this->memo('nginx');
        $this->fake->fail = new LlmUnavailable('연결 실패');
        $indexer = app(MemoIndexer::class);

        $this->assertSame(0, $indexer->indexPending($this->user));
        $this->assertTrue($indexer->available());                    // 한도가 아니므로 차단하지 않는다

        $this->fake->fail = null;
        $this->assertSame(1, $indexer->indexPending($this->user));
    }

    public function test_pending_indexing_respects_opt_in_limit_and_existing_index(): void
    {
        $indexer = app(MemoIndexer::class);
        foreach (range(1, 5) as $i) {
            $this->memo("메모 {$i} nginx");
        }

        $this->assertSame(0, $indexer->indexPending($this->user));          // 옵트인 전에는 임베딩을 호출하지 않는다
        $this->assertCount(0, $this->fake->calls);

        $this->enable();
        $this->assertSame(3, $indexer->indexPending($this->user, 3));       // limit
        $this->assertSame(2, $indexer->pendingCount($this->user));
        $this->assertSame(2, $indexer->indexPending($this->user, 10));      // 이미 색인된 것은 건너뛴다
        $this->assertSame(5, MemoChunk::count());
    }

    public function test_changing_the_model_reindexes_and_removes_the_old_vectors(): void
    {
        $this->enable();
        $memo = $this->memo('nginx 서버');
        $this->index($memo);

        $this->app->instance(Embedder::class, $newModel = new FakeEmbedder('fake-embed-v2'));
        $indexer = app(MemoIndexer::class);

        $this->assertSame(1, $indexer->pendingCount($this->user));          // 새 모델 기준으로는 아직 색인되지 않았다
        $this->assertSame(1, $indexer->indexPending($this->user));
        $this->assertSame(['fake-embed-v2'], MemoChunk::pluck('model')->unique()->values()->all()); // 옛 모델의 벡터는 남지 않는다
        $this->assertCount(1, $newModel->calls);
    }

    public function test_indexing_batches_whole_memos_and_keeps_earlier_batches_if_a_later_one_fails(): void
    {
        $this->enable();
        // 조각 24개짜리 메모 둘(메모당 상한 25 이내). 한 배치는 40조각을 넘기지 않으므로 24+24는 메모 단위로 두 번에 나눠 보낸다.
        $long = implode("\n\n", array_fill(0, 24, str_repeat('nginx ', 120)));
        $first = $this->memo($long);
        $second = $this->memo($long);

        // 두 번째 호출에서 실패하게 만든다
        $failing = new class extends FakeEmbedder
        {
            public function embed(array $texts, string $task): array
            {
                if (count($this->calls) === 1) {
                    $this->calls[] = ['task' => $task, 'texts' => $texts];
                    throw new LlmUnavailable('두 번째 배치 실패');
                }

                return parent::embed($texts, $task);
            }
        };
        $this->app->instance(Embedder::class, $failing);

        $this->assertSame(1, app(MemoIndexer::class)->indexMany(collect([$first, $second])));

        $this->assertCount(2, $failing->calls);
        $this->assertSame(24, MemoChunk::where('memo_id', $first->id)->count());   // 첫 메모는 조각이 전부 저장됨
        $this->assertSame(0, MemoChunk::where('memo_id', $second->id)->count());   // 둘째는 일부만 저장되는 일이 없다
    }

    // ───────────────────────── 하이브리드 검색 ─────────────────────────

    /** 배포 주제 메모 둘(키워드+의미, 의미만)과 무관한 메모들. */
    private function seedCorpus(): array
    {
        $this->enable();
        $m = [
            'both' => $this->memo('nginx 502 에러가 나서 php-fpm을 재시작했다', '배포 메모'),
            'semantic' => $this->memo('웹서버가 안 떠서 프로세스를 다시 띄웠다'),
            'keyword' => $this->memo('쿠버네티스 에러 해결 기록'),
            'cook' => $this->memo('우유 계란 두부 장보기'),
        ];
        $this->index(...array_values($m));
        $this->fake->calls = [];

        return $m;
    }

    public function test_hybrid_search_combines_keyword_and_semantic_and_ranks_matches_from_both_first(): void
    {
        $m = $this->seedCorpus();

        $res = $this->search('배포 에러')->assertOk()
            ->assertJsonPath('semantic', ['used' => true, 'reason' => null]);
        $rows = collect($res->json('data'))->keyBy('id');

        // 단어도 뜻도 맞는 메모가 맨 위, 표현이 달라 키워드로는 못 찾는 메모는 의미로 찾는다. 무관한 메모는 안 나온다.
        $this->assertSame([$m['both']->id, $m['semantic']->id], $res->json('data.*.id'));
        $this->assertSame('both', $rows[$m['both']->id]['match']);
        $this->assertSame('semantic', $rows[$m['semantic']->id]['match']);
        $this->assertGreaterThan(0.6, $rows[$m['semantic']->id]['similarity']);
        $this->assertSame('웹서버가 안 떠서 프로세스를 다시 띄웠다', $rows[$m['semantic']->id]['excerpt']);
        $this->assertNotContains($m['cook']->id, $res->json('data.*.id'));

        // 질문 쪽은 query 용도로 임베딩한다
        $this->assertSame(Embedder::QUERY, $this->fake->calls[0]['task']);
    }

    public function test_keyword_only_matches_are_found_even_when_no_vector_is_close(): void
    {
        $m = $this->seedCorpus();

        $res = $this->search('쿠버네티스 에러')->assertOk();

        $this->assertSame([$m['keyword']->id], $res->json('data.*.id'));
        $this->assertSame('keyword', $res->json('data.0.match'));
        $this->assertNull($res->json('data.0.similarity'));
        $this->assertNull($res->json('data.0.excerpt'));
        $res->assertJsonPath('semantic.used', true);   // 의미 검색은 썼지만 가까운 게 없었을 뿐이다
    }

    public function test_similarity_threshold_drops_unrelated_memos(): void
    {
        $m = $this->seedCorpus();

        config(['jarvis.embedding.min_similarity' => 0.0]);   // 임계값이 없으면 무관한 메모까지 "가장 덜 안 비슷한" 순으로 나온다
        $loose = $this->search('배포')->json('data.*.id');
        $this->assertContains($m['cook']->id, $loose);

        config(['jarvis.embedding.min_similarity' => 0.6]);
        $this->assertNotContains($m['cook']->id, $this->search('배포')->json('data.*.id'));
    }

    public function test_search_never_returns_another_users_memos_even_when_they_match_better(): void
    {
        $this->seedCorpus();
        $other = $this->enable(User::factory()->create());
        $theirs = $this->memo('배포 서버 nginx 502 php-fpm 웹서버 전부 다 있는 메모', '배포 서버', $other);
        $this->index($theirs);

        $ids = $this->search('배포 서버 nginx')->json('data.*.id');

        $this->assertNotContains($theirs->id, $ids);
        // 남의 벡터가 DB에 실제로 있는데도(그래서 이 테스트가 의미가 있다) 내 검색에는 쓰이지 않는다.
        $this->assertGreaterThan(0, MemoChunk::where('user_id', $other->id)->count());
    }

    public function test_tag_filter_applies_to_semantic_results_too(): void
    {
        $this->enable();
        $tagged = $this->memo('웹서버 502', null, null, ['ops']);
        $plain = $this->memo('웹서버 재시작');
        $this->index($tagged, $plain);

        $this->assertEqualsCanonicalizing([$tagged->id, $plain->id], $this->search('배포')->json('data.*.id'));
        $this->assertSame([$tagged->id], $this->search('배포', '&tag=ops')->json('data.*.id'));
    }

    public function test_semantic_search_is_skipped_with_a_reason_and_no_embedding_call_when_disabled(): void
    {
        $this->memo('쿠버네티스 에러');

        $res = $this->search('쿠버네티스')->assertOk();

        $res->assertJsonPath('semantic', ['used' => false, 'reason' => 'disabled'])->assertJsonCount(1, 'data');   // 키워드로는 찾는다
        $this->assertCount(0, $this->fake->calls);                                                                  // 옵트인 전에는 질문조차 전송하지 않는다
    }

    public function test_search_explains_why_semantic_is_unavailable_but_still_returns_keyword_results(): void
    {
        $this->enable();
        $this->memo('쿠버네티스 에러');                                                   // 켰지만 아직 색인 전

        $this->search('쿠버네티스')->assertJsonPath('semantic.reason', 'indexing')->assertJsonCount(1, 'data');

        $this->index(Memo::first());
        $this->fake->fail = new LlmUnavailable('연결 실패');
        $this->search('쿠버네티스')->assertJsonPath('semantic.reason', 'unavailable')->assertJsonCount(1, 'data');

        $this->fake->fail = new LlmQuotaExceeded('한도', 120);
        $this->search('쿠버네티스')->assertJsonPath('semantic.reason', 'quota')->assertJsonCount(1, 'data');

        // 한도로 막힌 동안에는 질문도 전송하지 않는다
        $this->fake->calls = [];
        $this->search('쿠버네티스')->assertJsonPath('semantic.reason', 'quota');
        $this->assertCount(0, $this->fake->calls);
    }

    public function test_keyword_search_requires_every_word_in_any_order_and_treats_wildcards_literally(): void
    {
        $both = $this->memo('에러가 났는데 nginx 설정이 문제였다');
        $this->memo('nginx 설정을 바꿨다');                                // 한 단어만
        $tagOnly = $this->memo('설정 메모', null, null, ['nginx']);        // 태그에 있는 단어도 센다
        $this->memo('진행률 100% 달성');
        $this->memo('진행률 없음');

        $this->assertSame([$both->id], $this->search('nginx 에러')->json('data.*.id'));      // 순서와 간격이 달라도 된다
        $this->assertSame([$tagOnly->id], $this->search('설정 nginx', '&tag=nginx')->json('data.*.id'));
        // %가 와일드카드로 해석되지 않는다: "100%"도, "%" 하나만 넣어도 글자 그대로 들어 있는 메모만 찾는다
        $this->assertCount(1, $this->search('100%')->json('data'));
        $this->assertCount(1, $this->search('%')->json('data'));
    }

    public function test_search_validates_input_limits_results_and_truncates_long_bodies(): void
    {
        $this->as($this->user)->getJson('/api/memos/search')->assertUnprocessable();
        $this->as($this->user)->getJson('/api/memos/search?q=')->assertUnprocessable();
        $this->as($this->user)->getJson('/api/memos/search?q='.str_repeat('가', 201))->assertUnprocessable();
        $this->as($this->user)->getJson('/api/memos/search?q=x&limit=11')->assertUnprocessable();

        foreach (range(1, 8) as $i) {
            $this->memo("공통어 {$i}");
        }
        $this->search('공통어')->assertJsonCount(5, 'data');                    // 기본은 5개
        $this->search('공통어', '&limit=8')->assertJsonCount(8, 'data');

        $this->memo('긴글 '.str_repeat('가', 2000));
        $row = $this->search('긴글')->json('data.0');
        $this->assertTrue($row['body_truncated']);
        $this->assertLessThanOrEqual(601, mb_strlen($row['body']));
        $this->assertFalse($this->search('공통어 1')->json('data.0.body_truncated'));
    }

    public function test_search_and_ai_endpoints_require_authentication(): void
    {
        $this->getJson('/api/memos/search?q=x')->assertUnauthorized();
        $this->getJson('/api/memos/ai')->assertUnauthorized();
        $this->putJson('/api/memos/ai', ['enabled' => true])->assertUnauthorized();
    }

    // ───────────────────────── 옵트인과 자동 색인 ─────────────────────────

    public function test_nothing_is_sent_to_the_embedding_provider_before_opt_in(): void
    {
        $id = $this->as($this->user)->postJson('/api/memos', ['title' => '비밀', 'body' => '민감한 내용'])->assertCreated()->json('id');
        $this->as($this->user)->putJson("/api/memos/{$id}", ['body' => '수정된 민감한 내용'])->assertOk();
        $this->search('민감한');

        $this->assertCount(0, $this->fake->calls, '옵트인 전에는 어떤 경로로도 내용이 전송되면 안 된다');
        $this->assertSame(0, MemoChunk::count());
    }

    public function test_enabling_indexes_existing_memos_after_the_response_and_disabling_deletes_every_vector(): void
    {
        foreach (range(1, 3) as $i) {
            $this->memo("메모 {$i} 배포");
        }
        $this->as($this->user)->getJson('/api/memos/ai')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('indexed', 0)->assertJsonPath('total', 3);

        $this->as($this->user)->putJson('/api/memos/ai', ['enabled' => true])->assertOk()->assertJsonPath('enabled', true);

        // 응답을 보낸 뒤(terminating)에 색인이 끝나 있다
        $this->as($this->user)->getJson('/api/memos/ai')->assertJsonPath('indexed', 3)->assertJsonPath('total', 3)->assertJsonPath('model', 'fake-embed')->assertJsonPath('available', true);

        $this->as($this->user)->putJson('/api/memos/ai', ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
        $this->assertSame(0, MemoChunk::where('user_id', $this->user->id)->count());   // 벡터를 모두 지운다

        $calls = count($this->fake->calls);
        $this->as($this->user)->postJson('/api/memos', ['body' => '끈 뒤의 메모'])->assertCreated();
        $this->assertCount($calls, $this->fake->calls);                                 // 끈 뒤에는 다시 전송하지 않는다

        $this->as($this->user)->putJson('/api/memos/ai', [])->assertUnprocessable();
        $this->as($this->user)->putJson('/api/memos/ai', ['enabled' => 'maybe'])->assertUnprocessable();
    }

    public function test_toggling_one_users_setting_does_not_touch_another_users_vectors(): void
    {
        $this->enable();
        $other = $this->enable(User::factory()->create());
        $this->index($this->memo('내 배포'), $this->memo('남의 배포', null, $other));

        $this->as($this->user)->putJson('/api/memos/ai', ['enabled' => false])->assertOk();

        $this->assertSame(0, MemoChunk::where('user_id', $this->user->id)->count());
        $this->assertSame(1, MemoChunk::where('user_id', $other->id)->count());
    }

    public function test_new_and_edited_memos_are_indexed_after_the_response_and_stale_vectors_are_removed_at_once(): void
    {
        $this->enable();

        $id = $this->as($this->user)->postJson('/api/memos', ['title' => '서버', 'body' => 'nginx 502'])->assertCreated()->json('id');
        $this->assertSame(1, MemoChunk::where('memo_id', $id)->count());                    // 응답 뒤에 색인되어 있다

        $calls = count($this->fake->calls);
        $this->as($this->user)->putJson("/api/memos/{$id}", ['body' => 'nginx 502', 'tags' => ['ops']])->assertOk();   // 태그만 바꿈
        $this->assertCount($calls, $this->fake->calls);                                      // 임베딩에 영향이 없으니 다시 보내지 않는다

        $this->as($this->user)->putJson("/api/memos/{$id}", ['body' => '완전히 다른 요리 레시피 두부'])->assertOk();
        $chunk = MemoChunk::where('memo_id', $id)->sole();
        $this->assertSame('완전히 다른 요리 레시피 두부', $chunk->content);                      // 옛 내용의 벡터는 없다
        $this->assertCount($calls + 1, $this->fake->calls);

        // 옛 내용(배포/서버 주제)으로는 더 이상 걸리지 않고, 새 내용(요리 주제)으로는 의미로 찾힌다.
        $this->assertSame([], $this->search('배포')->json('data.*.id'));
        $this->assertSame([$id], $this->search('요리')->json('data.*.id'));
    }

    public function test_editing_while_embedding_is_down_leaves_no_stale_vector_behind(): void
    {
        $this->enable();
        $memo = $this->memo('웹서버 502 nginx', '배포');
        $this->index($memo);
        $this->assertSame([$memo->id], $this->search('배포')->json('data.*.id'));

        // 임베딩 제공자가 죽은 동안 내용을 완전히 바꾼다. 재색인은 실패하지만 옛 내용의 벡터가 검색에 걸리면 안 된다.
        $this->fake->fail = new LlmUnavailable('연결 실패');
        $this->as($this->user)->putJson("/api/memos/{$memo->id}", ['title' => '장보기', 'body' => '우유 계란 두부'])->assertOk();

        $this->assertSame(0, MemoChunk::where('memo_id', $memo->id)->count());
        $this->fake->fail = null;
        $this->assertNotContains($memo->id, $this->search('배포')->json('data.*.id'));   // 옛 내용으로 더 이상 찾히지 않는다
    }

    public function test_searching_lazily_indexes_memos_that_were_missed_and_deleting_cascades(): void
    {
        $this->enable();
        $a = $this->memo('웹서버 a');
        $b = $this->memo('웹서버 b');
        $this->index($a);                                                                    // b는 색인 실패로 빠졌다고 가정

        $this->search('웹서버');                                                              // 검색이 끝난 뒤 빠진 것을 이어서 색인한다
        $this->assertSame(2, app(VectorStore::class)->indexedMemoCount($this->user->id, 'fake-embed'));

        $this->as($this->user)->deleteJson("/api/memos/{$b->id}")->assertNoContent();
        $this->assertSame(0, MemoChunk::where('memo_id', $b->id)->count());
    }

    // ───────────────────────── 자비스 연동 ─────────────────────────

    public function test_jarvis_memo_search_tool_points_at_the_hybrid_endpoint_and_requires_a_query(): void
    {
        $this->seed(SecondBrainToolsSeeder::class);
        $tool = DB::table('service_tools')->where('name', 'memo.search')->first();

        $this->assertSame(['GET', '/api/memos/search', 'read', 0], [$tool->http_method, $tool->path, $tool->access, (int) $tool->requires_confirmation]);
        $this->assertSame(['q'], json_decode($tool->parameters, true)['required']);
        $this->assertStringContainsString('의미', $tool->description);

        // 실측: LLM이 질문을 "서버", "웹서버" 같은 단어로 줄이면 유사도가 0.586~0.598로 임계값(0.6)에 걸려 정답 메모를 놓쳤고,
        // 문장 그대로 넣으면 0.747로 찾았다. 그래서 문장 그대로 넣으라는 지시가 도구 설명과 프롬프트에서 빠지면 안 된다.
        $this->assertStringContainsString('문장 그대로', $tool->description);
        $this->assertStringContainsString('문장 그대로', json_decode($tool->parameters, true)['properties']['q']['description']);
    }

    public function test_the_system_prompt_tells_the_model_not_to_shorten_memo_questions_and_to_cite_memos(): void
    {
        $seen = new \ArrayObject;
        $this->app->instance(LlmClient::class, new class($seen) implements LlmClient
        {
            public function __construct(private \ArrayObject $seen) {}

            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                $this->seen['system'] = $system;

                return new LlmReply('ok');
            }
        });

        $this->withToken($this->user->createToken('t')->plainTextToken)
            ->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => '안녕']]])->assertOk();

        $this->assertStringContainsString('원래 질문 전체를 q로', $seen['system']);   // 결과가 비면 줄이지 말고 원문으로 재검색
        $this->assertStringContainsString('근거가 된 메모의 제목', $seen['system']);   // 출처를 밝힌다
    }
}
