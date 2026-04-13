#!/usr/bin/env bash
# Deploy Laravel app to a remote server over SSH.
# The server checks out the full tree from Git (no rsync of app code from your laptop).
#
# Prerequisites:
#   - SSH access (e.g. Host "2tul" in ~/.ssh/config)
#   - Remote: DEPLOY_PATH is already a clone of the repo, OR set DEPLOY_GIT_URL for first-time clone
#     (first-time: directory must exist and be empty before deploy, or only contain a previous .git)
#   - Local file .env.prod at project root (copy from .env.prod.example)
#   - Remote: PHP 8.3+, composer in PATH, writable storage/bootstrap/cache
#   - After deploy, storage/ and bootstrap/cache/ are chown'd to www-data:www-data (override with DEPLOY_WEB_USER / DEPLOY_WEB_GROUP; use DEPLOY_SUDO_CHOWN=1 if needed).
#
# Usage:
#   ./scripts/deploy.sh
#   DEPLOY_TARGET=user@server DEPLOY_PATH=/var/www/app ./scripts/deploy.sh
#   DEPLOY_GIT_BRANCH=main DEPLOY_GIT_REMOTE=origin ./scripts/deploy.sh
# First-time (empty directory on server):
#   DEPLOY_GIT_URL=git@github.com:ashikkaiser/smsarea-backend.git ./scripts/deploy.sh
#   DEPLOY_GIT_BRANCH=master   # if the remote uses master instead of main
#   DEPLOY_SUDO_CHOWN=1        # if chown must use sudo on the server
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT"

# SSH target: host alias from ~/.ssh/config or user@host
DEPLOY_TARGET="${DEPLOY_TARGET:-2tul}"
# Remote app root (no trailing slash).
DEPLOY_PATH="${DEPLOY_PATH:-/var/www/app}"
# Git: branch and remote to deploy (full reset to match remote)
DEPLOY_GIT_BRANCH="${DEPLOY_GIT_BRANCH:-main}"
DEPLOY_GIT_REMOTE="${DEPLOY_GIT_REMOTE:-origin}"
# Optional: clone URL when DEPLOY_PATH has no .git (first deploy only)
DEPLOY_GIT_URL="${DEPLOY_GIT_URL:-}"
# Composer: set to php path if needed, e.g. DEPLOY_PHP=/usr/bin/php8.3
DEPLOY_PHP="${DEPLOY_PHP:-php}"
DEPLOY_COMPOSER="${DEPLOY_COMPOSER:-composer}"
# Writable dirs for PHP-FPM / Apache (Debian/Ubuntu default: www-data)
DEPLOY_WEB_USER="${DEPLOY_WEB_USER:-www-data}"
DEPLOY_WEB_GROUP="${DEPLOY_WEB_GROUP:-www-data}"
# Set to 1 if the SSH user must use sudo to chown (e.g. deploy is not root).
DEPLOY_SUDO_CHOWN="${DEPLOY_SUDO_CHOWN:-0}"

ENV_PROD_LOCAL="$ROOT/.env.prod"

if [[ ! -f "$ENV_PROD_LOCAL" ]]; then
  echo "error: missing $ENV_PROD_LOCAL"
  echo "  Copy .env.prod.example to .env.prod and fill production values."
  exit 1
fi

REMOTE_ENV_STAGING="/tmp/2tul-deploy-env-prod.$$.$RANDOM"

echo "==> Deploy target: $DEPLOY_TARGET:$DEPLOY_PATH"
echo "==> Git: ${DEPLOY_GIT_REMOTE}/${DEPLOY_GIT_BRANCH} (full reset on server)"

echo "==> Upload .env.prod → $DEPLOY_TARGET:$REMOTE_ENV_STAGING"
scp -q "$ENV_PROD_LOCAL" "${DEPLOY_TARGET}:${REMOTE_ENV_STAGING}"

echo "==> Remote: git, move env, composer, migrations, optimize…"
# Pass config via remote exports (positional args break when DEPLOY_GIT_URL is empty under set -u).
ssh -o BatchMode=yes "$DEPLOY_TARGET" \
  "export DEPLOY_PATH=$(printf '%q' "$DEPLOY_PATH");"\
" export DEPLOY_PHP=$(printf '%q' "$DEPLOY_PHP");"\
" export DEPLOY_COMPOSER=$(printf '%q' "$DEPLOY_COMPOSER");"\
" export DEPLOY_GIT_BRANCH=$(printf '%q' "$DEPLOY_GIT_BRANCH");"\
" export DEPLOY_GIT_REMOTE=$(printf '%q' "$DEPLOY_GIT_REMOTE");"\
" export DEPLOY_GIT_URL=$(printf '%q' "$DEPLOY_GIT_URL");"\
" export REMOTE_ENV_STAGING=$(printf '%q' "$REMOTE_ENV_STAGING");"\
" export DEPLOY_WEB_USER=$(printf '%q' "$DEPLOY_WEB_USER");"\
" export DEPLOY_WEB_GROUP=$(printf '%q' "$DEPLOY_WEB_GROUP");"\
" export DEPLOY_SUDO_CHOWN=$(printf '%q' "$DEPLOY_SUDO_CHOWN");"\
' exec bash -s' <<'REMOTE'
set -euo pipefail

cleanup_env_staging() {
  rm -f "$REMOTE_ENV_STAGING" 2>/dev/null || true
}
trap cleanup_env_staging EXIT

if [[ ! -d "$DEPLOY_PATH" ]]; then
  echo "error: remote directory does not exist: $DEPLOY_PATH (create it on the server first)"
  exit 1
fi

cd "$DEPLOY_PATH"

if [[ ! -d .git ]]; then
  if [[ -z "$DEPLOY_GIT_URL" ]]; then
    echo "error: $DEPLOY_PATH is not a git clone (.git missing)."
    echo "  Set DEPLOY_GIT_URL once, e.g.: DEPLOY_GIT_URL=git@github.com:ashikkaiser/smsarea-backend.git ./scripts/deploy.sh"
    exit 1
  fi
  shopt -s dotglob nullglob
  # shellcheck disable=SC2206
  existing=( * )
  shopt -u dotglob nullglob
  if [[ ${#existing[@]} -gt 0 ]]; then
    echo "error: $DEPLOY_PATH is not empty and has no .git; use an empty directory for first clone."
    exit 1
  fi
  echo "    [remote] git clone --branch $DEPLOY_GIT_BRANCH $DEPLOY_GIT_URL ."
  git clone --branch "$DEPLOY_GIT_BRANCH" --single-branch "$DEPLOY_GIT_URL" .
else
  echo "    [remote] git fetch $DEPLOY_GIT_REMOTE"
  git fetch "$DEPLOY_GIT_REMOTE"
  echo "    [remote] git checkout $DEPLOY_GIT_BRANCH"
  git checkout "$DEPLOY_GIT_BRANCH"
  echo "    [remote] git reset --hard ${DEPLOY_GIT_REMOTE}/${DEPLOY_GIT_BRANCH}"
  git reset --hard "${DEPLOY_GIT_REMOTE}/${DEPLOY_GIT_BRANCH}"
fi

if [[ ! -f "$REMOTE_ENV_STAGING" ]]; then
  echo "error: staged .env.prod not found on server at $REMOTE_ENV_STAGING"
  exit 1
fi
mv -f "$REMOTE_ENV_STAGING" .env.prod
trap - EXIT

if [[ ! -f artisan ]]; then
  echo "error: artisan not found in $DEPLOY_PATH after git update"
  exit 1
fi

echo "    [remote] composer install --no-dev --optimize-autoloader --no-interaction"
$DEPLOY_COMPOSER install --no-dev --optimize-autoloader --no-interaction

echo "    [remote] activate .env from .env.prod"
if [[ ! -f .env.prod ]]; then
  echo "error: .env.prod missing in $DEPLOY_PATH after move"
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

echo "    [remote] chown ${DEPLOY_WEB_USER}:${DEPLOY_WEB_GROUP} on storage and bootstrap/cache"
if id "$DEPLOY_WEB_USER" >/dev/null 2>&1; then
  if [[ "${DEPLOY_SUDO_CHOWN:-0}" == "1" ]]; then
    sudo chown -R "$DEPLOY_WEB_USER:$DEPLOY_WEB_GROUP" storage bootstrap/cache || echo "    [remote] warning: sudo chown failed (check sudoers)."
  else
    chown -R "$DEPLOY_WEB_USER:$DEPLOY_WEB_GROUP" storage bootstrap/cache || echo "    [remote] warning: chown failed; try DEPLOY_SUDO_CHOWN=1 or run as root."
  fi
else
  echo "    [remote] warning: user '$DEPLOY_WEB_USER' not found, skipping chown."
fi

echo "    [remote] done."
REMOTE

echo "==> Deploy finished."
