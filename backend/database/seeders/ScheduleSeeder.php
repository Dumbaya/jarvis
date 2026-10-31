<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * 일정/예약 서브 플랫폼을 레지스트리에 등록한다. 메인(자비스)의 코드는 건드리지 않는다.
 * 멱등이라 여러 번 실행해도 중복이 생기지 않는다.
 */
class ScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRooms();
        $this->registerTools();
    }

    private function seedRooms(): void
    {
        foreach ([
            ['name' => '스터디룸 A', 'capacity' => 4, 'description' => '4인용 조용한 스터디룸'],
            ['name' => '스터디룸 B', 'capacity' => 6, 'description' => '6인용 스터디룸, 화이트보드'],
            ['name' => '회의실', 'capacity' => 10, 'description' => '10인용 회의실, 프로젝터'],
        ] as $room) {
            Room::updateOrCreate(['name' => $room['name']], $room + ['is_active' => true]);
        }
    }

    private function registerTools(): void
    {
        $service = Service::updateOrCreate(
            ['slug' => 'schedule'],
            [
                'name' => '일정/예약',
                'description' => '스터디룸과 회의실을 예약하고 내 예약을 관리합니다.',
                'base_url' => null,
                'is_active' => true,
            ],
        );

        $tools = [
            [
                'name' => 'schedule.list_rooms',
                'description' => '예약할 수 있는 방(스터디룸, 회의실)의 id, 이름, 수용 인원 목록을 조회한다. 방 id를 모를 때 먼저 호출한다.',
                'http_method' => 'GET',
                'path' => '/api/rooms',
                'access' => 'read',
                'requires_confirmation' => false,
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'schedule.check_availability',
                'description' => '특정 방의 하루 예약 현황을 조회한다. 이미 예약된 시간대(busy)를 돌려주며, busy에 없는 시간은 비어 있다.',
                'http_method' => 'GET',
                'path' => '/api/availability',
                'access' => 'read',
                'requires_confirmation' => false,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'room_id' => ['type' => 'integer', 'description' => '방 id'],
                        'date' => ['type' => 'string', 'description' => '조회할 날짜. YYYY-MM-DD 형식'],
                    ],
                    'required' => ['room_id', 'date'],
                ],
            ],
            [
                'name' => 'schedule.my_reservations',
                'description' => '내 예약 목록을 시작 시간순으로 조회한다. 아직 끝나지 않은 확정 예약만 돌려준다.',
                'http_method' => 'GET',
                'path' => '/api/reservations',
                'access' => 'read',
                'requires_confirmation' => false,
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'schedule.reserve',
                'description' => '방을 예약한다. 15분 이상 4시간 이하, 90일 이내만 가능하고 이미 예약된 시간과 겹치면 실패한다.',
                'http_method' => 'POST',
                'path' => '/api/reservations',
                'access' => 'write',
                'requires_confirmation' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'room_id' => ['type' => 'integer', 'description' => '방 id'],
                        'starts_at' => ['type' => 'string', 'description' => '시작 시각. ISO 8601, 시간대 오프셋 포함. 예: 2026-11-01T15:00:00+09:00'],
                        'ends_at' => ['type' => 'string', 'description' => '종료 시각. ISO 8601, 시간대 오프셋 포함'],
                        'title' => ['type' => 'string', 'description' => '예약 제목 (선택)'],
                    ],
                    'required' => ['room_id', 'starts_at', 'ends_at'],
                ],
            ],
            [
                'name' => 'schedule.cancel',
                'description' => '내 예약을 취소한다. 아직 시작하지 않은 예약만 취소할 수 있다. 예약 id를 모르면 먼저 내 예약 목록을 조회한다.',
                'http_method' => 'POST',
                'path' => '/api/reservations/{reservation_id}/cancel',
                'access' => 'write',
                'requires_confirmation' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reservation_id' => ['type' => 'integer', 'description' => '취소할 예약 id'],
                    ],
                    'required' => ['reservation_id'],
                ],
            ],
        ];

        foreach ($tools as $tool) {
            $service->tools()->updateOrCreate(['name' => $tool['name']], $tool + ['is_active' => true]);
        }
    }
}
