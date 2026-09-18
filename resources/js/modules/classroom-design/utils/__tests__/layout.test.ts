import { test } from 'node:test'
import assert from 'node:assert/strict'
import { DEFAULT_GENERATOR, detectBoardWall, generateLayout, numberDesks } from '../layoutGenerator'
import { checkObject, openingZones } from '../collision'
import { templatePolygon } from '../polygon'
import type { Opening, Room, SceneObject } from '../../types'

const room: Room = { polygon: templatePolygon('rect', { width: 7.2, depth: 5.8, cutW: 0, cutD: 0 }), ceiling: 3, wallThickness: 0.2 }
const door: Opening = { id: 'd', kind: 'door', wall: 1, offset: 5, width: 0.9, height: 2.1, sill: 0, swing: 'in-left' }

test('tahta duvarı tahtadan bulunur', () => {
  const board: SceneObject = { id: 'b', type: 'smartboard', x: 7.14, z: 3, rot: 0 }
  assert.equal(detectBoardWall(room, [board]), 1)
  assert.equal(detectBoardWall(room, []), 0, 'tahta yoksa en uzun duvar')
})

test('7,20 × 5,80 odada 20 öğrenci için 20 tek masa; hepsi geçerli, tahtaya mesafe korunur', () => {
  const front: Opening = { ...door, offset: 0.9 }
  const r = generateLayout(room, [front], [], { ...DEFAULT_GENERATOR, gapZ: 0.12, wallClearance: 0.3, boardClearance: 1.45, students: 20, boardWall: 0, deskType: 'desk-single' })
  assert.equal(r.placed, 20)
  assert.equal(r.capacity, 20)
  assert.equal(r.shortage, 0)
  const zones = openingZones(room, [front])
  for (const o of r.objects) {
    assert.ok(checkObject(o, { room, zones, objects: r.objects }).ok, `masa ${o.no} geçerli`)
    assert.ok(o.z - 0.25 >= 1.45 - 1e-6, 'ilk sıra tahtadan 1,45 m geride')
  }
  assert.deepEqual(r.objects.map((o) => o.no), Array.from({ length: 20 }, (_, i) => i + 1))
})

test('kapı önü ızgarayı kesiyorsa ızgara yana kaydırılır; kapı önü boş kalır', () => {
  const r = generateLayout(room, [door], [], { ...DEFAULT_GENERATOR, gapZ: 0.12, wallClearance: 0.3, boardClearance: 1.45, students: 20, boardWall: 0, deskType: 'desk-single' })
  assert.equal(r.placed, 20)
  const zones = openingZones(room, [door])
  for (const o of r.objects) assert.deepEqual(checkObject(o, { room, zones, objects: r.objects }).zones, [])
})

test('kaçınılmaz engelde hücre atlanır ve eksik oturak bildirilir', () => {
  const wide: Opening = { ...door, width: 2.4, offset: 4.4 }
  const r = generateLayout(room, [wide], [], { ...DEFAULT_GENERATOR, gapZ: 0.12, wallClearance: 0.3, boardClearance: 1.45, students: 20, boardWall: 0, deskType: 'desk-single' })
  assert.ok(r.skipped > 0 && r.placed < 20)
  assert.equal(r.shortage, 20 - r.capacity)
  const zones = openingZones(room, [wide])
  for (const o of r.objects) assert.deepEqual(checkObject(o, { room, zones, objects: r.objects }).zones, [])
})

test('çift masada öğrenci sayısının yarısı kadar masa', () => {
  const r = generateLayout(room, [], [], { ...DEFAULT_GENERATOR, students: 21, boardWall: 0, deskType: 'desk-double' })
  assert.equal(r.requested, 11)
  assert.ok(r.capacity >= 21 || r.shortage > 0)
})

test('kolon engeli atlanır, L odada kesik bölgeye masa konmaz', () => {
  const l: Room = { ...room, polygon: templatePolygon('l', { width: 8, depth: 7, cutW: 3.5, cutD: 2.5 }) }
  const column: SceneObject = { id: 'k', type: 'column', x: 4, z: 3, rot: 0 }
  const r = generateLayout(l, [], [column], { ...DEFAULT_GENERATOR, students: 0, boardWall: 0, deskType: 'desk-single' })
  assert.ok(r.placed > 10)
  assert.ok(r.skipped > 0)
  for (const o of r.objects) assert.ok(checkObject(o, { room: l, zones: [], objects: [column, ...r.objects] }).ok)
})

test('yan duvardaki tahtaya bakan düzen döndürülür', () => {
  const r = generateLayout(room, [], [], { ...DEFAULT_GENERATOR, students: 6, boardWall: 3, deskType: 'desk-single' })
  assert.equal(r.placed, 6)
  // duvar 3 (sol duvar) → öğrenciler −x'e bakar: ön yön (−sin, −cos) = (−1, 0)
  const f = { x: -Math.sin(r.objects[0]!.rot), z: -Math.cos(r.objects[0]!.rot) }
  assert.ok(f.x < -0.99)
})

test('orta koridor sütunları ayırır', () => {
  const r = generateLayout(room, [], [], { ...DEFAULT_GENERATOR, cols: 4, rows: 1, aisle: 1.2, gapX: 0.3, students: 0, boardWall: 0, deskType: 'desk-single' })
  const xs = r.objects.map((o) => o.x).sort((a, b) => a - b)
  assert.equal(Math.round((xs[2]! - xs[1]! - 0.7) * 100), 120)
  assert.equal(Math.round((xs[1]! - xs[0]! - 0.7) * 100), 30)
})

test('numaralandırma: önden arkaya, soldan sağa', () => {
  const d = (id: string, x: number, z: number): SceneObject => ({ id, type: 'desk-single', x, z, rot: 0 })
  const m = numberDesks(room, [d('c', 1, 3), d('a', 3, 1.5), d('b', 1, 1.6), d('x', 5, 3.1)], 0)
  assert.deepEqual(['b', 'a', 'c', 'x'].map((id) => m.get(id)), [1, 2, 3, 4])
})
