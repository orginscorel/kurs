import { catalogOf, isDesk, localFootprint } from '../catalog'
import type { FurnitureType, Opening, Room, SceneObject, Vec2 } from '../types'
import { checkObject, openingZones, quadInsidePolygon, rectQuad } from './collision'
import { edgeAt, edges } from './polygon'
import { normalizeAngle, round3 } from './snap'

/**
 * AKILLI YERLEŞİM — gerçek oda çokgenine (L, U, kesik köşe…), kapı önüne, kolon ve diğer engellere göre
 * masa ızgarası üretir. Öğrenciler tahta duvarına bakar; ilk sıra tahtaya en az `boardClearance` uzaklıkta başlar.
 * Mevcut öğrenci masaları (ve masaya bağlı sandalyeler) yerini yeni düzene bırakır; diğer nesneler engeldir.
 */

export type GeneratorOptions = {
  deskType: Extract<FurnitureType, 'desk-single' | 'desk-double'>
  /** 0 = sığdığı kadar */
  rows: number
  /** 0 = sığdığı kadar */
  cols: number
  /** yan yana masalar arası boşluk (m) */
  gapX: number
  /** arka arkaya sıralar arası boşluk: sandalye arkası → sonraki masa önü (m) */
  gapZ: number
  /** tahta duvarından ilk masaya en az (m) */
  boardClearance: number
  /** yan ve arka duvarlara en az (m) */
  wallClearance: number
  /** orta koridor genişliği (m); 0 = orta koridor yok */
  aisle: number
  /** yerleşecek öğrenci sayısı; 0 = sığan kadar masa */
  students: number
  /** tahta duvarı (kenar sırası) */
  boardWall: number
}

export type GeneratorResult = {
  objects: SceneObject[]
  /** oluşturulan masa */
  placed: number
  /** öğrenci kapasitesi */
  capacity: number
  /** istenen masa (öğrenci sayısından) */
  requested: number
  /** engel/oda sınırı yüzünden atlanan ızgara hücresi */
  skipped: number
  rows: number
  cols: number
  /** öğrenci sayısına göre eksik oturak */
  shortage: number
}

export const DEFAULT_GENERATOR: Omit<GeneratorOptions, 'boardWall' | 'students'> = {
  deskType: 'desk-single',
  rows: 0,
  cols: 0,
  gapX: 0.5,
  gapZ: 0.12,
  boardClearance: 1.4,
  wallClearance: 0.3,
  aisle: 0,
}

/** Tahta nesnesi hangi duvardaysa o; yoksa en uzun duvar */
export function detectBoardWall(room: Room, objects: SceneObject[]): number {
  const board = objects.find((o) => o.type === 'smartboard' || o.type === 'whiteboard')
  const es = edges(room.polygon)
  if (board) {
    let best = 0
    let bestD = Infinity
    for (const e of es) {
      const dx = e.b.x - e.a.x
      const dz = e.b.z - e.a.z
      const t = Math.max(0, Math.min(1, ((board.x - e.a.x) * dx + (board.z - e.a.z) * dz) / (e.len * e.len)))
      const d = Math.hypot(board.x - (e.a.x + dx * t), board.z - (e.a.z + dz * t))
      if (d < bestD) {
        bestD = d
        best = e.i
      }
    }
    return best
  }
  return es.reduce((b, e) => (e.len > es[b]!.len ? e.i : b), 0)
}

export function generateLayout(room: Room, openings: Opening[], existing: SceneObject[], opt: GeneratorOptions): GeneratorResult {
  const cat = catalogOf(opt.deskType)!
  const seats = cat.seats ?? 1
  const chairZone = cat.chair?.zone ?? 0
  const w = cat.w
  const d = cat.d
  const fd = d + chairZone
  const e = edgeAt(room.polygon, Math.min(opt.boardWall, room.polygon.length - 1))
  const inward = { x: -e.n.x, z: -e.n.z }
  const rot = normalizeAngle(Math.atan2(-e.n.x, -e.n.z))

  // Oda çokgeni tahta çerçevesinde (u: duvar boyunca, v: odanın içine)
  const uv = room.polygon.map((p) => ({
    u: (p.x - e.a.x) * e.dir.x + (p.z - e.a.z) * e.dir.z,
    v: (p.x - e.a.x) * inward.x + (p.z - e.a.z) * inward.z,
  }))
  const uMin = Math.min(...uv.map((p) => p.u))
  const uMax = Math.max(...uv.map((p) => p.u))
  const vMax = Math.max(...uv.map((p) => p.v))
  const usable = uMax - uMin - 2 * opt.wallClearance
  const hasAisle = opt.aisle > 0

  const widthFor = (cols: number) => cols * w + Math.max(0, cols - 1) * opt.gapX + (hasAisle && cols >= 2 ? Math.max(0, opt.aisle - opt.gapX) : 0)
  let cols = opt.cols
  if (cols <= 0) {
    cols = 1
    while (widthFor(cols + 1) <= usable + 1e-6 && cols < 40) cols++
  }
  const pitchZ = fd + opt.gapZ
  let rows = opt.rows
  if (rows <= 0) rows = Math.max(1, Math.floor((vMax - opt.boardClearance - opt.wallClearance + opt.gapZ) / pitchZ + 1e-6))
  rows = Math.min(rows, 60)

  const requested = opt.students > 0 ? Math.ceil(opt.students / seats) : rows * cols
  const totalW = widthFor(cols)
  // Izgarayı tahta duvarının ortasına hizala (duvar odadan kısaysa oda ortasına)
  const center = e.len >= (uMax - uMin) * 0.6 ? e.len / 2 : (uMin + uMax) / 2
  const splitAfter = hasAisle && cols >= 2 ? Math.floor(cols / 2) - 1 : -1

  const obstacles = existing.filter((o) => !isDesk(o))
  const world = { room, zones: openingZones(room, openings), objects: obstacles }

  /** Verilen yatay kaydırmayla ızgarayı dener */
  const attempt = (shift: number) => {
    const u0 = center - totalW / 2 + shift
    const placedObjs: SceneObject[] = []
    let skipped = 0
    let no = 0
    outer: for (let r = 0; r < rows; r++) {
      const vFront = opt.boardClearance + r * pitchZ
      if (vFront + fd > vMax + 1e-6) break
      for (let c = 0; c < cols; c++) {
        if (placedObjs.length >= requested) break outer
        let u = u0 + c * (w + opt.gapX) + w / 2
        if (splitAfter >= 0 && c > splitAfter) u += Math.max(0, opt.aisle - opt.gapX)
        const vCenter = vFront + d / 2
        const pos: Vec2 = { x: e.a.x + e.dir.x * u + inward.x * vCenter, z: e.a.z + e.dir.z * u + inward.z * vCenter }
        const obj: SceneObject = {
          id: `gen-${r}-${c}`, type: opt.deskType, x: round3(pos.x), z: round3(pos.z), rot, chair: true,
          no: 0, status: 'normal', seats: Array.from({ length: seats }, () => null),
        }
        const f = localFootprint(obj, room.ceiling)
        const expanded = rectQuad(obj, { minX: f.minX - opt.wallClearance, maxX: f.maxX + opt.wallClearance, minZ: f.minZ, maxZ: f.maxZ + opt.wallClearance })
        const ok = quadInsidePolygon(expanded, room.polygon) && checkObject(obj, { ...world, objects: [...obstacles, ...placedObjs] }).ok
        if (!ok) {
          skipped++
          continue
        }
        obj.no = ++no
        obj.id = `gen-${no}`
        placedObjs.push(obj)
      }
    }
    return { placedObjs, skipped }
  }

  // Kapı önü / kolon gibi engeller ortadaki ızgarayı kesiyorsa ızgarayı yatayda kaydırarak en çok masayı sığdıran konum
  const room0 = (uMax - uMin - totalW) / 2
  let best = attempt(0)
  if (best.placedObjs.length < requested && room0 > 0.02) {
    for (let k = 1; k * 0.05 <= room0 + 1e-6; k++) {
      for (const sgn of [1, -1]) {
        const r = attempt(sgn * k * 0.05)
        if (r.placedObjs.length > best.placedObjs.length) best = r
      }
      if (best.placedObjs.length >= requested) break
    }
  }
  const placedObjs = best.placedObjs
  const skipped = best.skipped

  const capacity = placedObjs.length * seats
  return {
    objects: placedObjs,
    placed: placedObjs.length,
    capacity,
    requested,
    skipped,
    rows,
    cols,
    shortage: opt.students > 0 ? Math.max(0, opt.students - capacity) : 0,
  }
}

/** Masaları tahtaya göre önden arkaya, soldan sağa yeniden numaralar: kimlik → yeni numara */
export function numberDesks(room: Room, objects: SceneObject[], boardWall: number): Map<string, number> {
  const e = edgeAt(room.polygon, Math.min(boardWall, room.polygon.length - 1))
  const inward = { x: -e.n.x, z: -e.n.z }
  const desks = objects.filter(isDesk).map((o) => ({
    id: o.id,
    u: (o.x - e.a.x) * e.dir.x + (o.z - e.a.z) * e.dir.z,
    v: (o.x - e.a.x) * inward.x + (o.z - e.a.z) * inward.z,
  }))
  // aynı sırada sayılacak masalar: v farkı 35 cm'den az
  desks.sort((a, b) => a.v - b.v)
  const rows: (typeof desks)[] = []
  for (const dsk of desks) {
    const row = rows[rows.length - 1]
    if (row && Math.abs(row[0]!.v - dsk.v) < 0.35) row.push(dsk)
    else rows.push([dsk])
  }
  const out = new Map<string, number>()
  let n = 0
  for (const row of rows) for (const dsk of row.sort((a, b) => a.u - b.u)) out.set(dsk.id, ++n)
  return out
}
