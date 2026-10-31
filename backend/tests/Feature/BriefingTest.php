<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceTool;
use App\Models\ToolCall;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\LedgerSeeder;
use Database\Seeders\ScheduleSeeder;
use Database\Seeders\SecondBrainToolsSeeder;
use Database\Seeders\TasksSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BriefingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-04(수) 12:00 KST. 이번 주는 11-02(월)~11-08(일).
        $this->travelTo(Carbon::parse('2026-11-04T12:00:00+09:00')->utc());
        $this->seed([SecondBrainToolsSeeder::class, ScheduleSeeder::class, TasksSeeder::class, LedgerSeeder::class]);
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('t')->plainTextToken;
    }

    /** 서브 플랫폼의 응답을 URL별로 가짜로 만든다. 이 테스트는 "조합"을 검증하므로 각 서비스는 이미 검증된 응답 형식만 흉내 낸다. */
    private function fakeServices(array $overrides = []): void
    {
        $todo = fn ($id, $title, $due, $overdue, $done = false, $at = null) => [
            'id' => $id, 'title' => $title, 'due_date' => $due, 'completed' => $done, 'completed_at' => $at, 'overdue' => $overdue,
        ];
        $habit = fn ($id, $name, $today, $streak, $wc, $wd) => [
            'id' => $id, 'name' => $name, 'checked_today' => $today, 'streak' => $streak, 'week_checks' => $wc, 'week_days' => $wd,
        ];
        $res = fn ($id, $start, $end) => ['id' => $id, 'room' => ['id' => 1, 'name' => '스터디룸 A'], 'title' => "예약{$id}", 'starts_at' => $start, 'ends_at' => $end, 'status' => 'confirmed'];

        $defaults = [
            '*/api/todos?scope=today*' => [$todo(1, '밀린 일', '2026-11-03', true), $todo(2, '오늘 할 일', '2026-11-04', false)],
            '*/api/todos?scope=open*' => [$todo(1, '밀린 일', '2026-11-03', true), $todo(2, '오늘 할 일', '2026-11-04', false), $todo(3, '금요일 일', '2026-11-06', false)],
            '*/api/todos?scope=done*' => [
                $todo(8, '이번 주 완료', null, false, true, '2026-11-03T10:00:00+09:00'),
                $todo(9, '지난주 완료', null, false, true, '2026-10-30T10:00:00+09:00'),
            ],
            '*/api/habits*' => [$habit(1, '운동', true, 5, 3, 3), $habit(2, '독서', false, 0, 1, 3), $habit(3, '명상', false, 2, 2, 3)],
            '*/api/reservations*' => [
                $res(1, '2026-11-04T15:00:00+09:00', '2026-11-04T16:00:00+09:00'),   // 오늘
                $res(2, '2026-11-06T10:00:00+09:00', '2026-11-06T11:00:00+09:00'),   // 이번 주
                $res(3, '2026-11-20T10:00:00+09:00', '2026-11-20T11:00:00+09:00'),   // 한참 뒤
            ],
            '*/api/ledger/summary*' => [
                'month' => '2026-11', 'currency' => 'KRW', 'income' => 3_000_000, 'expense' => 50_000, 'balance' => 2_950_000, 'entry_count' => 3,
                'expense_by_category' => [['category' => '식비', 'total' => 40_000, 'count' => 1, 'percent' => 80], ['category' => '카페', 'total' => 10_000, 'count' => 2, 'percent' => 20]],
                'income_by_category' => [],
            ],
        ];

        // 같은 URL을 Http::fake로 두 번 등록하면 먼저 등록한 것이 우선하므로, 덮어쓸 응답은 여기서 한 번에 합친다.
        // 덮어쓰기 값은 본문(배열)이거나, 상태 코드가 필요하면 Http::response(...) 자체를 넘긴다.
        $respond = fn ($v) => $v instanceof PromiseInterface ? $v : Http::response($v);
        Http::fake(array_map($respond, array_merge($defaults, $overrides)));
    }

    private function briefing(string $period = 'today')
    {
        return $this->withToken($this->token)->getJson("/api/briefing?period={$period}");
    }

    public function test_requires_authentication_and_validates_period(): void
    {
        $this->getJson('/api/briefing')->assertUnauthorized();
        $this->briefing('year')->assertUnprocessable();
    }

    public function test_today_briefing_combines_all_four_services(): void
    {
        $this->fakeServices();

        $res = $this->briefing()->assertOk();

        $res->assertJsonPath('period', 'today')->assertJsonPath('date', '2026-11-04')
            // 할 일: 밀린 것과 오늘 것을 나눈다
            ->assertJsonPath('todos.overdue_count', 1)->assertJsonPath('todos.overdue.0.title', '밀린 일')
            ->assertJsonPath('todos.due_count', 1)->assertJsonPath('todos.due.0.title', '오늘 할 일')
            ->assertJsonPath('todos.completed_this_week', null) // 오늘 브리핑에서는 완료 목록을 조회하지 않는다
            // 습관: 아직 안 한 것과 가장 긴 연속
            ->assertJsonPath('habits.total', 3)->assertJsonPath('habits.checked_today', 1)
            ->assertJsonCount(2, 'habits.unchecked')
            ->assertJsonPath('habits.best_streak', ['name' => '운동', 'streak' => 5])
            // 예약: 오늘 안의 것만
            ->assertJsonPath('reservations.count', 1)->assertJsonPath('reservations.items.0.title', '예약1')
            // 가계부: 합계와 가장 큰 지출 카테고리만
            ->assertJsonPath('ledger.expense', 50_000)->assertJsonPath('ledger.balance', 2_950_000)
            ->assertJsonPath('ledger.top_expense.category', '식비');
    }

    public function test_todo_items_carry_only_id_title_and_due_date(): void
    {
        $this->fakeServices();

        $this->briefing()->assertOk()
            ->assertJsonPath('todos.overdue.0', ['id' => 1, 'title' => '밀린 일', 'due_date' => '2026-11-03']);
    }

    public function test_calls_stuck_in_running_are_marked_interrupted_when_the_log_is_opened(): void
    {
        $tool = ServiceTool::firstWhere('name', 'memo.search');
        $make = fn (string $status, $age) => tap(ToolCall::create([
            'user_id' => $this->user->id, 'service_tool_id' => $tool->id, 'tool_name' => 'memo.search', 'arguments' => [], 'status' => $status,
        ]), fn ($c) => $c->forceFill(['created_at' => $age])->save());

        $stale = $make('running', now()->subMinutes(6));
        $fresh = $make('running', now()->subMinutes(1));      // 아직 진행 중일 수 있으니 건드리지 않는다
        $done = $make('success', now()->subMinutes(30));

        $this->withToken($this->token)->getJson('/api/jarvis/calls')->assertOk();

        $this->assertSame('error', $stale->fresh()->status);
        $this->assertSame('응답을 받지 못하고 중단됐어요.', $stale->fresh()->error);
        $this->assertSame('running', $fresh->fresh()->status);
        $this->assertSame('success', $done->fresh()->status);
    }

    public function test_week_briefing_widens_reservations_and_counts_this_weeks_completions(): void
    {
        $this->fakeServices();

        $res = $this->briefing('week')->assertOk();

        $res->assertJsonPath('reservations.count', 2)                  // 오늘 + 금요일, 11-20은 제외
            ->assertJsonPath('todos.overdue_count', 1)
            ->assertJsonPath('todos.due_count', 2)                      // 이번 주 열려 있는 일 전체
            ->assertJsonPath('todos.completed_this_week', 1)            // 지난주 완료는 세지 않는다
            ->assertJsonPath('habits.week_checks', 6)
            ->assertJsonPath('habits.week_possible', 9);
    }

    public function test_calls_use_the_users_own_token_and_are_audited_as_briefing(): void
    {
        $this->fakeServices();

        $this->briefing()->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer '.$this->token));
        $rows = ToolCall::where('user_id', $this->user->id)->get();
        $this->assertEqualsCanonicalizing(
            ['tasks.list_todos', 'tasks.list_habits', 'schedule.my_reservations', 'ledger.summary'],
            $rows->pluck('tool_name')->all(),
        );
        $this->assertSame(['briefing'], $rows->pluck('source')->unique()->values()->all());
        $this->assertSame(['success'], $rows->pluck('status')->unique()->values()->all());
    }

    public function test_one_failing_service_only_removes_its_own_section(): void
    {
        $this->fakeServices(['*/api/ledger/summary*' => Http::response(['message' => '가계부 서버 오류'], 500)]);

        $res = $this->briefing()->assertOk();

        $res->assertJsonPath('ledger.error', fn ($e) => str_contains($e, '가계부 서버 오류'))
            ->assertJsonPath('todos.overdue_count', 1)      // 나머지는 정상
            ->assertJsonPath('habits.total', 3)
            ->assertJsonPath('reservations.count', 1);
        $this->assertDatabaseHas('tool_calls', ['tool_name' => 'ledger.summary', 'status' => 'error', 'source' => 'briefing']);
    }

    public function test_a_service_returning_an_unexpected_shape_does_not_break_the_whole_briefing(): void
    {
        // 서비스가 분리되어 버전이 어긋난 상황: 200이지만 예상한 필드가 없다.
        $this->fakeServices([
            '*/api/ledger/summary*' => ['unexpected' => true],
            '*/api/habits*' => [['id' => 1]],   // week_days, streak 같은 필드가 없다
        ]);

        $res = $this->briefing()->assertOk();

        $res->assertJsonPath('ledger.error', '응답을 해석하지 못했어요.')
            ->assertJsonPath('habits.error', '응답을 해석하지 못했어요.')
            ->assertJsonPath('todos.overdue_count', 1)       // 나머지는 정상
            ->assertJsonPath('reservations.count', 1);
    }

    public function test_a_disabled_or_missing_service_is_hidden_not_an_error(): void
    {
        $this->fakeServices();
        Service::where('slug', 'ledger')->update(['is_active' => false]);

        $res = $this->briefing()->assertOk();

        $this->assertNull($res->json('ledger'));
        $res->assertJsonPath('todos.overdue_count', 1);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/ledger'));
    }

    public function test_briefing_never_exposes_individual_ledger_entries(): void
    {
        $this->fakeServices();

        $body = json_encode($this->briefing()->json(), JSON_UNESCAPED_UNICODE);

        // 브리핑이 호출하는 가계부 도구는 집계(ledger.summary) 하나뿐이다.
        $this->assertDatabaseMissing('tool_calls', ['tool_name' => 'ledger.add_entry']);
        $this->assertStringNotContainsString('memo', $body);
        $this->assertStringNotContainsString('entries', $body);
    }

    public function test_call_log_hides_briefing_calls_by_default_and_shows_them_on_request(): void
    {
        $this->fakeServices();
        $this->briefing()->assertOk();
        $tool = ServiceTool::firstWhere('name', 'memo.search');
        ToolCall::create(['user_id' => $this->user->id, 'service_tool_id' => $tool->id, 'tool_name' => 'memo.search', 'arguments' => [], 'status' => 'success']);

        $log = fn (string $q = '') => $this->withToken($this->token)->getJson('/api/jarvis/calls'.$q);

        $this->assertSame(['memo.search'], $log()->assertOk()->json('data.*.tool_name'));               // 기본: 자비스 호출만
        $this->assertCount(4, $log('?source=briefing')->json('data'));
        $this->assertCount(5, $log('?source=all')->json('data'));
        $this->assertSame('jarvis', $log()->json('data.0.source'));
        $log('?source=hacked')->assertUnprocessable();
    }
}
