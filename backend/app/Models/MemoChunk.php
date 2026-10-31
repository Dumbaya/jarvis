<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 메모 조각. 삽입은 VectorStore가 벡터 타입을 다루며 직접 하므로 대량 할당을 열어 두지 않는다(guarded 전체).
 */
class MemoChunk extends Model
{
    protected $guarded = ['*'];

    public function memo(): BelongsTo
    {
        return $this->belongsTo(Memo::class);
    }
}
