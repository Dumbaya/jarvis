<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200)->nullable();
            // 시간은 항상 UTC로 저장하고, 표시할 때만 사용자 시간대로 바꾼다.
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 10)->default('confirmed'); // confirmed | cancelled
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'starts_at']);
            $table->index(['user_id', 'starts_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_time_valid CHECK (ends_at > starts_at)');
            // 같은 방에서 확정된 예약끼리 시간이 겹치면 DB가 직접 거부한다. 앱의 검사가 뚫려도 이중 예약은 불가능하다.
            DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_no_overlap
                EXCLUDE USING gist (room_id WITH =, tstzrange(starts_at, ends_at) WITH &&)
                WHERE (status = 'confirmed')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
