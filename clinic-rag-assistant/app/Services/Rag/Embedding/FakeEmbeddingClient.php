<?php

namespace App\Services\Rag\Embedding;

use App\Services\Contracts\EmbeddingClient;

// テスト・負荷試験用の決定的な埋め込み実装。
// トークンの feature hashing(符号付き)で疑似ベクトルを作るため、
// 語が重なるテキスト同士はコサイン類似度が高くなる。これにより実APIなしでも
// 検索の Feature テストが現実的に機能する。
class FakeEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private readonly int $dimensions = 1024,
        // 負荷試験で外部API相当のレイテンシを再現するための固定待機(ミリ秒)。
        private readonly int $latencyMs = 0,
    ) {}

    public function embed(string $text): array
    {
        if ($this->latencyMs > 0) {
            usleep($this->latencyMs * 1000);
        }

        $vec = array_fill(0, $this->dimensions, 0.0);
        foreach ($this->tokenize($text) as $token) {
            $idx = crc32($token) % $this->dimensions;
            $sign = (crc32('sign:'.$token) % 2) === 0 ? 1.0 : -1.0;
            $vec[$idx] += $sign;
        }

        return $this->normalize($vec);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn (string $t) => $this->embed($t), array_values($texts));
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));
        // CJK は1文字、ASCII 語は空白区切りで抽出
        preg_match_all(
            '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}]|[a-z0-9]+/u',
            $text,
            $m
        );

        return $m[0] ?? [];
    }

    /**
     * @param  list<float>  $vec
     * @return list<float>
     */
    private function normalize(array $vec): array
    {
        $norm = 0.0;
        foreach ($vec as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);
        if ($norm == 0.0) {
            // 空テキスト等: 先頭要素だけ1にした単位ベクトルを返す(ゼロベクトル回避)
            $vec[0] = 1.0;

            return $vec;
        }

        return array_map(fn (float $v) => $v / $norm, $vec);
    }
}
