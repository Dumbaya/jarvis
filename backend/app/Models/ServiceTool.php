<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_id', 'name', 'description', 'parameters', 'http_method',
    'path', 'access', 'requires_confirmation', 'is_active',
])]
class ServiceTool extends Model
{
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'requires_confirmation' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
