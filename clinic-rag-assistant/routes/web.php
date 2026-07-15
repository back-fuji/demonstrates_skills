<?php

use Illuminate\Support\Facades\Route;

// Vue SPA を返す catch-all。
// api / sanctum / up(ヘルスチェック)/ build(Viteアセット)/ storage を除く
// 全 GET を SPA のエントリ(app.blade.php)へ渡し、ルーティングは vue-router に委ねる。
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|sanctum|up|build|storage).*$');
