<?php

namespace App\Http\Controllers;

use App\Ledger\RecurrenceSchedule;
use App\Ledger\RecurringApplier;
use App\Models\LedgerRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LedgerRuleController extends Controller
{
    private const MAX_AMOUNT = 10_000_000_000;

    private const MAX_RULES = 30;

    /** 월별 일수 상한. 2월 29일은 허용하고 평년에는 28일로 보정한다. */
    private const DAYS_IN_MONTH = [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];

    public function __construct(private readonly RecurringApplier $applier) {}

    private function today(): string
    {
        return now(config('jarvis.timezone'))->toDateString();
    }

    public function index(Request $request): JsonResponse
    {
        // 목록을 보는 시점에 도래한 날짜를 먼저 반영해서 "다음 날짜"가 정확하게 나오게 한다.
        $this->applier->apply($request->user());

        return response()->json(
            $request->user()->ledgerRules()->orderBy('id')->get()->map(fn (LedgerRule $r) => $this->present($r))->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        if ($request->user()->ledgerRules()->count() >= self::MAX_RULES) {
            throw ValidationException::withMessages(['category' => ['고정 거래는 '.self::MAX_RULES.'개까지 만들 수 있어요.']]);
        }

        $rule = $request->user()->ledgerRules()->create($data);
        // 시작일이 오늘이거나 과거면 바로 거래로 반영한다("날짜가 되면 바로").
        $generated = $this->applier->applyRule($rule);

        return response()->json($this->present($rule->refresh()) + ['generated' => $generated], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = $request->user()->ledgerRules()->findOrFail($id);

        // 수정은 "지금부터" 적용된다. 이미 도래한 날짜는 수정 전 값으로 먼저 거래로 만들어 둔다.
        $this->applier->applyRule($rule);
        $rule->refresh();

        $data = $this->validated($request, creating: false, current: $rule);
        $yesterday = now(config('jarvis.timezone'))->subDay()->toDateString();

        $scheduleChanged = collect(['frequency', 'day_of_month', 'weekday', 'month_of_year', 'start_on'])
            ->contains(fn ($k) => array_key_exists($k, $data) && (string) $data[$k] !== (string) ($k === 'start_on' ? $rule->start_on->toDateString() : $rule->{$k}));
        $resumed = ($data['active'] ?? $rule->active) && ! $rule->active;

        // 일정을 바꾸거나 일시정지를 풀면 과거를 소급하지 않고 오늘부터 적용한다(어제까지 처리한 것으로 표시).
        // 같은 규칙, 같은 날짜는 유니크 제약이 막으므로 오늘 이미 만든 거래와 겹쳐도 중복되지 않는다.
        if ($scheduleChanged || $resumed) {
            $data['last_applied_on'] = $yesterday;
        }

        $rule->update($data);
        $this->applier->applyRule($rule);

        return response()->json($this->present($rule->refresh()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        // 규칙만 지운다. 이미 만들어진 거래는 일반 거래로 남는다(FK nullOnDelete).
        $request->user()->ledgerRules()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $creating, ?LedgerRule $current = null): array
    {
        $r = $creating ? 'required' : 'sometimes';
        $today = $this->today();

        $data = $request->validate([
            'type' => [$r, 'in:income,expense'],
            'amount' => [$r, 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'category' => [$r, 'string', 'max:50'],
            'memo' => ['nullable', 'string', 'max:200'],
            'frequency' => [$r, 'in:monthly,weekly,yearly'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'month_of_year' => ['nullable', 'integer', 'between:1,12'],
            'start_on' => ['nullable', 'date_format:Y-m-d'],
            'end_on' => ['nullable', 'date_format:Y-m-d'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['category'])) {
            $data['category'] = trim(preg_replace('/\s+/u', ' ', $data['category']));
            if ($data['category'] === '') {
                throw ValidationException::withMessages(['category' => ['카테고리를 입력해 주세요.']]);
            }
        }
        if (array_key_exists('memo', $data)) {
            $data['memo'] = ($data['memo'] === null || trim($data['memo']) === '') ? null : trim($data['memo']);
        }

        // 시작일: 안 주면 오늘. 소급은 1년까지, 미리 등록은 1년 앞까지만 받는다.
        if ($creating) {
            $data['start_on'] ??= $today;
        } elseif (array_key_exists('start_on', $data) && $data['start_on'] === null) {
            unset($data['start_on']); // 비울 수 없는 값이다
        }
        if (isset($data['start_on'])) {
            if ($data['start_on'] < now(config('jarvis.timezone'))->subDays(366)->toDateString()) {
                throw ValidationException::withMessages(['start_on' => ['시작일은 1년 안으로만 거슬러 올라갈 수 있어요.']]);
            }
            if ($data['start_on'] > now(config('jarvis.timezone'))->addDays(366)->toDateString()) {
                throw ValidationException::withMessages(['start_on' => ['시작일은 1년 안으로만 미리 등록할 수 있어요.']]);
            }
        }

        // 주기에 필요한 값이 빠지지 않았는지는 "합쳐진 결과"로 검사한다(수정 시 안 보낸 값은 기존 값을 쓴다).
        $merged = $data + ($current ? $current->only(['frequency', 'day_of_month', 'weekday', 'month_of_year']) : []);
        $this->validateFrequency($merged);

        // 주기와 맞지 않는 값은 저장하지 않는다(월 반복인데 요일이 남아 있는 식의 혼란을 막는다).
        if (isset($merged['frequency'])) {
            $keep = ['monthly' => ['day_of_month'], 'weekly' => ['weekday'], 'yearly' => ['month_of_year', 'day_of_month']][$merged['frequency']];
            foreach (['day_of_month', 'weekday', 'month_of_year'] as $field) {
                if (! in_array($field, $keep, true)) {
                    $data[$field] = null;
                } elseif (array_key_exists($field, $merged)) {
                    $data[$field] = $merged[$field];
                }
            }
        }

        $start = $data['start_on'] ?? $current?->start_on->toDateString() ?? $today;
        $end = array_key_exists('end_on', $data) ? $data['end_on'] : $current?->end_on?->toDateString();
        if ($end !== null && $end < $start) {
            throw ValidationException::withMessages(['end_on' => ['종료일은 시작일보다 빠를 수 없어요.']]);
        }

        return $data;
    }

    private function validateFrequency(array $v): void
    {
        $missing = fn (string $field, string $message) => throw ValidationException::withMessages([$field => [$message]]);

        match ($v['frequency'] ?? null) {
            'monthly' => isset($v['day_of_month']) ?: $missing('day_of_month', '매월 반복은 며칠에 반복할지(day_of_month) 알려 주세요.'),
            'weekly' => isset($v['weekday']) ?: $missing('weekday', '매주 반복은 무슨 요일인지(weekday, 1=월요일 ~ 7=일요일) 알려 주세요.'),
            'yearly' => (isset($v['month_of_year']) && isset($v['day_of_month'])) ?: $missing('month_of_year', '매년 반복은 몇 월 며칠인지(month_of_year, day_of_month) 알려 주세요.'),
            default => null,
        };

        if (($v['frequency'] ?? null) === 'yearly' && $v['day_of_month'] > self::DAYS_IN_MONTH[(int) $v['month_of_year']]) {
            throw ValidationException::withMessages(['day_of_month' => ["{$v['month_of_year']}월에는 {$v['day_of_month']}일이 없어요."]]);
        }
    }

    private function present(LedgerRule $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'amount' => $r->amount,
            'category' => $r->category,
            'memo' => $r->memo,
            'frequency' => $r->frequency,
            'day_of_month' => $r->day_of_month,
            'weekday' => $r->weekday,
            'month_of_year' => $r->month_of_year,
            'start_on' => $r->start_on->toDateString(),
            'end_on' => $r->end_on?->toDateString(),
            'active' => $r->active,
            // 일시정지 중이거나 종료된 규칙은 다음 날짜가 없다.
            'next_on' => $r->active ? RecurrenceSchedule::next($r->schedule(), max($this->today(), $r->last_applied_on?->toDateString() ?? $r->start_on->copy()->subDay()->toDateString())) : null,
        ];
    }
}
