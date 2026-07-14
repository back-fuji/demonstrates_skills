<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // SPA(Vue)からの Cookie ベース認証を有効化(Sanctum)
        $middleware->statefulApi();
        // 管理者専用ルート用エイリアス
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // エラー形式を RFC 7807 風 JSON に統一(docs/03 共通仕様)
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'type' => 'about:blank',
                    'title' => 'Unprocessable Entity',
                    'status' => 422,
                    'detail' => '入力値が不正です。',
                    'errors' => $e->errors(),
                ], 422, ['Content-Type' => 'application/problem+json']);
            }

            return null;
        });

        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) {
            if ($request->is('api/*')) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? 60;

                return response()->json([
                    'type' => 'about:blank',
                    'title' => 'Too Many Requests',
                    'status' => 429,
                    'detail' => 'リクエストが多すぎます。しばらく待って再試行してください。',
                ], 429, [
                    'Content-Type' => 'application/problem+json',
                    'Retry-After' => $retryAfter,
                ]);
            }

            return null;
        });
    })->create();
