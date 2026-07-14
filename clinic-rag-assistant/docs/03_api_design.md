# 03. API設計

## 共通仕様

- ベースパス: `/api`
- 認証: Laravel Sanctum(SPA認証)。全エンドポイント認証必須
- エラー形式: RFC 7807風のJSON(`{"type", "title", "status", "detail"}`)
- レート制限: 検索系 30req/min/user、管理系 60req/min/user

## 1. 検索・回答

### POST /api/search
質問を送信し、回答をSSEストリーミングで受け取る。

Request:
```json
{
  "question": "医療脱毛の当日キャンセル料はいくらですか?",
  "session_id": "01J...(任意。フォローアップ質問時に指定)"
}
```

Response(SSE):
```
event: sources
data: {"chunks": [{"chunk_id": 123, "document_title": "予約・キャンセルポリシー", "section": "当日キャンセル", "score": 0.87}, ...]}

event: token
data: {"text": "当日キャンセルの場合、"}

event: token
data: {"text": "施術料金の50%を..."}

event: done
data: {"answer_id": "01J...", "cached": false, "latency_ms": 2140}
```

設計意図:
- `sources`イベントを**回答生成前に先行送信**し、UIは引用元を即座に表示できる(体感速度)
- `cached: true`の場合はストリーミングせず一括返却
- 根拠なし(類似度しきい値未達)の場合は `event: no_answer` を返し、生成をスキップ(コスト削減+誤答防止)

### GET /api/sessions/{id}/messages
セッション内の会話履歴を取得(フォローアップ用のUI表示)。

### POST /api/answers/{id}/feedback
回答への👍/👎フィードバック。評価ハーネスのゴールデンセット候補収集にも使う。

```json
{ "rating": "bad", "comment": "キャンセル料の割合が古い情報だった" }
```

## 2. 文書管理(管理者ロールのみ)

### POST /api/documents
```json
{
  "title": "予約・キャンセルポリシー",
  "category": "policy",
  "content": "# 予約・キャンセルポリシー\n..."
}
```
→ `202 Accepted` + `{"document_id": 1, "status": "processing"}`(非同期取り込み)

### PUT /api/documents/{id}
文書更新。旧チャンクを無効化し再取り込み。**If-Matchヘッダでversionを指定**(楽観ロック、ADR-005)。version不一致は`409 Conflict`。

### DELETE /api/documents/{id}
論理削除。チャンクは検索対象から即時除外。

### GET /api/documents / GET /api/documents/{id}
一覧・詳細(取り込みステータス含む)。

## 3. 評価・運用(開発者向け、artisanコマンド中心)

| コマンド | 内容 |
|---|---|
| `php artisan eval:run` | ゴールデンセット全件で回帰テスト実行、レポート出力 |
| `php artisan eval:run --only=retrieval` | 検索精度(Recall@k)のみ計測(LLM呼び出しなし・高速) |
| `php artisan ingest:retry {document_id}` | 失敗した取り込みの再実行 |
| `php artisan bench:vector --index=hnsw\|ivfflat` | pgvectorインデックスのベンチマーク(Phase 3) |

## 4. ステータスコード方針

| 状況 | コード |
|---|---|
| 検索成功(根拠なし含む) | 200 |
| 文書取り込み受付 | 202 |
| バリデーションエラー | 422 |
| 楽観ロック競合 | 409 |
| レート制限超過 | 429(Retry-Afterヘッダ付与) |
| 上流API(Claude)障害 | 503(劣化運転時は200+検索結果のみ) |
