<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'provider', 'model', 'status', 'llm_calls', 'input_tokens', 'output_tokens', 'total_tokens'])]
class LlmUsage extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
