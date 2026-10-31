<?php

namespace Tests\Feature;

use App\Jarvis\LlmClient;
use App\Jarvis\LlmReply;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ScheduleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-01 10:00 KST = 01:00 UTC. 시간이 흐르지 않게 고정한다.
        $this->travelTo(Carbon::parse('2026-11-01T10:00:00+09:00'));

        $this->seed(ScheduleSeeder::class);
        $this->room = Room::firstWhere('name', '스터디룸 A');
        $this->user = User::factory()->create();
    }

    private function as(User $user): static
    {
        // 한 테스트 안에서 사용자를 바꿀 때 인증 가드가 이전 사용자를 기억하지 않도록 비운다.
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function book(string $start, string $end, ?User $user = null, ?Room $room = null, array $extra = [])
    {
        return $this->as($user ?? $this->user)->postJson('/api/reservations', [
            'room_id' => ($room ?? $this->room)->id,
            'starts_at' => $start,
            'ends_at' => $end,
        ] + $extra);
    }

    public function test_requires_authentication(): void
    {
        foreach (['/api/rooms', '/api/availability', '/api/reservations'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/reservations', [])->assertUnauthorized();
        $this->postJson('/api/reservations/1/cancel')->assertUnauthorized();
    }

    public function test_lists_only_active_rooms(): void
    {
        Room::create(['name' => '폐쇄된 방', 'is_active' => false]);

        $this->as($this->user)->getJson('/api/rooms')
            ->assertOk()->assertJsonCount(3)->assertJsonMissing(['name' => '폐쇄된 방']);
    }

    public function test_books_a_room_and_returns_times_in_service_timezone(): void
    {
        $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T16:30:00+09:00', extra: ['title' => '스터디'])
            ->assertCreated()
            ->assertJsonPath('starts_at', '2026-11-02T15:00:00+09:00')
            ->assertJsonPath('ends_at', '2026-11-02T16:30:00+09:00')
            ->assertJsonPath('room.name', '스터디룸 A')
            ->assertJsonPath('status', 'confirmed');

        // DB에는 UTC로 저장된다.
        $this->assertSame('2026-11-02 06:00:00', Reservation::first()->starts_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_time_without_offset_is_interpreted_in_service_timezone(): void
    {
        $this->book('2026-11-02T15:00:00', '2026-11-02T16:00:00')->assertCreated();

        $this->assertSame('06:00', Reservation::first()->starts_at->utc()->format('H:i'));
    }

    public function test_overlapping_reservation_is_rejected_with_409(): void
    {
        $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T16:00:00+09:00')->assertCreated();
        $other = User::factory()->create();

        // 앞쪽, 뒤쪽, 완전 포함, 완전 감싸기 모두 거절한다.
        foreach ([
            ['14:30', '15:30'], ['15:30', '16:30'], ['15:15', '15:45'], ['14:00', '17:00'], ['15:00', '16:00'],
        ] as [$s, $e]) {
            $this->book("2026-11-02T{$s}:00+09:00", "2026-11-02T{$e}:00+09:00", $other)->assertStatus(409);
        }
        $this->assertSame(1, Reservation::count());
    }

    public function test_adjacent_other_room_and_cancelled_slots_are_allowed(): void
    {
        $first = $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T16:00:00+09:00')->assertCreated();
        $other = User::factory()->create();

        // 끝나는 시각과 시작하는 시각이 같으면 겹치지 않는다.
        $this->book('2026-11-02T16:00:00+09:00', '2026-11-02T17:00:00+09:00', $other)->assertCreated();
        $this->book('2026-11-02T14:00:00+09:00', '2026-11-02T15:00:00+09:00', $other)->assertCreated();
        // 다른 방은 같은 시간이어도 된다.
        $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T16:00:00+09:00', $other, Room::firstWhere('name', '회의실'))->assertCreated();

        // 취소한 시간은 다시 예약할 수 있다.
        $this->as($this->user)->postJson('/api/reservations/'.$first->json('id').'/cancel')->assertOk();
        $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T16:00:00+09:00', $other)->assertCreated();
    }

    public function test_validation_rules(): void
    {
        $this->book('2026-11-01T09:00:00+09:00', '2026-11-01T10:30:00+09:00')->assertUnprocessable();   // 이미 지난 시작
        $this->book('2026-11-02T16:00:00+09:00', '2026-11-02T15:00:00+09:00')->assertUnprocessable();   // 끝이 시작보다 빠름
        $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T15:10:00+09:00')->assertUnprocessable();   // 15분 미만
        $this->book('2026-11-02T10:00:00+09:00', '2026-11-02T14:30:00+09:00')->assertUnprocessable();   // 4시간 초과
        $this->book('2027-03-01T10:00:00+09:00', '2027-03-01T11:00:00+09:00')->assertUnprocessable();   // 90일 초과
        $this->book('내일 오후 3시', '내일 오후 4시')->assertUnprocessable();                              // 형식 오류
        $this->as($this->user)->postJson('/api/reservations', ['room_id' => 9999, 'starts_at' => '2026-11-02T15:00:00+09:00', 'ends_at' => '2026-11-02T16:00:00+09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('room_id');

        $this->assertSame(0, Reservation::count());
    }

    public function test_inactive_room_cannot_be_booked(): void
    {
        $this->room->update(['is_active' => false]);

        $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T16:00:00+09:00')->assertUnprocessable();
    }

    public function test_active_reservation_limit_per_user(): void
    {
        $slot = fn (int $daysAhead) => [
            now()->addDays($daysAhead)->setTime(10, 0)->toIso8601String(),
            now()->addDays($daysAhead)->setTime(11, 0)->toIso8601String(),
        ];

        foreach (range(1, 10) as $i) {
            $this->book(...$slot($i))->assertCreated();
        }
        $this->book(...$slot(11))->assertUnprocessable();

        // 한도는 사용자별이다. 다른 사람은 계속 예약할 수 있다.
        $this->book(...[...$slot(11), User::factory()->create()])->assertCreated();
    }

    public function test_availability_shows_busy_slots_without_other_users_identity(): void
    {
        $other = User::factory()->create();
        $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T16:00:00+09:00', $other)->assertCreated();
        $this->book('2026-11-02T18:00:00+09:00', '2026-11-02T19:00:00+09:00')->assertCreated();
        $this->book('2026-11-03T09:00:00+09:00', '2026-11-03T10:00:00+09:00')->assertCreated(); // 다른 날

        $res = $this->as($this->user)->getJson("/api/availability?room_id={$this->room->id}&date=2026-11-02")
            ->assertOk()
            ->assertJsonCount(2, 'busy')
            ->assertJsonPath('busy.0.starts_at', '2026-11-02T15:00:00+09:00')
            ->assertJsonPath('busy.0.mine', false)
            ->assertJsonPath('busy.1.mine', true);

        $this->assertStringNotContainsString((string) $other->email, $res->getContent());
        $res->assertJsonMissingPath('busy.0.user_id')->assertJsonMissingPath('busy.0.title');

        $this->as($this->user)->getJson("/api/availability?room_id={$this->room->id}&date=내일")->assertUnprocessable();
    }

    public function test_availability_day_boundary_uses_service_timezone(): void
    {
        // 23:30 KST 시작은 UTC로는 14:30이지만 KST 기준 11-02 하루에 속한다.
        $this->book('2026-11-02T23:30:00+09:00', '2026-11-03T00:30:00+09:00')->assertCreated();

        $this->as($this->user)->getJson("/api/availability?room_id={$this->room->id}&date=2026-11-02")->assertJsonCount(1, 'busy');
        $this->as($this->user)->getJson("/api/availability?room_id={$this->room->id}&date=2026-11-03")->assertJsonCount(1, 'busy');
        $this->as($this->user)->getJson("/api/availability?room_id={$this->room->id}&date=2026-11-04")->assertJsonCount(0, 'busy');
    }

    public function test_my_reservations_lists_only_mine_and_upcoming_by_default(): void
    {
        $mine = $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T16:00:00+09:00')->json('id');
        $cancelled = $this->book('2026-11-03T15:00:00+09:00', '2026-11-03T16:00:00+09:00')->json('id');
        $this->as($this->user)->postJson("/api/reservations/{$cancelled}/cancel");
        $this->book('2026-11-04T15:00:00+09:00', '2026-11-04T16:00:00+09:00', User::factory()->create());
        Reservation::create([
            'room_id' => $this->room->id, 'user_id' => $this->user->id, 'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(), 'status' => 'confirmed',
        ]);

        $this->as($this->user)->getJson('/api/reservations')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mine);
        $this->as($this->user)->getJson('/api/reservations?scope=all')->assertJsonCount(3);
    }

    public function test_cancel_rules(): void
    {
        $id = $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T16:00:00+09:00')->json('id');

        // 남의 예약은 존재하지 않는 것처럼 404
        $this->as(User::factory()->create())->postJson("/api/reservations/{$id}/cancel")->assertNotFound();
        $this->assertSame('confirmed', Reservation::find($id)->status);

        $this->as($this->user)->postJson("/api/reservations/{$id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->as($this->user)->postJson("/api/reservations/{$id}/cancel")->assertStatus(409); // 이중 취소

        // 이미 시작한 예약은 취소할 수 없다.
        $started = Reservation::create([
            'room_id' => $this->room->id, 'user_id' => $this->user->id, 'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addMinutes(50), 'status' => 'confirmed',
        ]);
        $this->as($this->user)->postJson("/api/reservations/{$started->id}/cancel")->assertUnprocessable();
        $this->assertSame('confirmed', $started->fresh()->status);
    }

    public function test_database_constraint_blocks_overlap_even_if_app_check_is_bypassed(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('배타 제약은 PostgreSQL에서만 검증한다.');
        }

        $row = fn (string $s, string $e, string $status = 'confirmed') => [
            'room_id' => $this->room->id, 'user_id' => $this->user->id, 'starts_at' => $s, 'ends_at' => $e, 'status' => $status,
        ];
        Reservation::create($row('2026-11-02 06:00:00+00', '2026-11-02 07:00:00+00'));

        // 앱 검사를 거치지 않고 모델로 직접 넣어도 DB가 거부한다.
        // 테스트 전체가 하나의 트랜잭션이라 위반 뒤에도 계속 쓰려면 세이브포인트(중첩 트랜잭션)로 감싸야 한다.
        try {
            DB::transaction(fn () => Reservation::create($row('2026-11-02 06:30:00+00', '2026-11-02 07:30:00+00')));
            $this->fail('겹치는 예약이 저장되었습니다.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
        }

        // 취소된 예약과 맞닿은 시간은 허용한다.
        Reservation::create($row('2026-11-02 06:30:00+00', '2026-11-02 07:30:00+00', 'cancelled'));
        Reservation::create($row('2026-11-02 07:00:00+00', '2026-11-02 08:00:00+00'));
        $this->assertSame(3, Reservation::count());
    }

    public function test_conflict_from_database_constraint_is_reported_as_409(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('배타 제약은 PostgreSQL에서만 검증한다.');
        }

        // 앱의 겹침 검사를 건너뛰고 DB 제약에만 의존하는 경로를 만든다 (동시 요청이 검사를 동시에 통과한 상황).
        $booker = new class extends \App\Schedule\ReservationBooker
        {
            public function overlapping(int $roomId, \Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end): \Illuminate\Database\Eloquent\Builder
            {
                return Reservation::query()->whereRaw('1 = 0');
            }
        };
        $this->app->instance(\App\Schedule\ReservationBooker::class, $booker);

        $this->book('2026-11-02T15:00:00+09:00', '2026-11-02T16:00:00+09:00')->assertCreated();
        $this->book('2026-11-02T15:30:00+09:00', '2026-11-02T16:30:00+09:00', User::factory()->create())->assertStatus(409);
        $this->assertSame(1, Reservation::count());
    }

    public function test_tools_are_registered_and_cancel_path_placeholder_is_filled(): void
    {
        $this->seed(ScheduleSeeder::class); // 두 번 실행해도 중복되지 않는다.
        $token = $this->user->createToken('t')->plainTextToken;

        $names = $this->withToken($token)->getJson('/api/services')->assertOk()
            ->json('0.tools.*.name');
        $this->assertEqualsCanonicalizing([
            'schedule.list_rooms', 'schedule.check_availability', 'schedule.my_reservations', 'schedule.reserve', 'schedule.cancel',
        ], $names);

        Http::fake(['*' => Http::response(['id' => 5, 'status' => 'cancelled'])]);
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            private int $n = 0;

            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                // 첫 호출에서는 취소 도구를 부르고, 도구 선언에 빈 parameters가 없는지도 함께 확인한다.
                foreach ($tools as $t) {
                    assert(! array_key_exists('parameters', $t) || $t['parameters'] !== [], $t['name'].'의 빈 parameters');
                }

                return $this->n++ === 0
                    ? new LlmReply(null, [['name' => 'schedule.cancel', 'args' => ['reservation_id' => 5]]])
                    : new LlmReply('끝');
            }
        });

        $id = $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => '5번 예약 취소해 줘']]])
            ->assertOk()->json('pending_action.id');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson("/api/jarvis/actions/{$id}/confirm")->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r->url() === 'http://nginx/api/reservations/5/cancel'
            && ! isset($r['reservation_id'])); // 경로에 쓴 인자는 본문에서 뺀다
    }

    public function test_system_prompt_contains_current_time_and_timezone(): void
    {
        $token = $this->user->createToken('t')->plainTextToken;
        $seen = new \ArrayObject;
        $this->app->instance(LlmClient::class, new class($seen) implements LlmClient
        {
            public function __construct(private \ArrayObject $seen) {}

            public function generate(string $system, array $conversation, array $tools): LlmReply
            {
                $this->seen['system'] = $system;

                return new LlmReply('ok');
            }
        });

        $this->withToken($token)->postJson('/api/jarvis/chat', ['messages' => [['role' => 'user', 'content' => '지금 몇 시야']]])->assertOk();

        $this->assertStringContainsString('2026-11-01', $seen['system']);
        $this->assertStringContainsString('10:00', $seen['system']);
        $this->assertStringContainsString('+09:00', $seen['system']);
        $this->assertStringContainsString('Asia/Seoul', $seen['system']);
    }
}
