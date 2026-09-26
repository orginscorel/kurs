#!/usr/bin/env bash
# Tek depo (orginscorel/kurs) çalışma ağacını hazırlar: kök = web uygulaması, desktop/ = masaüstü projesi.
#
#   scripts/assemble-repo.sh <hedef-klasör>
#
# Hedef bir git çalışma ağacı olabilir (git clone …); dosyalar üzerine yazılır, .git'e dokunulmaz.
# Sırlar, bağımlılıklar, yedekler ve çalışma verisi kopyalanmaz (ayrıca repo-gitignore → .gitignore).
# İtme bu betiğin işi değildir: hedefte `git status` ile gözden geçirip commit/push yapın.
#
# Ortam: KURS_APP_DIR (varsayılan /home/oritoriu/kurs-app)
set -euo pipefail

DESKTOP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
KURS_APP_DIR="${KURS_APP_DIR:-/home/oritoriu/kurs-app}"
TARGET="${1:?Kullanım: assemble-repo.sh <hedef-klasör>}"
mkdir -p "$TARGET"
TARGET="$(cd "$TARGET" && pwd)"

common_excludes=(
  --include='.env.example'
  --exclude='.git/' --exclude='node_modules/' --exclude='.DS_Store'
  --exclude='.env' --exclude='.env.*'
  --exclude='*.p12' --exclude='*.p8' --exclude='*.pem' --exclude='*.key' --exclude='*.cer' --exclude='*.keychain-db'
  --exclude='*.sql' --exclude='*.sql.gz' --exclude='*.sqlite' --exclude='*.sqlite-*'
  --exclude='*.log' --exclude='error_log' --exclude='*.bak' --exclude='*.orig'
  --exclude='.phpunit.cache/' --exclude='.phpunit.result.cache' --exclude='*.tsbuildinfo'
  --exclude='.idea/' --exclude='.vscode/' --exclude='.cursor/' --exclude='.codex'
)

echo "▶ Web uygulaması → $TARGET"
rsync -a --delete \
  --exclude='/desktop/' --exclude='/.github/' --exclude='/.gitignore' \
  --exclude='/vendor/' --exclude='/public/build/' --exclude='/public/hot' --exclude='/public/storage' \
  --exclude='/storage/app/private/*' --exclude='/storage/app/public/*' \
  --exclude='/storage/framework/cache/*' --exclude='/storage/framework/sessions/*' \
  --exclude='/storage/framework/views/*' --exclude='/storage/framework/testing/*' \
  --exclude='/storage/logs/*' --exclude='/storage/pail/' --exclude='/bootstrap/cache/*' \
  --include='.gitignore' \
  --exclude='demo-users.txt' --exclude='backups/' \
  "${common_excludes[@]}" \
  --filter='P /.git/' --filter='P /desktop/' --filter='P /.github/' \
  "$KURS_APP_DIR/" "$TARGET/"

# storage/bootstrap iskelet .gitignore dosyaları (kaynakta varsa yukarıdaki --include ile gelir)
for d in storage/app storage/app/private storage/app/public storage/framework storage/framework/cache \
         storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache; do
  mkdir -p "$TARGET/$d"
  [ -f "$TARGET/$d/.gitignore" ] || printf '*\n!.gitignore\n' > "$TARGET/$d/.gitignore"
done

echo "▶ Masaüstü → $TARGET/desktop"
mkdir -p "$TARGET/desktop"
rsync -a --delete \
  --exclude='/dist/' --exclude='/build/' --exclude='/.spc/' --exclude='/out/' \
  --exclude='/src-tauri/target/' --exclude='/src-tauri/gen/' \
  --exclude='/src-tauri/binaries/php-*' --exclude='/runtime/cacert.pem' \
  --exclude='/.github/' --exclude='/repo-gitignore' \
  "${common_excludes[@]}" \
  "$DESKTOP_DIR/" "$TARGET/desktop/"
mkdir -p "$TARGET/desktop/src-tauri/binaries"
[ -f "$TARGET/desktop/src-tauri/binaries/.gitkeep" ] || : > "$TARGET/desktop/src-tauri/binaries/.gitkeep"

echo "▶ İş akışları ve kök .gitignore"
mkdir -p "$TARGET/.github/workflows"
# Tüm workflow'ları kopyala (desktop-macos, desktop-windows, mobile-android, mobile-ios, …)
cp "$DESKTOP_DIR/.github/workflows/"*.yml "$TARGET/.github/workflows/"
cp "$DESKTOP_DIR/repo-gitignore" "$TARGET/.gitignore"

echo "▶ Sır taraması"
bad="$(find "$TARGET" -path "$TARGET/.git" -prune -o -type f \( -name '.env' -o -name '*.p12' -o -name '*.p8' -o -name '*.pem' -o -name '*.key' -o -name '*.sql*' -o -name 'demo-users.txt' \) -print)"
if [ -n "$bad" ]; then
  echo "✖ Hedefte olmaması gereken dosyalar:"; echo "$bad"; exit 1
fi
if grep -rIlE '^(APP_KEY=base64:|DB_PASSWORD=.+|KURS_DATA_KEY=.+|DESKTOP_GITHUB_TOKEN=.+)' "$TARGET" --exclude-dir=.git 2>/dev/null; then
  echo "✖ Sır içeren satır bulundu"; exit 1
fi
echo "✔ Hazır: $TARGET ($(find "$TARGET" -path "$TARGET/.git" -prune -o -type f -print | wc -l | tr -d ' ') dosya)"
echo "  Sonra: cd $TARGET && git add -A && git status && git commit -m '…' && git push"
