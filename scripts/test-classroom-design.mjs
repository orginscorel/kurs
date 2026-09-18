#!/usr/bin/env node
/**
 * 3D derslik tasarımı modülünün SAF yardımcı testleri (çokgen, çarpışma, yapışma, yerleşim üreteci, oturma düzeni).
 * Projede vitest yok: test dosyaları rolldown ile tek dosyaya paketlenir ve Node'un yerleşik test koşucusuyla çalışır.
 *   node scripts/test-classroom-design.mjs
 */
import { build } from 'rolldown'
import { mkdtempSync, readdirSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const dir = join(root, 'resources/js/modules/classroom-design/utils/__tests__')
const files = readdirSync(dir).filter((f) => f.endsWith('.test.ts'))
const out = mkdtempSync(join(tmpdir(), 'cd-test-'))
try {
  for (const f of files) {
    await build({ input: join(dir, f), platform: 'node', external: [/^node:/], output: { file: join(out, f.replace(/\.ts$/, '.mjs')), format: 'esm' }, logLevel: 'silent' })
  }
  const r = spawnSync(process.execPath, ['--test', '--test-reporter=spec', ...files.map((f) => join(out, f.replace(/\.ts$/, '.mjs')))], { stdio: 'inherit' })
  process.exitCode = r.status ?? 1
} finally {
  rmSync(out, { recursive: true, force: true })
}
