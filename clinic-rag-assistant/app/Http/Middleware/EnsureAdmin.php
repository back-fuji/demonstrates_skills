<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 文書管理API等、管理者ロール専用のエンドポイントを保護する。
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->isAdmin()) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'この操作には管理者権限が必要です。',
            ], 403, ['Content-Type' => 'application/problem+json']);
        }

        return $next($request);
    }
}
