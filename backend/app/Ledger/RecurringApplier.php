<?php

namespace App\Ledger;

use App\Models\LedgerEntry;
use App\Models\LedgerRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 고정 거래 규칙을 실제 거래로 만든다. 서버에 스케줄러(cron)가 없어도 되도록 "가계부를 열 때" 실행한다.
 * 오늘까지 도래했는데 아직 만들지 않은 날짜를 한꺼번에 따라잡으므로, 며칠 안 열어도 빠지지 않는다.
 *
 * 같은 날짜가 두 번 만들어지지 않게 세 겹으로 막는다.
 *  1. 규칙 행 잠금(동시 요청을 한 줄로 세운다)
 *  2. 규칙의 last_applied_on(여기까지 처리했다는 표시)
 *  3. (rule_id, occurred_on) 유니크 + INSERT OR IGNORE
 * last_applied_on 덕분에 사용자가 만들어진 거래를 지워도 다시 살아나지 않는다.
 */
class RecurringApplier
{
    /** 사용자의 활성 규칙을 모두 적용하고, 새로 만든 거래 수를 돌려준다. */
    public function apply(User $user): int
    {
        $created = 0;
        foreach ($user->ledgerRules()->where('active', true)->get() as $rule) {
            $created += $this->applyRule($rule);
        }

        return $created;
    }

    public function applyRule(LedgerRule $rule): int
    {
        $today = now(config('jarvis.timezone'))->toDateString();

        return DB::transaction(function () use ($rule, $today) {
            // 잠근 뒤 최신 상태로 다시 읽는다. 기다리는 사이에 다른 요청이 이미 처리했을 수 있다.
            $rule = LedgerRule::query()->whereKey($rule->id)->lockForUpdate()->first();
            if (! $rule || ! $rule->active) {
                return 0;
            }

            $from = $rule->last_applied_on
                ? CarbonImmutable::parse($rule->last_applied_on->toDateString())->addDay()->toDateString()
                : $rule->start_on->toDateString();
            $to = $rule->end_on ? min($today, $rule->end_on->toDateString()) : $today;

            // 아직 시작 전이거나 이미 오늘까지 처리했다. 표시를 옮기지 않는다.
            if ($from > $to) {
                return 0;
            }

            $now = now();
            $created = 0;
            foreach (RecurrenceSchedule::dates($rule->schedule(), $from, $to) as $date) {
                $created += LedgerEntry::query()->insertOrIgnore([
                    'user_id' => $rule->user_id,
                    'rule_id' => $rule->id,
                    'type' => $rule->type,
                    'amount' => $rule->amount,
                    'category' => $rule->category,
                    'memo' => $rule->memo,
                    'occurred_on' => $date,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $rule->forceFill(['last_applied_on' => $to])->save();

            return $created;
        });
    }
}
