#!/usr/bin/env node
/**
 * Tauri güncelleyici bildirimi (latest.json) üretir.
 *
 *   node scripts/make-latest-json.mjs --version 0.2.0 --tag desktop-v0.2.0 --repo orginscorel/kurs \
 *        --archive "Erbaa Kurs.app.tar.gz" --sig "Erbaa Kurs.app.tar.gz.sig" --out latest.json
 *
 * `notes` alanı CHANGELOG.json kayıtlarının JSON dizisidir (en fazla 5); uygulamadaki güncelleme penceresi
 * kurulu sürümden yeni olanları gösterir. `url` GitHub Releases adresidir; sunucudaki
 * `kurs:desktop-release-sync` dosyaları kendi alan adına alır ve url'leri oraya çevirir (depo özel).
 * Evrensel (universal) paket: darwin-aarch64 ve darwin-x86_64 aynı arşivi gösterir.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const args = Object.fromEntries(
  process.argv.slice(2).reduce((acc, cur, i, all) => (cur.startsWith('--') ? [...acc, [cur.slice(2), all[i + 1]]] : acc), []),
)
for (const k of ['version', 'tag', 'repo', 'archive', 'sig', 'out']) {
  if (!args[k]) {
    console.error(`--${k} gerekli`)
    process.exit(1)
  }
}

const changelog = JSON.parse(fs.readFileSync(path.join(root, 'CHANGELOG.json'), 'utf8'))
if (changelog[0].version !== args.version) {
  console.error(`CHANGELOG en üst sürüm (${changelog[0].version}) ≠ ${args.version}`)
  process.exit(1)
}
const signature = fs.readFileSync(args.sig, 'utf8').trim()
if (!signature) {
  console.error('İmza dosyası boş')
  process.exit(1)
}
const url = `https://github.com/${args.repo}/releases/download/${encodeURIComponent(args.tag)}/${encodeURIComponent(path.basename(args.archive))}`
const platform = { signature, url }
const conf = JSON.parse(fs.readFileSync(path.join(root, 'src-tauri', 'tauri.conf.json'), 'utf8'))

const manifest = {
  version: args.version,
  notes: JSON.stringify(changelog.slice(0, 5)),
  pub_date: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
  minimum_system_version: conf.bundle?.macOS?.minimumSystemVersion ?? '12.0',
  platforms: {
    'darwin-aarch64': platform,
    'darwin-x86_64': platform,
    'darwin-universal': platform,
  },
}
fs.writeFileSync(args.out, JSON.stringify(manifest, null, 2) + '\n')
console.log(`latest.json yazıldı: v${args.version} → ${url}`)
