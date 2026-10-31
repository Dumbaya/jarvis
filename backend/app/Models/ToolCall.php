<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'service_tool_id', 'tool_name', 'arguments', 'status', 'result', 'error', 'duration_ms'])]
class ToolCall extends Model
{
    protected function casts(): array
    {
        return ['arguments' => 'array', 'result' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(ServiceTool::class, 'service_tool_id');
    }
}
