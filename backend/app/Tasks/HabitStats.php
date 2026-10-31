<?php

namespace App\Tasks;

use Carbon\CarbonImmutable;

/**
 * 습관 통계 계산. DB와 시계에 의존하지 않는 순수 함수라서 경계 상황을 테스트하기 쉽다.
 * 날짜는 모두 서비스 시간대 기준의 'Y-m-d' 문자열이다.
 */
class HabitStats
{
    /** 이 이상은 세지 않는다. 조회 범위와 같은 값이라 더 긴 연속은 이 숫자로 표시된다. */
    public const LOOKBACK_DAYS = 400;

    /**
     * 연속 달성일. 오늘 체크했으면 오늘부터, 아직 안 했으면 어제부터 거슬러 센다.
     * 오늘 아직 안 했다고 연속이 끊긴 것으로 보지 않는다(하루가 지나야 끊긴다).
     *
     * @param  array<int, string>  $checkedDates
     */
    public static function streak(array $checkedDates, string $today): int
    {
        $checked = array_flip($checkedDates);
        $cursor = CarbonImmutable::parse($today);

        if (! isset($checked[$cursor->toDateString()])) {
            $cursor = $cursor->subDay();
        }

        $streak = 0;
        while (isset($checked[$cursor->toDateString()]) && $streak < self::LOOKBACK_DAYS) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    /**
     * 이번 주(월~일)의 달성 현황. week_days는 이번 주가 시작해서 오늘까지 지난 날 수(1~7)라서,
     * 월요일이면 1/1처럼 보여 주기 위한 분모로 쓴다.
     *
     * @param  array<int, string>  $checkedDates
     * @return array{week_checks: int, week_days: int}
     */
    public static function week(array $checkedDates, string $today): array
    {
        $todayDate = CarbonImmutable::parse($today);
        $weekStart = $todayDate->startOfWeek()->toDateString();

        return [
            'week_checks' => count(array_filter($checkedDates, fn ($d) => $d >= $weekStart && $d <= $today)),
            'week_days' => (int) $todayDate->startOfWeek()->diffInDays($todayDate) + 1,
        ];
    }

    /** @param  array<int, string>  $checkedDates */
    public static function present(int $id, string $name, array $checkedDates, string $today): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'checked_today' => in_array($today, $checkedDates, true),
            'streak' => self::streak($checkedDates, $today),
        ] + self::week($checkedDates, $today);
    }
}
