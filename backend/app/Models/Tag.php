<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name'])]
class Tag extends Model
{
    public function memos(): BelongsToMany
    {
        return $this->belongsToMany(Memo::class);
    }
}
