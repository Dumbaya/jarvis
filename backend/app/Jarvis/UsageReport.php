<?php

namespace App\Jarvis;

use App\Models\LlmUsage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 사용량 집계. "오늘"과 "이번 달"은 서비스 시간대(기본 Asia/Seoul) 기준이다.
 * 비용은 저장하지 않고 조회할 때 config('jarvis.pricing')의 현재 단가로 계산한다.
 */
class UsageReport
{
    public function todayStart(): CarbonInterface
    {
        return now(config('jarvis.timezone'))->startOfDay()->utc();
    }

    public function monthStart(): CarbonInterface
    {
        return now(config('jarvis.timezone'))->startOfMonth()->utc();
    }

    /** 사용자 본인에게 보여 주는 값. */
    public function forUser(User $user): array
    {
        return [
            'tokens_today' => (int) LlmUsage::where('user_id', $user->id)->where('created_at', '>=', $this->todayStart())->sum('total_tokens'),
            'requests_month' => LlmUsage::where('user_id', $user->id)->where('created_at', '>=', $this->monthStart())->count(),
        ];
    }

    /** 관리자 전용. 모든 사용자의 합계다. */
    public function overall(): array
    {
        $models = $this->byModel();
        $cost = $this->monthCost($models);
        $budget = $this->budget();

        return [
            'tokens_today' => (int) $this->month()->where('created_at', '>=', $this->todayStart())->sum('total_tokens'),
            'requests_month' => $this->month()->count(),
            'estimated_cost_usd' => $cost['usd'],
            'unpriced_models' => $cost['unpriced'],
            'by_model' => $models,
            'budget' => [
                'monthly_usd' => $budget,
                'used_percent' => $budget && $cost['usd'] !== null ? round($cost['usd'] / $budget * 100, 1) : null,
                // 단가가 없으면 비용을 알 수 없어 한도를 적용할 수 없다.
                'enforced' => $budget !== null && $cost['usd'] !== null && $cost['unpriced'] === [],
            ],
        ];
    }

    /** 월 예산을 넘겼으면 true. 예산이나 단가가 없으면 판단할 수 없으므로 false(한도 미적용). */
    public function budgetExceeded(): bool
    {
        $budget = $this->budget();
        if ($budget === null) {
            return false;
        }

        $cost = $this->monthCost($this->byModel());

        return $cost['unpriced'] === [] && $cost['usd'] !== null && $cost['usd'] >= $budget;
    }

    private function month(): Builder
    {
        return LlmUsage::query()->where('created_at', '>=', $this->monthStart());
    }

    /** @return array<int, array{model: string, requests: int, input_tokens: int, output_tokens: int, total_tokens: int, estimated_cost_usd: float|null}> */
    private function byModel(): array
    {
        return $this->month()
            ->selectRaw('model, count(*) as requests, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(total_tokens) as total_tokens')
            ->groupBy('model')
            ->orderByDesc('total_tokens')
            ->get()
            ->map(fn ($r) => [
                'model' => $r->model,
                'requests' => (int) $r->requests,
                'input_tokens' => (int) $r->input_tokens,
                'output_tokens' => (int) $r->output_tokens,
                'total_tokens' => (int) $r->total_tokens,
                'estimated_cost_usd' => $this->cost($r->model, (int) $r->input_tokens, (int) $r->output_tokens),
            ])
            ->all();
    }

    /** 단가가 설정되지 않은 모델이면 null. */
    private function cost(string $model, int $input, int $output): ?float
    {
        $price = config('jarvis.pricing')[$model] ?? null;

        if (! $price || ! is_numeric($price['input'] ?? null) || ! is_numeric($price['output'] ?? null)) {
            return null;
        }

        return ($input * (float) $price['input'] + $output * (float) $price['output']) / 1_000_000;
    }

    /**
     * 토큰을 쓴 모델 중 단가가 없는 것이 하나라도 있으면 합계는 일부만 반영된 값이므로 unpriced로 알려 준다.
     *
     * @return array{usd: float|null, unpriced: array<int, string>}
     */
    private function monthCost(array $models): array
    {
        $used = array_filter($models, fn ($m) => $m['total_tokens'] > 0);
        $unpriced = array_values(array_map(
            fn ($m) => $m['model'],
            array_filter($used, fn ($m) => $m['estimated_cost_usd'] === null),
        ));
        $priced = array_filter($used, fn ($m) => $m['estimated_cost_usd'] !== null);

        if (! $used) {
            return ['usd' => 0.0, 'unpriced' => []];
        }

        return [
            'usd' => $priced ? round(array_sum(array_column($priced, 'estimated_cost_usd')), 6) : null,
            'unpriced' => $unpriced,
        ];
    }

    private function budget(): ?float
    {
        $budget = config('jarvis.monthly_budget_usd');

        return is_numeric($budget) && (float) $budget > 0 ? (float) $budget : null;
    }
}
