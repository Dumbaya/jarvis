<?php

namespace Tests\Feature;

use App\Jarvis\LlmClient;
use App\Jarvis\LlmReply;
use App\Jarvis\LlmUnavailable;
use App\Models\LlmUsage;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\SecondBrainToolsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-15 12:00 KST. 오늘/이번 달 경계를 시간대 기준으로 검증하려고 시간을 고정한다.
        // DB에는 UTC 시각이 들어가야 하므로 UTC로 변환해서 고정한다. (KST 인스턴스를 그대로 쓰면 벽시계 시각이 UTC인 것처럼 저장된다.)
        $this->travelTo(Carbon::parse('2026-11-15T12:00:00+09:00')->utc());
        config([
            'jarvis.pricing' => ['model-a' => ['input' => 1.0, 'output' => 4.0]], // USD / 100만 토큰
            'jarvis.monthly_budget_usd' => null,
        ]);
    }

    private function usage(?User $user, string $model, int $in, int $out, ?Carbon $at = null, string $status = 'ok'): LlmUsage
    {
        $row = LlmUsage::create([
            'user_id' => $user?->id, 'provider' => 'gemini', 'model' => $model, 'status' => $status,
            'llm_calls' => 1, 'input_tokens' => $in, 'output_tokens' => $out, 'total_tokens' => $in + $out,
        ]);
        if ($at) {
            $at = $at->copy()->utc();
            $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        return $row;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->forceFill(['is_admin' => true])->save());
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/usage')->assertUnauthorized();
        $this->getJson('/api/jarvis/limits')->assertUnauthorized();
    }

    public function test_user_sees_only_own_tokens_today_and_requests_this_month(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->usage($me, 'model-a', 100, 50);                                              // 오늘
        $this->usage($me, 'model-a', 10, 5, now()->subHours(2));                            // 오늘 오전 10시 (KST)
        $this->usage($me, 'model-a', 300, 0, Carbon::parse('2026-11-14T23:59:00+09:00'));   // 어제 → 오늘 토큰 제외, 이번 달 요청 포함
        $this->usage($me, 'model-a', 900, 0, Carbon::parse('2026-10-31T23:59:00+09:00'));   // 지난달 → 둘 다 제외
        $this->usage($other, 'model-a', 5000, 5000);                                        // 남의 것

        $this->as($me)->getJson('/api/usage')->assertOk()
            ->assertJsonPath('me.tokens_today', 165)
            ->assertJsonPath('me.requests_month', 3);
    }

    public function test_day_and_month_boundaries_use_service_timezone_not_utc(): void
    {
        $me = User::factory()->create();
        // 2026-11-15 00:00 KST = 11-14 15:00 UTC. UTC 날짜로는 어제지만 KST로는 오늘이다.
        $this->usage($me, 'model-a', 10, 0, Carbon::parse('2026-11-15T00:00:00+09:00'));
        // 2026-11-01 00:00 KST = 10-31 15:00 UTC. UTC로는 지난달이지만 KST로는 이번 달이다.
        $this->usage($me, 'model-a', 20, 0, Carbon::parse('2026-11-01T00:00:00+09:00'));

        $this->as($me)->getJson('/api/usage')
            ->assertJsonPath('me.tokens_today', 10)
            ->assertJsonPath('me.requests_month', 2);
    }

    public function test_regular_user_response_contains_no_admin_data(): void
    {
        $me = User::factory()->create();
        $this->usage($me, 'model-a', 1000, 1000);
        config(['jarvis.monthly_budget_usd' => 5]);

        $res = $this->as($me)->getJson('/api/usage')->assertOk()->assertJsonPath('admin', null);

        // 비용, 모델명, 예산이 응답 어디에도 섞여 나가지 않는다.
        foreach (['model-a', 'estimated_cost', 'budget', 'by_model', 'unpriced'] as $leak) {
            $this->assertStringNotContainsString($leak, $res->getContent());
        }
    }

    public function test_admin_sees_overall_cost_per_model_usage_and_budget(): void
    {
        $admin = $this->admin();
        $a = User::factory()->create();
        $b = User::factory()->create();
        config([
            'jarvis.pricing' => ['model-a' => ['input' => 1.0, 'output' => 4.0], 'model-b' => ['input' => 10.0, 'output' => 20.0]],
            'jarvis.monthly_budget_usd' => 10,
        ]);

        $this->usage($a, 'model-a', 1_000_000, 500_000);   // 1.0 + 2.0 = $3
        $this->usage($b, 'model-a', 1_000_000, 0);         // $1
        $this->usage($b, 'model-b', 100_000, 50_000);      // 1.0 + 1.0 = $2
        $this->usage($a, 'model-a', 7, 3, Carbon::parse('2026-10-20T10:00:00+09:00')); // 지난달은 집계 제외

        $res = $this->as($admin)->getJson('/api/usage')->assertOk();

        $res->assertJsonPath('admin.requests_month', 3)
            ->assertJsonPath('admin.tokens_today', 2_650_000)
            ->assertJsonPath('admin.estimated_cost_usd', 6)
            ->assertJsonPath('admin.unpriced_models', [])
            ->assertJsonPath('admin.budget.monthly_usd', 10)
            ->assertJsonPath('admin.budget.used_percent', 60)
            ->assertJsonPath('admin.budget.enforced', true)
            ->assertJsonCount(2, 'admin.by_model')
            ->assertJsonPath('admin.by_model.0.model', 'model-a')
            ->assertJsonPath('admin.by_model.0.requests', 2)
            ->assertJsonPath('admin.by_model.0.total_tokens', 2_500_000)
            ->assertJsonPath('admin.by_model.0.estimated_cost_usd', 4)
            ->assertJsonPath('admin.by_model.1.estimated_cost_usd', 2);

        // 관리자도 자기 개인 사용량은 따로 받는다.
        $res->assertJsonPath('me.requests_month', 0);
    }

    public function test_unpriced_model_yields_null_cost_and_disables_budget(): void
    {
        $admin = $this->admin();
        config(['jarvis.pricing' => ['model-a' => ['input' => null, 'output' => null]], 'jarvis.monthly_budget_usd' => 1]);
        $this->usage(User::factory()->create(), 'model-a', 5_000_000, 5_000_000);

        $this->as($admin)->getJson('/api/usage')
            ->assertJsonPath('admin.estimated_cost_usd', null)
            ->assertJsonPath('admin.unpriced_models', ['model-a'])
            ->assertJsonPath('admin.by_model.0.estimated_cost_usd', null)
            ->assertJsonPath('admin.budget.enforced', false)
            ->assertJsonPath('admin.budget.used_percent', null);
    }

    public function test_admin_with_no_usage_sees_zero_cost_not_null(): void
    {
        config(['jarvis.monthly_budget_usd' => 10]);

        $this->as($this->admin())->getJson('/api/usage')
            ->assertJsonPath('admin.estimated_cost_usd', 0)
            ->assertJsonPath('admin.budget.used_percent', 0)
            ->assertJsonPath('admin.by_model', []);
    }

    public function test_every_chat_request_is_recorded_once_with_summed_tokens(): void
    {
        $this->seed(SecondBrainToolsSeeder::class);
        Http::fake(['*' => Http::response(['data' => [], 'total' => 0])]);
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        // 도구 호출 1번 + 최종 답변 1번 = LLM 2번 호출. 토큰은 합산하고 요청은 1건으로 센다.
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            private int $n = 0;

            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                return $this->n++ === 0
                    ? new LlmReply(null, [['name' => 'memo.search', 'args' => ['q' => 'x']]], null, ['input' => 100, 'output' => 20], 'model-a')
                    : new LlmReply('없어요', [], null, ['input' => 150, 'output' => 30], 'model-a');
            }
        });

        $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => '찾아줘']]])->assertOk();

        $this->assertSame(1, LlmUsage::count());
        $row = LlmUsage::first();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('model-a', $row->model);
        $this->assertSame(2, $row->llm_calls);
        $this->assertSame(250, $row->input_tokens);
        $this->assertSame(50, $row->output_tokens);
        $this->assertSame(300, $row->total_tokens);
        $this->assertSame('ok', $row->status);
    }

    public function test_llm_failure_is_still_counted_as_a_request_with_fallback_status(): void
    {
        $this->seed(SecondBrainToolsSeeder::class);
        Http::fake(['*' => Http::response(['data' => []])]);
        $token = ($user = User::factory()->create())->createToken('t')->plainTextToken;
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                throw new LlmUnavailable('429');
            }
        });

        $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => 'x']]])
            ->assertOk()->assertJsonPath('fallback', true)->assertJsonMissingPath('fallback_reason');

        $row = LlmUsage::sole();
        $this->assertSame('fallback', $row->status);
        $this->assertSame(0, $row->total_tokens);
        $this->assertSame(0, $row->llm_calls);
        $this->assertSame(1, $this->as($user)->getJson('/api/usage')->json('me.requests_month'));
    }

    public function test_pending_confirmation_path_also_records_usage(): void
    {
        $this->seed(SecondBrainToolsSeeder::class);
        $token = User::factory()->create()->createToken('t')->plainTextToken;
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                return new LlmReply(null, [['name' => 'memo.create', 'args' => ['body' => 'x']]], null, ['input' => 40, 'output' => 10], 'model-a');
            }
        });

        $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => '저장']]])
            ->assertOk()->assertJsonPath('pending_action.tool', 'memo.create');

        $this->assertSame(50, LlmUsage::sole()->total_tokens);
    }

    public function test_monthly_budget_stops_llm_calls_and_falls_back(): void
    {
        $this->seed(SecondBrainToolsSeeder::class);
        Http::fake(['*' => Http::response(['data' => [['title' => null, 'body' => 'nginx 메모']]])]);
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        config(['jarvis.monthly_budget_usd' => 1]);
        $this->usage(User::factory()->create(), 'model-a', 1_000_000, 0); // 정확히 $1 → 한도 도달

        $llm = new class implements LlmClient
        {
            public int $calls = 0;

            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                $this->calls++;

                return new LlmReply('호출되면 안 됨');
            }
        };
        $this->app->instance(LlmClient::class, $llm);

        $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => 'nginx']]])
            ->assertOk()
            ->assertJsonPath('fallback', true)
            ->assertJsonPath('tool_calls.0.name', 'memo.search');

        $this->assertSame(0, $llm->calls);
        $this->assertStringContainsString('한도', $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => 'nginx']]])->json('reply'));
        $this->assertSame('budget', LlmUsage::where('user_id', $user->id)->first()->status);
    }

    public function test_budget_is_not_enforced_without_prices_or_under_the_limit(): void
    {
        $this->seed(SecondBrainToolsSeeder::class);
        $token = User::factory()->create()->createToken('t')->plainTextToken;
        $llm = new class implements LlmClient
        {
            public int $calls = 0;

            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                $this->calls++;

                return new LlmReply('안녕');
            }
        };
        $this->app->instance(LlmClient::class, $llm);
        $chat = fn () => $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => 'hi']]]);

        // 한도를 넘는 사용량이 있어도 단가가 없으면 비용을 알 수 없어 막지 않는다.
        config(['jarvis.pricing' => [], 'jarvis.monthly_budget_usd' => 0.01]);
        $this->usage(User::factory()->create(), 'model-a', 9_000_000, 9_000_000);
        $chat()->assertOk()->assertJsonMissingPath('fallback');

        // 단가가 있어도 한도 아래면 정상 호출한다.
        config(['jarvis.pricing' => ['model-a' => ['input' => 0.000001, 'output' => 0.000001]], 'jarvis.monthly_budget_usd' => 100]);
        $chat()->assertOk()->assertJsonMissingPath('fallback');

        $this->assertSame(2, $llm->calls);
    }

    public function test_limits_endpoint_reports_remaining_and_chat_consumes_them(): void
    {
        $this->seed(SecondBrainToolsSeeder::class);
        config(['jarvis.rate_limit.per_minute' => 5, 'jarvis.rate_limit.per_day' => 20]);
        $token = User::factory()->create()->createToken('t')->plainTextToken;
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                return new LlmReply('ok');
            }
        });

        $this->withToken($token)->getJson('/api/jarvis/limits')->assertOk()
            ->assertJsonPath('minute', ['limit' => 5, 'remaining' => 5, 'reset_in' => 0])
            ->assertJsonPath('day.remaining', 20);

        $res = $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => 'hi']]])->assertOk();
        $res->assertJsonPath('limits.minute.remaining', 4)->assertJsonPath('limits.day.remaining', 19);
        $this->assertGreaterThan(0, $res->json('limits.minute.reset_in'));

        // 한도 조회, 호출 로그, 사용량 조회는 횟수를 소비하지 않는다.
        $this->withToken($token)->getJson('/api/jarvis/limits');
        $this->withToken($token)->getJson('/api/jarvis/calls');
        $this->withToken($token)->getJson('/api/usage');
        $this->withToken($token)->getJson('/api/jarvis/limits')->assertJsonPath('minute.remaining', 4);
    }

    public function test_exceeding_limit_returns_429_with_wait_time_and_does_not_call_llm_or_record_usage(): void
    {
        config(['jarvis.rate_limit.per_minute' => 1, 'jarvis.rate_limit.per_day' => 50]);
        $token = ($user = User::factory()->create())->createToken('t')->plainTextToken;
        $llm = new class implements LlmClient
        {
            public int $calls = 0;

            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                $this->calls++;

                return new LlmReply('ok');
            }
        };
        $this->app->instance(LlmClient::class, $llm);
        $send = fn () => $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => 'hi']]]);

        $send()->assertOk();
        $res = $send()->assertStatus(429)
            ->assertJsonPath('limits.minute.remaining', 0)
            ->assertJsonPath('limits.day.remaining', 49) // 거절된 요청은 일일 한도도 소비하지 않는다
            ->assertHeader('Retry-After');

        $this->assertStringContainsString('초 뒤에', $res->json('message'));
        $this->assertSame(1, $llm->calls);
        $this->assertSame(1, LlmUsage::count());

        // 시간이 지나 창이 풀리면 다시 쓸 수 있다.
        $this->travel(61)->seconds();
        $send()->assertOk();
    }

    public function test_limits_are_per_user(): void
    {
        config(['jarvis.rate_limit.per_minute' => 1]);
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                return new LlmReply('ok');
            }
        });
        $send = fn (string $t) => $this->withToken($t)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => 'hi']]]);

        $a = User::factory()->create()->createToken('t')->plainTextToken;
        $b = User::factory()->create()->createToken('t')->plainTextToken;

        $send($a)->assertOk();
        $this->app['auth']->forgetGuards();
        $send($a)->assertStatus(429);
        $this->app['auth']->forgetGuards();
        $send($b)->assertOk(); // 다른 사용자는 영향받지 않는다
    }

    public function test_admin_flag_cannot_be_set_through_registration_and_command_toggles_it(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => '해커', 'email' => 'hacker@example.com', 'password' => 'password123',
            'password_confirmation' => 'password123', 'is_admin' => true,
        ])->assertCreated()->assertJsonPath('user.is_admin', false);
        $this->assertFalse(User::firstWhere('email', 'hacker@example.com')->is_admin);

        $this->artisan('user:admin', ['email' => 'hacker@example.com'])->assertSuccessful();
        $this->assertTrue(User::firstWhere('email', 'hacker@example.com')->is_admin);

        $this->artisan('user:admin', ['email' => 'hacker@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse(User::firstWhere('email', 'hacker@example.com')->is_admin);

        $this->artisan('user:admin', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_me_endpoint_exposes_is_admin_flag(): void
    {
        $this->as($this->admin())->getJson('/api/auth/me')->assertJsonPath('is_admin', true);
        $this->as(User::factory()->create())->getJson('/api/auth/me')->assertJsonPath('is_admin', false);
    }
}
