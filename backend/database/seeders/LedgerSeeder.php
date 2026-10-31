<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * 가계부 서브 플랫폼을 레지스트리에 등록한다. 멱등이다.
 *
 * 개인정보 설계: 자비스에게는 "월별 집계 조회"와 "기록 추가(확인 필요)"만 준다.
 * 개별 거래(메모 포함)를 돌려주는 도구는 일부러 만들지 않는다. 도구 결과는 LLM 제공자에게 전달되므로,
 * 지출 내역 원본이 밖으로 나가지 않고 합계만 나간다. 상세 내역은 화면에서만 볼 수 있다.
 */
class LedgerSeeder extends Seeder
{
    public function run(): void
    {
        $service = Service::updateOrCreate(
            ['slug' => 'ledger'],
            [
                'name' => '가계부',
                'description' => '수입과 지출을 기록하고 월별 합계와 카테고리별 지출을 확인합니다.',
                'base_url' => null,
                'is_active' => true,
            ],
        );

        $tools = [
            [
                'name' => 'ledger.summary',
                'description' => '월별 가계부 집계를 조회한다. 수입 합계, 지출 합계, 잔액, 카테고리별 지출과 수입 금액(원)을 돌려준다. fixed에는 월세나 구독료 같은 고정 거래의 이미 반영된 합계와 아직 안 나간 예정 합계가 들어 있다. 개별 거래 내역과 고정 거래 목록은 이 도구로 조회할 수 없고 합계만 알 수 있다. "이번 달 카페에 얼마 썼어?"는 expense_by_category에서 찾는다.',
                'http_method' => 'GET',
                'path' => '/api/ledger/summary',
                'access' => 'read',
                'requires_confirmation' => false,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'month' => ['type' => 'string', 'description' => '조회할 달 YYYY-MM. 생략하면 이번 달. "지난달"은 현재 날짜를 기준으로 계산한다'],
                    ],
                ],
            ],
            [
                'name' => 'ledger.add_entry',
                'description' => '수입이나 지출을 한 건 기록한다. 금액은 원 단위 정수다.',
                'http_method' => 'POST',
                'path' => '/api/ledger/entries',
                'access' => 'write',
                'requires_confirmation' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['income', 'expense'], 'description' => 'income은 수입, expense는 지출'],
                        'amount' => ['type' => 'integer', 'description' => '금액(원). 양의 정수. 예: 4500'],
                        'category' => ['type' => 'string', 'description' => '카테고리. 예: 식비, 카페, 교통, 월급'],
                        'memo' => ['type' => 'string', 'description' => '메모 (선택)'],
                        'occurred_on' => ['type' => 'string', 'description' => '거래 날짜 YYYY-MM-DD (선택, 생략하면 오늘). 미래 날짜는 안 된다'],
                    ],
                    'required' => ['type', 'amount', 'category'],
                ],
            ],
            [
                'name' => 'ledger.add_recurring',
                'description' => '월세, 구독료, 월급처럼 반복되는 고정 거래를 등록한다. 등록하면 해당 날짜가 될 때마다 자동으로 거래가 기록된다. 매월(monthly)은 day_of_month, 매주(weekly)는 weekday(1=월요일 ~ 7=일요일), 매년(yearly)은 month_of_year와 day_of_month가 필요하다. 그 달에 없는 날(31일 등)은 말일로 처리된다.',
                'http_method' => 'POST',
                'path' => '/api/ledger/rules',
                'access' => 'write',
                'requires_confirmation' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['income', 'expense'], 'description' => 'income은 수입, expense는 지출'],
                        'amount' => ['type' => 'integer', 'description' => '금액(원). 양의 정수'],
                        'category' => ['type' => 'string', 'description' => '카테고리. 예: 월세, 구독, 월급'],
                        'frequency' => ['type' => 'string', 'enum' => ['monthly', 'weekly', 'yearly'], 'description' => '반복 주기'],
                        'day_of_month' => ['type' => 'integer', 'description' => '며칠(1~31). monthly, yearly에서 사용'],
                        'weekday' => ['type' => 'integer', 'description' => '요일(1=월요일 ~ 7=일요일). weekly에서 사용'],
                        'month_of_year' => ['type' => 'integer', 'description' => '몇 월(1~12). yearly에서 사용'],
                        'memo' => ['type' => 'string', 'description' => '메모 (선택)'],
                        'start_on' => ['type' => 'string', 'description' => '시작일 YYYY-MM-DD (선택, 생략하면 오늘)'],
                    ],
                    'required' => ['type', 'amount', 'category', 'frequency'],
                ],
            ],
        ];

        foreach ($tools as $tool) {
            $service->tools()->updateOrCreate(['name' => $tool['name']], $tool + ['is_active' => true]);
        }
    }
}
