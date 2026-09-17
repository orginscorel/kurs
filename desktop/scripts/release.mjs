#!/usr/bin/env node
/**
 * Masaüstü sürümü (web sürümünden bağımsız semver). Tek kaynak: CHANGELOG.json (en üstteki kayıt).
 *
 *   node scripts/release.mjs minor "Başlık" "yeni: ..." "iyilestirme: ..." "duzeltme: ..."
 *   node scripts/release.mjs patch "Küçük düzeltmeler" "duzeltme: ..."
 *   node scripts/release.mjs add "iyilestirme: ..."        # en üst sürüme madde ekler
 *   node scripts/release.mjs sync                           # yalnız sürümü package.json + Cargo.toml'a yazar
 *   node scripts/release.mjs check                          # sürümler tutarlı mı (CI)
 *
 * Sürüm; package.json (tauri.conf.json oradan okur) ve src-tauri/Cargo.toml'a da yazılır.
 * Yayın: git tag desktop-v<sürüm> && git push --tags  → GitHub Actions derler, imzalar, yayımlar.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const files = {
  changelog: path.join(root, 'CHANGELOG.json'),
  pkg: path.join(root, 'package.json'),
  lock: path.join(root, 'package-lock.json'),
  cargo: path.join(root, 'src-tauri', 'Cargo.toml'),
}
const log = JSON.parse(fs.readFileSync(files.changelog, 'utf8'))
const [mode, ...rest] = process.argv.slice(2)
const today = new Date().toISOString().slice(0, 10)

const parseItem = (s) => {
  const m = /^(yeni|iyilestirme|duzeltme)\s*:\s*(.+)$/.exec(s)
  if (!m) throw new Error(`Madde biçimi "tür: metin" olmalı (yeni|iyilestirme|duzeltme): ${s}`)
  return { type: m[1], text: m[2].trim() }
}

function cargoVersion() {
  return /^version\s*=\s*"([^"]+)"/m.exec(fs.readFileSync(files.cargo, 'utf8'))?.[1]
}

function writeVersion(version) {
  const pkg = JSON.parse(fs.readFileSync(files.pkg, 'utf8'))
  pkg.version = version
  fs.writeFileSync(files.pkg, JSON.stringify(pkg, null, 2) + '\n')
  if (fs.existsSync(files.lock)) {
    const lock = JSON.parse(fs.readFileSync(files.lock, 'utf8'))
    lock.version = version
    if (lock.packages?.['']) lock.packages[''].version = version
    fs.writeFileSync(files.lock, JSON.stringify(lock, null, 2) + '\n')
  }
  const cargo = fs.readFileSync(files.cargo, 'utf8')
  // yalnız [package] bölümündeki ilk version satırı
  fs.writeFileSync(files.cargo, cargo.replace(/^version\s*=\s*"[^"]+"/m, `version = "${version}"`))
}

if (mode === 'check') {
  const want = log[0].version
  const pkg = JSON.parse(fs.readFileSync(files.pkg, 'utf8')).version
  const cargo = cargoVersion()
  const tag = process.env.GITHUB_REF_NAME?.startsWith('desktop-v') ? process.env.GITHUB_REF_NAME.slice('desktop-v'.length) : null
  const problems = []
  if (pkg !== want) problems.push(`package.json ${pkg} ≠ CHANGELOG ${want}`)
  if (cargo !== want) problems.push(`Cargo.toml ${cargo} ≠ CHANGELOG ${want}`)
  if (tag && tag !== want) problems.push(`etiket ${tag} ≠ CHANGELOG ${want}`)
  if (problems.length) {
    console.error('Sürüm tutarsız:\n  ' + problems.join('\n  ') + '\nDüzeltmek için: node scripts/release.mjs sync')
    process.exit(1)
  }
  console.log(want)
  process.exit(0)
}

if (mode === 'sync') {
  writeVersion(log[0].version)
  console.log(`Sürüm yazıldı: ${log[0].version}`)
  process.exit(0)
}

if (mode === 'add') {
  if (!rest.length) throw new Error('En az bir madde gerekli.')
  log[0].items.push(...rest.map(parseItem))
} else if (['major', 'minor', 'patch'].includes(mode)) {
  const [title, ...items] = rest
  if (!title || !items.length) throw new Error('Başlık ve en az bir madde gerekli.')
  const [a, b, c] = log[0].version.split('.').map(Number)
  const version = mode === 'major' ? `${a + 1}.0.0` : mode === 'minor' ? `${a}.${b + 1}.0` : `${a}.${b}.${c + 1}`
  log.unshift({ version, date: today, title, items: items.map(parseItem) })
} else {
  console.error('Kullanım: node scripts/release.mjs <major|minor|patch> "Başlık" "tür: metin"... | add "tür: metin"... | sync | check')
  process.exit(1)
}

fs.writeFileSync(files.changelog, JSON.stringify(log, null, 2) + '\n')
writeVersion(log[0].version)
console.log(`Masaüstü sürümü ${log[0].version}: ${log[0].items.length} madde`)
console.log(`Yayımlamak için: git commit -am "desktop: v${log[0].version}" && git tag desktop-v${log[0].version} && git push && git push --tags`)
