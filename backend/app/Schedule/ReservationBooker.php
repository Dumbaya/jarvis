<?php

namespace App\Schedule;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 예약 생성과 취소 규칙, 그리고 이중 예약 방지.
 *
 * 이중 예약은 두 겹으로 막는다.
 *  1. 트랜잭션 안에서 방 행을 잠그고(SELECT ... FOR UPDATE) 겹치는 예약을 검사한다.
 *  2. Postgres에서는 배타 제약(reservations_no_overlap)이 겹치는 확정 예약의 저장 자체를 거부한다.
 */
class ReservationBooker
{
    public const MIN_MINUTES = 15;

    public const MAX_MINUTES = 240;

    public const MAX_DAYS_AHEAD = 90;

    public const MAX_ACTIVE_PER_USER = 10;

    /** @throws InvalidReservation|ReservationConflict */
    public function book(User $user, Room $room, CarbonInterface $start, CarbonInterface $end, ?string $title): Reservation
    {
        $start = $start->copy()->utc();
        $end = $end->copy()->utc();
        $this->validateWindow($start, $end);

        try {
            return DB::transaction(function () use ($user, $room, $start, $end, $title) {
                // 같은 방에 대한 동시 예약을 한 줄로 세운다. SQLite(테스트)는 잠금을 무시한다.
                $room = Room::query()->whereKey($room->id)->where('is_active', true)->lockForUpdate()->first()
                    ?? throw new InvalidReservation('예약할 수 없는 방입니다.');

                if ($this->overlapping($room->id, $start, $end)->exists()) {
                    throw new ReservationConflict('이미 예약된 시간과 겹칩니다.');
                }

                $active = $user->reservations()->where('status', 'confirmed')->where('ends_at', '>', now())->count();
                if ($active >= self::MAX_ACTIVE_PER_USER) {
                    throw new InvalidReservation('동시에 유지할 수 있는 예약은 '.self::MAX_ACTIVE_PER_USER.'개까지예요.');
                }

                return $user->reservations()->create([
                    'room_id' => $room->id,
                    'title' => $title,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'status' => 'confirmed',
                ]);
            });
        } catch (QueryException $e) {
            // 잠금과 검사를 통과한 뒤에도 DB 제약이 겹침을 잡아내면 같은 충돌로 취급한다. (23P01 = exclusion_violation)
            if ($e->getCode() === '23P01') {
                throw new ReservationConflict('이미 예약된 시간과 겹칩니다.', 0, $e);
            }
            throw $e;
        }
    }

    /** 아직 시작하지 않은 내 예약만 취소할 수 있다. */
    public function cancel(Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'confirmed') {
                throw new ReservationConflict('이미 취소된 예약이에요.');
            }
            if ($locked->starts_at->lte(now())) {
                throw new InvalidReservation('이미 시작했거나 지난 예약은 취소할 수 없어요.');
            }

            $locked->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $locked;
        });
    }

    /** 이 시간대와 겹치는 확정 예약. 끝나는 시각과 시작하는 시각이 같으면 겹치지 않는다. */
    public function overlapping(int $roomId, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return Reservation::query()
            ->where('room_id', $roomId)
            ->where('status', 'confirmed')
            ->where('starts_at', '<', $end->copy()->utc())
            ->where('ends_at', '>', $start->copy()->utc());
    }

    private function validateWindow(CarbonInterface $start, CarbonInterface $end): void
    {
        if ($end->lte($start)) {
            throw new InvalidReservation('끝나는 시간은 시작 시간보다 늦어야 해요.');
        }
        if ($start->lte(now())) {
            throw new InvalidReservation('이미 지난 시간은 예약할 수 없어요.');
        }

        $minutes = $start->diffInMinutes($end);
        if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
            throw new InvalidReservation('예약은 '.self::MIN_MINUTES.'분 이상 '.(self::MAX_MINUTES / 60).'시간 이하로 할 수 있어요.');
        }
        if ($start->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            throw new InvalidReservation('예약은 '.self::MAX_DAYS_AHEAD.'일 이내로만 할 수 있어요.');
        }
    }
}
