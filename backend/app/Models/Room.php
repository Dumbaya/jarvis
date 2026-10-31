<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'capacity', 'is_active'])]
class Room extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
