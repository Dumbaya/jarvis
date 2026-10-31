<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 임베딩 차원. 컬럼 크기가 이 값으로 고정되므로 바꾸려면 마이그레이션과 재색인이 필요하다. */
    private const DIMENSIONS = 768;

    public function up(): void
    {
        // 메모를 잘게 나눈 조각과 그 임베딩(의미를 담은 숫자 벡터). 메모 검색(RAG)에 쓴다.
        Schema::create('memo_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memo_id')->constrained()->cascadeOnDelete();
            // 검색할 때 사용자별로 걸러야 하므로 메모를 거치지 않고 바로 거를 수 있게 둔다. 탈퇴하면 함께 지워진다.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chunk_index');
            $table->text('content');
            // 어떤 임베딩 모델로 만들었는지. 모델이 바뀌면 벡터를 섞어 비교할 수 없으므로 현재 모델의 것만 검색한다.
            $table->string('model', 100);
            $table->timestamps();

            $table->unique(['memo_id', 'chunk_index']);
            $table->index(['user_id', 'model']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            // 사용자별로 걸러 낸 뒤 정확한 거리 순으로 정렬한다. 개인 규모(사용자당 수천 조각)에서는 근사 색인(HNSW)보다
            // 정확하고 충분히 빠르다. 근사 색인은 필터와 함께 쓰면 결과가 빠질 수 있어서 일부러 만들지 않았다.
            DB::statement('ALTER TABLE memo_chunks ADD COLUMN embedding vector('.self::DIMENSIONS.') NOT NULL');
        } else {
            // 테스트용 SQLite에는 벡터 타입이 없어서 JSON 문자열로 저장하고, 유사도는 PHP에서 계산한다.
            Schema::table('memo_chunks', fn (Blueprint $table) => $table->text('embedding'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memo_chunks');
    }
};
