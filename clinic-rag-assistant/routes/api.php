<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SessionController;
use Illuminate\Support\Facades\Route;

// セッションを直接使う認証エンドポイント(SPA Cookie)。
// Sanctum のフロントエンド判定(Origin/Referer)に依存せずセッションを開始する。
$sessionStack = [
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
];

Route::middleware($sessionStack)->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function () use ($sessionStack) {
    Route::get('/user', [AuthController::class, 'me']);

    // 検索・回答(スタッフ向け)。レート制限 30req/min/user。
    Route::middleware('throttle:search')->group(function () {
        Route::post('/search', [SearchController::class, 'search']);
    });

    Route::get('/sessions/{id}/messages', [SessionController::class, 'messages']);
    Route::post('/answers/{id}/feedback', [FeedbackController::class, 'store']);

    // 文書管理(管理者専用)。レート制限 60req/min/user。
    Route::middleware(['admin', 'throttle:manage'])->group(function () {
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::get('/documents/{id}', [DocumentController::class, 'show']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::put('/documents/{id}', [DocumentController::class, 'update']);
        Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);
    });
});
