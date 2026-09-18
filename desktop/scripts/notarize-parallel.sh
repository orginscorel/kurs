#!/usr/bin/env bash
# Birden çok ürünü (DMG / .app) Apple notarization'a AYNI ANDA gönderir, hepsini bekler, sonra zımbalar.
#
#   bash scripts/notarize-parallel.sh "<...>.dmg" "<...>/aarch64/Erbaa Kurs.app" "<...>/x86_64/Erbaa Kurs.app"
#
# Ortam: APPLE_API_KEY_PATH (.p8), APPLE_API_KEY_ID, APPLE_API_ISSUER; isteğe bağlı NOTARY_TIMEOUT (varsayılan 60m).
# .app notarytool'a doğrudan verilemez: `ditto -c -k --keepParent` ile zip'lenir; bilet zip'e değil .app'e zımbalanır.
# Gönderimler --wait'siz yapılır (Apple tarafında paralel işlenir); toplam süre ≈ en yavaş gönderim.
set -euo pipefail

[ $# -gt 0 ] || { echo "kullanım: $0 <dmg|app>..."; exit 2; }
: "${APPLE_API_KEY_PATH:?}" "${APPLE_API_KEY_ID:?}" "${APPLE_API_ISSUER:?}"
TIMEOUT="${NOTARY_TIMEOUT:-60m}"
AUTH=(--key "$APPLE_API_KEY_PATH" --key-id "$APPLE_API_KEY_ID" --issuer "$APPLE_API_ISSUER")
WORK="$(mktemp -d "${RUNNER_TEMP:-/tmp}/notary.XXXXXX")"

json_field() { # $1 = alan, stdin = notarytool JSON çıktısı
  node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{let v="";try{v=JSON.parse(s)[process.argv[1]]??""}catch{}console.log(v)})' "$1"
}

submit() { # $1 = sıra, $2 = ürün → $WORK/$1.id
  local n="$1" item="$2" upload="$2" out id
  if [ -d "$item" ]; then
    upload="$WORK/$n-$(basename "$item" .app).zip"
    ditto -c -k --keepParent "$item" "$upload"
  fi
  out="$(xcrun notarytool submit "$upload" "${AUTH[@]}" --no-wait --output-format json)" || { echo "::error::Gönderilemedi: $item — $out"; return 1; }
  id="$(json_field id <<<"$out")"
  [ -n "$id" ] || { echo "::error::Gönderim kimliği yok: $item — $out"; return 1; }
  echo "Gönderildi: $(basename "$(dirname "$item")")/$(basename "$item") ($(du -h "$upload" | cut -f1)) → $id"
  printf '%s' "$id" >"$WORK/$n.id"
}

# Zip'leme + yükleme de paralel (her biri 40-70 MB)
pids=(); n=0
for item in "$@"; do
  [ -e "$item" ] || { echo "::error::Yok: $item"; exit 1; }
  submit "$n" "$item" &
  pids+=($!); n=$((n + 1))
done
for p in "${pids[@]}"; do wait "$p" || { echo "::error::Notarization gönderimi başarısız"; exit 1; }; done
ids=()
for ((i = 0; i < n; i++)); do ids+=("$(cat "$WORK/$i.id")"); done

# Hepsi Apple'da aynı anda işleniyor; sırayla beklemek toplamı uzatmaz
failed=0
for i in "${!ids[@]}"; do
  id="${ids[$i]}"; item="${*:$((i + 1)):1}"
  xcrun notarytool wait "$id" "${AUTH[@]}" --timeout "$TIMEOUT" --output-format json >"$WORK/$id.json" || true
  status="$(xcrun notarytool info "$id" "${AUTH[@]}" --output-format json | json_field status || true)"
  echo "$(basename "$(dirname "$item")")/$(basename "$item"): $status"
  if [ "$status" != "Accepted" ]; then
    echo "::error::Notarization reddedildi/bitmedi: $item ($status)"
    xcrun notarytool log "$id" "${AUTH[@]}" || true
    failed=1
  fi
done
[ "$failed" = 0 ] || exit 1

for item in "$@"; do
  xcrun stapler staple "$item"
  xcrun stapler validate "$item"
done
rm -rf "$WORK"
