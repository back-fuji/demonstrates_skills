<?php

// RAG(検索・回答・取り込み)関連の設定を一元管理する。
// 環境変数から読み込み、サービス層はこの config 経由で参照する。
return [

    // --- 検索・回答パラメータ ---
    'top_k' => (int) env('RAG_TOP_K', 8),
    'score_threshold' => (float) env('RAG_SCORE_THRESHOLD', 0.5),

    // resources/prompts/ 配下のプロンプトファイルのバージョン。
    // 評価ハーネスが eval_runs.prompt_version として記録する。
    'answer_prompt_version' => env('RAG_ANSWER_PROMPT_VERSION', 'v1'),
    'judge_prompt_version' => env('RAG_JUDGE_PROMPT_VERSION', 'v1'),

    // --- LLM(回答生成)---
    // driver: anthropic(実API) / ollama(ローカル・無料) / fake(擬似)
    'llm' => [
        'driver' => env('LLM_DRIVER', 'anthropic'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1024),
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://host.docker.internal:11434'),
            'model' => env('OLLAMA_LLM_MODEL', 'qwen2.5:7b'),
        ],
    ],

    // --- Embedding ---
    // driver: voyage(実API) / ollama(ローカル・無料) / fake(擬似)
    'embedding' => [
        'driver' => env('EMBEDDING_DRIVER', 'voyage'),
        'api_key' => env('EMBEDDING_API_KEY'),
        'model' => env('EMBEDDING_MODEL', 'voyage-3'),
        'base_url' => env('EMBEDDING_BASE_URL', 'https://api.voyageai.com/v1'),
        // DB の vector(N) と一致させること。変更時はマイグレーションの見直しが必要。
        'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1024),
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://host.docker.internal:11434'),
            // 1024次元のモデルを指定すること(bge-m3 / mxbai-embed-large 等)
            'model' => env('OLLAMA_EMBEDDING_MODEL', 'bge-m3'),
        ],
    ],

    // --- キャッシュ戦略(ADR-004)---
    // Phase 1 は単純 TTL のみ。SWR は Phase 3 Step 2 でフラグを ON にして計測する。
    'cache' => [
        'enabled' => (bool) env('RAG_CACHE_ENABLED', true),
        'ttl' => (int) env('RAG_CACHE_TTL', 3600),
        'swr_enabled' => (bool) env('RAG_CACHE_SWR_ENABLED', false),
        'stale_ttl' => (int) env('RAG_CACHE_STALE_TTL', 86400),
    ],

    // --- 負荷試験用フェイクの固定レイテンシ(ミリ秒)---
    'fake' => [
        'llm_latency_ms' => (int) env('FAKE_LLM_LATENCY_MS', 2000),
        'embedding_latency_ms' => (int) env('FAKE_EMBEDDING_LATENCY_MS', 100),
    ],

    // --- 取り込み(Ingest)---
    'ingest' => [
        // 楽観ロック(ADR-005)。負荷試験 S4 で「対策なし」を再現するため false 化できる。
        'optimistic_lock_enabled' => (bool) env('RAG_OPTIMISTIC_LOCK_ENABLED', true),
        // チャンク分割の閾値(トークン)。ADR-002。
        'chunk_max_tokens' => (int) env('RAG_CHUNK_MAX_TOKENS', 500),
        'chunk_overlap_tokens' => (int) env('RAG_CHUNK_OVERLAP_TOKENS', 100),
    ],

    // 定型の no_answer 文言(評価ハーネスの no_answer 判定でも参照する)。
    'no_answer_message' => '該当する社内文書が見つかりませんでした。推測での回答は行いません。',
];
