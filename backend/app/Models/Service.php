<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'description', 'base_url', 'is_active'])]
class Service extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function tools(): HasMany
    {
        return $this->hasMany(ServiceTool::class);
    }
}
