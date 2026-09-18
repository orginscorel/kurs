import { test } from 'node:test'
import assert from 'node:assert/strict'
import { assignStudent, autoAssign, computeStats, deskState, findStudentSeat, orderStudents } from '../seating'
import { templatePolygon } from '../polygon'
import type { SceneObject } from '../../types'

const desk = (id: string, no: number, seats = 1, status: SceneObject['status'] = 'normal'): SceneObject => ({
  id, type: seats === 2 ? 'desk-double' : 'desk-single', x: 0, z: 0, rot: 0, no, status, chair: true, seats: Array.from({ length: seats }, () => null),
})
const S = (uuid: string, name = uuid) => ({ uuid, name })

test('boş masaya atama ve başka masaya sürüklenince taşınma', () => {
  let objs: Record<string, SceneObject> = { a: desk('a', 1), b: desk('b', 2) }
  const r1 = assignStudent(objs, S('ali'), 'a')
  assert.ok(r1.ok)
  objs = (r1 as { objects: typeof objs }).objects
  const r2 = assignStudent(objs, S('ali'), 'b')
  assert.ok(r2.ok)
  objs = (r2 as { objects: typeof objs }).objects
  assert.equal(objs.a!.seats![0], null)
  assert.deepEqual(findStudentSeat(objs, 'ali'), { id: 'b', seat: 0 })
})

test('oturan öğrenci dolu masaya bırakılınca yer değiştirir', () => {
  let objs: Record<string, SceneObject> = { a: desk('a', 1), b: desk('b', 2) }
  objs = (assignStudent(objs, S('ali'), 'a') as { objects: typeof objs }).objects
  objs = (assignStudent(objs, S('ayse'), 'b') as { objects: typeof objs }).objects
  const r = assignStudent(objs, S('ali'), 'b')
  assert.ok(r.ok && r.swapped?.uuid === 'ayse')
  objs = (r as { objects: typeof objs }).objects
  assert.equal(objs.a!.seats![0]!.uuid, 'ayse')
  assert.equal(objs.b!.seats![0]!.uuid, 'ali')
})

test('oturmamış öğrenci dolu masaya bırakılınca eskisi boşa çıkar', () => {
  let objs: Record<string, SceneObject> = { a: desk('a', 1) }
  objs = (assignStudent(objs, S('ali'), 'a') as { objects: typeof objs }).objects
  const r = assignStudent(objs, S('veli'), 'a')
  assert.ok(r.ok && r.displaced?.uuid === 'ali')
  assert.equal(findStudentSeat((r as { objects: typeof objs }).objects, 'ali'), null)
})

test('kullanılamaz masaya atama reddedilir', () => {
  const r = assignStudent({ a: desk('a', 1, 1, 'unavailable') }, S('ali'), 'a')
  assert.equal(r.ok, false)
})

test('çift masada ikinci oturak', () => {
  let objs: Record<string, SceneObject> = { a: desk('a', 1, 2) }
  objs = (assignStudent(objs, S('x'), 'a') as { objects: typeof objs }).objects
  objs = (assignStudent(objs, S('y'), 'a') as { objects: typeof objs }).objects
  assert.deepEqual(objs.a!.seats!.map((s) => s?.uuid), ['x', 'y'])
  assert.equal(deskState(objs.a!), 'full')
})

test('otomatik yerleştirme: alfabetik, rezerve/kullanılamaz atlanır, masa sırasına göre', () => {
  const objs = { a: desk('a', 2), b: desk('b', 1), c: desk('c', 3, 1, 'reserved'), d: desk('d', 4, 1, 'unavailable'), e: desk('e', 5) }
  const st = [
    { uuid: '1', name: 'Zeynep Ak', first_name: 'Zeynep', last_name: 'Ak' },
    { uuid: '2', name: 'Çağla Er', first_name: 'Çağla', last_name: 'Er' },
    { uuid: '3', name: 'Cem Su', first_name: 'Cem', last_name: 'Su' },
    { uuid: '4', name: 'İpek Tan', first_name: 'İpek', last_name: 'Tan' },
  ]
  const r = autoAssign(objs, ['a', 'b', 'c', 'd', 'e'], st, { mode: 'alpha', keepExisting: false })
  assert.equal(r.placed, 3)
  assert.equal(r.left, 1)
  assert.equal(r.objects.b!.seats![0]!.name, 'Cem Su', 'masa 1 → ilk öğrenci (Türkçe sıralama: C < Ç)')
  assert.equal(r.objects.a!.seats![0]!.name, 'Çağla Er')
  assert.equal(r.objects.e!.seats![0]!.name, 'İpek Tan')
  assert.equal(r.objects.c!.seats![0], null)
})

test('karışık düzen tohumla tekrarlanabilir', () => {
  const st = Array.from({ length: 10 }, (_, i) => ({ uuid: String(i), name: `Ö${i}` }))
  assert.deepEqual(orderStudents(st, 'random', 42).map((s) => s.uuid), orderStudents(st, 'random', 42).map((s) => s.uuid))
  assert.notDeepEqual(orderStudents(st, 'random', 42).map((s) => s.uuid), st.map((s) => s.uuid))
})

test('istatistik: alan, kapasite, doluluk', () => {
  const objs: Record<string, SceneObject> = { a: desk('a', 1, 2), b: desk('b', 2, 1, 'unavailable'), c: { id: 'c', type: 'chair', x: 0, z: 0, rot: 0 } }
  objs.a!.seats = [S('x'), null]
  const s = computeStats({ room: { polygon: templatePolygon('rect', { width: 7.2, depth: 5.8, cutW: 0, cutD: 0 }), ceiling: 3, wallThickness: 0.2 }, objects: objs, order: ['a', 'b', 'c'] })
  assert.equal(s.area, 41.76)
  assert.equal(s.desks, 2)
  assert.equal(s.capacity, 2)
  assert.equal(s.assigned, 1)
  assert.equal(s.occupancy, 50)
  assert.equal(s.chairs, 4)
})

test('kız-erkek dönüşümlü sıralama', () => {
  const st = [
    { uuid: '1', name: 'Ayşe A', first_name: 'Ayşe', gender: 'female' as const },
    { uuid: '2', name: 'Ali B', first_name: 'Ali', gender: 'male' as const },
    { uuid: '3', name: 'Zehra C', first_name: 'Zehra', gender: 'female' as const },
    { uuid: '4', name: 'Can D', first_name: 'Can', gender: 'male' as const },
    { uuid: '5', name: 'Deniz E', first_name: 'Deniz', gender: null },
  ]
  assert.deepEqual(orderStudents(st, 'gender').map((s) => s.uuid), ['1', '2', '3', '4', '5'])
})
