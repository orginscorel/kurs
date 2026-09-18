import { test } from 'node:test'
import assert from 'node:assert/strict'
import { dataFromDoc, docFromData, remapOpenings, DEFAULT_SETTINGS } from '../doc'
import { insertVertex, removeVertex, templatePolygon } from '../polygon'
import type { LayoutData } from '../../types'

const rect = templatePolygon('rect', { width: 7.2, depth: 5.8, cutW: 0, cutD: 0 })

test('belge gidiş-dönüş', () => {
  const data: LayoutData = { schema: 1, room: { polygon: rect, ceiling: 3, wallThickness: 0.2 }, openings: [], objects: [{ id: 'a', type: 'chair', x: 1, z: 2, rot: 0 }] }
  const back = dataFromDoc(docFromData(data), null, DEFAULT_SETTINGS)
  assert.deepEqual(back.objects, data.objects)
  assert.deepEqual(back.room.polygon, rect)
})

test('saat yönündeki çokgen ters çevrilince kapı aynı yerde kalır', () => {
  const cw = rect.slice().reverse() // (0,5.8) (7.2,5.8) (7.2,0) (0,0)
  // cw kenar 1: (7.2,5.8)→(7.2,0) sağ duvar; merkez z=5 → offset 0.8
  const data: LayoutData = { schema: 1, room: { polygon: cw, ceiling: 3, wallThickness: 0.2 }, openings: [{ id: 'd', kind: 'door', wall: 1, offset: 0.8, width: 0.9, height: 2.1, sill: 0 }], objects: [] }
  const doc = docFromData(data)
  const o = doc.openings[0]!
  const e = doc.room.polygon
  const a = e[o.wall]!
  const b = e[(o.wall + 1) % e.length]!
  const len = Math.hypot(b.x - a.x, b.z - a.z)
  const c = { x: a.x + ((b.x - a.x) / len) * o.offset, z: a.z + ((b.z - a.z) / len) * o.offset }
  assert.ok(Math.abs(c.x - 7.2) < 1e-9 && Math.abs(c.z - 5) < 1e-9, `kapı merkezi (7,2; 5): ${c.x}, ${c.z}`)
})

test('köşe eklenince kapı doğru duvar parçasına geçer', () => {
  const door = { id: 'd', kind: 'door' as const, wall: 0, offset: 6, width: 0.9, height: 2.1, sill: 0 }
  const after = insertVertex(rect, 0) // duvar 0 ikiye bölünür (3,6 m + 3,6 m)
  const [o] = remapOpenings([door], rect, after, false)
  assert.equal(o!.wall, 1)
  assert.ok(Math.abs(o!.offset - 2.4) < 1e-9)
  const [back] = remapOpenings([o!], after, removeVertex(after, 1), false)
  assert.equal(back!.wall, 0)
  assert.ok(Math.abs(back!.offset - 6) < 1e-9)
})
