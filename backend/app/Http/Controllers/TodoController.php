<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TodoController extends Controller
{
    private const MAX_OPEN = 200;

    /** 서비스 시간대 기준의 오늘 날짜. 마감일 비교와 "오늘 할 일"의 기준이다. */
    private function today(): string
    {
        return now(config('jarvis.timezone'))->toDateString();
    }

    /**
     * scope: open(미완료 전체, 기본) | today(오늘까지 마감인 미완료, 밀린 것 포함) | done(완료, 최근 50개)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['scope' => ['nullable', 'in:open,today,done']]);
        $scope = $request->query('scope', 'open');
        $today = $this->today();

        $query = $request->user()->todos();

        if ($scope === 'done') {
            $query->whereNotNull('completed_at')->orderByDesc('completed_at')->limit(50);
        } else {
            $query->whereNull('completed_at')
                ->when($scope === 'today', fn ($q) => $q->whereDate('due_date', '<=', $today))
                // 마감일이 있는 것을 먼저, 마감일 없는 것은 뒤로. NULLS LAST 문법 없이도 SQLite와 Postgres에서 같게 동작한다.
                ->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id')
                ->limit(self::MAX_OPEN);
        }

        return response()->json($query->get()->map(fn (Todo $t) => $this->present($t, $today))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($request->user()->todos()->whereNull('completed_at')->count() >= self::MAX_OPEN) {
            throw ValidationException::withMessages(['title' => ['미완료 할 일은 '.self::MAX_OPEN.'개까지 만들 수 있어요. 완료하거나 지운 뒤 다시 추가해 주세요.']]);
        }

        $todo = $request->user()->todos()->create(['title' => trim($data['title']), 'due_date' => $data['due_date'] ?? null]);

        return response()->json($this->present($todo, $this->today()), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $todo = $request->user()->todos()->findOrFail($id);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        $todo->update($data);

        return response()->json($this->present($todo, $this->today()));
    }

    /** 이미 완료된 할 일에 다시 호출해도 완료 시각을 바꾸지 않는다(여러 번 눌러도 안전). */
    public function complete(Request $request, int $id): JsonResponse
    {
        $todo = $request->user()->todos()->findOrFail($id);
        $todo->completed_at ??= now();
        $todo->save();

        return response()->json($this->present($todo, $this->today()));
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $todo = $request->user()->todos()->findOrFail($id);
        $todo->update(['completed_at' => null]);

        return response()->json($this->present($todo, $this->today()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->todos()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function present(Todo $todo, string $today): array
    {
        return [
            'id' => $todo->id,
            'title' => $todo->title,
            'due_date' => $todo->due_date?->toDateString(),
            'completed' => $todo->completed_at !== null,
            'completed_at' => $todo->completed_at?->copy()->setTimezone(config('jarvis.timezone'))->toIso8601String(),
            'overdue' => $todo->completed_at === null && $todo->due_date !== null && $todo->due_date->toDateString() < $today,
        ];
    }
}
