<?php

namespace App\Jarvis;

use App\Models\ServiceTool;
use App\Models\User;
use Carbon\Carbon;

/**
 * 여러 서브 플랫폼의 읽기 도구를 묶어서 보여 주는 "오늘의 브리핑" (자비스 v2 서비스 조합).
 *
 * 서브 플랫폼의 DB를 직접 읽지 않고, 레지스트리에 등록된 도구를 사용자 본인의 토큰으로 호출한다.
 *  - 서비스가 분리되거나 꺼져도 해당 섹션만 빠지고 나머지는 그대로 나온다.
 *  - 새 서비스를 붙이는 방식이 자비스 채팅과 같다(권한, 감사 로그 포함).
 * AI(LLM)를 쓰지 않으므로 무료 호출 한도를 소모하지 않는다.
 */
class Briefing
{
    private const LIST_LIMIT = 10;

    public function __construct(private readonly ToolExecutor $executor) {}

    /** @param  string  $period  today | week */
    public function build(User $user, string $token, string $period): array
    {
        $tz = config('jarvis.timezone');
        $now = now($tz);
        $weekStart = $now->copy()->startOfWeek();
        $horizon = $period === 'week' ? $now->copy()->addDays(7) : $now->copy()->endOfDay();

        $call = fn (string $tool, array $args = []) => $this->call($user, $token, $tool, $args);

        return [
            'period' => $period,
            'date' => $now->toDateString(),
            'todos' => $this->section($call('tasks.list_todos', ['scope' => $period === 'week' ? 'open' : 'today']),
                fn ($todos) => $this->todos($todos, $period === 'week' ? $call('tasks.list_todos', ['scope' => 'done']) : null, $weekStart)),
            'habits' => $this->section($call('tasks.list_habits'), fn ($habits) => $this->habits($habits)),
            'reservations' => $this->section($call('schedule.my_reservations'), fn ($r) => $this->reservations($r, $horizon, $tz)),
            'ledger' => $this->section($call('ledger.summary'), fn ($s) => $this->ledger($s)),
        ];
    }

    /** @return array{ok: bool, data?: mixed, error?: string}|null 도구가 없거나 꺼져 있으면 null */
    private function call(User $user, string $token, string $name, array $args): ?array
    {
        $tool = ServiceTool::query()->with('service')
            ->where('name', $name)->where('is_active', true)
            ->whereHas('service', fn ($q) => $q->where('is_active', true))
            ->first();

        if (! $tool) {
            return null;
        }

        $call = $this->executor->run($this->executor->record($user, $tool, $args, 'running', 'briefing'), $tool, $token);

        return $call->status === 'success'
            ? ['ok' => true, 'data' => $call->result['data'] ?? null]
            : ['ok' => false, 'error' => $call->error ?? '조회하지 못했어요.'];
    }

    /**
     * 도구가 없으면 null(섹션 숨김), 실패하면 오류만 담고, 성공하면 변환한다. 한 섹션이 실패해도 다른 섹션은 나온다.
     * 서비스가 분리되면 버전 차이로 예상과 다른 모양의 응답이 올 수 있으므로, 변환 중 예외도 그 섹션의 오류로 가둔다.
     */
    private function section(?array $result, callable $transform): ?array
    {
        if ($result === null) {
            return null;
        }
        if (! $result['ok']) {
            return ['error' => $result['error']];
        }

        try {
            return $transform($result['data']);
        } catch (\Throwable $e) {
            report($e);

            return ['error' => '응답을 해석하지 못했어요.'];
        }
    }

    private function todos(array $open, ?array $done, Carbon $weekStart): array
    {
        $overdue = array_values(array_filter($open, fn ($t) => $t['overdue']));
        $upcoming = array_values(array_filter($open, fn ($t) => ! $t['overdue']));
        $brief = fn (array $todos) => array_map(
            fn ($t) => ['id' => $t['id'], 'title' => $t['title'], 'due_date' => $t['due_date']],
            array_slice($todos, 0, self::LIST_LIMIT),
        );

        return [
            'overdue_count' => count($overdue),
            'overdue' => $brief($overdue),
            'due_count' => count($upcoming),
            'due' => $brief($upcoming),
            // 이번 주에 끝낸 일. 완료 목록을 못 가져왔으면 null이라 화면에서 숨긴다.
            'completed_this_week' => ($done && $done['ok'])
                ? count(array_filter($done['data'], fn ($t) => $t['completed_at'] && Carbon::parse($t['completed_at'])->gte($weekStart)))
                : null,
        ];
    }

    private function habits(array $habits): array
    {
        $unchecked = array_values(array_filter($habits, fn ($h) => ! $h['checked_today']));
        usort($habits, fn ($a, $b) => $b['streak'] <=> $a['streak']);

        return [
            'total' => count($habits),
            'checked_today' => count($habits) - count($unchecked),
            'unchecked' => array_map(fn ($h) => ['id' => $h['id'], 'name' => $h['name'], 'streak' => $h['streak']], $unchecked),
            'best_streak' => $habits && $habits[0]['streak'] > 0 ? ['name' => $habits[0]['name'], 'streak' => $habits[0]['streak']] : null,
            'week_checks' => array_sum(array_column($habits, 'week_checks')),
            'week_possible' => array_sum(array_map(fn ($h) => $h['week_days'], $habits)),
        ];
    }

    private function reservations(array $all, Carbon $horizon, string $tz): array
    {
        $upcoming = array_values(array_filter($all, fn ($r) => Carbon::parse($r['starts_at'])->setTimezone($tz)->lte($horizon)));

        return [
            'count' => count($upcoming),
            'items' => array_map(fn ($r) => [
                'id' => $r['id'], 'room' => $r['room']['name'], 'title' => $r['title'],
                'starts_at' => $r['starts_at'], 'ends_at' => $r['ends_at'],
            ], array_slice($upcoming, 0, self::LIST_LIMIT)),
        ];
    }

    private function ledger(array $summary): array
    {
        return [
            'month' => $summary['month'],
            'income' => $summary['income'],
            'expense' => $summary['expense'],
            'balance' => $summary['balance'],
            'top_expense' => $summary['expense_by_category'][0] ?? null,
        ];
    }
}
