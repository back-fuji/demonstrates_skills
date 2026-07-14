# 02. アーキテクチャ設計

## 1. 全体構成

```
┌──────────┐     ┌─────────────────────────────────────────┐
│  Vue 3   │     │              Laravel 11                  │
│  (SPA)   │────▶│                                          │
└──────────┘     │  ┌─────────────┐   ┌──────────────────┐ │
                 │  │ Search API   │──▶│ Redis            │ │
                 │  │ (同期+SSE)   │   │ ・回答キャッシュ  │ │
                 │  └──────┬──────┘   │ ・SWRロック      │ │
                 │         │           └──────────────────┘ │
                 │         ▼                                 │
                 │  ┌─────────────┐   ┌──────────────────┐ │
                 │  │ RAG Service  │──▶│ PostgreSQL 16    │ │
                 │  │              │   │ + pgvector       │ │
                 │  └──────┬──────┘   │ (HNSW index)     │ │
                 │         │           └──────────────────┘ │
                 │         ▼                                 │
                 │  ┌─────────────┐                          │
                 │  │ Claude API   │ (embedding / 回答生成)  │
                 │  └─────────────┘                          │
                 │                                           │
                 │  ┌─────────────┐                          │
                 │  │ Ingest Job   │ (キュー / 文書取り込み) │
                 │  └─────────────┘                          │
                 └─────────────────────────────────────────┘
```

## 2. 処理フロー

### 2.1 検索・回答フロー(オンライン)

1. スタッフが質問を送信(`POST /api/search`)
2. **キャッシュ確認**: 質問の正規化キーでRedisを確認。ヒットすれば即返却(stale-while-revalidate: 期限切れでもstale値を返し、裏で再生成)
3. **クエリembedding生成**: 質問文をembedding APIでベクトル化
4. **ベクトル検索**: pgvector(HNSW)でコサイン類似度上位k件(k=8)のチャンクを取得。類似度しきい値(0.5)未満は除外
5. **リランキング(Phase 3拡張)**: 上位候補をスコアで並べ替え、上位5件に絞る
6. **回答生成**: 取得チャンクをコンテキストとしてClaude Messages APIへ。システムプロンプトで「コンテキストにない情報は答えない」「引用元を明示する」を強制
7. **ストリーミング返却**: SSEでフロントへ逐次配信(体感レイテンシの改善)
8. **キャッシュ保存 + ログ記録**: 回答と引用チャンクIDを保存(評価ハーネスの分析素材にもなる)

### 2.2 文書取り込みフロー(オフライン)

1. 管理者が文書をアップロード(`POST /api/documents`)
2. documentsレコードを`status=processing`で作成し、キューへジョブ投入(APIは即応答)
3. ジョブ内で:
   - Markdownをセクション単位でチャンク分割(詳細は ADR-002)
   - 各チャンクのembeddingをバッチ生成(レート制限対応: 指数バックオフ)
   - チャンクとembeddingをトランザクション内で一括INSERT
   - `status=completed`へ更新(**楽観ロック**: versionカラムで同時更新を検知 → ADR-005)
4. 失敗時は`status=failed`+エラー内容を記録し、リトライ可能にする

## 3. レイヤ構成(Laravel)

```
app/
├── Http/Controllers/Api/     # 薄いコントローラ(バリデーション+サービス呼び出し)
├── Services/
│   ├── Rag/
│   │   ├── SearchService.php        # 検索オーケストレーション
│   │   ├── EmbeddingClient.php      # embedding API(interface + 実装)
│   │   ├── AnswerGenerator.php      # Claude API呼び出し・プロンプト管理
│   │   └── AnswerCache.php          # SWRキャッシュ戦略
│   └── Ingest/
│       ├── ChunkSplitter.php        # チャンク分割ロジック(純粋関数・単体テスト対象)
│       └── DocumentIngestor.php     # 取り込みオーケストレーション
├── Jobs/IngestDocumentJob.php
├── Models/                    # Document, Chunk, SearchLog, Session
└── Console/Commands/          # eval:run(評価ハーネス実行)等
```

設計方針:
- **外部APIはinterfaceで抽象化**(EmbeddingClientInterface / LlmClientInterface)。評価ハーネスや単体テストでフェイク実装に差し替え可能にする
- チャンク分割などのコアロジックはフレームワーク非依存の純粋なクラスとして切り出し、単体テストを厚くする

## 4. 本番想定構成(AWS)

ローカルはDocker Compose。本番デプロイは以下を想定(docsのみ、実デプロイはスコープ外):

- ECS Fargate(Webサービス + キューワーカーを別タスク定義)
- RDS for PostgreSQL(pgvector拡張)
- ElastiCache for Redis
- CloudFront + S3(SPA配信)
- Secrets Manager(ANTHROPIC_API_KEY)

## 5. 障害・劣化運転の考え方

| 障害 | 挙動 |
|---|---|
| Claude API障害/レート制限 | キャッシュヒット分は回答継続。ミス分は「AI回答は一時利用できません。検索結果のみ表示します」として検索結果(チャンク)を素で返す |
| Redis障害 | キャッシュをスキップして直接処理(遅くなるが動く)。circuit breaker的にRedis接続失敗を一定時間記憶 |
| embedding API障害 | 取り込みジョブはリトライキューへ。検索はエラー応答(クエリembedding必須のため) |
