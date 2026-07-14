# ADR-006: 会話セッション用テーブル名 `sessions` を優先し、HTTPセッションをRedisへ移す

- Status: Accepted
- Date: 2026-07

## Context

DB設計(docs/04)では、会話履歴を保持するドメインエンティティのテーブル名を `sessions` としている。
一方 Laravel 11 は、HTTPセッションを DB に保存する場合(`SESSION_DRIVER=database`)、
同名の `sessions` テーブルを標準マイグレーションで作成する。両者はスキーマが全く異なるため、
同一DB内で名前が衝突する。

## Decision

設計docのドメインテーブル名 `sessions`(会話セッション)を正とし、
フレームワークのHTTPセッションストアを Redis に変更する(`SESSION_DRIVER=redis`)。

- 標準マイグレーション `create_users_table` から `sessions` テーブル作成ブロックを削除する
- ドメインモデルは `App\Models\ChatSession`(`$table = 'sessions'`)とし、
  `Illuminate\Support\Facades\Session` ファサードとのクラス名混同を避ける
- API パス(`/api/sessions/{id}/messages`)は設計docのまま変更しない

## Rationale

1. **設計docの尊重**: ER図・テーブル定義に現れる `sessions` を実装で維持する方が、
   ドキュメントとコードの対応が明快になる
2. **Redisは既に依存にある**: キャッシュ・キューで Redis を使うため、HTTPセッションを
   Redis に載せても新規コンポーネントは増えない。むしろ複数アプリインスタンス構成で
   セッション共有が容易になる(本番想定のECS Fargate構成に整合)
3. **代替案との比較**: 「ドメインテーブルを `chat_sessions` にリネーム」する案もあったが、
   設計docからの乖離を生むため不採用。フレームワーク側を寄せる方が影響が局所的

## Consequences

- (+) 設計docとスキーマが一致、本番マルチインスタンスでのセッション共有が容易
- (−) HTTPセッションがRedis障害の影響を受ける → 認証はSPA Cookie(Sanctum)であり、
  劣化時の影響範囲はログイン継続のみ。RAG検索・回答は劣化運転(docs/02 §5)で継続可能
