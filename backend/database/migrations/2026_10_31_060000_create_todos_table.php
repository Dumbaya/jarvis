<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            // 마감일은 시각이 아니라 "날짜"다. 시간대 변환 없이 사용자가 말한 날짜 그대로 저장한다.
            $table->date('due_date')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'completed_at', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
