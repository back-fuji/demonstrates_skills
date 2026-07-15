<?php

namespace App\Services\Rag\Embedding;

use App\Services\Contracts\EmbeddingClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// ローカル埋め込み(Ollama)実装。1024次元モデル(例: bge-m3 / mxbai-embed-large)を使う。
// Ollama /api/embed はバッチ対応: {"model":..,"input":[...]} -> {"embeddings":[[...],...]}
class OllamaEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
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

        $response = Http::baseUrl($this->baseUrl)
            ->timeout(120)
            ->retry(3, 1000, throw: false)
            ->post('/api/embed', [
                'model' => $this->model,
                'input' => $texts,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Ollama embedding failed: '.$response->status().' '.$response->body());
        }

        $embeddings = $response->json('embeddings');
        if (! is_array($embeddings)) {
            throw new RuntimeException('Ollama embedding returned unexpected payload');
        }

        return array_map(fn (array $vec) => array_map('floatval', $vec), $embeddings);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}
