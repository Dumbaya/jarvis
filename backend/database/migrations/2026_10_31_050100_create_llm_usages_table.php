<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 자비스 채팅 요청 1건당 1행. 한 요청 안에서 LLM을 여러 번 불러도 토큰은 합산해서 저장한다.
        // 비용은 저장하지 않는다. 토큰만 저장하고 조회할 때 현재 단가로 계산하므로 단가를 나중에 입력해도 과거 사용량에 적용된다.
        Schema::create('llm_usages', function (Blueprint $table) {
            $table->id();
            // 사용자가 탈퇴해도 전체 사용량과 비용 집계는 남아야 하므로 삭제 시 null로 둔다.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('model', 100);
            $table->string('status', 10); // ok | fallback(LLM 장애) | quota(모든 모델의 호출 한도 소진) | budget(월 예산 초과)
            $table->unsignedSmallInteger('llm_calls')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0); // 추론(thinking) 토큰 포함
            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_usages');
    }
};
