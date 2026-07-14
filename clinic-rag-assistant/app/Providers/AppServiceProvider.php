<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // レート制限(docs/03 共通仕様): 検索30/min、管理60/min(ユーザー単位)
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(30)->by((string) ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('manage', function (Request $request) {
            return Limit::perMinute(60)->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }
}
