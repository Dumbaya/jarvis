<?php

namespace App\Ledger;

use Carbon\CarbonImmutable;

/**
 * 고정 거래의 날짜 계산. DB와 시계에 의존하지 않는 순수 함수라서 경계 상황을 테스트하기 쉽다.
 * 날짜는 모두 서비스 시간대 기준의 'Y-m-d' 문자열이다(문자열 비교가 곧 날짜 비교다).
 *
 * 규칙 배열: frequency(monthly|weekly|yearly), day_of_month, weekday(ISO 1=월~7=일), month_of_year, start_on, end_on
 */
class RecurrenceSchedule
{
    /** 한 번에 계산하는 최대 날짜 수. 잘못된 입력이 오래 도는 것을 막는 안전장치다. */
    private const MAX_DATES = 1000;

    /**
     * [$from, $to] (양끝 포함) 안에서 규칙에 해당하는 날짜들. 시작일 이전과 종료일 이후는 제외한다.
     *
     * @return array<int, string>
     */
    public static function dates(array $rule, string $from, string $to): array
    {
        $from = max($from, $rule['start_on']);
        $to = ! empty($rule['end_on']) ? min($to, $rule['end_on']) : $to;
        if ($from > $to) {
            return [];
        }

        $dates = match ($rule['frequency']) {
            'weekly' => self::weekly((int) $rule['weekday'], $from, $to),
            'monthly' => self::monthly((int) $rule['day_of_month'], $from, $to),
            'yearly' => self::yearly((int) $rule['month_of_year'], (int) $rule['day_of_month'], $from, $to),
            default => [],
        };

        return array_slice($dates, 0, self::MAX_DATES);
    }

    /** $after 다음 날부터 가장 가까운 다음 날짜. 5년 안에 없으면 null. */
    public static function next(array $rule, string $after): ?string
    {
        $start = CarbonImmutable::parse($after)->addDay();

        return self::dates($rule, $start->toDateString(), $start->addYears(5)->toDateString())[0] ?? null;
    }

    private static function weekly(int $weekday, string $from, string $to): array
    {
        $day = CarbonImmutable::parse($from);
        while ($day->dayOfWeekIso !== $weekday) {
            $day = $day->addDay();
        }

        $out = [];
        for (; $day->toDateString() <= $to; $day = $day->addWeek()) {
            $out[] = $day->toDateString();
        }

        return $out;
    }

    private static function monthly(int $day, string $from, string $to): array
    {
        $out = [];
        $month = CarbonImmutable::parse($from)->startOfMonth();
        $last = CarbonImmutable::parse($to)->startOfMonth();

        for (; $month <= $last; $month = $month->addMonthNoOverflow()) {
            $date = self::clamped($month, $day);
            if ($date >= $from && $date <= $to) {
                $out[] = $date;
            }
        }

        return $out;
    }

    private static function yearly(int $month, int $day, string $from, string $to): array
    {
        $out = [];
        for ($year = (int) substr($from, 0, 4); $year <= (int) substr($to, 0, 4); $year++) {
            $date = self::clamped(CarbonImmutable::create($year, $month, 1), $day);
            if ($date >= $from && $date <= $to) {
                $out[] = $date;
            }
        }

        return $out;
    }

    /** 그 달에 없는 날(31일, 평년의 2월 29일)이면 말일로 보정한다. */
    private static function clamped(CarbonImmutable $firstOfMonth, int $day): string
    {
        return $firstOfMonth->day(min($day, $firstOfMonth->daysInMonth))->toDateString();
    }
}
