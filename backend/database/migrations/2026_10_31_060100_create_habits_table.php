<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        // 하루에 한 번만 체크할 수 있다. 같은 날 두 번 눌러도 한 행이다(unique).
        Schema::create('habit_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->date('checked_on');
            $table->timestamps();

            $table->unique(['habit_id', 'checked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_checks');
        Schema::dropIfExists('habits');
    }
};
