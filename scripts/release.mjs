#!/usr/bin/env node
/**
 * Değişiklik günlüğüne kayıt ekler (resources/changelog.json).
 *
 *   node scripts/release.mjs minor "Başlık" "yeni: ..." "iyilestirme: ..." "duzeltme: ..."
 *   node scripts/release.mjs patch "Küçük düzeltmeler" "duzeltme: ..."
 *   node scripts/release.mjs add "iyilestirme: ..."      # mevcut (en üst) sürüme madde ekler
 *
 * Sonra derleyin; açık ekranlarda "Yeni sürüm yayında" penceresi çıkar.
 */
import fs from 'node:fs'
import path from 'node:path'

const file = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../resources/changelog.json')
const log = JSON.parse(fs.readFileSync(file, 'utf8'))
const [mode, ...rest] = process.argv.slice(2)
const parseItem = (s) => {
  const m = /^(yeni|iyilestirme|duzeltme)\s*:\s*(.+)$/.exec(s)
  if (!m) throw new Error(`Madde biçimi "tür: metin" olmalı (yeni|iyilestirme|duzeltme): ${s}`)
  return { type: m[1], text: m[2].trim() }
}
const today = new Date().toISOString().slice(0, 10)

if (mode === 'add') {
  log[0].items.push(...rest.map(parseItem))
} else if (['major', 'minor', 'patch'].includes(mode)) {
  const [title, ...items] = rest
  if (!title || !items.length) throw new Error('Başlık ve en az bir madde gerekli.')
  const [a, b, c] = log[0].version.split('.').map(Number)
  const version = mode === 'major' ? `${a + 1}.0.0` : mode === 'minor' ? `${a}.${b + 1}.0` : `${a}.${b}.${c + 1}`
  log.unshift({ version, date: today, title, items: items.map(parseItem) })
} else {
  console.error('Kullanım: node scripts/release.mjs <major|minor|patch> "Başlık" "tür: metin"... | add "tür: metin"...')
  process.exit(1)
}
fs.writeFileSync(file, JSON.stringify(log, null, 2) + '\n')
console.log(`Sürüm ${log[0].version}: ${log[0].items.length} madde`)
