<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BriefingController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\JarvisController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\LedgerRuleController;
use App\Http\Controllers\MemoController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ServiceRegistryController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('services', [ServiceRegistryController::class, 'index']);

    // 자비스
    // 채팅 횟수 제한은 JarvisController::chat에서 ChatLimiter로 직접 건다(다른 요청이 한도를 깎지 않도록).
    Route::prefix('jarvis')->group(function () {
        Route::get('limits', [JarvisController::class, 'limits']);
        Route::get('calls', [JarvisController::class, 'calls']);
        Route::post('chat', [JarvisController::class, 'chat']);
        Route::post('actions/{id}/confirm', [JarvisController::class, 'confirm'])->middleware('throttle:60,1');
        Route::post('actions/{id}/cancel', [JarvisController::class, 'cancel'])->middleware('throttle:60,1');
    });

    Route::get('usage', [UsageController::class, 'show']);
    // 브리핑은 서브 플랫폼 도구를 여러 번 호출하므로 호출 빈도를 제한한다.
    Route::get('briefing', [BriefingController::class, 'show'])->middleware('throttle:30,1');

    // 일정/예약
    Route::get('rooms', [ReservationController::class, 'rooms']);
    Route::get('availability', [ReservationController::class, 'availability']);
    Route::get('reservations', [ReservationController::class, 'index']);
    Route::post('reservations', [ReservationController::class, 'store'])->middleware('throttle:30,1');
    Route::post('reservations/{id}/cancel', [ReservationController::class, 'cancel']);

    // 할 일/습관
    Route::apiResource('todos', TodoController::class)->except('show');
    Route::post('todos/{id}/complete', [TodoController::class, 'complete']);
    Route::post('todos/{id}/reopen', [TodoController::class, 'reopen']);
    Route::get('habits', [HabitController::class, 'index']);
    Route::post('habits', [HabitController::class, 'store']);
    Route::delete('habits/{id}', [HabitController::class, 'destroy']);
    Route::post('habits/{id}/check', [HabitController::class, 'check']);
    Route::post('habits/{id}/uncheck', [HabitController::class, 'uncheck']);

    // 가계부
    Route::prefix('ledger')->group(function () {
        Route::get('entries', [LedgerController::class, 'index']);
        Route::post('entries', [LedgerController::class, 'store'])->middleware('throttle:60,1');
        Route::put('entries/{id}', [LedgerController::class, 'update']);
        Route::delete('entries/{id}', [LedgerController::class, 'destroy']);
        Route::get('summary', [LedgerController::class, 'summary']);
        Route::get('categories', [LedgerController::class, 'categories']);

        // 고정 거래(반복 규칙). 날짜가 되면 일반 거래로 자동 반영된다.
        Route::get('rules', [LedgerRuleController::class, 'index']);
        Route::post('rules', [LedgerRuleController::class, 'store'])->middleware('throttle:30,1');
        Route::put('rules/{id}', [LedgerRuleController::class, 'update']);
        Route::delete('rules/{id}', [LedgerRuleController::class, 'destroy']);
    });

    // 세컨드 브레인
    Route::apiResource('memos', MemoController::class);
    Route::get('tags', [TagController::class, 'index']);
});

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});
