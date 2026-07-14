<?php

namespace App\Providers;

use App\Services\Contracts\EmbeddingClient;
use App\Services\Contracts\LlmClient;
use App\Services\Ingest\ChunkSplitter;
use App\Services\Ingest\TokenCounter;
use App\Services\Rag\Embedding\FakeEmbeddingClient;
use App\Services\Rag\Embedding\VoyageEmbeddingClient;
use App\Services\Rag\Llm\AnthropicLlmClient;
use App\Services\Rag\Llm\FakeLlmClient;
use Illuminate\Support\ServiceProvider;

// RAG 関連の依存を config('rag') のドライバ設定に応じてバインドする。
// テスト・負荷試験では driver=fake に切り替えて外部APIを呼ばない。
class RagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Embedding クライアント
        $this->app->singleton(EmbeddingClient::class, function () {
            $cfg = config('rag.embedding');
            $dimensions = (int) $cfg['dimensions'];

            if (($cfg['driver'] ?? 'voyage') === 'fake') {
                return new FakeEmbeddingClient(
                    dimensions: $dimensions,
                    latencyMs: (int) config('rag.fake.embedding_latency_ms', 0),
                );
            }

            return new VoyageEmbeddingClient(
                apiKey: (string) $cfg['api_key'],
                model: (string) $cfg['model'],
                baseUrl: (string) $cfg['base_url'],
                dimensions: $dimensions,
            );
        });

        // LLM クライアント
        $this->app->singleton(LlmClient::class, function () {
            $cfg = config('rag.llm');

            if (($cfg['driver'] ?? 'anthropic') === 'fake') {
                return new FakeLlmClient(
                    latencyMs: (int) config('rag.fake.llm_latency_ms', 0),
                );
            }

            return new AnthropicLlmClient(
                apiKey: (string) $cfg['api_key'],
                model: (string) $cfg['model'],
                baseUrl: (string) $cfg['base_url'],
                maxTokens: (int) $cfg['max_tokens'],
            );
        });

        // チャンク分割(純粋クラス)
        $this->app->singleton(TokenCounter::class);
        $this->app->singleton(ChunkSplitter::class, function ($app) {
            return new ChunkSplitter(
                tokenCounter: $app->make(TokenCounter::class),
                maxTokens: (int) config('rag.ingest.chunk_max_tokens', 500),
                overlapTokens: (int) config('rag.ingest.chunk_overlap_tokens', 100),
            );
        });
    }
}
