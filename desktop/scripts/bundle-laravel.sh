#!/usr/bin/env bash
# Web uygulamasının (kurs-app) masaüstü paketine girecek üretim kopyasını hazırlar.
#
#   scripts/bundle-laravel.sh            → <desktop>/build/laravel
#
# Kaynak (KURS_APP_SOURCE):
#   dir      (varsayılan) KURS_APP_DIR klasörü. Tek depo düzeninde (kök = web, desktop/ = masaüstü) otomatik bulunur;
#            sunucuda /home/oritoriu/kurs-app.
#   tarball  KURS_APP_TARBALL_URL (imzalı, süreli adres) + KURS_APP_TARBALL_SHA256 ile indirilen .tar.gz
#            (kökünde artisan olan). Masaüstü ayrı depodaysa ve web kodu GitHub'a konmayacaksa.
#
# Diğer değişkenler:
#   OUT_DIR           çıktı (varsayılan <desktop>/build/laravel)
#   PHP_BIN           composer ve doğrulama için PHP (varsayılan php)
#   COMPOSER_BIN      (varsayılan composer)
#   VITE_MODULES      yayın modül listesi (varsayılan: kaynaktaki resources/js/app/modules.generated.ts)
#   SKIP_FRONTEND=1   ön yüz derlemesini atla (kaynakta public/build hazırsa)
#   SKIP_TYPECHECK=1  tsc denetimini atla
#   KEEP_WORK=1       geçici klasörü silme
#
# Hariç tutulanlar: .env*, vendor (yeniden kurulur), node_modules, storage içeriği, bootstrap/cache,
# testler, gateway (donanım köprüsü), günlükler, git/IDE dosyaları, kaynak TS/CSS (derlenmiş build/ kalır).
set -euo pipefail

DESKTOP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${OUT_DIR:-$DESKTOP_DIR/build/laravel}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
SOURCE_KIND="${KURS_APP_SOURCE:-dir}"

log() { printf '\033[1;34m▶\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }

WORK_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/kurs-bundle.XXXXXX")"
cleanup() { [ "${KEEP_WORK:-0}" = 1 ] || rm -rf "$WORK_ROOT"; }
trap cleanup EXIT
# vite.config.ts çıktıyı ../kurs.bogahostdeveloper.com.tr/build klasörüne yazar → kopya, kardeş klasörüyle birlikte
WORK="$WORK_ROOT/kurs-app"
mkdir -p "$WORK" "$WORK_ROOT/kurs.bogahostdeveloper.com.tr/build"

# ------------------------------------------------------------------ 1) kaynak
case "$SOURCE_KIND" in
  dir)
    if [ -z "${KURS_APP_DIR:-}" ]; then
      if [ -f "$DESKTOP_DIR/../artisan" ]; then KURS_APP_DIR="$DESKTOP_DIR/.."
      elif [ -f /home/oritoriu/kurs-app/artisan ]; then KURS_APP_DIR=/home/oritoriu/kurs-app
      else die "Web uygulaması bulunamadı; KURS_APP_DIR verin."; fi
    fi
    SRC="$(cd "$KURS_APP_DIR" && pwd)"
    ;;
  tarball)
    : "${KURS_APP_TARBALL_URL:?KURS_APP_TARBALL_URL gerekli}"
    : "${KURS_APP_TARBALL_SHA256:?KURS_APP_TARBALL_SHA256 gerekli}"
    log "Web uygulaması arşivi indiriliyor"
    curl -fsSL --retry 3 -o "$WORK_ROOT/src.tar.gz" "$KURS_APP_TARBALL_URL"
    echo "$KURS_APP_TARBALL_SHA256  $WORK_ROOT/src.tar.gz" | shasum -a 256 -c - >/dev/null || die "Arşiv özeti tutmuyor."
    mkdir -p "$WORK_ROOT/src"
    tar -xzf "$WORK_ROOT/src.tar.gz" -C "$WORK_ROOT/src"
    SRC="$(dirname "$(find "$WORK_ROOT/src" -maxdepth 3 -name artisan -type f | head -1)")"
    [ -f "$SRC/artisan" ] || die "Arşivde artisan yok."
    ;;
  *) die "KURS_APP_SOURCE dir|tarball olmalı" ;;
esac
[ -f "$SRC/artisan" ] && [ -f "$SRC/composer.lock" ] || die "Geçersiz kaynak: $SRC"
log "Kaynak: $SRC"

log "Temiz kopya"
rsync -a --delete \
  --exclude='.git/' --exclude='.github/' --exclude='desktop/' \
  --exclude='.env' --exclude='.env.*' --exclude='*.env' \
  --exclude='/vendor/' --exclude='/node_modules/' \
  --exclude='/storage/' --exclude='/bootstrap/cache/*' \
  --exclude='/public/build/' --exclude='/public/hot' --exclude='/public/storage' \
  --exclude='/tests/' --exclude='/gateway/' --exclude='/docs/' \
  --exclude='error_log' --exclude='*.log' --exclude='*.sql' --exclude='*.sql.gz' \
  --exclude='*.p12' --exclude='*.p8' --exclude='*.pem' --exclude='*.key' \
  --exclude='.phpunit.cache/' --exclude='.phpunit.result.cache' --exclude='tsconfig.tsbuildinfo' \
  --exclude='.idea/' --exclude='.vscode/' --exclude='.DS_Store' --exclude='auth.json' \
  --exclude='_ide_helper*.php' --exclude='*.bak' --exclude='*.orig' \
  "$SRC/" "$WORK/"

# boş iskelet: paket içindeki storage kullanılmaz (LARAVEL_STORAGE_PATH), ama klasör beklenir
mkdir -p "$WORK/storage/app/private" "$WORK/storage/app/public" "$WORK/storage/framework/"{cache,sessions,views} "$WORK/storage/logs" "$WORK/bootstrap/cache"

# ------------------------------------------------------------------ 2) composer
log "composer install --no-dev"
( cd "$WORK" && COMPOSER_ALLOW_SUPERUSER=1 "$PHP_BIN" "$(command -v "$COMPOSER_BIN")" install \
    --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --no-scripts --prefer-dist )

# ------------------------------------------------------------------ 3) ön yüz
if [ "${SKIP_FRONTEND:-0}" != 1 ]; then
  if [ -z "${VITE_MODULES:-}" ]; then
    VITE_MODULES="$(grep -oE "modules/[a-z0-9-]+/module" "$WORK/resources/js/app/modules.generated.ts" | sed -E 's#modules/([^/]+)/module#\1#' | paste -sd, -)"
  fi
  [ -n "$VITE_MODULES" ] || die "VITE_MODULES boş."
  log "Ön yüz derleniyor (modüller: $VITE_MODULES)"
  (
    cd "$WORK"
    npm ci --no-audit --no-fund --loglevel=error
    VITE_MODULES="$VITE_MODULES" node scripts/gen-modules.mjs
    [ "${SKIP_TYPECHECK:-0}" = 1 ] || npx tsc --noEmit -p .
    npx vite build
  )
  rm -rf "$WORK/public/build"
  mv "$WORK_ROOT/kurs.bogahostdeveloper.com.tr/build" "$WORK/public/build"
  rm -rf "$WORK/node_modules"
else
  [ -f "$SRC/public/build/manifest.json" ] || die "SKIP_FRONTEND=1 ama kaynakta public/build yok."
  rsync -a "$SRC/public/build/" "$WORK/public/build/"
fi
[ -f "$WORK/public/build/manifest.json" ] || die "Vite manifest oluşmadı."

# web kökündeki simge (web kökü depoda değil)
[ -f "$WORK/public/favicon.svg" ] || cp "$DESKTOP_DIR/assets/favicon.svg" "$WORK/public/favicon.svg"

# ------------------------------------------------------------------ 4) sadeleştirme
log "Sadeleştirme"
rm -rf "$WORK/resources/js" "$WORK/resources/css" "$WORK/scripts" \
       "$WORK/package.json" "$WORK/package-lock.json" "$WORK/tsconfig.json" "$WORK/vite.config.ts" \
       "$WORK/.npmrc" "$WORK/phpunit.xml" "$WORK/.editorconfig" "$WORK/.gitattributes" "$WORK/.gitignore" \
       "$WORK/AGENTS.md" "$WORK/CLAUDE.md" "$WORK/PROJECT_STATUS.md" "$WORK/README.md"
# vendor/bin vekil dosyaları çalışma anında gerekmez (paket kaynaklarında bağlantı/yürütülebilir kalmasın)
rm -rf "$WORK/vendor/bin"
# vendor içi test/örnek klasörleri (çalışma anında kullanılmaz)
find "$WORK/vendor" -type d \( -name tests -o -name .github \) -prune -exec rm -rf {} + 2>/dev/null || true

# .env.example: masaüstü şablonu (bilgi amaçlı; uygulama kendi .env'sini veri klasöründe üretir)
cp "$DESKTOP_DIR/runtime/env.template" "$WORK/.env.example"

# ------------------------------------------------------------------ 5) güvenlik denetimi
log "Sır denetimi"
bad="$(find "$WORK" -path "$WORK/vendor" -prune -o \( -name '.env' -o -name '*.p12' -o -name '*.p8' -o -name '*.pem' -o -name '*.key' -o -name '*.sql*' -o -name 'demo-users.txt' \) -print)"
[ -z "$bad" ] || die "Pakete girmemesi gereken dosyalar: $bad"
if grep -rIlE '^(APP_KEY|DB_PASSWORD|KURS_DATA_KEY)=[^[:space:]]+' "$WORK" --exclude-dir=vendor 2>/dev/null | grep -v '.env.example' ; then
  die "Kopyada sır içeren satır bulundu."
fi

# ------------------------------------------------------------------ 6) doğrulama (geçici depolama ile)
log "artisan doğrulaması"
TMP_STORE="$WORK_ROOT/verify-storage"
mkdir -p "$TMP_STORE"/{app/private,app/public,framework/cache/data,framework/sessions,framework/views,logs}
(
  cd "$WORK"
  env -i PATH="$PATH" HOME="${HOME:-/tmp}" \
    LARAVEL_STORAGE_PATH="$TMP_STORE" \
    APP_SERVICES_CACHE="$WORK_ROOT/verify-services.php" APP_PACKAGES_CACHE="$WORK_ROOT/verify-packages.php" \
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" KURS_NODE=local DB_CONNECTION=sqlite DB_DATABASE=":memory:" \
    CACHE_STORE=array SESSION_DRIVER=array LOG_CHANNEL=stderr \
    "$PHP_BIN" -d variables_order=EGPCS artisan --version
  env -i PATH="$PATH" HOME="${HOME:-/tmp}" \
    LARAVEL_STORAGE_PATH="$TMP_STORE" \
    APP_SERVICES_CACHE="$WORK_ROOT/verify-services.php" APP_PACKAGES_CACHE="$WORK_ROOT/verify-packages.php" \
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" KURS_NODE=local DB_CONNECTION=sqlite DB_DATABASE=":memory:" \
    CACHE_STORE=array SESSION_DRIVER=array LOG_CHANNEL=stderr \
    "$PHP_BIN" -d variables_order=EGPCS artisan help kurs:desktop-pair >/dev/null || { echo "kurs:desktop-pair komutu yok" >&2; exit 1; }
)

# ------------------------------------------------------------------ 7) yerleştir
APP_VERSION="$(node -e 'console.log(JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"))[0].version)' "$WORK/resources/changelog.json" 2>/dev/null || echo "?")"
cat > "$WORK/.bundle.json" <<JSON
{"web_version": "$APP_VERSION", "built_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)", "source": "$SOURCE_KIND", "modules": "${VITE_MODULES:-}"}
JSON

mkdir -p "$OUT_DIR"
rsync -a --delete "$WORK/" "$OUT_DIR/"
chmod -R u+rwX,go+rX,go-w "$OUT_DIR"
log "Hazır: $OUT_DIR ($(du -sh "$OUT_DIR" | cut -f1), web v$APP_VERSION, $(find "$OUT_DIR" -type f | wc -l | tr -d ' ') dosya)"
