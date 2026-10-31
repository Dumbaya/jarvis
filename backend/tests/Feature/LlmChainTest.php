<?php

namespace Tests\Feature;

use App\Jarvis\GeminiClient;
use App\Jarvis\LlmBreaker;
use App\Jarvis\LlmChain;
use App\Jarvis\LlmClient;
use App\Jarvis\LlmQuotaExceeded;
use App\Jarvis\LlmReply;
use App\Jarvis\LlmUnavailable;
use App\Jarvis\OpenAiCompatibleClient;
use App\Jarvis\RuleBasedClient;
use App\Models\LlmUsage;
use App\Models\User;
use Database\Seeders\SecondBrainToolsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 정해 둔 결과(응답 또는 예외)를 차례로 내고 호출 횟수를 세는 가짜 클라이언트 */
class StubClient implements LlmClient
{
    public int $calls = 0;

    /** @param  array<int, LlmReply|\Throwable>  $script  소진되면 마지막 항목을 반복한다 */
    public function __construct(private array $script) {}

    public function generate(string $system, array $conversation, array $tools): LlmReply
    {
        $this->calls++;
        $next = count($this->script) > 1 ? array_shift($this->script) : $this->script[0];

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}

class LlmChainTest extends TestCase
{
    use RefreshDatabase;

    private function chain(array $clients): LlmChain
    {
        $entries = [];
        foreach ($clients as $id => $client) {
            $entries[] = [$id, $client];
        }

        return new LlmChain($entries, new LlmBreaker);
    }

    private function ok(string $text = 'ok'): LlmReply
    {
        return new LlmReply($text);
    }

    private function ask(LlmClient $client): LlmReply
    {
        return $client->generate('sys', [['role' => 'user', 'text' => 'hi']], []);
    }

    // ───────────────────────── 체인과 차단기 ─────────────────────────

    public function test_first_available_model_answers_and_later_ones_are_not_called(): void
    {
        $a = new StubClient([$this->ok('A')]);
        $b = new StubClient([$this->ok('B')]);

        $this->assertSame('A', $this->ask($this->chain(['a' => $a, 'b' => $b]))->text);
        $this->assertSame([1, 0], [$a->calls, $b->calls]);
    }

    public function test_quota_exceeded_moves_to_next_model_and_blocks_the_exhausted_one(): void
    {
        $a = new StubClient([new LlmQuotaExceeded('한도', 600, true)]);
        $b = new StubClient([$this->ok('B')]);
        $chain = $this->chain(['a' => $a, 'b' => $b]);

        $this->assertSame('B', $this->ask($chain)->text);

        // 한도가 풀릴 때까지 A에는 아예 요청을 보내지 않는다.
        $this->ask($chain);
        $this->ask($chain);
        $this->assertSame(1, $a->calls);
        $this->assertSame(3, $b->calls);
        $this->assertTrue((new LlmBreaker)->isBlocked('a'));
    }

    public function test_blocked_model_is_retried_after_the_retry_time(): void
    {
        $a = new StubClient([new LlmQuotaExceeded('한도', 120), $this->ok('A 복구')]);
        $b = new StubClient([$this->ok('B')]);
        $chain = $this->chain(['a' => $a, 'b' => $b]);

        $this->assertSame('B', $this->ask($chain)->text);
        $this->travel(119)->seconds();
        $this->assertSame('B', $this->ask($chain)->text);   // 아직 막혀 있다
        $this->travel(2)->seconds();
        $this->assertSame('A 복구', $this->ask($chain)->text); // 풀렸으니 다시 앞 모델부터
    }

    public function test_transient_failure_falls_through_for_this_request_without_blocking(): void
    {
        $a = new StubClient([new LlmUnavailable('503'), $this->ok('A')]);
        $b = new StubClient([$this->ok('B')]);
        $chain = $this->chain(['a' => $a, 'b' => $b]);

        $this->assertSame('B', $this->ask($chain)->text);   // A가 일시 장애 → B
        $this->assertFalse((new LlmBreaker)->isBlocked('a')); // 한도가 아니므로 차단하지 않는다
        $this->assertSame('A', $this->ask($chain)->text);   // 다음 요청은 다시 A부터
    }

    public function test_all_models_over_quota_throws_quota_exceeded_with_retry_time_and_stops_calling(): void
    {
        $a = new StubClient([new LlmQuotaExceeded('a', 300, true)]);
        $b = new StubClient([new LlmQuotaExceeded('b', 900, true)]);
        $chain = $this->chain(['a' => $a, 'b' => $b]);

        try {
            $this->ask($chain);
            $this->fail('예외가 나야 합니다.');
        } catch (LlmQuotaExceeded $e) {
            $this->assertSame(900, $e->retryAfter); // 마지막으로 시도한 모델의 값
        }

        // 이후에는 HTTP 호출 없이 곧바로 거절하고, 가장 빨리 풀리는 모델의 시간을 알려 준다.
        try {
            $this->ask($chain);
            $this->fail('예외가 나야 합니다.');
        } catch (LlmQuotaExceeded $e) {
            $this->assertSame(300, $e->retryAfter);
        }
        $this->assertSame([1, 1], [$a->calls, $b->calls]);

        $status = $chain->status();
        $this->assertFalse($status['available']);
        $this->assertSame(300, $status['retry_after']);
    }

    public function test_a_real_failure_is_reported_as_failure_not_as_quota(): void
    {
        $chain = $this->chain([
            'a' => new StubClient([new LlmQuotaExceeded('a', 300)]),
            'b' => new StubClient([new LlmUnavailable('연결 실패')]),
        ]);

        $this->expectException(LlmUnavailable::class);
        $this->expectExceptionMessage('연결 실패');
        $this->ask($chain);
    }

    public function test_status_lists_blocked_models(): void
    {
        $chain = $this->chain(['a' => new StubClient([new LlmQuotaExceeded('a', 300)]), 'b' => new StubClient([$this->ok()])]);
        $this->ask($chain);

        $status = $chain->status();
        $this->assertTrue($status['available']);
        $this->assertNull($status['retry_after']);
        $this->assertSame([['id' => 'a', 'blocked_for' => 300], ['id' => 'b', 'blocked_for' => 0]], $status['models']);
    }

    public function test_breaker_clamps_absurd_retry_times(): void
    {
        $breaker = new LlmBreaker;
        $breaker->block('x', 1);
        $this->assertSame(5, $breaker->remaining('x'));     // 최소 5초
        $breaker->block('y', 10_000_000);
        $this->assertSame(86400, $breaker->remaining('y')); // 최대 24시간
        $this->assertSame(0, $breaker->remaining('never'));
    }

    // ───────────────────────── 설정에서 체인 만들기 ─────────────────────────

    public function test_chain_is_built_from_config_and_skips_providers_without_keys(): void
    {
        config([
            'jarvis.provider' => 'gemini',
            'jarvis.chain' => 'gemini/m1, gemini/m2 ,groq/llama,openrouter/x/y,ollama/qwen,unknown/z',
            'jarvis.providers.gemini.api_key' => 'k',
            'jarvis.providers.groq.api_key' => null,        // 키 없음 → 건너뜀
            'jarvis.providers.openrouter.api_key' => null,  // 키 없음 → 건너뜀
        ]);

        $client = LlmChain::fromConfig(new LlmBreaker);

        $this->assertInstanceOf(LlmChain::class, $client);
        // ollama는 키가 필요 없어서 남고, 알 수 없는 제공자는 버린다.
        $this->assertSame(['gemini/m1', 'gemini/m2', 'ollama/qwen'], array_column($client->status()['models'], 'id'));
    }

    public function test_model_names_containing_slashes_are_kept_whole(): void
    {
        config(['jarvis.provider' => 'gemini', 'jarvis.chain' => 'openrouter/meta-llama/some-model:free', 'jarvis.providers.openrouter.api_key' => 'k']);

        $this->assertSame(['openrouter/meta-llama/some-model:free'], array_column(LlmChain::fromConfig(new LlmBreaker)->status()['models'], 'id'));
    }

    public function test_without_chain_the_single_gemini_model_is_used_and_rule_is_the_last_resort(): void
    {
        config(['jarvis.provider' => 'gemini', 'jarvis.chain' => null, 'jarvis.gemini.model' => 'only-model', 'jarvis.providers.gemini.api_key' => 'k']);
        $this->assertSame(['gemini/only-model'], array_column(LlmChain::fromConfig(new LlmBreaker)->status()['models'], 'id'));

        config(['jarvis.providers.gemini.api_key' => null]); // 쓸 수 있는 제공자가 없다
        $this->assertInstanceOf(RuleBasedClient::class, LlmChain::fromConfig(new LlmBreaker));

        config(['jarvis.provider' => 'rule', 'jarvis.providers.gemini.api_key' => 'k']); // 명시적으로 rule
        $this->assertInstanceOf(RuleBasedClient::class, LlmChain::fromConfig(new LlmBreaker));
    }

    // ───────────────────────── Gemini 한도 응답 해석 ─────────────────────────

    public function test_gemini_429_is_parsed_into_retry_time_and_daily_flag(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota', 'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]],
            ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '8693s'],
        ]]], 429)]);

        try {
            $this->ask(new GeminiClient('k', 'm'));
            $this->fail();
        } catch (LlmQuotaExceeded $e) {
            $this->assertSame(8693, $e->retryAfter);
            $this->assertTrue($e->daily);
        }
    }

    public function test_gemini_429_without_details_or_with_fractional_delay(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'x']], 429)
            ->push(['error' => ['details' => [['retryDelay' => '26.4s']]]], 429),
        ]);

        foreach ([[60, false], [27, false]] as [$retry, $daily]) {
            try {
                $this->ask(new GeminiClient('k', 'm'));
                $this->fail();
            } catch (LlmQuotaExceeded $e) {
                $this->assertSame([$retry, $daily], [$e->retryAfter, $e->daily]);
            }
        }
    }

    // ───────────────────────── OpenAI 호환 클라이언트 (Groq, OpenRouter, Ollama) ─────────────────────────

    private function openai(?string $key = 'secret'): OpenAiCompatibleClient
    {
        return new OpenAiCompatibleClient('groq', 'https://api.example.com/openai/v1/', $key, 'model-x');
    }

    private function completion(array $message, array $usage = ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120]): array
    {
        return ['choices' => [['message' => $message]], 'usage' => $usage];
    }

    public function test_openai_request_encodes_tool_names_and_adds_empty_object_schema(): void
    {
        Http::fake(['*' => Http::response($this->completion(['content' => 'hi']))]);

        $this->openai()->generate('시스템', [['role' => 'user', 'text' => '안녕']], [
            ['name' => 'memo.search', 'description' => '검색', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]],
            ['name' => 'schedule.list_rooms', 'description' => '방 목록'], // 인자 없는 도구
        ]);

        Http::assertSent(function (HttpRequest $r) {
            $body = $r->data();
            $tools = $body['tools'];

            return $r->url() === 'https://api.example.com/openai/v1/chat/completions'
                && $r->hasHeader('Authorization', 'Bearer secret')
                && $body['model'] === 'model-x'
                && $body['messages'][0] === ['role' => 'system', 'content' => '시스템']
                && $tools[0]['function']['name'] === 'memo__search'               // 점은 허용되지 않는다
                && $tools[1]['function']['name'] === 'schedule__list_rooms'
                // 빈 properties는 [] 가 아니라 {} 로 나가야 한다
                && str_contains($r->body(), '"parameters":{"type":"object","properties":{}}');
        });
    }

    public function test_openai_response_decodes_tool_names_arguments_and_usage(): void
    {
        Http::fake(['*' => Http::response($this->completion([
            'content' => null,
            'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'schedule__check_availability', 'arguments' => '{"room_id":1,"date":"2026-11-02"}']]],
        ]))]);

        $reply = $this->openai()->generate('s', [['role' => 'user', 'text' => 'x']], []);

        $this->assertNull($reply->text);
        $this->assertSame([['name' => 'schedule.check_availability', 'args' => ['room_id' => 1, 'date' => '2026-11-02']]], $reply->toolCalls);
        $this->assertSame(['input' => 100, 'output' => 20], $reply->usage);
        $this->assertSame(['model-x', 'groq'], [$reply->model, $reply->provider]);
    }

    public function test_openai_tool_results_are_matched_to_call_ids_in_order_and_gemini_raw_is_ignored(): void
    {
        Http::fake(['*' => Http::response($this->completion(['content' => '끝']))]);

        $this->openai()->generate('s', [
            ['role' => 'user', 'text' => '브리핑'],
            // 다른 제공자(Gemini)가 만든 raw가 섞여 있어도 무시하고 tool_calls로 다시 만든다.
            ['role' => 'assistant', 'text' => null, 'raw' => [['functionCall' => ['name' => 'x', 'args' => new \stdClass]]],
                'tool_calls' => [['name' => 'tasks.list_todos', 'args' => []], ['name' => 'ledger.summary', 'args' => ['month' => '2026-10']]]],
            ['role' => 'tool', 'name' => 'tasks.list_todos', 'result' => ['data' => []]],
            ['role' => 'tool', 'name' => 'ledger.summary', 'result' => ['expense' => 5]],
        ], []);

        Http::assertSent(function (HttpRequest $r) {
            $m = $r->data()['messages'];

            return $m[2]['role'] === 'assistant'
                && $m[2]['tool_calls'][0]['id'] === 'call_1' && $m[2]['tool_calls'][0]['function']['name'] === 'tasks__list_todos'
                && $m[2]['tool_calls'][0]['function']['arguments'] === '{}'               // 빈 인자도 JSON 객체
                && $m[2]['tool_calls'][1]['function']['arguments'] === '{"month":"2026-10"}'
                && $m[3] === ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"data":[]}']
                && $m[4]['tool_call_id'] === 'call_2'
                && ! str_contains($r->body(), 'functionCall');
        });
    }

    public function test_openai_local_provider_sends_no_authorization_header(): void
    {
        Http::fake(['*' => Http::response($this->completion(['content' => 'hi']))]);

        $this->openai(null)->generate('s', [['role' => 'user', 'text' => 'x']], []);

        Http::assertSent(fn (HttpRequest $r) => ! $r->hasHeader('Authorization'));
    }

    public function test_openai_error_mapping(): void
    {
        // Http::fake를 여러 번 부르면 먼저 등록한 응답이 계속 우선하므로, 순서대로 나올 응답을 한 번에 등록한다.
        $error = ['error' => ['message' => 'x']];
        Http::fake(['*' => Http::sequence()
            ->push($error, 429, ['Retry-After' => '37'])
            ->push($error, 429)
            ->push($error, 500)->push($error, 503)->push($error, 400)->push($error, 401),
        ]);

        $fail = function () {
            try {
                $this->openai()->generate('s', [['role' => 'user', 'text' => 'x']], []);
                $this->fail('예외가 나야 합니다.');
            } catch (LlmUnavailable $e) {
                return $e;
            }
        };

        $withHeader = $fail();
        $this->assertInstanceOf(LlmQuotaExceeded::class, $withHeader);
        $this->assertSame(37, $withHeader->retryAfter);

        $noHeader = $fail();
        $this->assertInstanceOf(LlmQuotaExceeded::class, $noHeader);
        $this->assertSame(60, $noHeader->retryAfter); // 헤더가 없으면 60초

        foreach ([500, 503, 400, 401] as $status) {
            $e = $fail();
            $this->assertNotInstanceOf(LlmQuotaExceeded::class, $e, "상태 {$status}는 한도가 아니라 장애/거부다");
        }
    }

    public function test_openai_empty_reply_and_malformed_arguments(): void
    {
        Http::fake(['*' => Http::response($this->completion(['content' => '']))]);
        $this->expectException(LlmUnavailable::class);
        $this->openai()->generate('s', [['role' => 'user', 'text' => 'x']], []);
    }

    public function test_malformed_tool_arguments_become_empty_args_instead_of_crashing(): void
    {
        Http::fake(['*' => Http::response($this->completion(['tool_calls' => [['function' => ['name' => 'memo__search', 'arguments' => '{깨진 json']]]]))]);

        $reply = $this->openai()->generate('s', [['role' => 'user', 'text' => 'x']], []);

        $this->assertSame([['name' => 'memo.search', 'args' => []]], $reply->toolCalls);
    }

    // ───────────────────────── 오케스트레이터, 사용량, 화면 정보 ─────────────────────────

    public function test_when_every_model_is_over_quota_chat_falls_back_with_a_clear_message_and_records_quota(): void
    {
        $this->seed(SecondBrainToolsSeeder::class);
        Http::fake(['*' => Http::response(['data' => [['title' => null, 'body' => 'nginx 메모']]])]);
        $stub = new StubClient([new LlmQuotaExceeded('a', 7200, true)]);
        $this->app->instance(LlmClient::class, $this->chain(['gemini/a' => $stub]));
        $token = User::factory()->create()->createToken('t')->plainTextToken;
        $ask = fn () => $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => 'nginx']]]);

        $first = $ask()->assertOk()->assertJsonPath('fallback', true)->assertJsonPath('limits.ai.available', false);
        $this->assertStringContainsString('무료 사용량을 모두 써서', $first->json('reply'));
        $this->assertSame(7200, $first->json('limits.ai.retry_after'));

        $ask()->assertOk()->assertJsonPath('fallback', true);
        $this->assertSame(1, $stub->calls); // 두 번째 채팅부터는 AI 호출 자체를 하지 않는다
        $this->assertSame(['quota', 'quota'], LlmUsage::orderBy('id')->pluck('status')->all());
    }

    public function test_limits_endpoint_reports_ai_availability_without_leaking_model_names(): void
    {
        $this->app->instance(LlmClient::class, $this->chain(['gemini/secret-model-name' => new StubClient([new LlmQuotaExceeded('a', 600)])]));
        $this->app->make(LlmClient::class)->status(); // 상태 조회만으로는 막히지 않는다
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/jarvis/limits')->assertOk();
        $res->assertJsonPath('ai', ['mode' => 'llm', 'available' => true, 'retry_after' => null]);

        // 한도를 소진시킨 뒤에는 사용 불가와 남은 시간만 나가고, 모델 이름은 나가지 않는다.
        $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => 'x']]]);
        $res = $this->withToken($token)->getJson('/api/jarvis/limits')->assertOk();
        $res->assertJsonPath('ai.available', false)->assertJsonPath('ai.retry_after', 600);
        $this->assertStringNotContainsString('secret-model-name', $res->getContent());
    }

    public function test_rule_based_mode_is_reported_as_rule(): void
    {
        $this->app->instance(LlmClient::class, new RuleBasedClient);
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/jarvis/limits')->assertJsonPath('ai', ['mode' => 'rule', 'available' => true, 'retry_after' => null]);
    }

    public function test_admin_sees_chain_status_but_regular_users_do_not(): void
    {
        $this->app->instance(LlmClient::class, $this->chain(['gemini/m1' => new StubClient([$this->ok()]), 'groq/m2' => new StubClient([$this->ok()])]));
        (new LlmBreaker)->block('gemini/m1', 1800);

        $admin = tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
        $regular = User::factory()->create();

        $res = $this->actingAs($admin)->getJson('/api/usage')->assertOk();
        $res->assertJsonPath('admin.llm.available', true)
            ->assertJsonPath('admin.llm.models.0', ['id' => 'gemini/m1', 'blocked_for' => 1800])
            ->assertJsonPath('admin.llm.models.1', ['id' => 'groq/m2', 'blocked_for' => 0]);

        $this->app['auth']->forgetGuards();
        $res = $this->actingAs($regular)->getJson('/api/usage')->assertOk()->assertJsonPath('admin', null);
        $this->assertStringNotContainsString('gemini/m1', $res->getContent());
    }

    public function test_usage_is_attributed_to_the_model_that_used_the_most_tokens(): void
    {
        $this->seed(SecondBrainToolsSeeder::class);
        Http::fake(['*' => Http::response(['data' => []])]);
        // 한 채팅 안에서 모델이 바뀐 경우: 작은 호출은 A, 큰 호출은 B
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            private int $n = 0;

            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                return $this->n++ === 0
                    ? new LlmReply(null, [['name' => 'memo.search', 'args' => ['q' => 'x']]], null, ['input' => 10, 'output' => 5], 'model-a', 'gemini')
                    : new LlmReply('끝', [], null, ['input' => 900, 'output' => 100], 'model-b', 'groq');
            }
        });
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => '찾아줘']]])->assertOk();

        $row = LlmUsage::sole();
        $this->assertSame(['model-b', 'groq', 1015, 2], [$row->model, $row->provider, $row->total_tokens, $row->llm_calls]);
    }
}
