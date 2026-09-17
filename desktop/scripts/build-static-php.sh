#!/usr/bin/env bash
# Statik PHP 8.4 CLI (macOS) — static-php-cli ile. CI'da (GitHub Actions macOS koşucusu) çalışır.
#
#   scripts/build-static-php.sh <çıktı-dosyası>
#
# Ortam:
#   SPC_VERSION   static-php-cli sürümü (varsayılan 2.8.5)
#   PHP_VERSION   PHP ana sürümü (varsayılan 8.4)
#   PHP_EXTENSIONS, PHP_LIBS  aşağıdaki varsayılanları ezer
#   GITHUB_TOKEN  kaynak indirmelerinde GitHub hız sınırına takılmamak için (Actions'ta otomatik)
#   MACOSX_DEPLOYMENT_TARGET  en düşük macOS (varsayılan 12.0; tauri.conf.json ile aynı)
#
# Uzantılar: Laravel + dompdf + openspout + intervention/image + eşitleme (sodium) + Türkçe sıralama (intl/ICU).
set -euo pipefail

OUT="${1:?Kullanım: build-static-php.sh <çıktı-dosyası>}"
SPC_VERSION="${SPC_VERSION:-2.8.5}"
PHP_VERSION="${PHP_VERSION:-8.4}"
export MACOSX_DEPLOYMENT_TARGET="${MACOSX_DEPLOYMENT_TARGET:-12.0}"

PHP_EXTENSIONS="${PHP_EXTENSIONS:-bcmath,ctype,curl,dom,exif,fileinfo,filter,gd,iconv,intl,mbstring,opcache,openssl,pcntl,pdo,pdo_sqlite,phar,posix,session,simplexml,sodium,sqlite3,tokenizer,xml,xmlreader,xmlwriter,zip,zlib}"
# gd: PNG (zorunlu) + JPEG + WebP + FreeType (dompdf, intervention/image, fotoğraflar)
PHP_LIBS="${PHP_LIBS:-libpng,libjpeg,libwebp,freetype}"

case "$(uname -m)" in
  arm64|aarch64) SPC_ARCH=aarch64 ;;
  x86_64) SPC_ARCH=x86_64 ;;
  *) echo "Desteklenmeyen mimari: $(uname -m)" >&2; exit 1 ;;
esac
[ "$(uname -s)" = "Darwin" ] || { echo "Bu betik macOS içindir." >&2; exit 1; }

WORK="${SPC_WORK_DIR:-$PWD/.spc}"
mkdir -p "$WORK"
cd "$WORK"

if [ ! -x ./spc ]; then
  echo "▶ static-php-cli $SPC_VERSION ($SPC_ARCH) indiriliyor"
  curl -fsSL --retry 3 -o spc.tgz "https://github.com/crazywhalecc/static-php-cli/releases/download/${SPC_VERSION}/spc-macos-${SPC_ARCH}.tar.gz"
  tar -xzf spc.tgz
  rm -f spc.tgz
  chmod +x ./spc
fi
./spc --version

echo "▶ Ortam denetimi"
./spc doctor --auto-fix

echo "▶ Kaynaklar indiriliyor (PHP $PHP_VERSION)"
./spc download --with-php="$PHP_VERSION" --for-extensions="$PHP_EXTENSIONS" --for-libs="$PHP_LIBS" --prefer-pre-built --retry=3

echo "▶ Derleniyor: $PHP_EXTENSIONS"
./spc build "$PHP_EXTENSIONS" --with-libs="$PHP_LIBS" --build-cli --disable-opcache-jit --debug

BIN="$WORK/buildroot/bin/php"
[ -x "$BIN" ] || { echo "Çıktı bulunamadı: $BIN" >&2; exit 1; }

echo "▶ Doğrulama"
"$BIN" -v
# Liste bir kez alınır: "php -m | grep -q" pipefail altında SIGPIPE ile yanlış "eksik" veriyordu
modules="$("$BIN" -m)"
printf '%s\n' "$modules"
missing=0
for ext in ${PHP_EXTENSIONS//,/ }; do
  name="$ext"
  [ "$ext" = "opcache" ] && name="Zend OPcache"
  if ! grep -qix "$name" <<<"$modules"; then
    echo "EKSİK uzantı: $ext" >&2
    missing=1
  fi
done
[ "$missing" = 0 ] || exit 1
# Türkçe sıralama (ICU verisi gömülü mü?)
"$BIN" -r '$c = new Collator("tr_TR"); $a = ["Zeynep","Çağla","Ceren","İlker","Işıl","Ömer","Oya"]; $c->sort($a); echo implode(",", $a), PHP_EOL; exit($a[0] === "Ceren" && $a[1] === "Çağla" ? 0 : 1);'
"$BIN" -r 'echo sodium_crypto_box_keypair() ? "sodium ok\n" : exit(1);'
"$BIN" -r '$p = new PDO("sqlite::memory:"); echo "sqlite ", $p->query("select sqlite_version()")->fetchColumn(), PHP_EOL;'
"$BIN" -r 'echo function_exists("imagewebp") && function_exists("imagejpeg") && function_exists("imagettftext") ? "gd ok\n" : exit(1);'
links="$(otool -L "$BIN")"
printf '%s\n' "$links"
if grep -qE '/opt/homebrew|/usr/local/(opt|Cellar)' <<<"$links"; then echo "Homebrew kitaplığına bağımlılık var (statik değil)" >&2; exit 1; fi
lipo -info "$BIN"

mkdir -p "$(dirname "$OUT")"
cp -f "$BIN" "$OUT"
chmod 755 "$OUT"
echo "✔ $OUT ($(du -h "$OUT" | cut -f1))"
