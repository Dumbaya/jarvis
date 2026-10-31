<?php

namespace Tests\Feature;

use App\Jarvis\LlmClient;
use App\Jarvis\LlmReply;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\LedgerSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-10-31(31일!) 12:00 KST. 다음 달(11월)은 31일이 없어서 월 계산 버그가 가장 잘 드러나는 날짜다.
        $this->travelTo(Carbon::parse('2026-10-31T12:00:00+09:00')->utc());
        $this->user = User::factory()->create();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function entry(string $type, int $amount, string $category, string $date, ?string $memo = null, ?User $owner = null): LedgerEntry
    {
        return ($owner ?? $this->user)->ledgerEntries()->create([
            'type' => $type, 'amount' => $amount, 'category' => $category, 'occurred_on' => $date, 'memo' => $memo,
        ]);
    }

    public function test_requires_authentication(): void
    {
        foreach (['entries', 'summary', 'categories'] as $path) {
            $this->getJson("/api/ledger/{$path}")->assertUnauthorized();
        }
        $this->postJson('/api/ledger/entries', [])->assertUnauthorized();
    }

    public function test_create_entry_defaults_to_today_and_normalizes_text(): void
    {
        $this->as($this->user)->postJson('/api/ledger/entries', [
            'type' => 'expense', 'amount' => 4500, 'category' => '  카페   라떼 ', 'memo' => '  아침  ',
        ])->assertCreated()
            ->assertJsonPath('amount', 4500)
            ->assertJsonPath('category', '카페 라떼')   // 앞뒤와 연속 공백 정리
            ->assertJsonPath('memo', '아침')
            ->assertJsonPath('occurred_on', '2026-10-31');

        $this->as($this->user)->postJson('/api/ledger/entries', ['type' => 'income', 'amount' => 1, 'category' => '용돈', 'memo' => '   '])
            ->assertCreated()->assertJsonPath('memo', null);
    }

    public function test_validation_rules(): void
    {
        $post = fn (array $data) => $this->as($this->user)->postJson('/api/ledger/entries', $data + ['type' => 'expense', 'amount' => 1000, 'category' => '식비']);

        $post(['type' => 'transfer'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $post(['amount' => 0])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $post(['amount' => -500])->assertUnprocessable();
        $post(['amount' => 12.5])->assertUnprocessable();
        $post(['amount' => '천원'])->assertUnprocessable();
        $post(['amount' => 10_000_000_001])->assertUnprocessable();       // 100억 초과
        $post(['amount' => 10_000_000_000])->assertCreated();              // 100억은 허용
        $post(['category' => ''])->assertUnprocessable();
        $post(['category' => '   '])->assertUnprocessable();
        $post(['category' => str_repeat('가', 51)])->assertUnprocessable();
        $post(['occurred_on' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('occurred_on'); // 미래
        $post(['occurred_on' => '어제'])->assertUnprocessable();
        $post(['occurred_on' => '2026-10-30'])->assertCreated();           // 과거는 허용
        $post(['occurred_on' => '2026-10-31'])->assertCreated();           // 오늘은 허용
    }

    public function test_amount_is_stored_as_exact_integer_won(): void
    {
        $this->as($this->user)->postJson('/api/ledger/entries', ['type' => 'expense', 'amount' => 9_999_999_999, 'category' => '집'])->assertCreated();

        $this->assertSame(9_999_999_999, LedgerEntry::first()->amount);
        $this->assertIsInt($this->as($this->user)->getJson('/api/ledger/entries')->json('data.0.amount'));
    }

    public function test_summary_totals_and_category_breakdown(): void
    {
        $this->entry('income', 3_000_000, '월급', '2026-10-25');
        $this->entry('expense', 4_500, '카페', '2026-10-30');
        $this->entry('expense', 5_500, '카페', '2026-10-31');
        $this->entry('expense', 40_000, '식비', '2026-10-15');
        $this->entry('expense', 50_000, '교통', '2026-10-01'); // 월 첫날
        $this->entry('expense', 999, '지난달', '2026-09-30');   // 지난달 → 제외
        $this->entry('expense', 777, '다음달', '2026-11-01');   // 다음 달 → 제외 (과거 데이터 직접 삽입)

        $res = $this->as($this->user)->getJson('/api/ledger/summary')->assertOk();

        $res->assertJsonPath('month', '2026-10')
            ->assertJsonPath('currency', 'KRW')
            ->assertJsonPath('income', 3_000_000)
            ->assertJsonPath('expense', 100_000)
            ->assertJsonPath('balance', 2_900_000)
            ->assertJsonPath('entry_count', 5)
            ->assertJsonCount(3, 'expense_by_category')
            ->assertJsonPath('expense_by_category.0', ['category' => '교통', 'total' => 50_000, 'count' => 1, 'percent' => 50])
            ->assertJsonPath('expense_by_category.1.category', '식비')
            ->assertJsonPath('expense_by_category.2', ['category' => '카페', 'total' => 10_000, 'count' => 2, 'percent' => 10])
            ->assertJsonPath('income_by_category.0.category', '월급');
    }

    public function test_summary_month_parameter_works_on_the_31st_without_overflowing_to_next_month(): void
    {
        // 오늘이 10-31이다. 'Y-m' 형식 파서는 오늘의 "일(31)"을 채워서 11월 31일 → 12월 1일로 넘어가는 고전적인 버그가 있다.
        // 컨트롤러는 미래 날짜를 막지만, 월 경계 계산을 검증하려고 DB에 직접 심는다.
        $this->entry('expense', 1_000, '10월', '2026-10-31');
        $this->entry('expense', 2_000, '11월', '2026-11-30');
        $this->entry('expense', 4_000, '12월', '2026-12-01');

        $this->as($this->user)->getJson('/api/ledger/summary?month=2026-11')
            ->assertOk()->assertJsonPath('month', '2026-11')->assertJsonPath('expense', 2_000);
        $this->as($this->user)->getJson('/api/ledger/summary?month=2026-12')->assertJsonPath('expense', 4_000);
        $this->as($this->user)->getJson('/api/ledger/summary?month=2026-02')->assertJsonPath('expense', 0)->assertJsonPath('balance', 0);
    }

    public function test_summary_validates_month_and_handles_empty_month(): void
    {
        $this->as($this->user)->getJson('/api/ledger/summary?month=지난달')->assertUnprocessable();
        $this->as($this->user)->getJson('/api/ledger/summary?month=2026-13')->assertUnprocessable();

        $this->as($this->user)->getJson('/api/ledger/summary')->assertOk()
            ->assertJsonPath('income', 0)->assertJsonPath('expense', 0)->assertJsonPath('expense_by_category', []);
    }

    public function test_summary_never_contains_individual_entries_or_memos(): void
    {
        $this->entry('expense', 12_000, '병원', '2026-10-30', '비밀스러운 진료 메모');

        // 한글이 \uXXXX로 이스케이프된 원본 본문으로 검사하면 "없다"는 단언이 내용과 상관없이 항상 통과한다. 풀어서 검사한다.
        $body = json_encode($this->as($this->user)->getJson('/api/ledger/summary')->assertOk()->json(), JSON_UNESCAPED_UNICODE);

        // 집계만 나가고 개별 거래의 메모, 날짜, id는 응답에 없다.
        $this->assertStringNotContainsString('비밀스러운', $body);
        $this->assertStringNotContainsString('memo', $body);
        $this->assertStringNotContainsString('occurred_on', $body);
        $this->assertStringContainsString('병원', $body); // 카테고리 합계는 나간다
    }

    public function test_entries_list_is_filtered_by_month_type_and_category_and_newest_first(): void
    {
        $a = $this->entry('expense', 100, '카페', '2026-10-10');
        $b = $this->entry('expense', 200, '카페', '2026-10-20');
        $this->entry('income', 300, '월급', '2026-10-25');
        $this->entry('expense', 400, '카페', '2026-09-10');

        $ids = $this->as($this->user)->getJson('/api/ledger/entries')->assertOk()->json('data.*.id');
        $this->assertCount(3, $ids); // 9월 제외

        $cafe = $this->as($this->user)->getJson('/api/ledger/entries?category=카페&type=expense')->json('data.*.id');
        $this->assertSame([$b->id, $a->id], $cafe); // 날짜 내림차순

        $this->as($this->user)->getJson('/api/ledger/entries?month=2026-09')->assertJsonCount(1, 'data');
        $this->as($this->user)->getJson('/api/ledger/entries?type=bogus')->assertUnprocessable();
    }

    public function test_update_and_delete(): void
    {
        $e = $this->entry('expense', 1_000, '식비', '2026-10-20', '원래');

        $this->as($this->user)->putJson("/api/ledger/entries/{$e->id}", ['amount' => 2_500])
            ->assertOk()->assertJsonPath('amount', 2_500)->assertJsonPath('category', '식비')->assertJsonPath('memo', '원래');

        $this->as($this->user)->putJson("/api/ledger/entries/{$e->id}", ['memo' => null])->assertOk()->assertJsonPath('memo', null);
        $this->as($this->user)->putJson("/api/ledger/entries/{$e->id}", ['occurred_on' => null])->assertOk()
            ->assertJsonPath('occurred_on', '2026-10-20'); // null을 보내도 날짜가 비지 않는다
        $this->as($this->user)->putJson("/api/ledger/entries/{$e->id}", ['amount' => 0])->assertUnprocessable();
        $this->as($this->user)->putJson("/api/ledger/entries/{$e->id}", ['occurred_on' => '2026-12-25'])->assertUnprocessable();

        $this->as($this->user)->deleteJson("/api/ledger/entries/{$e->id}")->assertNoContent();
        $this->assertModelMissing($e);
    }

    public function test_users_cannot_see_or_change_each_others_entries(): void
    {
        $other = User::factory()->create();
        $theirs = $this->entry('expense', 77_000, '비밀', '2026-10-20', '남의 메모', $other);

        $this->as($this->user)->getJson('/api/ledger/entries')->assertJsonCount(0, 'data');
        $this->as($this->user)->getJson('/api/ledger/summary')->assertJsonPath('expense', 0)->assertJsonPath('entry_count', 0);
        $this->as($this->user)->getJson('/api/ledger/categories')->assertJsonCount(0);
        $this->as($this->user)->putJson("/api/ledger/entries/{$theirs->id}", ['amount' => 1])->assertNotFound();
        $this->as($this->user)->deleteJson("/api/ledger/entries/{$theirs->id}")->assertNotFound();

        $this->assertSame(77_000, $theirs->fresh()->amount);
    }

    public function test_categories_are_ordered_by_use_and_scoped_to_user(): void
    {
        $this->entry('expense', 1, '식비', '2026-10-01');
        $this->entry('expense', 1, '카페', '2026-10-02');
        $this->entry('expense', 1, '카페', '2026-10-03');
        $this->entry('expense', 1, '카페', '2026-10-04');
        $this->entry('expense', 1, '교통', '2026-10-05');
        $this->entry('expense', 1, '남의 카테고리', '2026-10-05', null, User::factory()->create());

        $this->assertSame(['카페', '교통', '식비'], $this->as($this->user)->getJson('/api/ledger/categories')->json());
    }

    public function test_database_rejects_invalid_rows_even_if_validation_is_bypassed(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK 제약은 PostgreSQL에서만 검증한다.');
        }

        $insert = fn (array $row) => DB::transaction(fn () => DB::table('ledger_entries')->insert($row + [
            'user_id' => $this->user->id, 'type' => 'expense', 'amount' => 1, 'category' => 'x',
            'occurred_on' => '2026-10-01', 'created_at' => now(), 'updated_at' => now(),
        ]));

        foreach ([['amount' => 0], ['type' => 'refund']] as $bad) {
            try {
                $insert($bad);
                $this->fail('잘못된 행이 저장되었습니다: '.json_encode($bad));
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode()); // check_violation
            }
        }
    }

    // ───────────────────────── 자비스 도구 ─────────────────────────

    public function test_only_aggregate_read_and_confirmed_write_tools_are_exposed_to_jarvis(): void
    {
        $this->seed(LedgerSeeder::class);
        $this->seed(LedgerSeeder::class);
        $token = $this->user->createToken('t')->plainTextToken;

        $tools = collect($this->withToken($token)->getJson('/api/services')->assertOk()->json('0.tools'))->keyBy('name');

        // 개별 거래나 고정 거래 목록을 돌려주는 도구가 없어야 한다. 읽기 도구는 집계 하나뿐이다.
        $this->assertEqualsCanonicalizing(['ledger.summary', 'ledger.add_entry', 'ledger.add_recurring'], $tools->keys()->all());
        $reads = $tools->where('access', 'read');
        $this->assertCount(1, $reads);
        $this->assertSame('ledger.summary', $reads->keys()->first());

        // 데이터를 바꾸는 도구는 모두 사용자 확인을 거친다.
        foreach (['ledger.add_entry', 'ledger.add_recurring'] as $write) {
            $this->assertTrue($tools[$write]['requires_confirmation'], $write);
            $this->assertSame('write', $tools[$write]['access'], $write);
        }
    }

    public function test_jarvis_summary_flow_sends_only_aggregates_to_the_llm(): void
    {
        $this->seed(LedgerSeeder::class);
        $this->entry('expense', 12_000, '병원', '2026-10-30', '비밀스러운 진료 메모');
        $token = $this->user->createToken('t')->plainTextToken;
        $received = new \ArrayObject;

        // 실제 내부 호출(nginx)은 테스트에서 불가능하므로, 방금 만든 컨트롤러 응답을 그대로 가짜 HTTP 응답으로 쓴다.
        $summary = $this->as($this->user)->getJson('/api/ledger/summary')->json();
        Http::fake(['*' => Http::response($summary)]);

        $this->app->instance(LlmClient::class, new class($received) implements LlmClient
        {
            private int $n = 0;

            public function __construct(private \ArrayObject $received) {}

            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                $this->received[] = $conversation;

                return $this->n++ === 0
                    ? new LlmReply(null, [['name' => 'ledger.summary', 'args' => []]])
                    : new LlmReply('이번 달 병원 지출은 12,000원이에요.');
            }
        });

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => '이번 달 병원비 얼마야?']]])
            ->assertOk()->assertJsonPath('tool_calls.0', ['name' => 'ledger.summary', 'status' => 'success']);

        $toLlm = json_encode($received->getArrayCopy(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('병원', $toLlm);          // 카테고리 합계는 전달됨
        $this->assertStringContainsString('12000', $toLlm);
        $this->assertStringNotContainsString('비밀스러운', $toLlm);   // 메모 원문은 LLM에 가지 않는다

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_starts_with($r->url(), 'http://nginx/api/ledger/summary'));
    }

    public function test_jarvis_add_entry_waits_for_confirmation_then_posts_with_users_token(): void
    {
        $this->seed(LedgerSeeder::class);
        Http::fake(['*' => Http::response(['id' => 1, 'amount' => 4500], 201)]);
        $token = $this->user->createToken('t')->plainTextToken;
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                return new LlmReply(null, [['name' => 'ledger.add_entry', 'args' => ['type' => 'expense', 'amount' => 4500, 'category' => '카페', 'secret' => 'x']]]);
            }
        });

        $id = $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => '카페 4500원 썼어']]])
            ->assertOk()->assertJsonPath('pending_action.tool', 'ledger.add_entry')
            ->assertJsonPath('pending_action.arguments.amount', 4500)->json('pending_action.id');

        Http::assertNothingSent();           // 확인 전에는 기록되지 않는다
        $this->assertSame(0, LedgerEntry::count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson("/api/jarvis/actions/{$id}/confirm")->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r->url() === 'http://nginx/api/ledger/entries'
            && $r['amount'] === 4500 && $r['category'] === '카페'
            && ! isset($r['secret'])         // 스키마에 없는 인자는 버린다
            && $r->hasHeader('Authorization', 'Bearer '.$token));
    }
}
