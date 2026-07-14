<?php

namespace App\Services\Rag\Embedding;

use App\Services\Contracts\EmbeddingClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Voyage AI の埋め込みAPI実装(既定 voyage-3 / 1024次元)。
// レート制限・一時障害に備え、指数バックオフでリトライする。
class VoyageEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $dimensions,
    ) {}

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        $texts = array_values($texts);
        if ($texts === []) {
            return [];
        }

        $response = Http::withToken($this->apiKey)
            ->baseUrl($this->baseUrl)
            ->timeout(60)
            // 指数バックオフ(1s, 2s, 4s)でレート制限・一時障害に対応
            ->retry(3, 1000, throw: false)
            ->post('/embeddings', [
                'model' => $this->model,
                'input' => $texts,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Embedding API failed: '.$response->status().' '.$response->body()
            );
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('Embedding API returned unexpected payload');
        }

        // API は input 順で data を返す。念のため index で並べ替える。
        usort($data, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(
            fn (array $row) => array_map('floatval', $row['embedding']),
            $data
        );
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}
