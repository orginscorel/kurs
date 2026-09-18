#!/usr/bin/env node
/**
 * Tauri güncelleyici bildirimi (latest.json) üretir.
 *
 *   node scripts/make-latest-json.mjs --version 1.12.2 --tag desktop-v1.12.2 --repo orginscorel/kurs \
 *        --archive ErbaaKurs_1.12.2_universal.app.tar.gz --sig ErbaaKurs_1.12.2_universal.app.tar.gz.sig \
 *        --archive-aarch64 ErbaaKurs_1.12.2_aarch64.app.tar.gz --sig-aarch64 ErbaaKurs_1.12.2_aarch64.app.tar.gz.sig \
 *        --archive-x86_64 ErbaaKurs_1.12.2_x86_64.app.tar.gz --sig-x86_64 ErbaaKurs_1.12.2_x86_64.app.tar.gz.sig \
 *        --out latest.json
 *
 * Platform anahtarları: tauri-plugin-updater (2.11) önce `{os}-{arch}-{kurulum}` (darwin-aarch64-app), sonra
 * `{os}-{arch}` (darwin-aarch64 / darwin-x86_64) anahtarını arar; `arch` ÇALIŞAN ikilinin mimarisidir (universal
 * paket Apple Silicon'da aarch64, Intel'de x86_64 dilimiyle çalışır). `darwin-universal` anahtarını eklenti
 * kendiliğinden hiç okumaz (yalnız `Builder::target("darwin-universal")` elle verilirse); geri uyumluluk ve
 * elle indirme için universal arşivi gösterir. İşlemciye özel arşiv verilmezse o anahtar universal arşive düşer
 * (imzasız deneme / eski akış).
 *
 * `notes` alanı CHANGELOG.json kayıtlarının JSON dizisidir (en fazla 5); uygulamadaki güncelleme penceresi
 * kurulu sürümden yeni olanları gösterir. `url` GitHub Releases adresidir; sunucudaki
 * `kurs:desktop-release-sync` dosyaları kendi alan adına alır ve url'leri oraya çevirir (depo özel).
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const args = Object.fromEntries(
  process.argv.slice(2).reduce((acc, cur, i, all) => (cur.startsWith('--') ? [...acc, [cur.slice(2), all[i + 1]]] : acc), []),
)
const fail = (msg) => {
  console.error(msg)
  process.exit(1)
}
for (const k of ['version', 'tag', 'repo', 'archive', 'sig', 'out']) {
  if (!args[k]) fail(`--${k} gerekli`)
}

const changelog = JSON.parse(fs.readFileSync(path.join(root, 'CHANGELOG.json'), 'utf8'))
if (changelog[0].version !== args.version) {
  fail(`CHANGELOG en üst sürüm (${changelog[0].version}) ≠ ${args.version}`)
}

/** Tauri imzası: base64 kodlu minisign metni ("untrusted comment: …" + imza + "trusted comment: …" + genel imza). */
function readSignature(sigPath, archivePath) {
  if (!fs.existsSync(archivePath)) fail(`Arşiv yok: ${archivePath}`)
  const signature = fs.readFileSync(sigPath, 'utf8').trim()
  if (!signature) fail(`İmza dosyası boş: ${sigPath}`)
  const text = Buffer.from(signature, 'base64').toString('utf8')
  const lines = text.split('\n').filter(Boolean)
  if (!/^[A-Za-z0-9+/=]+$/.test(signature) || lines.length < 4 || !lines[0].startsWith('untrusted comment:') || !lines[2].startsWith('trusted comment:')) {
    fail(`İmza biçimi tanınmadı (minisign/Tauri bekleniyordu): ${sigPath}`)
  }
  return signature
}

const entry = (archive, sig) => ({
  signature: readSignature(sig, archive),
  url: `https://github.com/${args.repo}/releases/download/${encodeURIComponent(args.tag)}/${encodeURIComponent(path.basename(archive))}`,
})
const universal = entry(args.archive, args.sig)
const perArch = (arch) => {
  const a = args[`archive-${arch}`]
  const s = args[`sig-${arch}`]
  if (!a && !s) return universal
  if (!a || !s) fail(`--archive-${arch} ve --sig-${arch} birlikte verilmeli`)
  return entry(a, s)
}

const conf = JSON.parse(fs.readFileSync(path.join(root, 'src-tauri', 'tauri.conf.json'), 'utf8'))
const manifest = {
  version: args.version,
  notes: JSON.stringify(changelog.slice(0, 5)),
  pub_date: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
  minimum_system_version: conf.bundle?.macOS?.minimumSystemVersion ?? '12.0',
  platforms: {
    'darwin-aarch64': perArch('aarch64'),
    'darwin-x86_64': perArch('x86_64'),
    'darwin-universal': universal,
  },
}
fs.writeFileSync(args.out, JSON.stringify(manifest, null, 2) + '\n')
console.log(`latest.json yazıldı: v${args.version}`)
for (const [k, p] of Object.entries(manifest.platforms)) console.log(`  ${k.padEnd(16)} → ${p.url}`)
