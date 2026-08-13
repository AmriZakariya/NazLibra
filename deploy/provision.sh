#!/bin/bash
# ============================================================================
#  Castl-it-POS — Client provisioning by COPYING the master install.
# ----------------------------------------------------------------------------
#  The master (~/htdocs) is already a working, vendored, asset-built checkout.
#  Each client is a filesystem copy of it into $BASE_DIR/<sub>, with its own
#  .env + SQLite database and the LWS root .htaccess. No git clone, no composer,
#  no vendor cache — those all fought the shared-host process/thread limits.
#
#  Usage:  ./provision.sh <subdomain> <payload_b64>
#  Env (set by ProvisionTenantJob):
#    SOURCE_DIR  master app root (base_path())     BASE_DIR   parent of client dirs
#    MAIN_DOMAIN DB_DRIVER DB_PREFIX PHP_BIN
#  Emits "▸ …" progress to stderr; the LAST stdout line is one JSON object.
# ============================================================================
set -uo pipefail
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:${PATH:-}"

# The queue worker carries the MASTER's Laravel env (APP_KEY, DB_*, CASTLIT_MASTER,
# APP_URL…) via putenv(); child `artisan` calls would inherit it — key:generate
# then refuses ("APP_KEY already present") and the client could read the master's
# DB/URL. Strip it so every client artisan reads ONLY its own .env.
unset APP_KEY APP_ENV APP_DEBUG APP_URL APP_NAME CASTLIT_MASTER \
      DB_CONNECTION DB_DATABASE DB_HOST DB_PORT DB_USERNAME DB_PASSWORD \
      SESSION_DRIVER QUEUE_CONNECTION CACHE_STORE MAIL_MAILER 2>/dev/null || true

emit()  { printf '%s\n' "$1" >&2; }
final() { printf '%s\n' "$1"; exit "${2:-0}"; }

: "${MAIN_DOMAIN:=castlitpos.com}"
: "${BASE_DIR:=/home/castlit}"
: "${SOURCE_DIR:=}"
: "${DB_PREFIX:=castlit_}"
: "${DB_DRIVER:=sqlite}"
: "${PHP_BIN:=php}"

SUB_NAME="${1:-}"
PAYLOAD_B64="${2:-}"

[ -n "$SUB_NAME" ] || final '{"status":"error","step":"input","message":"No subdomain provided."}' 1
printf '%s' "$SUB_NAME" | grep -Eq '^[a-z0-9]{2,30}$' \
  || final '{"status":"error","step":"input","message":"Invalid subdomain (2-30 chars a-z0-9)."}' 1
[ -n "$SOURCE_DIR" ] && [ -d "$SOURCE_DIR" ] \
  || final '{"status":"error","step":"source","message":"SOURCE_DIR not set or missing."}' 1
[ -d "$SOURCE_DIR/vendor" ] \
  || final '{"status":"error","step":"source","message":"Master vendor/ missing — run composer install in ~/htdocs."}' 1

FULL_DOMAIN="$SUB_NAME.$MAIN_DOMAIN"
# LWS names each subdomain's directory after the FULL hostname (~/<sub>.<domain>),
# so the client install must live there for the subdomain to serve it.
DOC_ROOT="$BASE_DIR/$FULL_DOMAIN"
SQLITE_PATH="$DOC_ROOT/database/database.sqlite"
NEW_DB_NAME="${DB_PREFIX}${SUB_NAME}"
NEW_DB_USER="${DB_PREFIX}$(printf '%s' "$SUB_NAME" | cut -c1-8)"
NEW_DB_PASS=""

# ============================================================================
# STEP 1 — Subdomain (best-effort: uapi if present, else create it in the panel)
# ============================================================================
emit "▸ Creating subdomain $FULL_DOMAIN → $DOC_ROOT"
mkdir -p "$DOC_ROOT"
SUBDOMAIN_STATUS="manual_required"
if command -v uapi >/dev/null 2>&1; then
  if uapi SubDomain addsubdomain domain="$SUB_NAME" rootdomain="$MAIN_DOMAIN" dir="$DOC_ROOT" disallowdot=1 >&2 2>&1; then
    SUBDOMAIN_STATUS="created"
  else
    SUBDOMAIN_STATUS="uapi_failed"
    emit "  (uapi subdomain create failed — point $SUB_NAME.$MAIN_DOMAIN at $DOC_ROOT in the panel)"
  fi
else
  emit "  (uapi absent — point $SUB_NAME.$MAIN_DOMAIN at $DOC_ROOT in the LWS panel)"
fi

# ============================================================================
# STEP 2 — Database (SQLite by default; MySQL via uapi when DB_DRIVER=mysql)
# ============================================================================
if [ "$DB_DRIVER" = "mysql" ]; then
  command -v uapi >/dev/null 2>&1 || final '{"status":"error","step":"database","message":"uapi required for the mysql driver."}' 1
  NEW_DB_PASS="$("$PHP_BIN" -r 'echo bin2hex(random_bytes(12));' 2>/dev/null)"
  emit "▸ [mysql] Creating database $NEW_DB_NAME and user $NEW_DB_USER"
  uapi Mysql create_database name="$NEW_DB_NAME" >&2 2>&1 || final '{"status":"error","step":"database","message":"create_database failed."}' 1
  uapi Mysql create_user name="$NEW_DB_USER" password="$NEW_DB_PASS" >&2 2>&1 || final '{"status":"error","step":"database","message":"create_user failed."}' 1
  uapi Mysql set_privileges_on_database user="$NEW_DB_USER" database="$NEW_DB_NAME" privileges=ALL >&2 2>&1 || final '{"status":"error","step":"database","message":"grant privileges failed."}' 1
fi

# ============================================================================
# STEP 3 — Copy the master install (code + vendor + built assets) into the client
# ============================================================================
emit "▸ Copying application from master ($SOURCE_DIR)"
# tar-pipe: one pass, honours excludes, needs no rsync. Skip per-install files,
# the master's caches (would carry the master's config!), git and node_modules.
( cd "$SOURCE_DIR" && tar \
    --exclude='./.git' \
    --exclude='./.env' \
    --exclude='./node_modules' \
    --exclude='./bootstrap/cache/*' \
    --exclude='./storage/logs/*' \
    --exclude='./storage/framework/cache/*' \
    --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/views/*' \
    --exclude='./public/storage' \
    --exclude='./database/*.sqlite' \
    --exclude="./*.$MAIN_DOMAIN" \
    --exclude='./_preview.html' \
    -cf - . ) | ( tar -xf - -C "$DOC_ROOT" ) \
  || final '{"status":"error","step":"copy","message":"copy from master failed."}' 1

# Remove any LWS placeholder page that would shadow the app at "/".
rm -f "$DOC_ROOT/index.html" "$DOC_ROOT/default_index.html" "$DOC_ROOT/index.htm" 2>/dev/null || true

# ── STEP 3b — Client root .htaccess (docroot is the client dir → forward to public/)
cat > "$DOC_ROOT/.htaccess" <<'HT'
Options -Indexes
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteRule (^|/)\.(?!well-known) - [F,L]
    RewriteRule ^(app|bootstrap|config|database|lang|resources|routes|scripts|tests|vendor)(/|$) - [F,L]
    RewriteRule ^(composer\.(json|lock)|package(-lock)?\.json|artisan|phpunit\.xml|vite\.config\.js)$ - [F,L]
    RewriteRule \.(md|sqlite)$ - [F,L]
    RewriteRule ^(build|css|fonts|img|js|storage)/(.*)$ public/$1/$2 [L]
    RewriteRule ^favicon\.ico$ public/favicon.ico [L]
</IfModule>
FallbackResource /public/index.php
HT

# ── STEP 3c — SQLite DB file + writable runtime dirs
if [ "$DB_DRIVER" != "mysql" ]; then
  mkdir -p "$DOC_ROOT/database"
  : > "$SQLITE_PATH"
  chmod 664 "$SQLITE_PATH" 2>/dev/null || true
fi
mkdir -p "$DOC_ROOT/storage/framework/cache" "$DOC_ROOT/storage/framework/sessions" \
         "$DOC_ROOT/storage/framework/views" "$DOC_ROOT/storage/logs" "$DOC_ROOT/bootstrap/cache"
chmod -R 775 "$DOC_ROOT/storage" "$DOC_ROOT/bootstrap/cache" 2>/dev/null || true

# ============================================================================
# STEP 4 — Render .env (from the copied .env.example)
# ============================================================================
emit "▸ Writing .env"
ENV_DST="$DOC_ROOT/.env"
[ -f "$DOC_ROOT/.env.example" ] && cp "$DOC_ROOT/.env.example" "$ENV_DST" || : > "$ENV_DST"

if [ "$DB_DRIVER" = "mysql" ]; then
  DB_CONN="mysql"; DB_DATABASE_VAL="$NEW_DB_NAME"; DB_USER_VAL="$NEW_DB_USER"; DB_PASS_VAL="$NEW_DB_PASS"
else
  DB_CONN="sqlite"; DB_DATABASE_VAL="$SQLITE_PATH"; DB_USER_VAL=""; DB_PASS_VAL=""
fi

"$PHP_BIN" -r '
  $f=$argv[1];
  $set=[
    "APP_ENV"=>"production","APP_DEBUG"=>"false","APP_URL"=>$argv[2],
    "CASTLIT_MASTER"=>"false",
    "DB_CONNECTION"=>$argv[6],"DB_HOST"=>"127.0.0.1","DB_PORT"=>"3306",
    "DB_DATABASE"=>$argv[3],"DB_USERNAME"=>$argv[4],"DB_PASSWORD"=>$argv[5],
    "SESSION_DRIVER"=>"database","QUEUE_CONNECTION"=>"database","CACHE_STORE"=>"database",
    "MAIL_MAILER"=>"log",
  ];
  $c=is_file($f)?file_get_contents($f):"";
  foreach($set as $k=>$v){$l=$k."=".$v;
    if(preg_match("/^".preg_quote($k,"/")."=.*$/m",$c)) $c=preg_replace("/^".preg_quote($k,"/")."=.*$/m",$l,$c);
    else $c.=(str_ends_with($c,"\n")||$c===""?"":"\n").$l."\n";}
  file_put_contents($f,$c);
' "$ENV_DST" "https://$FULL_DOMAIN" "$DB_DATABASE_VAL" "$DB_USER_VAL" "$DB_PASS_VAL" "$DB_CONN" >&2 2>&1 \
  || final '{"status":"error","step":"env","message":".env render failed."}' 1

# ============================================================================
# STEP 5 — Key, clear stale caches, migrate, storage link
# ============================================================================
emit "▸ artisan key:generate / migrate / storage:link"
( cd "$DOC_ROOT" && "$PHP_BIN" artisan config:clear ) >&2 2>&1 || true
( cd "$DOC_ROOT" && "$PHP_BIN" artisan key:generate --force ) >&2 2>&1 \
  || final '{"status":"error","step":"key","message":"key:generate failed."}' 1
( cd "$DOC_ROOT" && "$PHP_BIN" artisan migrate --force ) >&2 2>&1 \
  || final '{"status":"error","step":"migrate","message":"migrate failed."}' 1
rm -f "$DOC_ROOT/public/storage" 2>/dev/null || true
( cd "$DOC_ROOT" && "$PHP_BIN" artisan storage:link ) >&2 2>&1 || true

# ============================================================================
# STEP 6 — Seed the tenant + owner from the subscription payload
# ============================================================================
emit "▸ Seeding tenant + owner"
INSTALL_JSON="$( cd "$DOC_ROOT" && "$PHP_BIN" artisan castlit:install-tenant --payload="$PAYLOAD_B64" 2>/dev/null | tail -n 1 )"
case "$INSTALL_JSON" in
  *'"status":"success"'*|*'"status":"skipped"'*) : ;;
  *) final "{\"status\":\"error\",\"step\":\"seed\",\"message\":\"tenant seed failed\",\"detail\":$(printf '%s' "${INSTALL_JSON:-\"\"}" | sed 's/\\/\\\\/g;s/"/\\"/g;s/^/"/;s/$/"/')}" 1 ;;
esac
OWNER_EMAIL="$(printf '%s' "$INSTALL_JSON"    | sed -n 's/.*"owner_email":"\([^"]*\)".*/\1/p')"
OWNER_PASSWORD="$(printf '%s' "$INSTALL_JSON" | sed -n 's/.*"owner_password":"\([^"]*\)".*/\1/p')"

# ============================================================================
# STEP 7 — Stamp version
# ============================================================================
DEPLOY_SHA="$(git -C "$SOURCE_DIR" rev-parse --short HEAD 2>/dev/null || echo master)"
printf '%s' "$DEPLOY_SHA" > "$DOC_ROOT/.version"

final "{\"status\":\"success\",\"url\":\"https://$FULL_DOMAIN\",\"docroot\":\"$DOC_ROOT\",\"db_driver\":\"$DB_DRIVER\",\"db\":\"$DB_DATABASE_VAL\",\"db_user\":\"$DB_USER_VAL\",\"commit\":\"$DEPLOY_SHA\",\"subdomain\":\"$SUBDOMAIN_STATUS\",\"owner_email\":\"$OWNER_EMAIL\",\"owner_password\":\"$OWNER_PASSWORD\"}" 0
