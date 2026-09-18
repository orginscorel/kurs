import { toast } from 'sonner'
import { catalogOf, isDesk, mountOf } from '../catalog'
import type { SceneObject, SeatStudent, Vec2 } from '../types'
import { checkObject, invalidIds, type CollisionResult } from '../utils/collision'
import { numberDesks } from '../utils/layoutGenerator'
import { centroid, pointInPolygon } from '../utils/polygon'
import { assignStudent, autoAssign, clearSeat, type AutoMode, type AutoStudent } from '../utils/seating'
import { newObjectDefaults, normalizeAngle, placeObject, round3, snapAngle, snapValue } from '../utils/snap'
import { newId } from '../utils/doc'
import { collisionWorld, useClassroom } from './classroomStore'
import { NO_INVALID, useSelection } from './selectionStore'

/**
 * DÜZENLEYİCİ İŞLEMLERİ — 3D sahne, 2D plan, klavye, araç çubuğu ve paneller aynı işlevleri çağırır.
 * Kural: çakışan konuma bırakılamaz; sürükleme bitince son geçerli konuma dönülür.
 */

const cs = () => useClassroom.getState()
const sel = () => useSelection.getState()

export function describeCollision(r: CollisionResult): string {
  if (r.outside) return 'Oda sınırının dışına taşıyor.'
  if (r.zones.length) {
    const o = cs().doc.openings.find((x) => x.id === r.zones[0])
    return o?.kind === 'window' ? 'Pencere boşluğunu kapatıyor.' : 'Kapı önünü kapatıyor.'
  }
  if (r.hits.length) {
    const other = cs().doc.objects[r.hits[0]!]
    const label = other ? (catalogOf(other.type)?.label ?? 'nesne') : 'nesne'
    return `${label}${other?.no ? ` ${other.no}` : ''} ile çakışıyor.`
  }
  return 'Buraya yerleştirilemez.'
}

function nextDeskNo(): number {
  let max = 0
  for (const o of Object.values(cs().doc.objects)) if (isDesk(o) && (o.no ?? 0) > max) max = o.no ?? 0
  return max + 1
}

/** Yeni nesne (konumlandırılmamış) */
export function makeObject(type: string): SceneObject {
  const base: SceneObject = { id: newId('o'), type: type as SceneObject['type'], x: 0, z: 0, rot: 0, ...newObjectDefaults(type) }
  if (isDesk(base)) base.no = nextDeskNo()
  const c = catalogOf(type)
  if (c?.mount === 'ceiling') base.elev = round3(cs().doc.room.ceiling + (c.elev ?? -0.6))
  return base
}

/** Nesneyi hedef noktaya (yapışma kurallarıyla) koyar ve geçerliliğini döndürür */
export function preparePlacement(o: SceneObject, point: Vec2): { obj: SceneObject; result: CollisionResult } {
  const { doc, settings } = cs()
  const obj = placeObject(o, doc.room, point, settings)
  return { obj, result: checkObject(obj, collisionWorld()) }
}

/** Oda merkezinden sarmal arama ile ilk boş yer */
export function findFreeSpot(o: SceneObject, near?: Vec2): SceneObject | null {
  const { doc } = cs()
  const c = near ?? centroid(doc.room.polygon)
  const step = 0.25
  for (let ring = 0; ring < 60; ring++) {
    const pts: Vec2[] = ring === 0 ? [c] : []
    for (let i = -ring; i <= ring; i++) {
      pts.push({ x: c.x + i * step, z: c.z - ring * step }, { x: c.x + i * step, z: c.z + ring * step })
      if (Math.abs(i) !== ring) pts.push({ x: c.x - ring * step, z: c.z + i * step }, { x: c.x + ring * step, z: c.z + i * step })
    }
    for (const p of pts) {
      if (mountOf(o) === 'floor' && !pointInPolygon(p, doc.room.polygon)) continue
      const r = preparePlacement(o, p)
      if (r.result.ok) return r.obj
    }
  }
  return null
}

export function addFurniture(type: string, point?: Vec2): boolean {
  const base = makeObject(type)
  let obj: SceneObject | null
  if (point) {
    const r = preparePlacement(base, point)
    if (!r.result.ok) {
      toast.error(`${catalogOf(type)?.label ?? 'Nesne'} buraya konamaz: ${describeCollision(r.result)}`)
      return false
    }
    obj = r.obj
  } else {
    obj = findFreeSpot(base)
    if (!obj) {
      toast.error('Odada bu nesne için boş yer bulunamadı.')
      return false
    }
  }
  cs().addObjects([obj])
  sel().select([obj.id])
  return true
}

// ------------------------------------------------------------------ seçim işlemleri

export function selectedObjects(): SceneObject[] {
  const { doc } = cs()
  return sel().selected.map((id) => doc.objects[id]!).filter(Boolean)
}

export function deleteSelected() {
  const s = sel()
  if (s.openingId) {
    const { doc, setOpenings } = cs()
    setOpenings(doc.openings.filter((o) => o.id !== s.openingId))
    s.selectOpening(null)
    return
  }
  const objs = selectedObjects()
  const locked = objs.filter((o) => o.locked)
  const ids = objs.filter((o) => !o.locked).map((o) => o.id)
  if (locked.length) toast.info(`${locked.length} kilitli nesne silinmedi.`)
  if (!ids.length) return
  const seated = objs.reduce((n, o) => n + (o.seats ?? []).filter(Boolean).length, 0)
  cs().removeObjects(ids)
  s.clear()
  toast.success(`${ids.length} nesne silindi${seated ? ` (${seated} öğrenci listeye döndü)` : ''}. Geri almak için Ctrl+Z.`)
}

export function copySelected() {
  const objs = selectedObjects()
  if (!objs.length) return
  sel().setClipboard(objs.map((o) => ({ ...o })))
  toast.success(`${objs.length} nesne kopyalandı.`)
}

/** Panodaki (ya da seçili) nesneleri kaydırarak ekler; çakışmayan ilk kaydırma kullanılır */
export function pasteObjects(source?: SceneObject[]) {
  const src = source ?? sel().clipboard
  if (!src.length) return
  const world = collisionWorld()
  const offsets: Vec2[] = []
  for (let r = 1; r <= 12; r++) offsets.push({ x: r * 0.5, z: 0 }, { x: 0, z: r * 0.5 }, { x: r * 0.5, z: r * 0.5 }, { x: -r * 0.5, z: 0 }, { x: 0, z: -r * 0.5 })
  let no = nextDeskNo()
  for (const off of offsets) {
    const copies = src.map((o) => ({
      ...o, id: newId('o'), x: round3(o.x + off.x), z: round3(o.z + off.z), locked: false,
      seats: o.seats ? o.seats.map(() => null) : undefined,
      no: isDesk(o) ? no++ : o.no,
    }))
    if (invalidIds(copies, world).size === 0) {
      cs().addObjects(copies)
      sel().select(copies.map((c) => c.id))
      return
    }
    no -= copies.filter(isDesk).length
  }
  // Tek nesne: odada ilk boş yeri ara
  if (src.length === 1) {
    const o = src[0]!
    const copy = findFreeSpot({ ...o, id: newId('o'), locked: false, seats: o.seats ? o.seats.map(() => null) : undefined, no: isDesk(o) ? nextDeskNo() : o.no }, { x: o.x, z: o.z })
    if (copy) {
      cs().addObjects([copy])
      sel().select([copy.id])
      return
    }
  }
  toast.error('Kopya için odada boş yer yok.')
}

export function duplicateSelected() {
  pasteObjects(selectedObjects())
}

export function rotateSelected(delta: number) {
  const objs = selectedObjects().filter((o) => !o.locked)
  if (!objs.length) return
  const room = cs().doc.room
  const moved = objs.map((o) => (mountOf(o) === 'wall' ? o : { ...o, rot: normalizeAngle(snapAngle(o.rot + delta, Math.PI / 12)) }))
  const world = collisionWorld()
  const bad = invalidIds(moved, world)
  if (bad.size) {
    const r = checkObject(moved.find((m) => bad.has(m.id))!, world, new Set(moved.map((m) => m.id)))
    toast.error(`Döndürülemedi: ${describeCollision(r)}`)
    return
  }
  void room
  cs().patchObjects(Object.fromEntries(moved.map((m) => [m.id, { rot: m.rot }])))
}

export function nudgeSelected(dx: number, dz: number) {
  const objs = selectedObjects().filter((o) => !o.locked)
  if (!objs.length) return
  const moved = objs.map((o) => ({ ...o, x: round3(o.x + dx), z: round3(o.z + dz) }))
  const world = collisionWorld()
  const bad = invalidIds(moved, world)
  if (bad.size) {
    const r = checkObject(moved.find((m) => bad.has(m.id))!, world, new Set(moved.map((m) => m.id)))
    toast.error(describeCollision(r), { id: 'nudge' })
    return
  }
  cs().patchObjects(Object.fromEntries(moved.map((m) => [m.id, { x: m.x, z: m.z }])))
}

export function toggleLockSelected() {
  const objs = selectedObjects()
  if (!objs.length) return
  const lock = objs.some((o) => !o.locked)
  cs().patchObjects(Object.fromEntries(objs.map((o) => [o.id, { locked: lock }])))
  toast.success(lock ? `${objs.length} nesne kilitlendi.` : `${objs.length} nesnenin kilidi açıldı.`)
}

export function selectAll() {
  sel().select(cs().doc.order.slice())
}

// ------------------------------------------------------------------ sürükleyerek taşıma / döndürme

type MoveGesture = { kind: 'move'; ids: string[]; start: Vec2; orig: SceneObject[]; lastValid: SceneObject[]; axis: 'x' | 'z' | null; moved: boolean }
type RotateGesture = { kind: 'rotate'; id: string; center: Vec2; startAngle: number; orig: SceneObject; lastValid: SceneObject; moved: boolean }
let gesture: MoveGesture | RotateGesture | null = null

export function gestureActive() {
  return gesture !== null
}

export function startMove(ids: string[], start: Vec2, axis: 'x' | 'z' | null = null): boolean {
  const { doc } = cs()
  const orig = ids.map((id) => doc.objects[id]!).filter(Boolean)
  if (!orig.length || orig.some((o) => o.locked)) {
    if (orig.some((o) => o.locked)) toast.info('Kilitli nesne taşınamaz. Kilidi Özellikler panelinden açın.', { id: 'locked' })
    return false
  }
  gesture = { kind: 'move', ids, start, orig, lastValid: orig, axis, moved: false }
  cs().beginGesture()
  return true
}

export function updateMove(point: Vec2, opts?: { free?: boolean }) {
  if (!gesture || gesture.kind !== 'move') return
  const { doc, settings } = cs()
  let dx = point.x - gesture.start.x
  let dz = point.z - gesture.start.z
  if (gesture.axis === 'x') dz = 0
  if (gesture.axis === 'z') dx = 0
  if (!gesture.moved && Math.hypot(dx, dz) < 0.01) return
  gesture.moved = true
  const snap = settings.snap && !opts?.free
  let moved: SceneObject[]
  if (gesture.orig.length === 1) {
    const o = gesture.orig[0]!
    const target = { x: o.x + dx, z: o.z + dz }
    moved = [gesture.axis || mountOf(o) !== 'wall' ? axisPlace(o, target, snap, settings.grid, gesture.axis) : placeObject(o, doc.room, target, { ...settings, snap })]
  } else {
    if (snap) {
      dx = snapValue(dx, settings.grid)
      dz = snapValue(dz, settings.grid)
    }
    moved = gesture.orig.map((o) => ({ ...o, x: round3(o.x + dx), z: round3(o.z + dz) }))
  }
  const bad = invalidIds(moved, collisionWorld())
  sel().setInvalid(bad.size ? bad : NO_INVALID)
  if (!bad.size) gesture.lastValid = moved
  cs().updateGesture(moved)
}

function axisPlace(o: SceneObject, target: Vec2, snap: boolean, grid: number, axis: 'x' | 'z' | null): SceneObject {
  if (!axis) return placeObject(o, cs().doc.room, target, { grid, snap })
  const v = axis === 'x' ? target.x : target.z
  const s = snap ? snapValue(v, grid) : v
  return axis === 'x' ? { ...o, x: round3(s) } : { ...o, z: round3(s) }
}

export function startRotate(id: string, point: Vec2): boolean {
  const o = cs().doc.objects[id]
  if (!o || o.locked) return false
  const center = { x: o.x, z: o.z }
  gesture = { kind: 'rotate', id, center, startAngle: Math.atan2(-(point.z - center.z), point.x - center.x), orig: o, lastValid: o, moved: false }
  cs().beginGesture()
  return true
}

export function updateRotate(point: Vec2, opts?: { fine?: boolean }) {
  if (!gesture || gesture.kind !== 'rotate') return
  const a = Math.atan2(-(point.z - gesture.center.z), point.x - gesture.center.x)
  let rot = gesture.orig.rot + (a - gesture.startAngle)
  rot = opts?.fine ? normalizeAngle(rot) : snapAngle(rot, Math.PI / 12)
  gesture.moved = true
  const o = { ...gesture.orig, rot: round3(rot) }
  const r = checkObject(o, collisionWorld())
  sel().setInvalid(r.ok ? NO_INVALID : new Set([o.id]))
  if (r.ok) gesture.lastValid = o
  cs().updateGesture([o])
}

/** Hareketi bitirir; geçersiz konumdaysa son geçerli konuma döner */
export function endGesture() {
  if (!gesture) return
  const g = gesture
  gesture = null
  const invalid = sel().invalid.size > 0
  sel().setInvalid(NO_INVALID)
  if (!g.moved) {
    cs().endGesture()
    return
  }
  if (invalid) {
    const revert = g.kind === 'move' ? g.lastValid : [g.lastValid]
    cs().endGesture(revert)
    toast.warning('Çakışan konuma bırakılamaz; son geçerli konuma döndü.', { id: 'revert' })
    return
  }
  cs().endGesture()
}

export function cancelGesture() {
  if (!gesture) return
  gesture = null
  sel().setInvalid(NO_INVALID)
  cs().cancelGesture()
}

// ------------------------------------------------------------------ öğrenci ataması

export function assignToSeat(student: SeatStudent, deskId: string, seat?: number): boolean {
  const { doc, replaceObjects } = cs()
  const r = assignStudent(doc.objects, student, deskId, seat)
  if (!r.ok) {
    toast.error(r.reason)
    return false
  }
  if (r.objects === doc.objects) return true
  replaceObjects(r.objects, doc.order)
  const desk = r.objects[deskId]
  if (r.swapped) toast.success(`${student.name} ile ${r.swapped.name} yer değiştirdi.`)
  else if (r.displaced) toast.info(`${r.displaced.name} masadan kaldırıldı; listede bekliyor.`)
  else toast.success(`${student.name} → Masa ${desk?.no ?? ''}`, { id: 'assign' })
  return true
}

export function removeFromSeat(deskId: string, seat: number) {
  const { doc, replaceObjects } = cs()
  replaceObjects(clearSeat(doc.objects, deskId, seat), doc.order)
}

export function runAutoAssign(students: AutoStudent[], mode: AutoMode, keepExisting: boolean) {
  const { doc, replaceObjects } = cs()
  const r = autoAssign(doc.objects, doc.order, students, { mode, keepExisting })
  replaceObjects(r.objects, doc.order)
  return r
}

export function renumberDesks(boardWall: number) {
  const { doc, patchObjects } = cs()
  const m = numberDesks(doc.room, doc.order.map((id) => doc.objects[id]!), boardWall)
  patchObjects(Object.fromEntries([...m.entries()].map(([id, no]) => [id, { no }])))
  toast.success(`${m.size} masa tahtaya göre yeniden numaralandı.`)
}

/** Akıllı yerleşim sonucunu uygular: eski öğrenci masaları kalkar; atamalar numara sırasıyla korunabilir */
export function applyGenerated(generated: SceneObject[], keepAssignments: boolean) {
  const { doc, replaceObjects } = cs()
  const oldDesks = doc.order.map((id) => doc.objects[id]!).filter((o) => o && isDesk(o)).sort((a, b) => (a.no ?? 1e9) - (b.no ?? 1e9))
  const queue: SeatStudent[] = keepAssignments ? oldDesks.flatMap((d) => (d.seats ?? []).filter((s): s is SeatStudent => !!s)) : []
  const objects: Record<string, SceneObject> = {}
  const order: string[] = []
  for (const id of doc.order) {
    const o = doc.objects[id]!
    if (isDesk(o)) continue
    objects[id] = o
    order.push(id)
  }
  for (const g of generated) {
    const id = newId('o')
    const seats = (g.seats ?? []).map(() => queue.shift() ?? null)
    objects[id] = { ...g, id, seats }
    order.push(id)
  }
  replaceObjects(objects, order)
  sel().clear()
  return { lost: queue.length }
}
