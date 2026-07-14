# clinic-rag-assistant

架空の美容クリニック「Lumière Clinic(リュミエールクリニック)」の社内スタッフ向けナレッジ検索システム。
施術マニュアル・予約/キャンセルポリシー・術後ケアFAQ・接客対応マニュアルなどの社内文書を対象に、
RAG(Retrieval-Augmented Generation)で根拠付きの回答を返します。

> **スクリーンショット / デモGIF**(実装完了後にここへ追加)

## なぜこの題材か

- 医療・美容ドメインでの実務経験を活かし、実在しうる業務課題(新人スタッフの問い合わせ対応コスト、マニュアルの検索性の低さ)を解決するシステムとして設計
- 「回答の根拠となる文書を必ず提示する」「わからないことは推測せず答えない」という、**誤情報が許されないドメインでのAI活用の型**を実装で示す

## 3つの証明ポイント

### 1. RAGを実務レベルで構築できる
- pgvectorによるベクトル検索 + Claude APIによる回答生成
- チャンク分割戦略・メタデータ設計・引用元の提示までを含む一連の実装
- 詳細: [docs/02_architecture.md](./docs/02_architecture.md), [docs/04_db_design.md](./docs/04_db_design.md)

### 2. LLMの回答品質を継続的に担保できる(評価ハーネス)
- ゴールデンデータセット(質問と期待回答のペア)に対する回帰テスト
- 検索精度(Recall@k)と回答品質(LLM-as-a-Judge)の自動スコアリング
- プロンプト変更・モデル更新時のデグレ検知をCIで実行
- 詳細: [docs/05_eval_harness.md](./docs/05_eval_harness.md)

### 3. 性能を計測し、数字で改善を示せる(負荷試験)
- k6によるスパイクシナリオでベースライン計測(rps / p95 / p99)
- Redisキャッシュ(stale-while-revalidate)によるスタンピード対策
- pgvectorインデックス(HNSW vs IVFFlat)のベンチマーク比較
- 文書取り込みジョブの同時実行制御(楽観ロック)
- 詳細: [docs/06_load_testing.md](./docs/06_load_testing.md)

## ドキュメント構成

| ドキュメント | 内容 |
|---|---|
| [01_requirements.md](./docs/01_requirements.md) | 要件定義(想定顧客・ユースケース・非機能要件) |
| [02_architecture.md](./docs/02_architecture.md) | システム構成・処理フロー |
| [03_api_design.md](./docs/03_api_design.md) | APIエンドポイント設計 |
| [04_db_design.md](./docs/04_db_design.md) | DBスキーマ・チャンク戦略 |
| [05_eval_harness.md](./docs/05_eval_harness.md) | 評価ハーネス設計 |
| [06_load_testing.md](./docs/06_load_testing.md) | 負荷試験計画とチューニング方針 |
| [docs/adr/](./docs/adr/) | 設計判断の記録(Architecture Decision Records) |
| [CLAUDE_CODE_HANDOFF.md](./docs/CLAUDE_CODE_HANDOFF.md) | Claude Code向け実装指示書 |

## 実装フェーズ

| Phase | 内容 | 状態 |
|---|---|---|
| Phase 0 | 設計ドキュメント一式 | ✅ 完了(本リポジトリ) |
| Phase 1 | RAG本体(文書取り込み・検索・回答生成・UI) | 🔲 実装中 |
| Phase 2 | 評価ハーネス(ゴールデンセット・回帰テスト・CI) | 🔲 未着手 |
| Phase 3 | 負荷試験と性能改善(k6・キャッシュ・インデックス比較) | 🔲 未着手 |

## ローカル起動(Phase 1完了後)

```bash
docker compose up -d          # PostgreSQL(pgvector) + Redis + アプリ
cp .env.example .env          # ANTHROPIC_API_KEY を設定
php artisan migrate --seed    # ダミー文書の取り込み含む
npm run dev
```
