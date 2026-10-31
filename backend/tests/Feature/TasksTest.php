<?php

namespace Tests\Feature;

use App\Models\Habit;
use App\Models\HabitCheck;
use App\Models\Todo;
use App\Models\User;
use App\Tasks\HabitStats;
use Carbon\Carbon;
use Database\Seeders\TasksSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-04(수) 12:00 KST. 같은 주(월 11-02 ~ 일 11-08)와 전날, 다음 날 경계를 검증하기 좋은 날이다.
        $this->travelTo(Carbon::parse('2026-11-04T12:00:00+09:00')->utc());
        $this->user = User::factory()->create();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function todo(array $attrs = [], ?User $owner = null): Todo
    {
        return ($owner ?? $this->user)->todos()->create($attrs + ['title' => '할 일']);
    }

    private function habit(string $name = '운동', ?User $owner = null): Habit
    {
        return ($owner ?? $this->user)->habits()->create(['name' => $name]);
    }

    private function check(Habit $habit, string $date): void
    {
        HabitCheck::create(['habit_id' => $habit->id, 'checked_on' => $date]);
    }

    // ───────────────────────── 습관 통계(순수 로직) ─────────────────────────

    public function test_streak_counts_consecutive_days_ending_today(): void
    {
        $this->assertSame(3, HabitStats::streak(['2026-11-04', '2026-11-03', '2026-11-02'], '2026-11-04'));
    }

    public function test_streak_survives_until_the_day_ends_if_today_is_not_checked_yet(): void
    {
        // 오늘 아직 안 했어도 어제까지 이어졌다면 연속은 살아 있다.
        $this->assertSame(2, HabitStats::streak(['2026-11-03', '2026-11-02'], '2026-11-04'));
    }

    public function test_streak_breaks_after_a_missed_day(): void
    {
        $this->assertSame(0, HabitStats::streak(['2026-11-02'], '2026-11-04'));             // 어제도 안 함 → 끊김
        $this->assertSame(1, HabitStats::streak(['2026-11-04', '2026-11-02'], '2026-11-04')); // 중간에 빈 날
        $this->assertSame(0, HabitStats::streak([], '2026-11-04'));
    }

    public function test_streak_crosses_month_and_year_boundaries(): void
    {
        $this->assertSame(4, HabitStats::streak(['2027-01-02', '2027-01-01', '2026-12-31', '2026-12-30'], '2027-01-02'));
    }

    public function test_streak_ignores_duplicates_and_future_dates(): void
    {
        $this->assertSame(2, HabitStats::streak(['2026-11-04', '2026-11-04', '2026-11-03', '2026-11-09'], '2026-11-04'));
    }

    public function test_week_counts_monday_to_today(): void
    {
        // 수요일: 월(11-02)부터 3일이 지났다. 지난주 일요일(11-01)은 이번 주가 아니다.
        $week = HabitStats::week(['2026-11-01', '2026-11-02', '2026-11-04'], '2026-11-04');
        $this->assertSame(['week_checks' => 2, 'week_days' => 3], $week);

        // 월요일이면 분모는 1, 일요일이면 7
        $this->assertSame(1, HabitStats::week([], '2026-11-02')['week_days']);
        $this->assertSame(7, HabitStats::week([], '2026-11-08')['week_days']);
    }

    // ───────────────────────── 할 일 ─────────────────────────

    public function test_requires_authentication(): void
    {
        foreach (['/api/todos', '/api/habits'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/todos', ['title' => 'x'])->assertUnauthorized();
        $this->postJson('/api/habits/1/check')->assertUnauthorized();
    }

    public function test_create_and_list_todos_with_overdue_and_ordering(): void
    {
        $this->as($this->user)->postJson('/api/todos', ['title' => '  보고서  ', 'due_date' => '2026-11-06'])
            ->assertCreated()->assertJsonPath('title', '보고서')->assertJsonPath('due_date', '2026-11-06')
            ->assertJsonPath('completed', false)->assertJsonPath('overdue', false);

        $this->todo(['title' => '밀린 일', 'due_date' => '2026-11-03']);
        $this->todo(['title' => '마감 없음']);
        $this->todo(['title' => '오늘', 'due_date' => '2026-11-04']);

        $titles = $this->as($this->user)->getJson('/api/todos')->assertOk()->json('*.title');
        // 마감일 빠른 순, 마감 없는 것은 맨 뒤
        $this->assertSame(['밀린 일', '오늘', '보고서', '마감 없음'], $titles);

        $overdue = collect($this->as($this->user)->getJson('/api/todos')->json())->firstWhere('title', '밀린 일');
        $this->assertTrue($overdue['overdue']);
    }

    public function test_today_scope_includes_overdue_but_not_future_or_undated(): void
    {
        $this->todo(['title' => '밀린 일', 'due_date' => '2026-11-03']);
        $this->todo(['title' => '오늘', 'due_date' => '2026-11-04']);
        $this->todo(['title' => '내일', 'due_date' => '2026-11-05']);
        $this->todo(['title' => '마감 없음']);
        $this->todo(['title' => '끝낸 오늘', 'due_date' => '2026-11-04', 'completed_at' => now()]);

        $this->assertEqualsCanonicalizing(
            ['밀린 일', '오늘'],
            $this->as($this->user)->getJson('/api/todos?scope=today')->json('*.title'),
        );
        $this->as($this->user)->getJson('/api/todos?scope=done')->assertJsonCount(1)->assertJsonPath('0.title', '끝낸 오늘');
        $this->as($this->user)->getJson('/api/todos?scope=nope')->assertUnprocessable();
    }

    public function test_complete_is_idempotent_and_reopen_clears_it(): void
    {
        $todo = $this->todo();

        $first = $this->as($this->user)->postJson("/api/todos/{$todo->id}/complete")->assertOk()->assertJsonPath('completed', true);
        $this->travel(1)->hours();
        $second = $this->as($this->user)->postJson("/api/todos/{$todo->id}/complete")->assertOk();
        $this->assertSame($first->json('completed_at'), $second->json('completed_at')); // 두 번 눌러도 완료 시각이 그대로

        $this->as($this->user)->postJson("/api/todos/{$todo->id}/reopen")->assertOk()->assertJsonPath('completed', false);
    }

    public function test_update_and_delete(): void
    {
        $todo = $this->todo(['title' => '원래', 'due_date' => '2026-11-10']);

        $this->as($this->user)->putJson("/api/todos/{$todo->id}", ['title' => '수정'])
            ->assertOk()->assertJsonPath('title', '수정')->assertJsonPath('due_date', '2026-11-10'); // 안 보낸 필드는 유지

        $this->as($this->user)->putJson("/api/todos/{$todo->id}", ['due_date' => null])->assertOk()->assertJsonPath('due_date', null);
        $this->as($this->user)->putJson("/api/todos/{$todo->id}", ['title' => ''])->assertUnprocessable();

        $this->as($this->user)->deleteJson("/api/todos/{$todo->id}")->assertNoContent();
        $this->assertModelMissing($todo);
    }

    public function test_todo_validation_and_open_limit(): void
    {
        $this->as($this->user)->postJson('/api/todos', [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->as($this->user)->postJson('/api/todos', ['title' => 'x', 'due_date' => '내일'])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->as($this->user)->postJson('/api/todos', ['title' => str_repeat('가', 201)])->assertUnprocessable();

        Todo::insert(array_map(fn ($i) => ['user_id' => $this->user->id, 'title' => "t{$i}", 'created_at' => now(), 'updated_at' => now()], range(1, 200)));
        $this->as($this->user)->postJson('/api/todos', ['title' => '201번째'])->assertUnprocessable();

        // 완료한 것은 한도에 포함되지 않는다.
        Todo::where('user_id', $this->user->id)->limit(1)->update(['completed_at' => now()]);
        $this->as($this->user)->postJson('/api/todos', ['title' => '이제 가능'])->assertCreated();
    }

    public function test_users_cannot_touch_each_others_todos(): void
    {
        $theirs = $this->todo(['title' => '남의 일'], User::factory()->create());

        $this->as($this->user)->getJson('/api/todos')->assertJsonCount(0);
        foreach ([['putJson', ''], ['deleteJson', ''], ['postJson', '/complete'], ['postJson', '/reopen']] as [$method, $suffix]) {
            $this->as($this->user)->{$method}("/api/todos/{$theirs->id}{$suffix}", ['title' => '탈취'])->assertNotFound();
        }
        $this->assertSame('남의 일', $theirs->fresh()->title);
        $this->assertNull($theirs->fresh()->completed_at);
    }

    // ───────────────────────── 습관 ─────────────────────────

    public function test_create_habit_rules(): void
    {
        $this->as($this->user)->postJson('/api/habits', ['name' => ' 운동 '])->assertCreated()
            ->assertJsonPath('name', '운동')->assertJsonPath('checked_today', false)->assertJsonPath('streak', 0);

        $this->as($this->user)->postJson('/api/habits', ['name' => '운동'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->as(User::factory()->create())->postJson('/api/habits', ['name' => '운동'])->assertCreated(); // 사용자별로 따로

        Habit::insert(array_map(fn ($i) => ['user_id' => $this->user->id, 'name' => "h{$i}", 'created_at' => now(), 'updated_at' => now()], range(1, 29)));
        $this->as($this->user)->postJson('/api/habits', ['name' => '31번째'])->assertUnprocessable();
    }

    public function test_check_today_updates_streak_and_is_idempotent(): void
    {
        $habit = $this->habit();
        $this->check($habit, '2026-11-03');
        $this->check($habit, '2026-11-02');

        $this->as($this->user)->postJson("/api/habits/{$habit->id}/check")->assertOk()
            ->assertJsonPath('checked_today', true)->assertJsonPath('streak', 3)
            ->assertJsonPath('week_checks', 3)->assertJsonPath('week_days', 3);

        $this->as($this->user)->postJson("/api/habits/{$habit->id}/check")->assertOk()->assertJsonPath('streak', 3);
        $this->assertSame(3, HabitCheck::where('habit_id', $habit->id)->count()); // 두 번 눌러도 한 행
    }

    public function test_uncheck_removes_today_and_backfill_fills_a_gap(): void
    {
        $habit = $this->habit();
        $this->check($habit, '2026-11-04');
        $this->check($habit, '2026-11-02');

        $this->as($this->user)->postJson("/api/habits/{$habit->id}/check", ['date' => '2026-11-03'])->assertOk()->assertJsonPath('streak', 3);
        $this->as($this->user)->postJson("/api/habits/{$habit->id}/uncheck")->assertOk()
            ->assertJsonPath('checked_today', false)->assertJsonPath('streak', 2); // 오늘 해제해도 어제까지는 살아 있다
    }

    public function test_check_date_limits(): void
    {
        $habit = $this->habit();
        $url = "/api/habits/{$habit->id}/check";

        $this->as($this->user)->postJson($url, ['date' => '2026-11-05'])->assertUnprocessable();          // 미래
        // 오늘(11-04) 포함 7일 = 11-04, 03, 02, 01, 10-31, 10-30, 10-29. 10-29가 가장 오래된 허용일이다.
        $this->as($this->user)->postJson($url, ['date' => '2026-10-28'])->assertUnprocessable();          // 허용 범위 밖 (8일 전)
        $this->as($this->user)->postJson($url, ['date' => '2026-10-29'])->assertOk();                      // 허용되는 가장 오래된 날
        $this->as($this->user)->postJson($url, ['date' => '어제'])->assertUnprocessable();
    }

    public function test_today_follows_service_timezone_not_utc(): void
    {
        // 2026-11-04 23:30 KST = 14:30 UTC. UTC로는 아직 11-04지만, 00:30 KST(11-05)면 UTC로도 11-04다.
        $habit = $this->habit();
        $this->travelTo(Carbon::parse('2026-11-05T00:30:00+09:00')->utc()); // UTC로는 11-04 15:30

        $this->as($this->user)->postJson("/api/habits/{$habit->id}/check")->assertOk();

        $this->assertSame('2026-11-05', HabitCheck::first()->checked_on->toDateString());
    }

    public function test_habit_list_reports_each_habit_and_only_mine(): void
    {
        $run = $this->habit('운동');
        $read = $this->habit('독서');
        $this->check($run, '2026-11-04');
        $this->check($run, '2026-11-03');
        $this->check($read, '2026-11-02');
        $this->habit('남의 습관', User::factory()->create());

        $res = $this->as($this->user)->getJson('/api/habits')->assertOk()->assertJsonCount(2);
        $byName = collect($res->json())->keyBy('name');

        $this->assertTrue($byName['운동']['checked_today']);
        $this->assertSame(2, $byName['운동']['streak']);
        $this->assertFalse($byName['독서']['checked_today']);
        $this->assertSame(0, $byName['독서']['streak']); // 어제도 안 했으므로 끊김
        $this->assertSame(1, $byName['독서']['week_checks']);
    }

    public function test_users_cannot_touch_each_others_habits(): void
    {
        $theirs = $this->habit('남의 습관', User::factory()->create());

        foreach ([['postJson', '/check'], ['postJson', '/uncheck'], ['deleteJson', '']] as [$method, $suffix]) {
            $this->as($this->user)->{$method}("/api/habits/{$theirs->id}{$suffix}")->assertNotFound();
        }
        $this->assertSame(0, HabitCheck::count());
        $this->assertModelExists($theirs);
    }

    public function test_deleting_a_habit_removes_its_checks(): void
    {
        $habit = $this->habit();
        $this->check($habit, '2026-11-04');

        $this->as($this->user)->deleteJson("/api/habits/{$habit->id}")->assertNoContent();

        $this->assertSame(0, HabitCheck::count());
    }

    // ───────────────────────── 자비스 도구 ─────────────────────────

    public function test_tools_are_registered_with_correct_access_and_idempotently(): void
    {
        $this->seed(TasksSeeder::class);
        $this->seed(TasksSeeder::class);
        $token = $this->user->createToken('t')->plainTextToken;

        $tools = collect($this->withToken($token)->getJson('/api/services')->assertOk()->json('0.tools'))->keyBy('name');

        $this->assertEqualsCanonicalizing(
            ['tasks.list_todos', 'tasks.add_todo', 'tasks.complete_todo', 'tasks.list_habits', 'tasks.check_habit'],
            $tools->keys()->all(),
        );
        foreach (['tasks.list_todos', 'tasks.list_habits'] as $read) {
            $this->assertSame('read', $tools[$read]['access']);
            $this->assertFalse($tools[$read]['requires_confirmation']);
        }
        foreach (['tasks.add_todo', 'tasks.complete_todo', 'tasks.check_habit'] as $write) {
            $this->assertSame('write', $tools[$write]['access']);
            $this->assertTrue($tools[$write]['requires_confirmation']);
        }
    }
}
