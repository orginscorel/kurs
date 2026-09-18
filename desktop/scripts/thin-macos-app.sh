#!/usr/bin/env bash
# İmzalı universal .app'ten işlemciye özel (arm64 / x86_64) ince kopyalar üretir ve yeniden imzalar.
#
#   bash scripts/thin-macos-app.sh "<...>/Erbaa Kurs.app" "$RUNNER_TEMP/thin"
#     → $RUNNER_TEMP/thin/aarch64/Erbaa Kurs.app   (yalnız arm64 dilimleri)
#     → $RUNNER_TEMP/thin/x86_64/Erbaa Kurs.app    (yalnız x86_64 dilimleri)
#
# Ortam:
#   SIGN_IDENTITY  Developer ID kimliği; boş ya da "-" → ad-hoc imza (imzasız deneme)
#   ENTITLEMENTS   sertleştirilmiş çalışma zamanı yetkileri (varsayılan src-tauri/Entitlements.plist)
#
# Neden inceltme (tauri'yi iki hedef için ayrıca derlemek yerine): universal derleme iki mimariyi zaten
# derliyor; ayrı `tauri build --target …` her mimari için yeniden paketler ve notarization'ı SIRAYLA bekler
# (+5-10 dk). İnceltilmiş kopya universal paketle bayt bayt aynı içeriği (Laravel, ön yüz, runtime) taşır;
# yalnız Mach-O dilimleri ayrılır, imza içten dışa yeniden atılır ve notarization/`codesign --verify` bunu doğrular.
#
# İmza sırası (Apple TN2206 / "Signing a Mac Product for Distribution"): önce iç içe kod (Contents/MacOS/php),
# en son paketin kendisi (ana ikiliyi + Info.plist + kaynak mührünü imzalar). `--deep` İMZALAMADA KULLANILMAZ:
# Apple bunu önermez (her iç koda aynı yetki/seçenekleri basar, iç kodun kendi kimliğini ezer, hataları gizler);
# yalnız doğrulamada (`codesign --verify --deep --strict`) kullanılır.
set -euo pipefail

SRC="${1:?universal .app yolu gerekli}"
OUT="${2:?çıktı klasörü gerekli}"
ENTITLEMENTS="${ENTITLEMENTS:-src-tauri/Entitlements.plist}"
IDENTITY="${SIGN_IDENTITY:--}"
[ -n "$IDENTITY" ] || IDENTITY="-"
NAME="$(basename "$SRC")"
PLISTBUDDY="${PLISTBUDDY:-/usr/libexec/PlistBuddy}"
MAIN_EXE="$("$PLISTBUDDY" -c 'Print :CFBundleExecutable' "$SRC/Contents/Info.plist")"

[ -d "$SRC/Contents/MacOS" ] || { echo "::error::Geçersiz .app: $SRC"; exit 1; }
[ -f "$ENTITLEMENTS" ] || { echo "::error::Yetki dosyası yok: $ENTITLEMENTS"; exit 1; }

# Ad-hoc imzada zaman damgası sunucusu kullanılamaz
if [ "$IDENTITY" = "-" ]; then TS=(--timestamp=none); else TS=(--timestamp); fi

sign() { # $1 = yol; iç ikilinin mevcut kimliği (Identifier) korunur
  local target="$1" ident=()
  if [ -f "$target" ]; then
    local id
    id="$(codesign -dv "$target" 2>&1 | sed -n 's/^Identifier=//p' || true)"
    [ -z "$id" ] || ident=(--identifier "$id")
  fi
  # ${a[@]+"${a[@]}"}: macOS'un bash 3.2'sinde boş dizi + set -u hatasını önler
  codesign --force "${TS[@]}" --options runtime --entitlements "$ENTITLEMENTS" ${ident[@]+"${ident[@]}"} --sign "$IDENTITY" "$target"
}

for arch in aarch64 x86_64; do
  lipo_arch="$arch"; [ "$arch" = aarch64 ] && lipo_arch=arm64
  dest="$OUT/$arch/$NAME"
  rm -rf "${OUT:?}/$arch"; mkdir -p "$OUT/$arch"
  # ditto: izinleri, sembolik bağları ve genişletilmiş öznitelikleri koruyarak kopyalar
  ditto "$SRC" "$dest"
  # universal paketin zımbalanmış notarization bileti yeni imzayla geçersiz; stapler yenisini yazacak
  rm -f "$dest/Contents/CodeResources"

  inner=()
  while IFS= read -r -d '' f; do
    archs="$(lipo -archs "$f" 2>/dev/null || true)"
    [ -n "$archs" ] || continue # Mach-O değil
    if [ "$(wc -w <<<"$archs")" -gt 1 ]; then
      lipo -thin "$lipo_arch" "$f" -output "$f.thin"
      chmod 755 "$f.thin"
      mv -f "$f.thin" "$f"
    fi
    got="$(lipo -archs "$f")"
    [ "$got" = "$lipo_arch" ] || { echo "::error::$f beklenen $lipo_arch değil: $got"; exit 1; }
    [ "$(basename "$f")" = "$MAIN_EXE" ] || inner+=("$f")
  done < <(find "$dest/Contents/MacOS" -type f -print0)

  # 1) iç ikililer (php), 2) paket (ana ikili + mühür)
  for f in ${inner[@]+"${inner[@]}"}; do sign "$f"; done
  sign "$dest"

  codesign --verify --deep --strict --verbose=2 "$dest"
  echo "== $arch: $(du -sh "$dest" | cut -f1)"
  for f in "$dest"/Contents/MacOS/*; do echo "   $(basename "$f"): $(lipo -archs "$f"), $(du -h "$f" | cut -f1)"; done
done
