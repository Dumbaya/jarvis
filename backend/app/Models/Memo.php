<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['title', 'body'])]
class Memo extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** 제목, 본문, 태그 이름에서 부분 일치(대소문자 무시)로 찾는다. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        // LIKE 와일드카드가 사용자 입력에서 의미를 갖지 않도록 이스케이프한다.
        // ESCAPE를 명시해야 Postgres와 SQLite(테스트)에서 똑같이 동작한다.
        $like = '%'.addcslashes($term, '\\%_').'%';
        $op = $query->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $match = fn (string $column) => "$column $op ? ESCAPE '\\'";

        return $query->where(fn (Builder $q) => $q
            ->whereRaw($match('title'), [$like])
            ->orWhereRaw($match('body'), [$like])
            ->orWhereHas('tags', fn (Builder $t) => $t->whereRaw($match('name'), [$like])));
    }

    public function scopeTagged(Builder $query, ?string $tag): Builder
    {
        $tag = trim((string) $tag);

        return $tag === '' ? $query : $query->whereHas('tags', fn (Builder $t) => $t->where('name', $tag));
    }
}
