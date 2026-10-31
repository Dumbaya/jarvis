<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('name');                      // 예: memo.search
            $table->text('description');                 // LLM이 도구를 고를 때 읽는 설명
            $table->jsonb('parameters');                 // JSON Schema
            $table->string('http_method', 10)->default('POST');
            $table->string('path');                      // service.base_url 기준 경로
            $table->string('access', 10)->default('read'); // read | write
            $table->boolean('requires_confirmation')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['service_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_tools');
    }
};
