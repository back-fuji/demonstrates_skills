<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;
use Throwable;

// 負荷試験用の軽量メトリクスカウンタ(Redis)。
// 回答キャッシュ用の Cache::flush と干渉しないよう、Cache ではなく Redis を直接使う。
class Metrics
{
    private const PREFIX = 'metrics:';

    public static function increment(string $name, int $by = 1): void
    {
        try {
            Redis::incrby(self::PREFIX.$name, $by);
        } catch (Throwable) {
            // メトリクスは本処理を止めない
        }
    }

    public static function get(string $name): int
    {
        try {
            return (int) Redis::get(self::PREFIX.$name);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function reset(string $name): void
    {
        try {
            Redis::del(self::PREFIX.$name);
        } catch (Throwable) {
        }
    }
}
