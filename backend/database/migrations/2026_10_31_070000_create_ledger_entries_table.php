<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 7); // income | expense
            // 원(KRW) 단위 정수. 소수점이 없어서 float 오차가 생기지 않는다.
            $table->unsignedBigInteger('amount');
            $table->string('category', 50);
            $table->string('memo', 200)->nullable();
            // 거래 "날짜". 시각이 아니라 사용자가 말한 날짜 그대로라서 시간대 변환을 하지 않는다.
            $table->date('occurred_on');
            $table->timestamps();

            $table->index(['user_id', 'occurred_on']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_valid CHECK (type IN ('income', 'expense'))");
            DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
