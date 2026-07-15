# Railway デプロイ手順

clinic-rag-assistant を Railway に公開URLとしてデプロイする手順です。
本番は開発用の `php artisan serve` ではなく **FrankenPHP**(`docker/Dockerfile.production`)で配信し、
フロント(Vite)ビルドを同梱、pgvector と Redis はサービスとして用意します。

構成(Railway プロジェクト内の4サービス):

```
┌─ web (FrankenPHP)   … docker/Dockerfile.production、公開ドメイン付き
├─ worker             … 同じイメージ、CONTAINER_ROLE=worker(キュー処理)
├─ postgres (pgvector)… pgvector/pgvector:pg16 イメージ + Volume
└─ redis              … Railway の Redis
```

> LLM/embedding はGPU不要な **実API(Claude + Voyage)** を使う。Ollama はGPUホストが要るため公開向きではない。

---

## 0. 事前準備

- Railway アカウント(https://railway.app)
- GitHub リポジトリ連携(このリポジトリ)
- APIキー: `ANTHROPIC_API_KEY`(platform.claude.com)/ `EMBEDDING_API_KEY`(voyageai.com)
- `APP_KEY` をローカルで生成しておく:
  ```bash
  docker compose run --rm app php artisan key:generate --show
  # 例: base64:xxxxxxxx... をコピー
  ```

Railway CLI(任意・シード投入で使用):
```bash
npm i -g @railway/cli   # または brew install railway
railway login
```

---

## 1. プロジェクト作成

Railway ダッシュボードで **New Project → Empty Project**。

---

## 2. PostgreSQL(pgvector)サービス

Railway 標準の Postgres は pgvector が入らない場合があるため、**pgvector 公式イメージ**で建てる:

1. **New → Deploy from Docker Image** に `pgvector/pgvector:pg16` を指定
2. **Variables** に設定:
   ```
   POSTGRES_DB=clinic_rag
   POSTGRES_USER=clinic
   POSTGRES_PASSWORD=（強力なパスワード）
   PGDATA=/var/lib/postgresql/data/pgdata
   ```
3. **Settings → Volumes** で Volume を追加しマウント先を `/var/lib/postgresql/data` に
4. デプロイ後、このサービスの **private domain**(例 `postgres.railway.internal`)を控える

> 代替: 外部の **Supabase**(pgvector標準・無料枠)をDBに使う場合は、その接続情報を後述のDB_*に入れるだけ。

---

## 3. Redis サービス

**New → Database → Add Redis**。Railway が `REDIS_URL` 等を提供する。

---

## 4. web サービス(アプリ本体)

1. **New → GitHub Repo** でこのリポジトリを選択
2. **Settings** で:
   - **Root Directory**: `clinic-rag-assistant`(モノレポのため)
   - **Build**: Dockerfile を使用 → **Dockerfile Path**: `docker/Dockerfile.production`
3. **Settings → Networking → Generate Domain** で公開URLを発行(例 `clinic-rag-production.up.railway.app`)
4. **Variables** に以下を設定(`${{...}}` は Railway の変数参照):

   ```bash
   APP_KEY=base64:...            # 手順0で生成した値
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://<発行された公開ドメイン>
   APP_LOCALE=ja
   APP_FALLBACK_LOCALE=en

   # DB(手順2の pgvector サービス)
   DB_CONNECTION=pgsql
   DB_HOST=${{Postgres.RAILWAY_PRIVATE_DOMAIN}}   # サービス名に合わせて調整
   DB_PORT=5432
   DB_DATABASE=clinic_rag
   DB_USERNAME=clinic
   DB_PASSWORD=${{Postgres.POSTGRES_PASSWORD}}

   # Redis(手順3)
   REDIS_CLIENT=predis
   REDIS_URL=${{Redis.REDIS_URL}}
   CACHE_STORE=redis
   SESSION_DRIVER=redis
   QUEUE_CONNECTION=redis

   # SPA(Sanctum)Cookie 認証 — 公開ドメインを指定(プロトコルなし)
   SANCTUM_STATEFUL_DOMAINS=<公開ドメイン>
   SESSION_DOMAIN=<公開ドメイン>
   FRONTEND_URL=https://<公開ドメイン>

   # LLM / embedding(実API)
   LLM_DRIVER=anthropic
   ANTHROPIC_API_KEY=sk-ant-...
   ANTHROPIC_MODEL=claude-haiku-4-5      # 低コスト。品質重視なら claude-sonnet-5
   EMBEDDING_DRIVER=voyage
   EMBEDDING_API_KEY=pa-...
   EMBEDDING_MODEL=voyage-3
   EMBEDDING_DIMENSIONS=1024

   # キャッシュ戦略(任意)
   RAG_CACHE_SWR_ENABLED=true
   ```

5. デプロイ実行。起動時に **自動でマイグレーションが走る**(`docker/railway-entrypoint.sh`)。

> 注: `REDIS_URL` を使う場合は Laravel が自動解釈する。個別指定したい場合は
> `REDIS_HOST=${{Redis.RAILWAY_PRIVATE_DOMAIN}}` `REDIS_PORT=6379` `REDIS_PASSWORD=${{Redis.REDIS_PASSWORD}}` を設定。

---

## 5. worker サービス(キュー処理)

取り込みジョブ・SWR再生成ジョブを処理する常駐ワーカー。

1. **New → GitHub Repo**(同じリポジトリ)、Root Directory / Dockerfile Path は web と同じ
2. **Variables**: web と同じ変数を設定し、**さらに** 次を追加:
   ```
   CONTAINER_ROLE=worker
   ```
   → エントリポイントが `php artisan queue:work` を起動する(web判定を上書き)
3. 公開ドメインは不要(Generate Domain しない)

> 変数を共有したい場合は Railway の **Shared Variables** を使うと重複設定を避けられる。

---

## 6. 初回シード(ダミー文書30本 + 評価ケース)

マイグレーションは自動だが、シードは手動。**Railway CLI の SSH** で web サービス内で実行:

```bash
railway ssh --service web
# コンテナ内で:
php artisan db:seed --force        # 管理者/スタッフ + ダミー文書30本を取り込み(embedding生成)
php artisan eval:seed              # 評価ケース30件(任意)
exit
```

> `railway ssh` が使えない場合は、web サービスの Start Command を一時的に
> `sh -c "php artisan migrate --force && php artisan db:seed --force && sh docker/railway-entrypoint.sh"`
> に変更して1回デプロイ→元に戻す、でも可。
>
> シードは embedding API(Voyage)を叩くため、`EMBEDDING_API_KEY` が有効である必要がある。

---

## 7. 動作確認

1. `https://<公開ドメイン>/up` が 200(ヘルスチェック)
2. `https://<公開ドメイン>/` で SPA 表示
3. ログイン: `admin@example.com` / `staff@example.com`(パスワード `password`)
   - **公開前に必ずデモアカウントのパスワードを変更**すること(`php artisan tinker` 等)
4. チャットで質問 → 引用付き回答が返る

---

## 運用メモ / 注意

- `APP_ENV=production` により負荷試験用 `/_loadtest` エンドポイントは**自動的に無効化**される。
- 公開する場合はレート制限(実装済み: 検索30/分・管理60/分)に加え、必要ならIP制限や認証必須の維持を検討。
- **web を複数インスタンスにスケール**する場合、起動時マイグレーションが競合しうる。その場合は
  マイグレーションを worker 側 or 手動 one-off に寄せ、web はマイグレーションをスキップする運用にする。
- CI(`.github/workflows/ci.yml`)の Secrets に `ANTHROPIC_API_KEY` / `EMBEDDING_API_KEY` を登録すると
  フル評価が有効化される(未登録ならスキップして緑)。
- コスト最小化: `ANTHROPIC_MODEL=claude-haiku-4-5` + 回答キャッシュ(既定ON)+ Voyage無料枠。

---

## 別案

- **Render / Fly.io** でもほぼ同手順(Dockerfile.production を利用、pgvector対応DB + Redis + worker)。
- 設計docに沿った本番は **AWS ECS Fargate + RDS(pgvector)+ ElastiCache + CloudFront/S3**(docs/02 §4)。
