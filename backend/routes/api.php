<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\JarvisController;
use App\Http\Controllers\MemoController;
use App\Http\Controllers\ServiceRegistryController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('services', [ServiceRegistryController::class, 'index']);

    // 자비스
    Route::prefix('jarvis')->middleware('throttle:jarvis')->group(function () {
        Route::post('chat', [JarvisController::class, 'chat']);
        Route::post('actions/{id}/confirm', [JarvisController::class, 'confirm']);
        Route::post('actions/{id}/cancel', [JarvisController::class, 'cancel']);
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
