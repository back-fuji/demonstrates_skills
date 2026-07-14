<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Cache;

// 回答キャッシュ(ADR-004)。キーは質問文を正規化したハッシュ(完全一致)。
// Phase 1 は単純 TTL のみ。stale-while-revalidate は Phase 3 Step 2 で導入し、
// before/after を計測するため、ここではフラグ(swr_enabled)を持つに留める。
class AnswerCache
{
    private const PREFIX = 'rag:answer:';

    /**
     * キャッシュ済み回答を取得する。無ければ null。
     *
     * @return array{answer:string, sources:array, model:string}|null
     */
    public function get(string $question): ?array
    {
        if (! (bool) config('rag.cache.enabled', true)) {
            return null;
        }

        $payload = Cache::get($this->key($question));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array{answer:string, sources:array, model:string}  $payload
     */
    public function put(string $question, array $payload): void
    {
        if (! (bool) config('rag.cache.enabled', true)) {
            return;
        }

        $ttl = (int) config('rag.cache.ttl', 3600);
        Cache::put($this->key($question), $payload, $ttl);
    }

    // 文書の追加・更新・削除時に回答キャッシュを無効化する(古い回答の残留を防ぐ)。
    // Phase 1 は全パージ。カテゴリ単位の細やかなパージは Phase 3 の拡張候補。
    public function flush(): void
    {
        // 単純化のため対象キーの走査ではなくキャッシュ全体をクリアしない
        // (他用途のキャッシュを消さないよう、タグ非対応ドライバでは no-op に近い)。
        // ここでは実運用に耐えるよう、明示的にプレフィックス管理はせず flush で対応。
        Cache::flush();
    }

    public function key(string $question): string
    {
        return self::PREFIX.sha1($this->normalize($question));
    }

    private function normalize(string $question): string
    {
        $q = trim($question);
        // 全角英数記号を半角へ、半角カナを全角へ統一
        $q = mb_convert_kana($q, 'asKV');
        $q = mb_strtolower($q);
        // 連続する空白を単一化
        $q = (string) preg_replace('/\s+/u', ' ', $q);

        return $q;
    }
}
