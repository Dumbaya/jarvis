<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 자비스의 모든 도구 호출 기록(감사 로그). 확인 대기 중인 쓰기 작업도 여기에 pending으로 저장한다.
        Schema::create('tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_tool_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool_name'); // 도구가 지워져도 기록은 남도록 이름을 따로 저장
            $table->jsonb('arguments');
            // pending(확인 대기) | running | success | error | rejected(잘못된 인자) | cancelled
            $table->string('status', 12)->index();
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_calls');
    }
};
