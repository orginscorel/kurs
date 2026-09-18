import { test } from 'node:test'
import assert from 'node:assert/strict'
import { placeObject, snapAngle, snapToWall, snapValue } from '../snap'
import { footprintQuad } from '../collision'
import { templatePolygon } from '../polygon'
import type { Room, SceneObject } from '../../types'

const room: Room = { polygon: templatePolygon('rect', { width: 7.2, depth: 5.8, cutW: 0, cutD: 0 }), ceiling: 3, wallThickness: 0.2 }

test('ızgara ve açı yapışması', () => {
  assert.equal(snapValue(1.26, 0.25), 1.25)
  assert.ok(Math.abs(snapAngle(1.4) - Math.PI / 2) < 1e-9)
  assert.equal(snapAngle(-0.1), 0)
})

test('tahta en yakın duvara yapışır, önü odaya bakar', () => {
  const b: SceneObject = { id: 'b', type: 'smartboard', x: 0, z: 0, rot: 0 }
  const s = snapToWall(b, room, { x: 3, z: 0.4 })
  assert.equal(s.z, 0.06)
  assert.ok(Math.abs(s.rot - Math.PI) < 1e-6, 'üst duvarda 180°')
  const left = snapToWall(b, room, { x: 0.3, z: 3 })
  assert.equal(left.x, 0.06)
  assert.ok(Math.abs(left.rot - Math.PI / 2) < 1e-6 || Math.abs(left.rot - (3 * Math.PI) / 2) < 1e-6)
  // önü odaya: yerel −z → dünya (−sin, −cos) içe bakmalı (+x)
  assert.ok(-Math.sin(left.rot) > 0.99)
})

test('duvar nesnesi duvar ucundan taşmaz', () => {
  const b: SceneObject = { id: 'b', type: 'whiteboard', x: 0, z: 0, rot: 0 }
  const s = snapToWall(b, room, { x: 7.1, z: 0.1 })
  assert.ok(s.x <= 7.2 - 1.5 + 1e-6)
})

test('zemin nesnesi: ayak izi köşesi ızgaraya oturur (sandalye alanı dahil)', () => {
  const d: SceneObject = { id: 'd', type: 'desk-single', x: 0, z: 0, rot: 0, chair: true }
  const p = placeObject(d, room, { x: 2.03, z: 2.07 }, { grid: 0.1, snap: true })
  const q = footprintQuad(p, 3)
  const onGrid = (v: number) => Math.abs(v * 10 - Math.round(v * 10)) < 1e-6
  assert.ok(onGrid(q[0].x) && onGrid(q[0].z), `köşe ızgarada: ${q[0].x}, ${q[0].z}`)
  const free = placeObject(d, room, { x: 2.03, z: 2.07 }, { grid: 0.1, snap: false })
  assert.equal(free.x, 2.03)
})
