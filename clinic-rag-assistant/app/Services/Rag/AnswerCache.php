<?php

namespace App\Services\Rag;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

// 回答キャッシュ(ADR-004)。キーは質問文を正規化したハッシュ(完全一致)。
// Phase 1 は単純 TTL。Phase 3 Step 2 で stale-while-revalidate(SWR)+ 再生成ロックを
// フラグ(rag.cache.swr_enabled)で有効化し、before/after を計測する。
class AnswerCache
{
    private const FRESH = 'rag:answer:fresh:';
    private const STALE = 'rag:answer:stale:';
    private const REGEN_LOCK = 'rag:answer:regen:';

    /**
     * キャッシュ済み回答(fresh)を取得する。無ければ null。
     * 単純TTL経路(Phase 1)の互換用。
     *
     * @return array{answer:string, sources:array, model:string}|null
     */
    public function get(string $question): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $payload = Cache::get(self::FRESH.$this->hash($question));

        return is_array($payload) ? $payload : null;
    }

    /**
     * SWR を考慮したエントリ取得。
     *
     * @return array{payload: array|null, stale: bool}
     *   stale=true は「fresh は期限切れだが stale 値がある」= 即返ししつつ裏で再生成すべき状態。
     */
    public function getEntry(string $question): array
    {
        if (! $this->enabled()) {
            return ['payload' => null, 'stale' => false];
        }

        $hash = $this->hash($question);
        $fresh = Cache::get(self::FRESH.$hash);
        if (is_array($fresh)) {
            return ['payload' => $fresh, 'stale' => false];
        }

        if ($this->swrEnabled()) {
            $stale = Cache::get(self::STALE.$hash);
            if (is_array($stale)) {
                return ['payload' => $stale, 'stale' => true];
            }
        }

        return ['payload' => null, 'stale' => false];
    }

    /**
     * @param  array{answer:string, sources:array, model:string}  $payload
     */
    public function put(string $question, array $payload): void
    {
        if (! $this->enabled()) {
            return;
        }
        $hash = $this->hash($question);
        Cache::put(self::FRESH.$hash, $payload, (int) config('rag.cache.ttl', 3600));

        if ($this->swrEnabled()) {
            // 期限切れ後も stale として一定時間保持(SWR)
            Cache::put(self::STALE.$hash, $payload, (int) config('rag.cache.stale_ttl', 86400));
        }
    }

    // fresh のみ失効させる(負荷試験 S3 の「人気質問キャッシュの強制失効」再現)。stale は残す。
    public function expireFresh(string $question): void
    {
        Cache::forget(self::FRESH.$this->hash($question));
    }

    // 再生成ロック(同時失効時に生成処理を1件へ収束させる)。非ブロッキングで取得。
    public function acquireRegenLock(string $question, int $ttl = 30): ?Lock
    {
        $lock = Cache::lock(self::REGEN_LOCK.$this->hash($question), $ttl);

        return $lock->get() ? $lock : null;
    }

    // 別プロセス(キュージョブ)から owner トークンでロックを解放する。
    public function releaseRegenLock(string $question, string $owner): void
    {
        Cache::restoreLock(self::REGEN_LOCK.$this->hash($question), $owner)->release();
    }

    // 文書の追加・更新・削除時に回答キャッシュを無効化する。
    public function flush(): void
    {
        Cache::flush();
    }

    public function key(string $question): string
    {
        return self::FRESH.$this->hash($question);
    }

    private function enabled(): bool
    {
        return (bool) config('rag.cache.enabled', true);
    }

    private function swrEnabled(): bool
    {
        return (bool) config('rag.cache.swr_enabled', false);
    }

    private function hash(string $question): string
    {
        return sha1($this->normalize($question));
    }

    private function normalize(string $question): string
    {
        $q = trim($question);
        $q = mb_convert_kana($q, 'asKV');
        $q = mb_strtolower($q);
        $q = (string) preg_replace('/\s+/u', ' ', $q);

        return $q;
    }
}
