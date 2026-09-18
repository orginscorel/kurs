import { test } from 'node:test'
import assert from 'node:assert/strict'
import { area, centroid, edgeAt, insertVertex, normalizePolygon, offsetPolygon, pointInPolygon, removeVertex, selfIntersects, signedArea, templatePolygon, validatePolygon } from '../polygon'

const rect = templatePolygon('rect', { width: 7.2, depth: 5.8, cutW: 0, cutD: 0 })

test('dikdörtgen: alan, yön, merkez', () => {
  assert.ok(signedArea(rect) > 0, 'şablon saat yönünün tersine')
  assert.equal(Math.round(area(rect) * 100) / 100, 41.76)
  const c = centroid(rect)
  assert.ok(Math.abs(c.x - 3.6) < 1e-9 && Math.abs(c.z - 2.9) < 1e-9)
  // duvar 0 = tahta duvarı (üst), dış normal −z
  const e0 = edgeAt(rect, 0)
  assert.deepEqual([e0.n.x, e0.n.z].map((v) => Math.round(v)), [0, -1])
})

test('ters sıralı çokgen normalize edilir', () => {
  const cw = rect.slice().reverse()
  assert.ok(signedArea(cw) < 0)
  assert.ok(signedArea(normalizePolygon(cw)) > 0)
})

test('L şablonu: kesik köşe içeride değil', () => {
  const l = templatePolygon('l', { width: 8, depth: 6, cutW: 3, cutD: 2 })
  assert.equal(l.length, 6)
  assert.equal(area(l), 8 * 6 - 3 * 2)
  assert.ok(pointInPolygon({ x: 1, z: 1 }, l))
  assert.ok(!pointInPolygon({ x: 7, z: 5.5 }, l), 'arka sağ kesik bölge odanın dışında')
})

test('U-girintili ve kesik köşe şablonları geçerli', () => {
  const u = templatePolygon('u', { width: 9, depth: 7, cutW: 2, cutD: 1.5 })
  assert.equal(u.length, 8)
  assert.equal(area(u), 9 * 7 - 3)
  assert.ok(!pointInPolygon({ x: 4.5, z: 6.5 }, u), 'girinti odanın dışında')
  assert.deepEqual(validatePolygon(u), [])
  const ch = templatePolygon('chamfer', { width: 6, depth: 5, cutW: 1, cutD: 1 })
  assert.equal(area(ch), 30 - 0.5)
  assert.deepEqual(validatePolygon(ch), [])
})

test('kendini kesen çokgen yakalanır', () => {
  const bow = [{ x: 0, z: 0 }, { x: 4, z: 4 }, { x: 4, z: 0 }, { x: 0, z: 4 }]
  assert.ok(selfIntersects(bow))
  assert.ok(validatePolygon(bow).some((p) => p.code === 'cross'))
  assert.ok(!selfIntersects(rect))
})

test('dışa öteleme duvar kalınlığı kadar büyütür (gönye)', () => {
  const o = offsetPolygon(rect, 0.2)
  assert.equal(o.length, 4)
  assert.ok(Math.abs(o[0]!.x + 0.2) < 1e-9 && Math.abs(o[0]!.z + 0.2) < 1e-9)
  assert.ok(Math.abs(area(o) - 7.6 * 6.2) < 1e-9)
})

test('köşe ekle / sil', () => {
  const p = insertVertex(rect, 0)
  assert.equal(p.length, 5)
  assert.deepEqual(p[1], { x: 3.6, z: 0 })
  assert.equal(removeVertex(p, 1).length, 4)
  assert.equal(removeVertex(templatePolygon('rect', { width: 1, depth: 1, cutW: 0, cutD: 0 }).slice(0, 3), 0).length, 3, 'üçgenden köşe silinmez')
})
