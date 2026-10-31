<?php

namespace App\Http\Controllers;

use App\Ledger\RecurrenceSchedule;
use App\Ledger\RecurringApplier;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LedgerController extends Controller
{
    /** 100억 원. 오타로 0을 잔뜩 붙인 입력을 막는 안전장치다. */
    private const MAX_AMOUNT = 10_000_000_000;

    public function __construct(private readonly RecurringApplier $applier) {}

    private function today(): string
    {
        return now(config('jarvis.timezone'))->toDateString();
    }

    /** 거래 내역(화면용). 자비스 도구로는 일부러 노출하지 않는다. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'type' => ['nullable', 'in:income,expense'],
            'category' => ['nullable', 'string', 'max:50'],
        ]);
        [$from, $to] = $this->monthRange($request->query('month'));

        // 도래한 고정 거래를 먼저 거래로 만든 뒤 보여 준다(스케줄러 없이 "날짜가 되면 바로" 반영하는 방식).
        $this->applier->apply($request->user());

        $entries = $request->user()->ledgerEntries()
            ->whereBetween('occurred_on', [$from, $to])
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('category'), fn ($q, $category) => $q->where('category', $category))
            ->orderByDesc('occurred_on')->orderByDesc('id')
            ->paginate(30)
            ->through(fn (LedgerEntry $e) => $this->present($e));

        return response()->json($entries);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        $entry = $request->user()->ledgerEntries()->create($data);

        return response()->json($this->present($entry), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entry = $request->user()->ledgerEntries()->findOrFail($id);
        $data = $this->validated($request, creating: false);

        $entry->fill($data);
        // 고정 거래가 만든 거래의 날짜를 옮기면 일반 거래가 된다. 같은 규칙의 같은 날짜 거래가 둘이 될 수 없다는
        // 유니크 제약에 걸리지 않게 하고, 규칙 쪽은 last_applied_on이 있어서 원래 날짜가 다시 만들어지지도 않는다.
        if ($entry->rule_id !== null && $entry->isDirty('occurred_on')) {
            $entry->forceFill(['rule_id' => null]);
        }
        $entry->save();

        return response()->json($this->present($entry));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->ledgerEntries()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /**
     * 월별 집계. 합계와 카테고리별 금액만 돌려주고 개별 거래(메모 포함)는 담지 않는다.
     * 자비스가 이 값만 쓰므로 실제 지출 내역 원본이 LLM 제공자에게 나가지 않는다.
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        [$from, $to, $month] = $this->monthRange($request->query('month'));

        // 자비스의 브리핑도 이 집계를 부르므로, 여기서 고정 거래를 반영하면 채팅에서도 고정지출이 합계에 들어간다.
        $this->applier->apply($request->user());

        $rows = $request->user()->ledgerEntries()
            ->whereBetween('occurred_on', [$from, $to])
            ->selectRaw('type, category, sum(amount) as total, count(*) as entries')
            ->groupBy('type', 'category')
            ->get();

        $byType = fn (string $type) => $rows->where('type', $type);
        $income = (int) $byType('income')->sum('total');
        $expense = (int) $byType('expense')->sum('total');

        $breakdown = fn (string $type, int $sum) => $byType($type)
            ->sortByDesc('total')->values()
            ->map(fn ($r) => [
                'category' => $r->category,
                'total' => (int) $r->total,
                'count' => (int) $r->entries,
                'percent' => $sum > 0 ? round($r->total / $sum * 100, 1) : 0,
            ])->all();

        return response()->json([
            'month' => $month,
            'currency' => 'KRW',
            'income' => $income,
            'expense' => $expense,
            'balance' => $income - $expense,
            'entry_count' => (int) $rows->sum('entries'),
            'expense_by_category' => $breakdown('expense', $expense),
            'income_by_category' => $breakdown('income', $income),
            'fixed' => $this->fixedTotals($request->user(), $from, $to),
        ]);
    }

    /**
     * 고정 거래 합계. 이미 거래로 반영된 금액과, 이 달에 아직 도래하지 않은 예정 금액의 합계만 돌려준다(개별 규칙은 담지 않는다).
     * 예정은 오늘 이후 날짜만 센다. 지난 달은 0이고, 이번 달은 남은 날짜, 다음 달부터는 그 달 전체다.
     */
    private function fixedTotals($user, string $from, string $to): array
    {
        $applied = fn (string $type) => (int) $user->ledgerEntries()
            ->whereBetween('occurred_on', [$from, $to])->whereNotNull('rule_id')->where('type', $type)->sum('amount');

        $tomorrow = CarbonImmutable::parse($this->today())->addDay()->toDateString();
        $upcoming = ['income' => 0, 'expense' => 0];
        foreach ($user->ledgerRules()->where('active', true)->get() as $rule) {
            $upcoming[$rule->type] += $rule->amount * count(RecurrenceSchedule::dates($rule->schedule(), max($from, $tomorrow), $to));
        }

        return [
            'expense' => $applied('expense'),
            'income' => $applied('income'),
            'upcoming_expense' => $upcoming['expense'],
            'upcoming_income' => $upcoming['income'],
        ];
    }

    /** 자주 쓴 카테고리 순. 입력창의 자동완성에 쓴다. */
    public function categories(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->ledgerEntries()
                ->selectRaw('category, count(*) as uses')->groupBy('category')
                ->orderByDesc('uses')->orderBy('category')->limit(50)
                ->pluck('category'),
        );
    }

    /**
     * "2026-11" → [첫날, 마지막날, "2026-11"]. 월이 없으면 이번 달(서비스 시간대).
     * createFromFormat('Y-m')은 오늘의 "일"을 채워 넣어서 31일에 11월을 요청하면 12월로 넘어가므로 1일로 직접 만든다.
     */
    private function monthRange(?string $month): array
    {
        $start = CarbonImmutable::parse(($month ?? now(config('jarvis.timezone'))->format('Y-m')).'-01');

        return [$start->toDateString(), $start->endOfMonth()->toDateString(), $start->format('Y-m')];
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'type' => [$required, 'in:income,expense'],
            'amount' => [$required, 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'category' => [$required, 'string', 'max:50'],
            'memo' => ['nullable', 'string', 'max:200'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // 날짜는 비울 수 없는 컬럼이다. 명시적으로 null을 보내도 "안 보냄"으로 취급한다(생성이면 아래에서 오늘로 채운다).
        if (array_key_exists('occurred_on', $data) && $data['occurred_on'] === null) {
            unset($data['occurred_on']);
        }

        if (isset($data['category'])) {
            // 앞뒤 공백과 연속 공백을 정리해서 "카페"와 " 카페 "가 따로 집계되지 않게 한다.
            $data['category'] = trim(preg_replace('/\s+/u', ' ', $data['category']));
            if ($data['category'] === '') {
                throw ValidationException::withMessages(['category' => ['카테고리를 입력해 주세요.']]);
            }
        }
        if (array_key_exists('memo', $data)) {
            $data['memo'] = ($data['memo'] === null || trim($data['memo']) === '') ? null : trim($data['memo']);
        }

        // 날짜를 안 주면 오늘. 미래 날짜는 아직 일어나지 않은 거래이므로 받지 않는다.
        if ($creating) {
            $data['occurred_on'] ??= $this->today();
        }
        if (isset($data['occurred_on']) && $data['occurred_on'] > $this->today()) {
            throw ValidationException::withMessages(['occurred_on' => ['미래 날짜는 기록할 수 없어요.']]);
        }

        return $data;
    }

    private function present(LedgerEntry $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'amount' => $e->amount,
            'category' => $e->category,
            'memo' => $e->memo,
            'occurred_on' => $e->occurred_on->toDateString(),
            // 고정 거래 규칙이 만든 거래인지(화면에서 "고정" 표시에 쓴다). rule_id 자체는 내보내지 않는다.
            'recurring' => $e->rule_id !== null,
        ];
    }
}
