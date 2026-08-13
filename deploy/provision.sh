#!/bin/bash
# ============================================================================
#  CastLit POS — Client Provisioning Script (Laravel, Git-based, cPanel/LWS)
# ----------------------------------------------------------------------------
#  Provisions a brand-new NazLibra client install from the Git code cache:
#    subdomain → docroot/public, MySQL DB+user, code, vendor/, .env, key,
#    migrate, tenant+owner seed, storage link.
#
#  Modeled on eInvoiceTrack/deploy/deploy.sh but adapted for a Laravel app:
#   • subdomain docroot points at <docroot>/public
#   • vendor/ (git-ignored) is rsynced from the repo cache (composer install
#     is run once in the cache, not per client)
#   • config is .env (rendered here) + `php artisan key:generate`
#   • schema via `php artisan migrate --force`; tenant seeded via
#     `php artisan castlit:install-tenant`
#
#  Usage:
#    ./provision.sh <subdomain> <payload_b64> [git_ref]
#      <subdomain>    sanitized [a-z0-9], 2..30 chars
#      <payload_b64>  base64 JSON subscription payload (passed to install-tenant)
#      [git_ref]      defaults to origin/<branch>
#
#  Emits progress to stderr; the LAST stdout line is a single JSON object the
#  caller parses. All tunables come from environment variables set by the
#  ProvisionTenantJob (which reads config/castlit.php):
#    MAIN_DOMAIN BASE_DIR DB_PREFIX REPO_DIR BRANCH REPO_OWNER REPO_NAME
#    GH_TOKEN PHP_BIN
# ============================================================================
set -uo pipefail

emit()  { printf '%s\n' "$1" >&2; }
final() { printf '%s\n' "$1"; exit "${2:-0}"; }

# --- Defaults (overridable via env) -----------------------------------------
: "${MAIN_DOMAIN:=castlitpos.com}"
: "${BASE_DIR:=/home/castlit/public_html}"
: "${DB_PREFIX:=castlit_}"
: "${REPO_DIR:=$(cd "$(dirname "$0")" && pwd)/repo}"
: "${BRANCH:=main}"
: "${REPO_OWNER:=AmriZakariya}"
: "${REPO_NAME:=NazLibra}"
: "${GH_TOKEN:=}"
: "${PHP_BIN:=php}"
: "${DB_DRIVER:=sqlite}"   # sqlite (default) | mysql

SUB_NAME="${1:-}"
PAYLOAD_B64="${2:-}"
GIT_REF="${3:-origin/$BRANCH}"

if [ -z "$SUB_NAME" ]; then
  final '{"status":"error","step":"input","message":"No subdomain provided."}' 1
fi
# Defense in depth — never trust the caller; only [a-z0-9], 2..30.
if ! printf '%s' "$SUB_NAME" | grep -Eq '^[a-z0-9]{2,30}$'; then
  final '{"status":"error","step":"input","message":"Invalid subdomain (need 2-30 chars a-z0-9)."}' 1
fi

FULL_DOMAIN="$SUB_NAME.$MAIN_DOMAIN"
DOC_ROOT="$BASE_DIR/$SUB_NAME"
PUBLIC_DIR="$DOC_ROOT/public"
NEW_DB_NAME="${DB_PREFIX}${SUB_NAME}"
NEW_DB_USER="${DB_PREFIX}$(printf '%s' "$SUB_NAME" | cut -c1-8)"
NEW_DB_PASS="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"

# ============================================================================
# STEP 0 — Ensure the Git code cache (auto-clone if missing) + vendor present
# ============================================================================
emit "▸ Ensuring code cache at $REPO_DIR"
if [ ! -d "$REPO_DIR/.git" ]; then
  if [ -z "$GH_TOKEN" ]; then
    final '{"status":"error","step":"cache","message":"Code cache missing and no GH_TOKEN set."}' 1
  fi
  git clone --branch "$BRANCH" \
    "https://${GH_TOKEN}@github.com/${REPO_OWNER}/${REPO_NAME}.git" "$REPO_DIR" >&2 2>&1 \
    || final '{"status":"error","step":"cache","message":"git clone failed (token/repo?)."}' 1
  git -C "$REPO_DIR" config credential.helper store >/dev/null 2>&1
fi
git -C "$REPO_DIR" fetch --all --prune --tags >&2 2>&1
DEPLOY_SHA="$(git -C "$REPO_DIR" rev-parse "$GIT_REF" 2>/dev/null)"
[ -n "$DEPLOY_SHA" ] || final "{\"status\":\"error\",\"step\":\"cache\",\"message\":\"Git ref not found: $GIT_REF\"}" 1

# vendor/ is git-ignored, so it must already exist in the cache (run
# `composer install --no-dev -o` in the cache once, kept warm between deploys).
if [ ! -d "$REPO_DIR/vendor" ]; then
  final '{"status":"error","step":"cache","message":"repo/vendor missing — run composer install in the cache first."}' 1
fi

# ============================================================================
# STEP 1 — Create subdomain (docroot → Laravel public/)
# ============================================================================
emit "▸ Creating subdomain $FULL_DOMAIN → $PUBLIC_DIR"
mkdir -p "$PUBLIC_DIR"
uapi SubDomain addsubdomain domain="$SUB_NAME" rootdomain="$MAIN_DOMAIN" dir="$PUBLIC_DIR" disallowdot=1 >&2 2>&1 \
  || emit "  (subdomain create returned non-zero — may already exist, continuing)"

# ============================================================================
# STEP 2 — Prepare the database (engine chosen by DB_DRIVER)
# ============================================================================
SQLITE_PATH="$DOC_ROOT/database/database.sqlite"
if [ "$DB_DRIVER" = "mysql" ]; then
  emit "▸ [mysql] Creating database $NEW_DB_NAME and user $NEW_DB_USER"
  uapi Mysql create_database name="$NEW_DB_NAME" >&2 2>&1 \
    || final '{"status":"error","step":"database","message":"create_database failed."}' 1
  uapi Mysql create_user name="$NEW_DB_USER" password="$NEW_DB_PASS" >&2 2>&1 \
    || final '{"status":"error","step":"database","message":"create_user failed."}' 1
  uapi Mysql set_privileges_on_database user="$NEW_DB_USER" database="$NEW_DB_NAME" privileges=ALL >&2 2>&1 \
    || final '{"status":"error","step":"database","message":"grant privileges failed."}' 1
else
  emit "▸ [sqlite] Database file will be created after code export"
fi

# ============================================================================
# STEP 3 — Deploy application code from Git + vendor/ from cache
# ============================================================================
emit "▸ Exporting commit ${DEPLOY_SHA:0:8} into $DOC_ROOT"
git -C "$REPO_DIR" archive "$DEPLOY_SHA" | tar -x -C "$DOC_ROOT" \
  || final '{"status":"error","step":"code","message":"git archive export failed."}' 1
# vendor/ ships from the warm cache (composer not run per client).
rsync -a "$REPO_DIR/vendor/" "$DOC_ROOT/vendor/" >&2 2>&1 \
  || final '{"status":"error","step":"code","message":"vendor rsync failed."}' 1

# SQLite: create the database file now that the database/ dir exists.
if [ "$DB_DRIVER" != "mysql" ]; then
  mkdir -p "$DOC_ROOT/database"
  : > "$SQLITE_PATH"
  chmod 664 "$SQLITE_PATH" 2>/dev/null || true
  chmod 775 "$DOC_ROOT/database" 2>/dev/null || true
fi

# ============================================================================
# STEP 4 — Render .env
# ============================================================================
emit "▸ Writing .env"
APP_KEY_PLACEHOLDER=""
ENV_SRC="$DOC_ROOT/.env.example"
ENV_DST="$DOC_ROOT/.env"
if [ -f "$ENV_SRC" ]; then
  cp "$ENV_SRC" "$ENV_DST"
else
  : > "$ENV_DST"
fi
# DB connection values depend on the driver.
if [ "$DB_DRIVER" = "mysql" ]; then
  DB_CONN="mysql"; DB_DATABASE_VAL="$NEW_DB_NAME"; DB_USER_VAL="$NEW_DB_USER"; DB_PASS_VAL="$NEW_DB_PASS"
else
  DB_CONN="sqlite"; DB_DATABASE_VAL="$SQLITE_PATH"; DB_USER_VAL=""; DB_PASS_VAL=""
fi

# Overwrite/append the keys we control. Uses a PHP helper so quoting is safe.
"$PHP_BIN" -r '
  $f = $argv[1];
  $set = [
    "APP_ENV"       => "production",
    "APP_DEBUG"     => "false",
    "APP_URL"       => $argv[2],
    "CASTLIT_MASTER"=> "false",
    "DB_CONNECTION" => $argv[6],
    "DB_HOST"       => "127.0.0.1",
    "DB_PORT"       => "3306",
    "DB_DATABASE"   => $argv[3],
    "DB_USERNAME"   => $argv[4],
    "DB_PASSWORD"   => $argv[5],
    "SESSION_DRIVER"=> "database",
    "QUEUE_CONNECTION" => "database",
    "MAIL_MAILER"   => "log",
  ];
  $c = is_file($f) ? file_get_contents($f) : "";
  foreach ($set as $k => $v) {
    $line = $k."=".$v;
    if (preg_match("/^".preg_quote($k,"/")."=.*$/m", $c)) {
      $c = preg_replace("/^".preg_quote($k,"/")."=.*$/m", $line, $c);
    } else {
      $c .= (str_ends_with($c, "\n") || $c === "" ? "" : "\n").$line."\n";
    }
  }
  file_put_contents($f, $c);
' "$ENV_DST" "https://$FULL_DOMAIN" "$DB_DATABASE_VAL" "$DB_USER_VAL" "$DB_PASS_VAL" "$DB_CONN" >&2 2>&1 \
  || final '{"status":"error","step":"env","message":".env render failed."}' 1

# ============================================================================
# STEP 5 — Key, migrate, storage link
# ============================================================================
emit "▸ artisan key:generate / migrate / storage:link"
( cd "$DOC_ROOT" && "$PHP_BIN" artisan key:generate --force ) >&2 2>&1 \
  || final '{"status":"error","step":"key","message":"key:generate failed."}' 1
( cd "$DOC_ROOT" && "$PHP_BIN" artisan migrate --force ) >&2 2>&1 \
  || final '{"status":"error","step":"migrate","message":"migrate failed."}' 1
( cd "$DOC_ROOT" && "$PHP_BIN" artisan storage:link ) >&2 2>&1 || true

# ============================================================================
# STEP 6 — Seed tenant + owner from the subscription payload
# ============================================================================
emit "▸ Seeding tenant + owner"
INSTALL_JSON="$( cd "$DOC_ROOT" && "$PHP_BIN" artisan castlit:install-tenant --payload="$PAYLOAD_B64" 2>/dev/null | tail -n 1 )"
case "$INSTALL_JSON" in
  *'"status":"success"'*|*'"status":"skipped"'*) : ;;
  *) final "{\"status\":\"error\",\"step\":\"seed\",\"message\":\"tenant seed failed\",\"detail\":$(printf '%s' "${INSTALL_JSON:-\"\"}" | sed 's/\\/\\\\/g;s/"/\\"/g;s/^/"/;s/$/"/')}" 1 ;;
esac

# Extract owner credentials from the install JSON (best-effort).
OWNER_EMAIL="$(printf '%s' "$INSTALL_JSON"    | sed -n 's/.*"owner_email":"\([^"]*\)".*/\1/p')"
OWNER_PASSWORD="$(printf '%s' "$INSTALL_JSON" | sed -n 's/.*"owner_password":"\([^"]*\)".*/\1/p')"

# ============================================================================
# STEP 7 — Cache config/routes + stamp the deployed commit
# ============================================================================
( cd "$DOC_ROOT" && "$PHP_BIN" artisan config:cache && "$PHP_BIN" artisan route:cache ) >&2 2>&1 || true
printf '%s' "$DEPLOY_SHA" > "$DOC_ROOT/.version"

# ============================================================================
# OUTPUT — single JSON line
# ============================================================================
final "{\"status\":\"success\",\"url\":\"https://$FULL_DOMAIN\",\"docroot\":\"$DOC_ROOT\",\"db_driver\":\"$DB_DRIVER\",\"db\":\"$DB_DATABASE_VAL\",\"db_user\":\"$DB_USER_VAL\",\"commit\":\"${DEPLOY_SHA:0:8}\",\"owner_email\":\"$OWNER_EMAIL\",\"owner_password\":\"$OWNER_PASSWORD\"}" 0
