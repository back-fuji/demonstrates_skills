#!/bin/sh
set -e

# 役割の切替: 環境変数 CONTAINER_ROLE を優先(Railwayでworkerサービスに設定)、無ければ第1引数、既定web
role="${CONTAINER_ROLE:-${1:-web}}"

if [ "$role" = "worker" ]; then
	echo "[entrypoint] starting queue worker"
	exec php artisan queue:work --tries=3 --timeout=120
fi

# --- web ---
# マイグレーション(本番)。複数インスタンス構成では別途one-off実行を推奨。
echo "[entrypoint] running migrations"
php artisan migrate --force || echo "[entrypoint] migrate skipped/failed (continuing)"

# 設定キャッシュ(route:cache は SPA catch-all のクロージャ回避のため行わない)
php artisan config:cache || true

echo "[entrypoint] starting FrankenPHP on :${PORT:-8080}"
exec frankenphp run --config /app/docker/Caddyfile
