<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 시간은 사용자 시간대(+오프셋)로 내보낸다. UTC("Z")로 주면 LLM이 시간대를 잘못 환산하기 쉽다.
 *
 * @mixin \App\Models\Reservation
 */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = config('jarvis.timezone');

        return [
            'id' => $this->id,
            'room' => ['id' => $this->room_id, 'name' => $this->whenLoaded('room', fn () => $this->room->name)],
            'title' => $this->title,
            'starts_at' => $this->starts_at->copy()->setTimezone($tz)->toIso8601String(),
            'ends_at' => $this->ends_at->copy()->setTimezone($tz)->toIso8601String(),
            'status' => $this->status,
        ];
    }
}
