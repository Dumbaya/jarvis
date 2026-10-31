<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'due_date', 'completed_at'])]
class Todo extends Model
{
    protected function casts(): array
    {
        // date:Y-m-d로 해야 JSON에 시각 없는 날짜("2026-11-15")로 나간다.
        return ['due_date' => 'date:Y-m-d', 'completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
