<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

/** 할 일/습관 서브 플랫폼을 레지스트리에 등록한다. 멱등이다. */
class TasksSeeder extends Seeder
{
    public function run(): void
    {
        $service = Service::updateOrCreate(
            ['slug' => 'tasks'],
            [
                'name' => '할 일/습관',
                'description' => '할 일을 마감일과 함께 관리하고, 습관을 매일 체크하며 연속 달성일을 확인합니다.',
                'base_url' => null,
                'is_active' => true,
            ],
        );

        $none = ['type' => 'object', 'properties' => new \stdClass];

        $tools = [
            [
                'name' => 'tasks.list_todos',
                'description' => '내 할 일 목록을 조회한다. scope가 today면 오늘까지 마감인 미완료(밀린 것 포함), open이면 미완료 전체, done이면 최근 완료한 것이다. 각 항목의 id, 제목, 마감일, overdue(마감 지남) 여부를 돌려준다.',
                'http_method' => 'GET',
                'path' => '/api/todos',
                'access' => 'read',
                'requires_confirmation' => false,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'scope' => ['type' => 'string', 'enum' => ['open', 'today', 'done'], 'description' => '조회 범위. 기본은 open'],
                    ],
                ],
            ],
            [
                'name' => 'tasks.add_todo',
                'description' => '새 할 일을 추가한다.',
                'http_method' => 'POST',
                'path' => '/api/todos',
                'access' => 'write',
                'requires_confirmation' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => '할 일 제목'],
                        'due_date' => ['type' => 'string', 'description' => '마감일 YYYY-MM-DD (선택). "내일", "금요일" 같은 말은 현재 날짜를 기준으로 계산한다'],
                    ],
                    'required' => ['title'],
                ],
            ],
            [
                'name' => 'tasks.complete_todo',
                'description' => '할 일을 완료 처리한다. id를 모르면 먼저 할 일 목록을 조회한다.',
                'http_method' => 'POST',
                'path' => '/api/todos/{todo_id}/complete',
                'access' => 'write',
                'requires_confirmation' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['todo_id' => ['type' => 'integer', 'description' => '완료할 할 일 id']],
                    'required' => ['todo_id'],
                ],
            ],
            [
                'name' => 'tasks.list_habits',
                'description' => '내 습관 목록과 현황을 조회한다. 각 습관의 id, 이름, 오늘 체크 여부(checked_today), 연속 달성일(streak), 이번 주 달성 횟수(week_checks)와 이번 주 지난 일수(week_days)를 돌려준다.',
                'http_method' => 'GET',
                'path' => '/api/habits',
                'access' => 'read',
                'requires_confirmation' => false,
                'parameters' => $none,
            ],
            [
                'name' => 'tasks.check_habit',
                'description' => '습관을 오늘 한 것으로 체크한다. date를 주면 그 날짜(최근 7일 이내)로 체크한다. 이미 체크했어도 안전하다. id를 모르면 먼저 습관 목록을 조회한다.',
                'http_method' => 'POST',
                'path' => '/api/habits/{habit_id}/check',
                'access' => 'write',
                'requires_confirmation' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'habit_id' => ['type' => 'integer', 'description' => '습관 id'],
                        'date' => ['type' => 'string', 'description' => '체크할 날짜 YYYY-MM-DD (선택, 기본 오늘)'],
                    ],
                    'required' => ['habit_id'],
                ],
            ],
        ];

        foreach ($tools as $tool) {
            $service->tools()->updateOrCreate(['name' => $tool['name']], $tool + ['is_active' => true]);
        }
    }
}
