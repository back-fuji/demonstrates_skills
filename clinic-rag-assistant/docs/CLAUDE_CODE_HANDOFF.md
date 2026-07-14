# Claude Code 実装指示書(CLAUDE_CODE_HANDOFF)

このドキュメントは、docs/01〜06 と adr/ を前提に、Claude Codeへ実装を依頼するための指示書です。
実装セッション開始時に「docs/ 配下を全て読んでから着手すること」と指示してください。

## 前提

- リポジトリ: back-fuji/demonstrates_skills / 作業ディレクトリ: clinic-rag-assistant/
- スタック: PHP 8.3 / Laravel 11 / Vue 3 + TypeScript / PostgreSQL 16 + pgvector / Redis / Docker Compose
- **設計ドキュメントが正**。実装上の都合で設計を変える場合は、必ずADRを追加または更新してから変更すること
- コミットは意味単位で細かく。コミットメッセージは日本語可、prefix(feat/fix/test/docs/chore)を付ける

## Phase 1: RAG本体

### 1-1. 環境構築
- [ ] Docker Compose(app / postgres:16 + pgvector / redis / mailpit不要)
- [ ] Laravel 11新規作成、Sanctum SPA認証、Pest導入
- [ ] .env.example に ANTHROPIC_API_KEY / EMBEDDING_* を定義
- 完了条件: `docker compose up` → `php artisan test` が通る

### 1-2. マイグレーション + モデル
- [ ] docs/04 のスキーマ通りに作成(vector型はDB::statementで)
- [ ] HNSWインデックス、部分インデックス
- 完了条件: migrate成功、モデルのリレーションテスト通過

### 1-3. 取り込みパイプライン
- [ ] ChunkSplitter(純粋クラス): 見出し分割 / 500トークン超の二次分割 / オーバーラップ / section_path生成。**単体テスト厚め**(空文書・見出しなし・深いネスト・巨大セクション)
- [ ] EmbeddingClientInterface + 実装 + Fake(テスト用)
- [ ] IngestDocumentJob: docs/02のフロー通り。指数バックオフ、失敗時status=failed、version整合チェック(ADR-005)
- [ ] Documents CRUD API(docs/03)。PUTはIf-Match必須、409応答
- 完了条件: シード文書30本が取り込める、S4相当のFeatureテスト(並列更新で不整合ゼロ)通過

### 1-4. シードデータ(ダミーコーパス)
- [ ] database/seeders/corpus/ に架空クリニックのMarkdown文書30本を生成して配置
  - 内訳は docs/01 §7 の通り。各文書冒頭に「本文書はポートフォリオ用の架空の内容です」と明記
  - 施術情報(料金・ダウンタイム・禁忌)は文書間で矛盾しないよう、まず`corpus_facts.yaml`(料金表・施術一覧のマスタ)を作り、そこから各文書を書くこと
  - **医学的に正確である必要はないが、危険な誤情報(実在の薬剤の誤用法など)は書かない。**架空の施術名・架空の薬剤名を使ってよい
- 完了条件: `php artisan migrate --seed` で全文書がcompletedになる

### 1-5. 検索・回答API
- [ ] SearchService: docs/02 §2.1のフロー。類似度しきい値0.5、no_answer分岐
- [ ] AnswerGenerator: システムプロンプトは `resources/prompts/answer_v1.md` として外部ファイル管理(評価ハーネスでprompt_versionを記録するため)。「コンテキスト外は答えない」「引用元を[文書名 > セクション]形式で明示」を強制
- [ ] SSEストリーミング(sources先行送信 → token → done)
- [ ] AnswerCache: **Phase 1では単純TTLのみ**(SWRはPhase 3のStep 2で導入し、before/afterを計測するため。先に入れないこと)
- [ ] sessions / messages / message_sources / feedback API
- 完了条件: cURLで質問→引用付き回答が返る。no_answerケース動作確認

### 1-6. フロントエンド(最小限)
- [ ] チャットUI(質問入力 / ストリーミング表示 / 引用元カード / フィードバックボタン)
- [ ] 管理画面(文書一覧・アップロード・ステータス表示)
- デザインは簡素でよいが、READMEに載せるスクショに耐える程度に整える
- 完了条件: ブラウザで一連の操作が完結、スクショ撮影してREADMEに追加

## Phase 2: 評価ハーネス

- [ ] eval_cases / eval_runs / eval_results マイグレーション
- [ ] YAMLケース定義30件(docs/05 §3。corpus_facts.yamlと整合させる)+ `eval:seed`
- [ ] `eval:run`コマンド: 層1(Recall@k) / 層2(LLM-as-a-Judge、`--only=retrieval`でスキップ)
- [ ] Judgeプロンプト `resources/prompts/judge_v1.md`、JSON出力強制、パース失敗時リトライ1回
- [ ] Markdownレポート出力(前回run差分付き、docs/05 §4の形式)
- [ ] GitHub Actions: PR時 retrieval-only / main時フル(ANTHROPIC_API_KEYはsecrets、未設定ならスキップして緑にする)
- 完了条件: レポートが生成され、意図的にプロンプトを壊すとデグレが検出される(この実験もdocsに記録すると良い)

## Phase 3: 負荷試験と性能改善

docs/06 の Step 0→4 を**順番に**実施。各Stepで計測→コミット→結果を docs/06 の表に追記、を繰り返す。

- [ ] Step 0: k6シナリオS1〜S4作成(`load/`ディレクトリ)。LLM/embeddingをフェイク(固定レイテンシ2s/0.1s)に差し替えるenvフラグ。ベースライン計測
- [ ] Step 1: 単純TTLキャッシュの効果計測(Phase 1-5で実装済みのものを計測)
- [ ] Step 2: SWR+ロック実装(ADR-004)→ S3でLLM呼び出し収束を実証
- [ ] Step 3: 合成1万チャンク生成 → `bench:vector`でHNSW/IVFFlat/なしを比較 → ADR-003に結果追記
- [ ] Step 4: S4再計測(楽観ロックはPhase 1実装済み。対策なし版はフィーチャーフラグで再現)
- [ ] 最終: before/after表とグラフをREADMEへ

## 実装時の注意

- 外部API(Anthropic)をテストで実呼び出ししない。Fakeを使う。評価ハーネスのみ実呼び出し(コマンド実行時)
- N+1、無トランザクションの複数書き込みを作らない
- 各Phaseの完了時にREADMEの進捗表を更新すること
