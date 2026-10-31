<?php

namespace App\Http\Controllers;

use App\Models\Habit;
use App\Models\HabitCheck;
use App\Tasks\HabitStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HabitController extends Controller
{
    private const MAX_HABITS = 30;

    /** 오늘 포함 최근 이 일수 안에서만 지난 날짜를 체크하거나 해제할 수 있다. */
    private const BACKFILL_DAYS = 7;

    private function today(): string
    {
        return now(config('jarvis.timezone'))->toDateString();
    }

    public function index(Request $request): JsonResponse
    {
        $habits = $request->user()->habits()->orderBy('id')->get();
        $today = $this->today();

        // 습관마다 쿼리하지 않고 한 번에 가져와서 묶는다.
        $dates = HabitCheck::query()
            ->whereIn('habit_id', $habits->pluck('id'))
            ->where('checked_on', '>=', now(config('jarvis.timezone'))->subDays(HabitStats::LOOKBACK_DAYS)->toDateString())
            ->get(['habit_id', 'checked_on'])
            ->groupBy('habit_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => $r->checked_on->toDateString())->all());

        return response()->json(
            $habits->map(fn (Habit $h) => HabitStats::present($h->id, $h->name, $dates->get($h->id, []), $today))->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $name = trim($data['name']);

        if ($request->user()->habits()->count() >= self::MAX_HABITS) {
            throw ValidationException::withMessages(['name' => ['습관은 '.self::MAX_HABITS.'개까지 만들 수 있어요.']]);
        }
        if ($request->user()->habits()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => ['이미 같은 이름의 습관이 있어요.']]);
        }

        $habit = $request->user()->habits()->create(['name' => $name]);

        return response()->json($this->show($habit), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->habits()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** 오늘(또는 date) 체크한다. 이미 체크했으면 그대로 두는 멱등 동작이다. */
    public function check(Request $request, int $id): JsonResponse
    {
        $habit = $request->user()->habits()->findOrFail($id);
        $date = $this->date($request);

        HabitCheck::firstOrCreate(['habit_id' => $habit->id, 'checked_on' => $date]);

        return response()->json($this->show($habit));
    }

    public function uncheck(Request $request, int $id): JsonResponse
    {
        $habit = $request->user()->habits()->findOrFail($id);

        HabitCheck::where('habit_id', $habit->id)->where('checked_on', $this->date($request))->delete();

        return response()->json($this->show($habit));
    }

    private function show(Habit $habit): array
    {
        $dates = $habit->checks()
            ->where('checked_on', '>=', now(config('jarvis.timezone'))->subDays(HabitStats::LOOKBACK_DAYS)->toDateString())
            ->get(['checked_on'])
            ->map(fn ($r) => $r->checked_on->toDateString())
            ->all();

        return HabitStats::present($habit->id, $habit->name, $dates, $this->today());
    }

    /** 날짜를 안 주면 오늘. 미래와 너무 오래된 날짜는 거절한다. */
    private function date(Request $request): string
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $today = $this->today();
        $date = $data['date'] ?? $today;

        if ($date > $today) {
            throw ValidationException::withMessages(['date' => ['미래 날짜는 체크할 수 없어요.']]);
        }
        if ($date < now(config('jarvis.timezone'))->subDays(self::BACKFILL_DAYS - 1)->toDateString()) {
            throw ValidationException::withMessages(['date' => ['최근 '.self::BACKFILL_DAYS.'일 안의 날짜만 고칠 수 있어요.']]);
        }

        return $date;
    }
}
