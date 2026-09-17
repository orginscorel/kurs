#!/usr/bin/env bash
# Paketlenmiş Laravel düzenini, macOS uygulamasının yaptığı gibi yerel kipte çalıştırır (Linux sunucuda deneme).
# Rust tarafındaki src/php.rs ortamının kabuk karşılığıdır; anahtar zinciri yerine DATA/secrets/* dosyaları.
#
#   DATA=/yol/deneme scripts/local-smoke.sh init <sunucu>        # .env + APP_KEY + migrate
#   DATA=… scripts/local-smoke.sh pair < girdi.json               # kurs:desktop-pair (sırlar DATA/secrets'a)
#   DATA=… scripts/local-smoke.sh snapshot                        # kurs:desktop-snapshot
#   DATA=… scripts/local-smoke.sh serve <port> <jeton>            # php -S + router.php (ön planda)
#   DATA=… scripts/local-smoke.sh artisan <komut…>
#
# Ortam: BUNDLE (varsayılan <desktop>/build/laravel), PHP_BIN,
#        PHP_EXTRA_INI_DIR (paylaşımlı uzantılı PHP'de uzantı .ini'leri; ör. /opt/cpanel/ea-php83/root/etc/php.d —
#        runtime/php.ini ile birleştirilip DATA/phprc/php.ini yapılır; statik PHP'de gerekmez),
#        KURS_CACERT (varsayılan runtime/cacert.pem, yoksa sistem CA dosyası)
set -euo pipefail

DESKTOP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUNDLE="${BUNDLE:-$DESKTOP_DIR/build/laravel}"
RUNTIME="$DESKTOP_DIR/runtime"
DATA="${DATA:?DATA klasörü verin}"
PHP_BIN="${PHP_BIN:-php}"
LOCAL="$DATA/local"
SECRETS="$DATA/secrets"
CACERT="${KURS_CACERT:-$RUNTIME/cacert.pem}"
[ -f "$CACERT" ] || CACERT=/etc/pki/tls/certs/ca-bundle.crt

mkdir -p "$SECRETS" "$DATA/logs" "$LOCAL/database" "$LOCAL/cache" \
  "$LOCAL/storage/app/private" "$LOCAL/storage/app/public" "$LOCAL/storage/framework/cache/data" \
  "$LOCAL/storage/framework/sessions" "$LOCAL/storage/framework/views" "$LOCAL/storage/logs" "$LOCAL/storage/fonts"
chmod 700 "$DATA" "$SECRETS"

PHPRC_DIR="$RUNTIME"
if [ -n "${PHP_EXTRA_INI_DIR:-}" ]; then
  PHPRC_DIR="$DATA/phprc"
  mkdir -p "$PHPRC_DIR"
  { cat "$RUNTIME/php.ini"; for f in "$PHP_EXTRA_INI_DIR"/*.ini; do echo; cat "$f"; done; } > "$PHPRC_DIR/php.ini"
fi

secret() { [ -s "$SECRETS/$1" ] && cat "$SECRETS/$1" || true; }

# .env'i (tırnaklı değerler dahil) KEY=VALUE satırlarına çevirir
env_lines() {
  [ -f "$LOCAL/.env" ] || return 0
  sed -nE 's/^([A-Z0-9_]+)=(.*)$/\1=\2/p' "$LOCAL/.env" | sed -E 's/^([A-Z0-9_]+)="(.*)"$/\1=\2/'
}

run_php() {
  local -a vars=(
    "PATH=/usr/bin:/bin" "HOME=${HOME:-/tmp}" "LANG=tr_TR.UTF-8"
    "PHPRC=$PHPRC_DIR" "PHP_INI_SCAN_DIR="
    "KURS_PREPEND=$RUNTIME/prepend.php" "KURS_CACERT=$CACERT" "KURS_PHP_ERROR_LOG=$DATA/logs/php-error.log" "SSL_CERT_FILE=$CACERT"
  )
  while IFS= read -r line; do [ -n "$line" ] && vars+=("$line"); done < <(env_lines)
  vars+=(
    "LARAVEL_STORAGE_PATH=$LOCAL/storage"
    "APP_SERVICES_CACHE=$LOCAL/cache/services.php" "APP_PACKAGES_CACHE=$LOCAL/cache/packages.php"
    "APP_CONFIG_CACHE=$LOCAL/cache/config.php" "APP_ROUTES_CACHE=$LOCAL/cache/routes-v7.php" "APP_EVENTS_CACHE=$LOCAL/cache/events.php"
    "VIEW_COMPILED_PATH=$LOCAL/storage/framework/views" "KURS_PUBLIC_PATH=$BUNDLE/public"
    "KURS_NODE=local" "KURS_DESKTOP=1" "DB_CONNECTION=sqlite" "DB_DATABASE=$LOCAL/database/kurs-local.sqlite"
    "APP_KEY=$(secret app-key)"
  )
  [ -n "$(secret device-token)" ] && vars+=("SYNC_DEVICE_TOKEN=$(secret device-token)")
  [ -n "$(secret data-key)" ] && vars+=("KURS_DATA_KEY=$(secret data-key)")
  vars+=("${EXTRA_ENV[@]}")
  (cd "$BUNDLE" && env -i "${vars[@]}" "$PHP_BIN" "$@")
}
EXTRA_ENV=()

cmd="${1:-}"; shift || true
case "$cmd" in
  init)
    server="${1:?sunucu adresi}"
    [ -s "$SECRETS/app-key" ] || { printf 'base64:%s' "$(head -c 32 /dev/urandom | base64)" > "$SECRETS/app-key"; chmod 600 "$SECRETS/app-key"; }
    sed -e "s#{{DB_PATH}}#\"$LOCAL/database/kurs-local.sqlite\"#" -e "s#{{SERVER_URL}}#\"$server\"#" "$RUNTIME/env.template" > "$LOCAL/.env"
    chmod 600 "$LOCAL/.env"
    touch "$LOCAL/database/kurs-local.sqlite"
    run_php artisan migrate --force --no-interaction --no-ansi
    ;;
  pair)
    out="$(run_php artisan kurs:desktop-pair --no-ansi)"
    # sırları dosyaya al, ekrana yalnız sırsız olayları bas
    printf '%s\n' "$out" | while IFS= read -r line; do
      case "$line" in
        *'"event":"secrets"'*)
          printf '%s' "$line" | "$PHP_BIN" -n -r '$j=json_decode(stream_get_contents(STDIN),true); file_put_contents($argv[1]."/device-token",$j["device_token"]); if(!empty($j["data_key"])) file_put_contents($argv[1]."/data-key",$j["data_key"]); echo "{\"event\":\"secrets\",\"stored\":true,\"data_key\":".(empty($j["data_key"])?"false":"true")."}\n";' "$SECRETS"
          chmod 600 "$SECRETS"/* ;;
        *) printf '%s\n' "$line" ;;
      esac
    done
    ;;
  snapshot)
    run_php artisan kurs:desktop-snapshot --no-ansi
    ;;
  serve)
    port="${1:?port}"; token="${2:?jeton}"
    hash="$(printf '%s' "$token" | sha256sum | cut -d' ' -f1)"
    EXTRA_ENV=("APP_URL=http://127.0.0.1:$port" "SESSION_SECURE_COOKIE=false" "KURS_DESKTOP_TOKEN_HASH=$hash" "PHP_CLI_SERVER_WORKERS=4")
    exec_log="$DATA/logs/php-server.log"
    echo "Yerel sunucu: http://127.0.0.1:$port (günlük: $exec_log)"
    run_php -S "127.0.0.1:$port" -t "$BUNDLE/public" "$RUNTIME/router.php" >>"$exec_log" 2>&1
    ;;
  artisan)
    run_php artisan "$@"
    ;;
  *)
    sed -n '2,12p' "$0"; exit 1 ;;
esac
