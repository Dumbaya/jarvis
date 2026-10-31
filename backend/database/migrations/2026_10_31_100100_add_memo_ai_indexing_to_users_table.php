<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 메모 의미 검색 옵트인. 켜기 전에는 메모 내용이 임베딩 제공자로 전송되지 않는다.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('memo_ai_indexing')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('memo_ai_indexing');
        });
    }
};
