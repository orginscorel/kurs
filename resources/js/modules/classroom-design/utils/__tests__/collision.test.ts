import { test } from 'node:test'
import assert from 'node:assert/strict'
import { checkObject, footprintQuad, nearestNeighbor, openingZones, quadsOverlap, invalidIds } from '../collision'
import { templatePolygon } from '../polygon'
import type { Room, SceneObject, Opening } from '../../types'

const room: Room = { polygon: templatePolygon('rect', { width: 7.2, depth: 5.8, cutW: 0, cutD: 0 }), ceiling: 3, wallThickness: 0.2 }
const desk = (id: string, x: number, z: number, rot = 0): SceneObject => ({ id, type: 'desk-single', x, z, rot, chair: true, seats: [null], status: 'normal' })
const world = (objects: SceneObject[], openings: Opening[] = []) => ({ room, zones: openingZones(room, openings), objects })

test('masa + sandalye ayak izi: 70 × 92 cm, döndürünce eksenler yer değiştirir', () => {
  const q = footprintQuad(desk('a', 2, 2), 3)
  assert.ok(Math.abs(q[1].x - q[0].x - 0.7) < 1e-9)
  assert.ok(Math.abs(q[3].z - q[0].z - 0.92) < 1e-9)
  const r = footprintQuad(desk('a', 2, 2, Math.PI / 2), 3)
  const xs = r.map((p) => p.x)
  assert.ok(Math.abs(Math.max(...xs) - Math.min(...xs) - 0.92) < 1e-9)
})

test('yan yana değen masalar çakışmaz, iç içe girenler çakışır', () => {
  const a = desk('a', 2, 2)
  assert.ok(checkObject(desk('b', 2.7, 2), world([a])).ok, '70 cm aralıkla değme serbest')
  const r = checkObject(desk('b', 2.5, 2), world([a]))
  assert.equal(r.ok, false)
  assert.deepEqual(r.hits, ['a'])
})

test('duvar dışına taşan nesne geçersiz', () => {
  const r = checkObject(desk('a', 0.2, 2), world([]))
  assert.equal(r.outside, true)
  assert.equal(r.ok, false)
})

test('L odada kesik bölgeye masa konamaz', () => {
  const l: Room = { ...room, polygon: templatePolygon('l', { width: 8, depth: 6, cutW: 3, cutD: 2 }) }
  const r = checkObject(desk('a', 6.8, 5.2), { room: l, zones: [], objects: [] })
  assert.equal(r.ok, false)
  assert.ok(checkObject(desk('a', 2, 2), { room: l, zones: [], objects: [] }).ok)
})

test('içbükey köşe masanın ortasından geçemez', () => {
  const l: Room = { ...room, polygon: templatePolygon('l', { width: 8, depth: 6, cutW: 3, cutD: 2 }) }
  // köşe (5, 4): masa köşeyi içine alacak şekilde
  const r = checkObject({ ...desk('a', 5, 4), chair: false }, { room: l, zones: [], objects: [] })
  assert.equal(r.ok, false)
})

test('dikeyde kesişmeyen nesneler (klima ↔ masa) çakışmaz', () => {
  const ac: SceneObject = { id: 'ac', type: 'ac', x: 2, z: 0.125, rot: Math.PI, elev: 2.25 }
  assert.ok(checkObject(desk('a', 2, 0.6), world([ac])).ok)
})

test('kapı önüne masa konamaz, dışa açılan kapıda geçiş payı 50 cm', () => {
  const door: Opening = { id: 'd', kind: 'door', wall: 1, offset: 5, width: 0.9, height: 2.1, sill: 0, swing: 'in-left' }
  const r = checkObject({ ...desk('a', 6.6, 5.0, Math.PI / 2), chair: false }, world([], [door]))
  assert.deepEqual(r.zones, ['d'])
  const out = { ...door, swing: 'out-left' as const }
  assert.ok(checkObject({ ...desk('a', 6.3, 5.0, Math.PI / 2), chair: false }, world([], [out])).ok, 'dışa açılanda 50 cm sonrası serbest')
})

test('tahta pencerenin üstüne asılamaz ama yanına asılabilir', () => {
  const win: Opening = { id: 'w', kind: 'window', wall: 0, offset: 2, width: 1.2, height: 1.4, sill: 0.9 }
  const board: SceneObject = { id: 'b', type: 'smartboard', x: 2, z: 0.06, rot: Math.PI, elev: 0.9 }
  assert.equal(checkObject(board, world([], [win])).ok, false)
  assert.ok(checkObject({ ...board, x: 5 }, world([], [win])).ok)
})

test('toplu taşımada grup içi çakışma yakalanır', () => {
  const bad = invalidIds([desk('a', 2, 2), desk('b', 2.3, 2)], world([]))
  assert.deepEqual([...bad].sort(), ['a', 'b'])
})

test('en yakın komşu mesafesi (↔ cm)', () => {
  const a = desk('a', 2, 2)
  const b = desk('b', 3.5, 2)
  const n = nearestNeighbor(a, room, [a, b])!
  assert.equal(n.target, 'object')
  assert.equal(Math.round(n.dist * 100), 80)
  const w = nearestNeighbor(desk('c', 1, 3), room, [])!
  assert.equal(w.target, 'wall')
  assert.equal(Math.round(w.dist * 100), 65)
})

test('OBB örtüşme: 45° döndürülmüş kare', () => {
  const s = (x: number) => [{ x, z: 0 }, { x: x + 1, z: 0 }, { x: x + 1, z: 1 }, { x, z: 1 }]
  const diamond = [{ x: 1.5, z: -0.2 }, { x: 2.2, z: 0.5 }, { x: 1.5, z: 1.2 }, { x: 0.8, z: 0.5 }]
  assert.ok(quadsOverlap(s(0), diamond))
  assert.ok(!quadsOverlap(s(3), diamond))
})
