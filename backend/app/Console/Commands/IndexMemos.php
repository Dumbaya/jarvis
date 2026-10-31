<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rag\MemoIndexer;
use Illuminate\Console\Command;

class IndexMemos extends Command
{
    protected $signature = 'memos:index {--user= : 이 사용자 id만} {--limit=100 : 사용자당 최대 메모 수}';

    protected $description = '의미 검색을 켠 사용자의 아직 색인되지 않은 메모를 색인한다(임베딩 한도 때문에 못 한 것을 이어서 할 때)';

    public function handle(MemoIndexer $indexer): int
    {
        $users = User::query()->where('memo_ai_indexing', true)->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($users as $user) {
            $before = $indexer->pendingCount($user);
            $done = $indexer->indexPending($user, (int) $this->option('limit'));
            $this->line("사용자 {$user->id}: {$done}개 색인 (남은 메모 ".($before - $done).'개)');
        }

        if (! $indexer->available()) {
            $this->warn("임베딩 한도에 걸려 있어요. {$indexer->retryAfter()}초 뒤에 다시 시도하세요.");
        }

        return self::SUCCESS;
    }
}
