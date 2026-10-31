<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReservationResource;
use App\Models\Room;
use App\Schedule\InvalidReservation;
use App\Schedule\ReservationBooker;
use App\Schedule\ReservationConflict;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function __construct(private readonly ReservationBooker $booker) {}

    public function rooms(): JsonResponse
    {
        return response()->json(
            Room::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'description', 'capacity']),
        );
    }

    /** 방의 하루 예약 현황. 다른 사람의 예약은 시간대만 보이고 누가 예약했는지는 알려 주지 않는다. */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $room = Room::query()->where('is_active', true)->findOrFail($data['room_id']);
        $tz = config('jarvis.timezone');
        $dayStart = Carbon::createFromFormat('Y-m-d', $data['date'], $tz)->startOfDay();

        $busy = $this->booker->overlapping($room->id, $dayStart, $dayStart->copy()->addDay())
            ->orderBy('starts_at')
            ->get()
            ->map(fn ($r) => [
                'starts_at' => $r->starts_at->copy()->setTimezone($tz)->toIso8601String(),
                'ends_at' => $r->ends_at->copy()->setTimezone($tz)->toIso8601String(),
                'mine' => $r->user_id === $request->user()->id,
            ]);

        return response()->json([
            'room' => ['id' => $room->id, 'name' => $room->name],
            'date' => $data['date'],
            'timezone' => $tz,
            'busy' => $busy,
        ]);
    }

    /** 내 예약. 기본은 아직 끝나지 않은 확정 예약이고, scope=all이면 취소와 지난 예약도 포함한다. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['scope' => ['nullable', 'in:upcoming,all']]);

        $reservations = $request->user()->reservations()
            ->with('room:id,name')
            ->when($request->query('scope', 'upcoming') === 'upcoming', fn ($q) => $q
                ->where('status', 'confirmed')->where('ends_at', '>', now()))
            ->orderBy('starts_at')
            ->limit(100)
            ->get();

        return response()->json(ReservationResource::collection($reservations));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'starts_at' => ['required', 'string', 'max:40'],
            'ends_at' => ['required', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        $room = Room::query()->where('is_active', true)->find($data['room_id'])
            ?? throw ValidationException::withMessages(['room_id' => ['존재하지 않는 방입니다.']]);

        try {
            $reservation = $this->booker->book(
                $request->user(),
                $room,
                $this->parseTime('starts_at', $data['starts_at']),
                $this->parseTime('ends_at', $data['ends_at']),
                $data['title'] ?? null,
            );
        } catch (InvalidReservation $e) {
            throw ValidationException::withMessages(['starts_at' => [$e->getMessage()]]);
        } catch (ReservationConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(new ReservationResource($reservation->load('room:id,name')), 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $reservation = $request->user()->reservations()->findOrFail($id);

        try {
            $reservation = $this->booker->cancel($reservation);
        } catch (ReservationConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InvalidReservation $e) {
            throw ValidationException::withMessages(['reservation_id' => [$e->getMessage()]]);
        }

        return response()->json(new ReservationResource($reservation->load('room:id,name')));
    }

    /** 오프셋이 있으면 그대로, 없으면 서비스 시간대(기본 Asia/Seoul)의 시각으로 해석한다. */
    private function parseTime(string $field, string $value): Carbon
    {
        try {
            return Carbon::parse($value, config('jarvis.timezone'));
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages([$field => ['시간 형식이 올바르지 않습니다. 예: 2026-11-01T15:00:00+09:00']]);
        }
    }
}
