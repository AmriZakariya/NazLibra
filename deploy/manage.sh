#!/bin/bash
# ============================================================================
#  Castl-it-POS — manage an existing client install.
# ----------------------------------------------------------------------------
#  Usage:  ./manage.sh <update|enable|disable> <subdomain>
#
#  update   Re-copy the master code over the client (keeps .env, SQLite DB and
#           storage/), runs migrate --force, clears caches, re-stamps .version.
#  disable  Suspend the client — drop a ".suspended" marker so the .htaccess
#           serves the branded 503 page. Data is untouched.
#  enable   Remove the marker → client is live again.
#
#  Env (set by ManageClientJob): SOURCE_DIR BASE_DIR MAIN_DOMAIN PHP_BIN
#  Emits "▸ …" progress to stderr; the LAST stdout line is one JSON object.
# ============================================================================
set -uo pipefail
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:${PATH:-}"

# Resolve the script dir with pure bash — some hosts' minimal PATH lacks dirname.
LIB_DIR="${BASH_SOURCE[0]%/*}"; [ "$LIB_DIR" = "${BASH_SOURCE[0]}" ] && LIB_DIR="."
source "$LIB_DIR/lib.sh"

# Same reason as provision.sh: don't let the worker's master env leak into the
# client's artisan calls.
unset APP_KEY APP_ENV APP_DEBUG APP_URL APP_NAME CASTLIT_MASTER \
      DB_CONNECTION DB_DATABASE DB_HOST DB_PORT DB_USERNAME DB_PASSWORD \
      SESSION_DRIVER QUEUE_CONNECTION CACHE_STORE MAIL_MAILER 2>/dev/null || true

emit()  { printf '%s\n' "$1" >&2; }
final() { printf '%s\n' "$1"; exit "${2:-0}"; }

: "${MAIN_DOMAIN:=castlitpos.com}"
: "${BASE_DIR:=/home/castlit}"
: "${SOURCE_DIR:=}"
: "${PHP_BIN:=php}"

ACTION="${1:-}"
SUB_NAME="${2:-}"

case "$ACTION" in update|enable|disable|clear-cache) : ;; *)
  final '{"status":"error","step":"input","message":"Action must be update|enable|disable|clear-cache."}' 1 ;;
esac

# Reset the CLIENT's own web-worker OPcache via its signed maintenance endpoint.
# Safe (no pkill): it runs inside the client pool and calls opcache_reset there,
# so freshly copied code is served even with opcache.validate_timestamps=0.
reset_client_opcache() {
  local key ts sig
  key=$(grep -m1 '^APP_KEY=' "$DOC_ROOT/.env" 2>/dev/null | cut -d= -f2- | tr -d "\"' \r")
  [ -z "$key" ] && return 0
  command -v openssl >/dev/null 2>&1 || return 0
  command -v curl    >/dev/null 2>&1 || return 0
  ts=$(date +%s)
  sig=$(printf '%s' "clear-cache|$ts" | openssl dgst -sha256 -hmac "$key" 2>/dev/null | awk '{print $NF}')
  [ -z "$sig" ] && return 0
  curl -s -m 30 -o /dev/null \
    -H "X-Castlit-Timestamp: $ts" -H "X-Castlit-Signature: $sig" \
    --data 'action=clear-cache' "https://$FULL_DOMAIN/__castlit/maintenance" 2>/dev/null || true
}
printf '%s' "$SUB_NAME" | grep -Eq '^[a-z0-9]{2,30}$' \
  || final '{"status":"error","step":"input","message":"Invalid subdomain (2-30 chars a-z0-9)."}' 1

FULL_DOMAIN="$SUB_NAME.$MAIN_DOMAIN"
DOC_ROOT="$BASE_DIR/$FULL_DOMAIN"
[ -d "$DOC_ROOT" ] \
  || final "{\"status\":\"error\",\"step\":\"resolve\",\"message\":\"Client dir not found: $DOC_ROOT\"}" 1

# ============================================================================
#  disable / enable — just toggle the marker (no app boot needed)
# ============================================================================
if [ "$ACTION" = "disable" ]; then
  emit "▸ Suspending $FULL_DOMAIN"
  # Make sure the .htaccess knows about the marker + the branded page exists,
  # even on installs provisioned before this feature shipped.
  grep -q '.suspended' "$DOC_ROOT/.htaccess" 2>/dev/null || write_client_htaccess "$DOC_ROOT"
  write_suspended_page "$DOC_ROOT"
  : > "$DOC_ROOT/.suspended"
  final "{\"status\":\"success\",\"action\":\"disable\",\"url\":\"https://$FULL_DOMAIN\"}" 0
fi

if [ "$ACTION" = "enable" ]; then
  emit "▸ Reactivating $FULL_DOMAIN"
  rm -f "$DOC_ROOT/.suspended" 2>/dev/null || true
  final "{\"status\":\"success\",\"action\":\"enable\",\"url\":\"https://$FULL_DOMAIN\"}" 0
fi

if [ "$ACTION" = "clear-cache" ]; then
  emit "▸ Vidage du cache de $FULL_DOMAIN"
  ( cd "$DOC_ROOT" && "$PHP_BIN" artisan optimize:clear ) >&2 2>&1 || true
  reset_client_opcache
  final "{\"status\":\"success\",\"action\":\"clear-cache\",\"url\":\"https://$FULL_DOMAIN\"}" 0
fi

# ============================================================================
#  update — redeploy master code over the client, preserving its data
# ============================================================================
[ -n "$SOURCE_DIR" ] && [ -d "$SOURCE_DIR" ] \
  || final '{"status":"error","step":"source","message":"SOURCE_DIR not set or missing."}' 1
[ -d "$SOURCE_DIR/vendor" ] \
  || final '{"status":"error","step":"source","message":"Master vendor/ missing — run composer install in ~/htdocs."}' 1

emit "▸ Updating code from master ($SOURCE_DIR)"
# Overwrite CODE (app, config, routes, resources, public/build, vendor,
# database/migrations) but NEVER the client's data: .env, the SQLite DB,
# storage/ (uploads, logs, sessions) and its .htaccess are excluded. tar merges,
# so existing files are replaced and new ones added; stale removed files linger
# harmlessly.
( cd "$SOURCE_DIR" && tar \
    --exclude='./.git' \
    --exclude='./.env' \
    --exclude='./.htaccess' \
    --exclude='./node_modules' \
    --exclude='./bootstrap/cache/*' \
    --exclude='./storage' \
    --exclude='./public/storage' \
    --exclude='./database/*.sqlite' \
    --exclude='./suspended.html' \
    --exclude='./.suspended' \
    --exclude='./.version' \
    --exclude="./*.$MAIN_DOMAIN" \
    --exclude='./_preview.html' \
    -cf - . ) | ( tar -xf - -C "$DOC_ROOT" ) \
  || final '{"status":"error","step":"copy","message":"code copy from master failed."}' 1

# Refresh the managed root files (in case the master's changed).
write_client_htaccess "$DOC_ROOT"
write_suspended_page "$DOC_ROOT"
# A code update must not resurrect a suspended client.
[ -f "$DOC_ROOT/.suspended" ] && : > "$DOC_ROOT/.suspended"

emit "▸ artisan migrate / cache refresh"
# config:clear first so a stale cached config can't break the boot, then a full
# optimize:clear (cache, compiled, config, events, routes, views) so new code —
# including Blade/view changes — is always served after a deploy.
( cd "$DOC_ROOT" && "$PHP_BIN" artisan config:clear ) >&2 2>&1 || true
( cd "$DOC_ROOT" && "$PHP_BIN" artisan optimize:clear ) >&2 2>&1 || true
( cd "$DOC_ROOT" && "$PHP_BIN" artisan migrate --force ) >&2 2>&1 \
  || final '{"status":"error","step":"migrate","message":"migrate failed after update."}' 1

DEPLOY_SHA="$(git -C "$SOURCE_DIR" rev-parse --short HEAD 2>/dev/null || echo master)"
printf '%s' "$DEPLOY_SHA" > "$DOC_ROOT/.version"

# Reset the client's web OPcache so the freshly copied bytecode is served
# (shared-host OPcache usually runs validate_timestamps=0). Done via the
# client's own signed endpoint — safe, unlike pkill, which would kill the web
# request / worker running this script when executed inline.
emit "▸ resetting client OPcache"
reset_client_opcache

final "{\"status\":\"success\",\"action\":\"update\",\"url\":\"https://$FULL_DOMAIN\",\"commit\":\"$DEPLOY_SHA\"}" 0
