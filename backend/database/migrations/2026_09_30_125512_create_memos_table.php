<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200)->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // 한글 부분 일치 검색(ILIKE)을 빠르게 하기 위한 trigram 인덱스. Postgres에서만 적용한다.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX memos_body_trgm ON memos USING gin (body gin_trgm_ops)');
            DB::statement('CREATE INDEX memos_title_trgm ON memos USING gin (title gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memos');
    }
};
