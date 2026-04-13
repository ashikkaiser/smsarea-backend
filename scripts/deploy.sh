#!/usr/bin/env bash
# Deploy Laravel app to a remote server over SSH (rsync + composer + artisan).
#
# Prerequisites:
#   - SSH access (e.g. Host "2tul" in ~/.ssh/config)
#   - Local file .env.prod at project root (copy from .env.prod.example)
#   - Remote: PHP 8.3+, composer in PATH, writable storage/bootstrap/cache
#
# Usage:
#   ./scripts/deploy.sh
#   DEPLOY_TARGET=user@server DEPLOY_PATH=/var/www/app ./scripts/deploy.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT"

# SSH target: host alias from ~/.ssh/config or user@host
DEPLOY_TARGET="${DEPLOY_TARGET:-2tul}"
# Remote app root (no trailing slash). Use e.g. /var/www/app/2tul if the site lives in a subfolder.
DEPLOY_PATH="${DEPLOY_PATH:-/var/www/app}"
# Composer: set to php path if needed, e.g. DEPLOY_PHP=/usr/bin/php8.3
DEPLOY_PHP="${DEPLOY_PHP:-php}"
DEPLOY_COMPOSER="${DEPLOY_COMPOSER:-composer}"

ENV_PROD_LOCAL="$ROOT/.env.prod"
ENV_PROD_REMOTE="$DEPLOY_PATH/.env.prod"

if [[ ! -f "$ENV_PROD_LOCAL" ]]; then
  echo "error: missing $ENV_PROD_LOCAL"
  echo "  Copy .env.prod.example to .env.prod and fill production values."
  exit 1
fi

echo "==> Deploy target: $DEPLOY_TARGET:$DEPLOY_PATH"
echo "==> Rsync project (excluding vendor, .git, node_modules, local env, caches)…"

rsync -avz --delete \
  --human-readable \
  --exclude 'vendor/' \
  --exclude '.git/' \
  --exclude 'node_modules/' \
  --exclude '.env' \
  --exclude '.env.backup' \
  --exclude '.env.production' \
  --exclude '.env.prod' \
  --exclude '.phpunit.result.cache' \
  --exclude 'storage/app/' \
  --exclude 'storage/logs/' \
  --exclude 'storage/framework/cache/data/' \
  --exclude 'storage/framework/sessions/' \
  --exclude 'storage/framework/views/' \
  --exclude 'bootstrap/cache/*.php' \
  --exclude 'public/hot' \
  --exclude 'public/build/' \
  ./ "${DEPLOY_TARGET}:${DEPLOY_PATH}/"

echo "==> Upload .env.prod → remote .env.prod"
scp -q "$ENV_PROD_LOCAL" "${DEPLOY_TARGET}:${ENV_PROD_REMOTE}"

echo "==> Remote: composer, env, migrations, optimize…"
ssh -o BatchMode=yes "$DEPLOY_TARGET" bash -s -- "$DEPLOY_PATH" "$DEPLOY_PHP" "$DEPLOY_COMPOSER" <<'REMOTE'
set -euo pipefail
DEPLOY_PATH="$1"
DEPLOY_PHP="$2"
DEPLOY_COMPOSER="$3"
cd "$DEPLOY_PATH"

if [[ ! -f artisan ]]; then
  echo "error: artisan not found in $DEPLOY_PATH"
  exit 1
fi

echo "    [remote] composer install --no-dev --optimize-autoloader --no-interaction"
$DEPLOY_COMPOSER install --no-dev --optimize-autoloader --no-interaction

echo "    [remote] activate .env from .env.prod"
if [[ ! -f .env.prod ]]; then
  echo "error: .env.prod missing on server after upload"
  exit 1
fi
mv -f .env.prod .env

echo "    [remote] ensure storage permissions"
mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache || true

echo "    [remote] php artisan storage:link (ignore if exists)"
$DEPLOY_PHP artisan storage:link 2>/dev/null || true

# Migrations must run before optimize:clear when CACHE_STORE/SESSION_DRIVER use database,
# otherwise clearing cache touches the `cache` table before it exists.
echo "    [remote] php artisan migrate --force"
$DEPLOY_PHP artisan migrate --force

echo "    [remote] php artisan optimize:clear"
$DEPLOY_PHP artisan optimize:clear

echo "    [remote] php artisan optimize"
$DEPLOY_PHP artisan optimize

echo "    [remote] php artisan queue:restart (ignore if no queue worker)"
$DEPLOY_PHP artisan queue:restart 2>/dev/null || true

echo "    [remote] done."
REMOTE

echo "==> Deploy finished."
