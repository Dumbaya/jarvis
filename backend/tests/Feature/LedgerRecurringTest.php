<?php

namespace Tests\Feature;

use App\Ledger\RecurrenceSchedule;
use App\Ledger\RecurringApplier;
use App\Models\LedgerEntry;
use App\Models\LedgerRule;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\LedgerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerRecurringTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-15(일) 12:00 KST
        $this->travelTo(Carbon::parse('2026-11-15T12:00:00+09:00')->utc());
        $this->user = User::factory()->create();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function rule(array $attrs = [], ?User $owner = null): LedgerRule
    {
        return ($owner ?? $this->user)->ledgerRules()->create($attrs + [
            'type' => 'expense', 'amount' => 500_000, 'category' => '월세', 'frequency' => 'monthly',
            'day_of_month' => 25, 'start_on' => '2026-08-01',
        ]);
    }

    private function apply(): int
    {
        return app(RecurringApplier::class)->apply($this->user);
    }

    private function dates(LedgerRule $rule): array
    {
        return $rule->entries()->orderBy('occurred_on')->pluck('occurred_on')->map->toDateString()->all();
    }

    // ───────────────────────── 날짜 계산(순수 로직) ─────────────────────────

    public function test_monthly_clamps_to_the_last_day_of_short_months(): void
    {
        $rule = ['frequency' => 'monthly', 'day_of_month' => 31, 'start_on' => '2026-01-01'];

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'],
            RecurrenceSchedule::dates($rule, '2026-01-01', '2026-06-30'),
        );
        $this->assertSame(['2028-02-29'], RecurrenceSchedule::dates($rule, '2028-02-01', '2028-02-29')); // 윤년
        $this->assertSame(['2026-04-30'], RecurrenceSchedule::dates(['day_of_month' => 30] + $rule, '2026-04-01', '2026-04-30'));
    }

    public function test_monthly_respects_range_edges_start_and_end(): void
    {
        $rule = ['frequency' => 'monthly', 'day_of_month' => 25, 'start_on' => '2026-03-10', 'end_on' => '2026-06-25'];

        // 시작일 전(3월 25일은 시작 후라 포함, 2월은 제외)과 종료일 당일 포함, 이후 제외
        $this->assertSame(['2026-03-25', '2026-04-25', '2026-05-25', '2026-06-25'], RecurrenceSchedule::dates($rule, '2026-01-01', '2026-12-31'));
        $this->assertSame([], RecurrenceSchedule::dates($rule, '2026-03-26', '2026-04-24'));      // 사이 구간에 해당 날짜 없음
        $this->assertSame(['2026-04-25'], RecurrenceSchedule::dates($rule, '2026-04-25', '2026-04-25')); // 양 끝 포함
        $this->assertSame(['2026-03-25'], RecurrenceSchedule::dates(['start_on' => '2026-03-25'] + $rule, '2026-03-01', '2026-03-31')); // 시작일 당일 포함
        $this->assertSame([], RecurrenceSchedule::dates(['start_on' => '2026-03-26'] + $rule, '2026-03-01', '2026-03-31'));               // 시작일 하루 뒤면 제외
    }

    public function test_weekly_hits_the_right_weekday(): void
    {
        // 2026-11-02는 월요일. 매주 수요일(3) → 11-04, 11-11, 11-18
        $rule = ['frequency' => 'weekly', 'weekday' => 3, 'start_on' => '2026-01-01'];
        $this->assertSame(['2026-11-04', '2026-11-11', '2026-11-18'], RecurrenceSchedule::dates($rule, '2026-11-02', '2026-11-20'));

        // 일요일(7)과 월요일(1) 경계
        $this->assertSame(['2026-11-08', '2026-11-15'], RecurrenceSchedule::dates(['weekday' => 7] + $rule, '2026-11-02', '2026-11-20'));
        $this->assertSame(['2026-11-02', '2026-11-09', '2026-11-16'], RecurrenceSchedule::dates(['weekday' => 1] + $rule, '2026-11-02', '2026-11-20'));
        // 구간이 해당 요일 하루 전에 끝나면 없다
        $this->assertSame([], RecurrenceSchedule::dates($rule, '2026-11-05', '2026-11-10'));
    }

    public function test_yearly_clamps_leap_day_and_crosses_years(): void
    {
        $leap = ['frequency' => 'yearly', 'month_of_year' => 2, 'day_of_month' => 29, 'start_on' => '2024-01-01'];

        $this->assertSame(['2024-02-29', '2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29'], RecurrenceSchedule::dates($leap, '2024-01-01', '2028-12-31'));
        $this->assertSame(['2026-12-31', '2027-12-31'], RecurrenceSchedule::dates(['month_of_year' => 12, 'day_of_month' => 31] + $leap, '2026-06-01', '2027-12-31'));
    }

    public function test_next_finds_the_following_occurrence(): void
    {
        $monthly = ['frequency' => 'monthly', 'day_of_month' => 31, 'start_on' => '2026-01-01'];

        $this->assertSame('2026-11-30', RecurrenceSchedule::next($monthly, '2026-11-15'));
        $this->assertSame('2026-12-31', RecurrenceSchedule::next($monthly, '2026-11-30')); // 당일은 "다음"이 아니다
        $this->assertNull(RecurrenceSchedule::next($monthly + ['end_on' => '2026-11-30'], '2026-11-30'));
        $this->assertSame('2027-03-15', RecurrenceSchedule::next(['frequency' => 'yearly', 'month_of_year' => 3, 'day_of_month' => 15, 'start_on' => '2020-01-01'], '2026-11-15'));
    }

    // ───────────────────────── 적용(거래 생성) ─────────────────────────

    public function test_catches_up_every_missed_occurrence_when_opened_after_a_long_gap(): void
    {
        $rule = $this->rule(['start_on' => '2026-08-01']);

        $created = $this->apply();

        // 8/25, 9/25, 10/25 (11/25는 아직 안 됐다)
        $this->assertSame(3, $created);
        $this->assertSame(['2026-08-25', '2026-09-25', '2026-10-25'], $this->dates($rule));
        $this->assertSame('2026-11-15', $rule->fresh()->last_applied_on->toDateString());

        $entry = $rule->entries()->first();
        $this->assertSame([500_000, '월세', 'expense', $this->user->id], [$entry->amount, $entry->category, $entry->type, $entry->user_id]);
    }

    public function test_applying_twice_never_creates_duplicates(): void
    {
        $rule = $this->rule();

        $this->assertSame(3, $this->apply());
        $this->assertSame(0, $this->apply());
        $this->assertSame(0, $this->apply());
        $this->assertSame(3, LedgerEntry::count());

        // 포인터를 일부러 되감아 같은 날짜를 다시 계산하게 해도 DB 유니크가 막는다(동시 요청 상황).
        $rule->forceFill(['last_applied_on' => null])->save();
        $this->assertSame(0, $this->apply());
        $this->assertSame(3, LedgerEntry::count());
    }

    public function test_an_occurrence_is_applied_the_day_it_arrives_and_not_before(): void
    {
        $rule = $this->rule(['day_of_month' => 16, 'start_on' => '2026-11-01']);

        $this->assertSame(0, $this->apply());            // 11-15: 아직
        $this->travelTo(Carbon::parse('2026-11-16T00:00:30+09:00')->utc());
        $this->assertSame(1, $this->apply());            // 11-16 00:00(KST): 도래한 날 바로
        $this->assertSame(['2026-11-16'], $this->dates($rule));
    }

    public function test_deleting_a_generated_entry_does_not_bring_it_back(): void
    {
        $rule = $this->rule();
        $this->apply();
        $rule->entries()->where('occurred_on', '2026-09-25')->first()->delete();

        $this->assertSame(0, $this->apply());

        $this->assertSame(['2026-08-25', '2026-10-25'], $this->dates($rule));
    }

    public function test_future_start_and_end_date_are_respected(): void
    {
        $future = $this->rule(['start_on' => '2026-12-01', 'day_of_month' => 1, 'category' => '미래']);
        $ended = $this->rule(['start_on' => '2026-01-01', 'end_on' => '2026-02-28', 'day_of_month' => 10, 'category' => '끝난 구독']);

        $this->apply();

        $this->assertSame([], $this->dates($future));                       // 아직 시작 전
        $this->assertSame(['2026-01-10', '2026-02-10'], $this->dates($ended)); // 종료일 이후는 안 만든다
        $this->assertSame(0, $this->apply());
    }

    public function test_paused_rules_generate_nothing_and_resuming_does_not_backfill_the_pause(): void
    {
        $rule = $this->rule(['start_on' => '2026-09-01']);
        $this->apply();                                  // 9/25, 10/25
        $this->as($this->user)->putJson("/api/ledger/rules/{$rule->id}", ['active' => false])->assertOk();

        $this->travelTo(Carbon::parse('2027-02-10T12:00:00+09:00')->utc());   // 석 달 넘게 일시정지
        $this->assertSame(0, $this->apply());
        $this->assertCount(2, $this->dates($rule));

        $this->as($this->user)->putJson("/api/ledger/rules/{$rule->id}", ['active' => true])->assertOk();

        // 정지 기간(11/25, 12/25, 1/25)은 소급하지 않는다. 다음은 2/25다.
        $this->assertCount(2, $this->dates($rule));
        $this->travelTo(Carbon::parse('2027-02-25T09:00:00+09:00')->utc());
        $this->assertSame(1, $this->apply());
        $this->assertSame('2027-02-25', last($this->dates($rule)));
    }

    public function test_editing_applies_due_dates_with_old_values_and_only_changes_the_future(): void
    {
        $rule = $this->rule(['amount' => 500_000]);      // 아직 한 번도 안 열었으니 8/25~10/25가 밀려 있다

        $this->as($this->user)->putJson("/api/ledger/rules/{$rule->id}", ['amount' => 600_000])->assertOk()->assertJsonPath('amount', 600_000);

        // 이미 도래한 3건은 수정 전 금액으로 만들어졌다.
        $this->assertSame([500_000, 500_000, 500_000], $rule->entries()->pluck('amount')->all());

        // 다음 달부터 새 금액
        $this->travelTo(Carbon::parse('2026-11-25T09:00:00+09:00')->utc());
        $this->apply();
        $this->assertSame(600_000, $rule->entries()->orderByDesc('occurred_on')->first()->amount);
    }

    public function test_changing_the_schedule_does_not_rewrite_the_past(): void
    {
        $rule = $this->rule();
        $this->apply();                                  // 8/25, 9/25, 10/25

        // 매월 25일 → 매월 5일로 변경. 과거의 5일들(9/5, 10/5, 11/5)을 만들어 내면 안 된다.
        $this->as($this->user)->putJson("/api/ledger/rules/{$rule->id}", ['day_of_month' => 5])->assertOk();

        $this->assertSame(['2026-08-25', '2026-09-25', '2026-10-25'], $this->dates($rule));
        $this->travelTo(Carbon::parse('2026-12-05T09:00:00+09:00')->utc());
        $this->apply();
        $this->assertSame('2026-12-05', last($this->dates($rule)));
    }

    public function test_changing_the_schedule_to_today_applies_todays_occurrence_exactly_once(): void
    {
        $rule = $this->rule();
        $this->apply();                                  // 처리 표시가 오늘(11-15)까지 옮겨 간다

        // 오늘(15일)에 반복하도록 바꾸면 "오늘부터" 적용이라 오늘 거래가 바로 생겨야 한다.
        $this->as($this->user)->putJson("/api/ledger/rules/{$rule->id}", ['day_of_month' => 15])->assertOk();
        $this->assertContains('2026-11-15', $this->dates($rule));

        // 같은 수정을 다시 해도 같은 날짜가 두 번 생기지 않는다.
        $this->as($this->user)->putJson("/api/ledger/rules/{$rule->id}", ['day_of_month' => 15, 'amount' => 1])->assertOk();
        $this->assertSame(1, $rule->entries()->where('occurred_on', '2026-11-15')->count());
    }

    public function test_moving_a_generated_entrys_date_detaches_it_from_the_rule(): void
    {
        $rule = $this->rule();
        $this->apply();
        $entry = $rule->entries()->where('occurred_on', '2026-10-25')->first();

        $this->as($this->user)->putJson("/api/ledger/entries/{$entry->id}", ['occurred_on' => '2026-10-27'])
            ->assertOk()->assertJsonPath('recurring', false);

        $this->assertNull($entry->fresh()->rule_id);
        $this->assertSame(0, $this->apply());            // 원래 날짜(10/25)가 다시 만들어지지 않는다
        $this->assertSame(['2026-08-25', '2026-09-25'], $this->dates($rule));

        // 금액만 고치면 규칙에 그대로 묶여 있다.
        $kept = $rule->entries()->first();
        $this->as($this->user)->putJson("/api/ledger/entries/{$kept->id}", ['amount' => 1])->assertOk()->assertJsonPath('recurring', true);
    }

    public function test_deleting_a_rule_keeps_the_entries_it_already_made(): void
    {
        $rule = $this->rule();
        $this->apply();

        $this->as($this->user)->deleteJson("/api/ledger/rules/{$rule->id}")->assertNoContent();

        $this->assertSame(3, LedgerEntry::count());
        $this->assertSame(0, LedgerEntry::whereNotNull('rule_id')->count());
        $this->assertSame(0, $this->apply());
    }

    // ───────────────────────── API ─────────────────────────

    public function test_creating_a_rule_applies_it_immediately(): void
    {
        // 시작일이 과거이면 바로 거래가 만들어진다.
        $res = $this->as($this->user)->postJson('/api/ledger/rules', [
            'type' => 'expense', 'amount' => 17_000, 'category' => '  넷플릭스 ', 'frequency' => 'monthly', 'day_of_month' => 10, 'start_on' => '2026-09-01',
        ])->assertCreated()
            ->assertJsonPath('category', '넷플릭스')
            ->assertJsonPath('generated', 3)                  // 9/10, 10/10, 11/10
            ->assertJsonPath('next_on', '2026-12-10');

        $this->assertSame(3, LedgerEntry::where('rule_id', $res->json('id'))->count());
    }

    public function test_default_start_is_today_and_applies_today_if_it_matches(): void
    {
        $this->as($this->user)->postJson('/api/ledger/rules', [
            'type' => 'income', 'amount' => 3_000_000, 'category' => '월급', 'frequency' => 'monthly', 'day_of_month' => 15,
        ])->assertCreated()->assertJsonPath('start_on', '2026-11-15')->assertJsonPath('generated', 1)->assertJsonPath('next_on', '2026-12-15');
    }

    public function test_frequency_specific_fields_are_required_and_irrelevant_ones_are_dropped(): void
    {
        $post = fn (array $d) => $this->as($this->user)->postJson('/api/ledger/rules', $d + ['type' => 'expense', 'amount' => 1000, 'category' => 'x']);

        $post(['frequency' => 'monthly'])->assertUnprocessable()->assertJsonValidationErrors('day_of_month');
        $post(['frequency' => 'weekly'])->assertUnprocessable()->assertJsonValidationErrors('weekday');
        $post(['frequency' => 'weekly', 'weekday' => 8])->assertUnprocessable();
        $post(['frequency' => 'yearly', 'day_of_month' => 5])->assertUnprocessable()->assertJsonValidationErrors('month_of_year');
        $post(['frequency' => 'yearly', 'month_of_year' => 2, 'day_of_month' => 30])->assertUnprocessable()->assertJsonValidationErrors('day_of_month'); // 2월 30일은 없다
        $post(['frequency' => 'yearly', 'month_of_year' => 2, 'day_of_month' => 29])->assertCreated();                                                  // 29일은 허용(평년엔 28일)
        $post(['frequency' => 'daily'])->assertUnprocessable();
        $post([])->assertUnprocessable();

        // 주 반복에 day_of_month를 같이 보내도 저장하지 않는다.
        $this->as($this->user)->postJson('/api/ledger/rules', ['type' => 'expense', 'amount' => 1000, 'category' => '헬스', 'frequency' => 'weekly', 'weekday' => 2, 'day_of_month' => 9, 'month_of_year' => 3])
            ->assertCreated()->assertJsonPath('weekday', 2)->assertJsonPath('day_of_month', null)->assertJsonPath('month_of_year', null);
    }

    public function test_amount_date_and_limit_validation(): void
    {
        $base = ['type' => 'expense', 'category' => 'x', 'frequency' => 'monthly', 'day_of_month' => 1];
        $post = fn (array $d) => $this->as($this->user)->postJson('/api/ledger/rules', $d + $base + ['amount' => 1000]);

        $post(['amount' => 0])->assertUnprocessable();
        $post(['amount' => 10_000_000_001])->assertUnprocessable();
        // 오늘이 2026-11-15이므로 366일 전은 2025-11-14, 366일 뒤는 2027-11-16이다. 그 안쪽은 허용, 바깥쪽은 거부.
        $post(['start_on' => '2025-11-13'])->assertUnprocessable()->assertJsonValidationErrors('start_on');  // 367일 전
        $post(['start_on' => '2027-11-17'])->assertUnprocessable()->assertJsonValidationErrors('start_on');  // 367일 뒤
        $post(['start_on' => '2026-11-20', 'end_on' => '2026-11-19'])->assertUnprocessable()->assertJsonValidationErrors('end_on');
        $post(['start_on' => '2025-11-14'])->assertCreated();                                                // 366일 전은 허용(경계)
        $post(['start_on' => '2027-11-16'])->assertCreated();                                                // 366일 뒤는 허용(경계)

        LedgerRule::query()->where('user_id', $this->user->id)->delete();
        foreach (range(1, 30) as $i) {
            $this->rule(['category' => "r{$i}", 'start_on' => '2026-11-15']);
        }
        $post([])->assertUnprocessable()->assertJsonValidationErrors('category');
    }

    public function test_client_cannot_set_server_managed_fields(): void
    {
        $other = User::factory()->create();
        $id = $this->as($this->user)->postJson('/api/ledger/rules', [
            'type' => 'expense', 'amount' => 1000, 'category' => 'x', 'frequency' => 'monthly', 'day_of_month' => 1, 'start_on' => '2026-11-01',
            'user_id' => $other->id, 'last_applied_on' => '2030-01-01',
        ])->assertCreated()->json('id');

        $rule = LedgerRule::find($id);
        $this->assertSame($this->user->id, $rule->user_id);
        $this->assertNotSame('2030-01-01', $rule->last_applied_on?->toDateString()); // 미래 날짜로 포인터를 밀어 규칙을 무력화할 수 없다
        $this->assertSame(1, $rule->entries()->count());                               // 11/1 한 건
    }

    public function test_users_cannot_see_or_change_each_others_rules(): void
    {
        $other = User::factory()->create();
        $theirs = $this->rule(['category' => '남의 월세'], $other);

        $this->as($this->user)->getJson('/api/ledger/rules')->assertOk()->assertJsonCount(0);
        $this->as($this->user)->putJson("/api/ledger/rules/{$theirs->id}", ['amount' => 1])->assertNotFound();
        $this->as($this->user)->deleteJson("/api/ledger/rules/{$theirs->id}")->assertNotFound();
        $this->assertSame(500_000, $theirs->fresh()->amount);

        // 내가 가계부를 열어도 남의 규칙은 적용되지 않는다.
        $this->as($this->user)->getJson('/api/ledger/entries')->assertJsonCount(0, 'data');
        $this->assertSame(0, LedgerEntry::where('user_id', $other->id)->count());
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/ledger/rules')->assertUnauthorized();
        $this->postJson('/api/ledger/rules', [])->assertUnauthorized();
    }

    // ───────────────────────── 화면에 도래한 거래가 보이는 경로 ─────────────────────────

    public function test_opening_the_list_or_summary_applies_due_rules_without_any_explicit_call(): void
    {
        $this->rule(['start_on' => '2026-10-01']);       // 10/25가 도래했다

        $this->as($this->user)->getJson('/api/ledger/entries?month=2026-10')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.recurring', true)->assertJsonPath('data.0.occurred_on', '2026-10-25');
    }

    public function test_summary_includes_fixed_totals_and_upcoming_amounts(): void
    {
        $this->rule(['start_on' => '2026-10-01', 'amount' => 500_000, 'day_of_month' => 25]);                                     // 월세: 10/25 반영, 11/25 예정
        $this->rule(['start_on' => '2026-10-01', 'amount' => 3_000_000, 'type' => 'income', 'category' => '월급', 'day_of_month' => 20]); // 월급: 11/20 예정
        $this->rule(['start_on' => '2026-11-01', 'amount' => 10_000, 'frequency' => 'weekly', 'weekday' => 3, 'day_of_month' => null, 'category' => '운동']); // 수요일마다
        $this->user->ledgerEntries()->create(['type' => 'expense', 'amount' => 7_000, 'category' => '택시', 'occurred_on' => '2026-11-10']); // 일반 거래

        // 오늘 11-15(일). 11월: 수요일은 11-04, 11-11(반영), 11-18, 11-25(예정)
        $res = $this->as($this->user)->getJson('/api/ledger/summary?month=2026-11')->assertOk();

        $res->assertJsonPath('fixed.expense', 20_000)           // 운동 2회만 반영(월세 11/25은 아직)
            ->assertJsonPath('fixed.upcoming_expense', 520_000) // 월세 11/25 + 운동 2회
            ->assertJsonPath('fixed.income', 0)
            ->assertJsonPath('fixed.upcoming_income', 3_000_000)
            ->assertJsonPath('expense', 27_000);                // 고정 20,000 + 택시 7,000 (예정은 합계에 넣지 않는다)

        // 지난달은 예정이 없고, 다음 달은 그 달 전체가 예정이다.
        $this->as($this->user)->getJson('/api/ledger/summary?month=2026-10')->assertJsonPath('fixed.upcoming_expense', 0)->assertJsonPath('fixed.expense', 500_000);
        // 12월 수요일은 2, 9, 16, 23, 30일로 5번이다(12월 1일이 화요일).
        $this->as($this->user)->getJson('/api/ledger/summary?month=2026-12')->assertJsonPath('fixed.upcoming_expense', 500_000 + 10_000 * 5);
    }

    public function test_summary_exposes_totals_only_never_rule_details(): void
    {
        $this->rule(['category' => '비밀 병원비', 'memo' => '민감한 메모', 'start_on' => '2026-10-01']);

        $body = json_encode($this->as($this->user)->getJson('/api/ledger/summary')->assertOk()->json(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('민감한 메모', $body);
        $this->assertStringNotContainsString('last_applied', $body);
        $this->assertStringNotContainsString('day_of_month', $body);
        $this->assertStringContainsString('"fixed"', $body);
    }

    // ───────────────────────── 자비스 도구 ─────────────────────────

    public function test_jarvis_recurring_tool_is_registered_as_write_with_confirmation(): void
    {
        $this->seed(LedgerSeeder::class);
        $tool = DB::table('service_tools')->where('name', 'ledger.add_recurring')->first();

        $this->assertSame(['write', 1, '/api/ledger/rules'], [$tool->access, (int) $tool->requires_confirmation, $tool->path]);
        $schema = json_decode($tool->parameters, true);
        $this->assertSame(['type', 'amount', 'category', 'frequency'], $schema['required']);
        $this->assertSame(['monthly', 'weekly', 'yearly'], $schema['properties']['frequency']['enum']);
    }
}
