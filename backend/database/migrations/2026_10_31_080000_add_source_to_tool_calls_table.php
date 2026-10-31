<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 누가 도구를 불렀는지. 자비스 대화(jarvis)와 대시보드 브리핑(briefing)을 구분해서,
        // 대시보드를 열 때마다 쌓이는 조회 기록이 자비스 호출 로그를 덮지 않게 한다.
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->string('source', 12)->default('jarvis')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
