<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 고정 거래 규칙(월세, 구독료, 월급 같은 반복 거래). 규칙은 "언제 반복하는가"만 담고,
        // 실제 거래는 날짜가 되면 ledger_entries에 일반 거래로 만들어진다.
        Schema::create('ledger_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 7); // income | expense
            $table->unsignedBigInteger('amount');
            $table->string('category', 50);
            $table->string('memo', 200)->nullable();

            $table->string('frequency', 7); // monthly | weekly | yearly
            $table->unsignedTinyInteger('day_of_month')->nullable();   // monthly, yearly (1~31, 짧은 달은 말일로 보정)
            $table->unsignedTinyInteger('weekday')->nullable();        // weekly (ISO: 1=월 ... 7=일)
            $table->unsignedTinyInteger('month_of_year')->nullable();  // yearly (1~12)

            $table->date('start_on');
            $table->date('end_on')->nullable();
            $table->boolean('active')->default(true);
            // 여기까지(포함)의 날짜는 거래로 만들었다는 표시. 사용자가 만들어진 거래를 지워도 다시 살아나지 않게 한다.
            $table->date('last_applied_on')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ledger_rules ADD CONSTRAINT ledger_rules_type_valid CHECK (type IN ('income', 'expense'))");
            DB::statement("ALTER TABLE ledger_rules ADD CONSTRAINT ledger_rules_frequency_valid CHECK (frequency IN ('monthly', 'weekly', 'yearly'))");
            DB::statement('ALTER TABLE ledger_rules ADD CONSTRAINT ledger_rules_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_rules');
    }
};
