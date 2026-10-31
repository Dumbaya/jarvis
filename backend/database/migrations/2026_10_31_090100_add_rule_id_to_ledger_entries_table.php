<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            // 규칙이 만든 거래인지. 규칙을 지워도 이미 만들어진 거래는 남는다.
            $table->foreignId('rule_id')->nullable()->constrained('ledger_rules')->nullOnDelete();

            // 같은 규칙이 같은 날짜에 거래를 두 번 만들 수 없다. 동시에 두 요청이 규칙을 적용해도 중복이 생기지 않는다.
            // (rule_id가 null인 일반 거래는 서로 겹쳐도 된다. NULL은 유니크 비교에서 서로 다른 값으로 취급된다.)
            $table->unique(['rule_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['rule_id', 'occurred_on']);
            $table->dropConstrainedForeignId('rule_id');
        });
    }
};
