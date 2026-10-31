<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type', 'amount', 'category', 'memo', 'frequency', 'day_of_month', 'weekday', 'month_of_year',
    'start_on', 'end_on', 'active', 'last_applied_on',
])]
class LedgerRule extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'active' => 'boolean',
            'start_on' => 'date:Y-m-d',
            'end_on' => 'date:Y-m-d',
            'last_applied_on' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'rule_id');
    }

    /** 일정 계산에 쓰는 순수 배열 형태. */
    public function schedule(): array
    {
        return [
            'frequency' => $this->frequency,
            'day_of_month' => $this->day_of_month,
            'weekday' => $this->weekday,
            'month_of_year' => $this->month_of_year,
            'start_on' => $this->start_on->toDateString(),
            'end_on' => $this->end_on?->toDateString(),
        ];
    }
}
