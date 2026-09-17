#!/usr/bin/env node
/**
 * Yayına girecek modüllerin açık import listesini üretir: resources/js/app/modules.generated.ts
 *
 *   VITE_MODULES=core,students,academic node scripts/gen-modules.mjs
 *
 * import.meta.glob tüm modül klasörlerini derlemeye soktuğu için, geliştirmesi süren
 * (derlenmeyen) bir modül bütün yayını bozuyordu. Bu dosya yalnız izinli modülleri içerir.
 * VITE_MODULES boşsa resources/js/modules altındaki module.tsx içeren tüm klasörler alınır.
 */
import { existsSync, readdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const modulesDir = join(root, 'resources/js/modules')
const available = readdirSync(modulesDir, { withFileTypes: true })
  .filter((d) => d.isDirectory() && existsSync(join(modulesDir, d.name, 'module.tsx')))
  .map((d) => d.name)
  .sort()

const requested = (process.env.VITE_MODULES ?? '').split(',').map((s) => s.trim()).filter(Boolean)
const missing = requested.filter((m) => !available.includes(m))
if (missing.length) {
  console.error(`Bulunamayan modül(ler): ${missing.join(', ')}`)
  process.exit(1)
}
const selected = requested.length ? requested : available

const lines = [
  '// OTOMATİK ÜRETİLDİ — scripts/gen-modules.mjs. Elle düzenlemeyin.',
  "import type { ModuleDef } from './modules'",
  ...selected.map((m, i) => `import m${i} from '../modules/${m}/module'`),
  '',
  `export const loadedModules: ModuleDef[] = [${selected.map((_, i) => `m${i}`).join(', ')}]`,
  '',
]
writeFileSync(join(root, 'resources/js/app/modules.generated.ts'), lines.join('\n'))
console.log(`Modüller: ${selected.join(', ')}`)
