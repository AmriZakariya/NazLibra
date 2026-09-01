#!/bin/bash
# Runs from cron, never from the web request. Executes queued manage.sh actions
# and lets Laravel persist the final result and complete log.
set -u

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
QUEUE_DIR="$ROOT_DIR/storage/app/castlit-manage-queue"
PHP_BIN="${CASTLIT_PHP_BIN:-php}"

mkdir -p "$QUEUE_DIR"
shopt -s nullglob

for REQUEST in "$QUEUE_DIR"/*.json; do
  CLAIM="$REQUEST.running"
  mv "$REQUEST" "$CLAIM" 2>/dev/null || continue

  mapfile -t VALUES < <("$PHP_BIN" -r '
    $r=json_decode(file_get_contents($argv[1]), true);
    if (!is_array($r) || !isset($r["action"], $r["subdomain"])) exit(2);
    echo $r["action"], "\n", $r["subdomain"], "\n";
  ' "$CLAIM")
  ACTION="${VALUES[0]:-}"
  SUBDOMAIN="${VALUES[1]:-}"
  STDOUT="$CLAIM.stdout"
  STDERR="$CLAIM.stderr"

  if [ -z "$ACTION" ] || [ -z "$SUBDOMAIN" ]; then
    printf '%s\n' 'Invalid deployment request.' > "$STDERR"
    EXIT_CODE=2
  else
    (
      cd "$ROOT_DIR" || exit 1
      MAIN_DOMAIN="${CASTLIT_MAIN_DOMAIN:-castlitpos.com}" \
      BASE_DIR="$ROOT_DIR" \
      SOURCE_DIR="$ROOT_DIR" \
      PHP_BIN="$PHP_BIN" \
      bash "$ROOT_DIR/deploy/manage.sh" "$ACTION" "$SUBDOMAIN"
    ) >"$STDOUT" 2>"$STDERR"
    EXIT_CODE=$?
  fi

  "$PHP_BIN" "$ROOT_DIR/artisan" castlit:complete-manage "$CLAIM" "$STDOUT" "$STDERR" "$EXIT_CODE" || true
  rm -f "$CLAIM" "$STDOUT" "$STDERR"
done
