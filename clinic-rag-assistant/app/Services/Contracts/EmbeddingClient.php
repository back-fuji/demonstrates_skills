<?php

namespace App\Services\Contracts;

// 埋め込み(embedding)生成の抽象。実API実装とフェイク実装を差し替え可能にする(docs/02 §3)。
interface EmbeddingClient
{
    /**
     * 単一テキストの埋め込みベクトルを返す。
     *
     * @return list<float>
     */
    public function embed(string $text): array;

    /**
     * 複数テキストの埋め込みをまとめて返す(取り込み時のバッチ生成用)。
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;

    // ベクトルの次元数(DB の vector(N) と一致する必要がある)。
    public function dimensions(): int;
}
