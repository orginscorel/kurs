import { catalogOf, isDesk, seatCount } from '../catalog'
import type { Doc, LayoutStats, SceneObject, SeatStudent } from '../types'
import { area } from './polygon'

/**
 * OTURMA DÜZENİ (saf) — öğrenci ataması, yer değiştirme, otomatik yerleştirme, istatistik.
 * Kurallar:
 *  - "Kullanılamaz" masaya atama yapılmaz; "Rezerve" masaya elle atanabilir, otomatik yerleştirme atlar.
 *  - Oturmuş öğrenci dolu bir yere bırakılırsa iki öğrenci YER DEĞİŞTİRİR; oturmamış öğrenci bırakılırsa
 *    yerindeki öğrenci boşa çıkar (listeye döner).
 *  - Bir öğrenci aynı anda tek oturakta bulunur.
 */

export type SeatRef = { id: string; seat: number }

export function findStudentSeat(objects: Record<string, SceneObject>, uuid: string): SeatRef | null {
  for (const o of Object.values(objects)) {
    const seats = o.seats
    if (!seats) continue
    for (let i = 0; i < seats.length; i++) if (seats[i]?.uuid === uuid) return { id: o.id, seat: i }
  }
  return null
}

function withSeat(o: SceneObject, seat: number, value: SeatStudent | null): SceneObject {
  const seats = (o.seats ?? Array.from({ length: seatCount(o) }, () => null)).slice()
  seats[seat] = value
  return { ...o, seats }
}

export type AssignOutcome =
  | { ok: true; objects: Record<string, SceneObject>; swapped?: SeatStudent; displaced?: SeatStudent }
  | { ok: false; reason: string }

/** Öğrenciyi masanın oturağına yerleştirir (seat verilmezse ilk boş oturak, yoksa 0) */
export function assignStudent(objects: Record<string, SceneObject>, student: SeatStudent, targetId: string, seat?: number): AssignOutcome {
  const target = objects[targetId]
  if (!target || !isDesk(target)) return { ok: false, reason: 'Öğrenci yalnız öğrenci masasına yerleştirilebilir.' }
  if (target.status === 'unavailable') return { ok: false, reason: `Masa ${target.no ?? ''} kullanılamaz durumda.` }
  const n = seatCount(target)
  const seats = target.seats ?? Array.from({ length: n }, () => null)
  let idx = seat ?? seats.findIndex((s) => !s)
  if (idx < 0 || idx >= n) idx = 0

  const from = findStudentSeat(objects, student.uuid)
  if (from && from.id === targetId && from.seat === idx) return { ok: true, objects }
  const occupant = seats[idx] ?? null
  const next = { ...objects }
  if (from) next[from.id] = withSeat(next[from.id]!, from.seat, null)
  next[targetId] = withSeat(next[targetId]!, idx, { uuid: student.uuid, name: student.name })
  if (occupant && occupant.uuid !== student.uuid) {
    if (from) {
      next[from.id] = withSeat(next[from.id]!, from.seat, occupant)
      return { ok: true, objects: next, swapped: occupant }
    }
    return { ok: true, objects: next, displaced: occupant }
  }
  return { ok: true, objects: next }
}

export function unassignStudent(objects: Record<string, SceneObject>, uuid: string): Record<string, SceneObject> {
  const at = findStudentSeat(objects, uuid)
  if (!at) return objects
  return { ...objects, [at.id]: withSeat(objects[at.id]!, at.seat, null) }
}

export function clearSeat(objects: Record<string, SceneObject>, id: string, seat: number): Record<string, SceneObject> {
  const o = objects[id]
  if (!o) return objects
  return { ...objects, [id]: withSeat(o, seat, null) }
}

export type AutoMode = 'alpha' | 'list' | 'random' | 'gender'

export const AUTO_MODE_LABELS: Record<AutoMode, string> = {
  alpha: 'Alfabetik (ada göre)',
  list: 'Sınıf listesine göre (öğrenci no)',
  random: 'Karışık',
  gender: 'Kız-erkek dönüşümlü (kendi içinde alfabetik)',
}

export type AutoStudent = SeatStudent & { first_name?: string; last_name?: string; student_no?: string; gender?: 'female' | 'male' | null }

/** Rastgele ama tekrar üretilebilir karıştırma (tohum) */
export function seededShuffle<T>(list: T[], seed: number): T[] {
  const out = list.slice()
  let s = seed >>> 0 || 1
  const rnd = () => {
    s ^= s << 13
    s ^= s >>> 17
    s ^= s << 5
    return ((s >>> 0) % 1_000_000) / 1_000_000
  }
  for (let i = out.length - 1; i > 0; i--) {
    const j = Math.floor(rnd() * (i + 1))
    ;[out[i], out[j]] = [out[j]!, out[i]!]
  }
  return out
}

const collator = new Intl.Collator('tr', { sensitivity: 'base', numeric: true })

export function orderStudents(students: AutoStudent[], mode: AutoMode, seed = Date.now()): AutoStudent[] {
  if (mode === 'random') return seededShuffle(students, seed)
  if (mode === 'gender') {
    const alpha = orderStudents(students, 'alpha')
    const f = alpha.filter((s) => s.gender === 'female')
    const m = alpha.filter((s) => s.gender === 'male')
    const rest = alpha.filter((s) => s.gender !== 'female' && s.gender !== 'male')
    const [a, b] = f.length >= m.length ? [f, m] : [m, f]
    const out: AutoStudent[] = []
    for (let i = 0; i < a.length; i++) {
      out.push(a[i]!)
      if (b[i]) out.push(b[i]!)
    }
    return [...out, ...rest]
  }
  if (mode === 'list') return students.slice().sort((a, b) => collator.compare(a.student_no ?? '', b.student_no ?? '') || collator.compare(a.name, b.name))
  return students.slice().sort((a, b) => collator.compare(a.first_name ?? a.name, b.first_name ?? b.name) || collator.compare(a.last_name ?? '', b.last_name ?? ''))
}

/**
 * Otomatik yerleştirme: masalar numara sırasıyla (numarasız olanlar sonda), rezerve/kullanılamaz atlanır.
 * keepExisting: mevcut atamalar korunur, yalnız boş oturaklar ve oturmamış öğrenciler eşleşir.
 */
export function autoAssign(objects: Record<string, SceneObject>, order: string[], students: AutoStudent[], opts: { mode: AutoMode; keepExisting: boolean; seed?: number }) {
  const next: Record<string, SceneObject> = { ...objects }
  const desks = order.map((id) => next[id]!).filter((o) => o && isDesk(o))
  desks.sort((a, b) => (a.no ?? 1e9) - (b.no ?? 1e9))
  if (!opts.keepExisting) for (const d of desks) next[d.id] = { ...d, seats: (d.seats ?? []).map(() => null) }
  const seated = new Set<string>()
  if (opts.keepExisting) for (const d of desks) for (const s of next[d.id]!.seats ?? []) if (s) seated.add(s.uuid)
  const queue = orderStudents(students.filter((s) => !seated.has(s.uuid)), opts.mode, opts.seed)
  let placed = 0
  for (const d0 of desks) {
    const d = next[d0.id]!
    if (d.status === 'reserved' || d.status === 'unavailable') continue
    const n = seatCount(d)
    const seats = (d.seats ?? Array.from({ length: n }, () => null)).slice()
    let changed = false
    for (let i = 0; i < n && queue.length; i++) {
      if (seats[i]) continue
      const s = queue.shift()!
      seats[i] = { uuid: s.uuid, name: s.name }
      placed++
      changed = true
    }
    if (changed) next[d.id] = { ...d, seats }
  }
  return { objects: next, placed, left: queue.length }
}

export function computeStats(doc: Pick<Doc, 'room' | 'objects' | 'order'>): LayoutStats {
  let desks = 0
  let chairs = 0
  let capacity = 0
  let assigned = 0
  for (const id of doc.order) {
    const o = doc.objects[id]
    if (!o) continue
    const c = catalogOf(o.type)
    if (o.type === 'chair' || o.type === 'teacher-chair') chairs++
    if (c?.chair && o.chair) chairs += c.seats ?? 1
    if (isDesk(o)) {
      desks++
      if (o.status !== 'unavailable') capacity += seatCount(o)
      for (const s of o.seats ?? []) if (s) assigned++
    }
  }
  const empty = Math.max(0, capacity - assigned)
  return {
    area: Math.round(area(doc.room.polygon) * 100) / 100,
    desks,
    chairs,
    capacity,
    assigned,
    empty,
    objects: doc.order.length,
    occupancy: capacity ? Math.round((assigned / capacity) * 100) : 0,
  }
}

/** Masa durumu (görsel): boş / dolu / kısmen / rezerve / kullanılamaz */
export type DeskState = 'empty' | 'full' | 'partial' | 'reserved' | 'unavailable'

export function deskState(o: SceneObject): DeskState {
  if (o.status === 'unavailable') return 'unavailable'
  const seats = o.seats ?? []
  const filled = seats.filter(Boolean).length
  if (o.status === 'reserved' && filled === 0) return 'reserved'
  if (filled === 0) return 'empty'
  return filled >= seatCount(o) ? 'full' : 'partial'
}

export const DESK_STATE_LABELS: Record<DeskState, string> = {
  empty: 'Boş',
  full: 'Dolu',
  partial: 'Kısmen dolu',
  reserved: 'Rezerve',
  unavailable: 'Kullanılamaz',
}
