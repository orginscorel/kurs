import { catalogOf, localFootprint, mountOf, sizeOf } from '../catalog'
import type { Room, SceneObject, Vec2 } from '../types'
import { distPointSegment, edges, type Edge } from './polygon'

/**
 * YAPIŞMA — ızgara, duvar ve açı.
 *  zemin nesneleri: merkez ızgaraya yapışır (açıkken)
 *  duvar nesneleri (tahta, klima, radyatör, priz, kamera): en yakın duvara dayanır, önü odaya bakar
 *  tavan nesneleri (projeksiyon): konum ızgaraya, yükseklik tavandan asılı
 */

export const GRID_SIZES = [0.1, 0.25, 0.5, 1] as const

export function snapValue(v: number, grid: number): number {
  if (grid <= 0) return v
  return Math.round(v / grid) * grid
}

export function round3(v: number): number {
  return Math.round(v * 1000) / 1000
}

export function normalizeAngle(a: number): number {
  const t = Math.PI * 2
  let r = a % t
  if (r < 0) r += t
  return Math.abs(r) < 1e-9 || Math.abs(r - t) < 1e-6 ? 0 : r
}

/** 90°'lik adıma en yakın açı (serbest döndürmede 15° yapışma için step verilir) */
export function snapAngle(a: number, step = Math.PI / 2): number {
  return normalizeAngle(Math.round(a / step) * step)
}

/** Kenar dış normaline göre, önü odaya bakan dönüş açısı */
export function wallFacingRotation(e: Edge): number {
  return normalizeAngle(Math.atan2(e.n.x, e.n.z))
}

/** Duvar nesnesini en yakın duvara dayar: merkez = izdüşüm + içe (derinlik/2); duvar boyunca uçlar taşmaz */
export function snapToWall(o: SceneObject, room: Room, target: Vec2, grid = 0): SceneObject {
  const { w, d } = sizeOf(o, room.ceiling)
  let best: { e: Edge; s: number; dist: number } | null = null
  for (const e of edges(room.polygon)) {
    if (e.len < w + 0.02) continue
    const r = distPointSegment(target, e.a, e.b)
    if (!best || r.dist < best.dist) best = { e, s: r.t * e.len, dist: r.dist }
  }
  if (!best) return { ...o, x: target.x, z: target.z }
  const { e } = best
  let s = grid > 0 ? snapValue(best.s, grid) : best.s
  s = Math.min(Math.max(s, w / 2 + 0.01), e.len - w / 2 - 0.01)
  const inward = { x: -e.n.x, z: -e.n.z }
  return {
    ...o,
    x: round3(e.a.x + e.dir.x * s + inward.x * (d / 2)),
    z: round3(e.a.z + e.dir.z * s + inward.z * (d / 2)),
    rot: wallFacingRotation(e),
  }
}

/** Taşıma/ekleme konumunu nesnenin montaj türüne göre düzeltir */
export function placeObject(o: SceneObject, room: Room, target: Vec2, opts: { grid: number; snap: boolean }): SceneObject {
  const mount = mountOf(o)
  if (mount === 'wall') return snapToWall(o, room, target, opts.snap ? opts.grid : 0)
  const g = opts.snap ? opts.grid : 0
  if (!g) return { ...o, x: round3(target.x), z: round3(target.z) }
  // Ayak izi kenarı (merkez değil) ızgaraya otursun: dönüş 0/90/180/270 iken sol-ön köşe ızgaraya
  const f = localFootprint(o, room.ceiling)
  const q = Math.round(o.rot / (Math.PI / 2))
  const axisAligned = Math.abs(o.rot - q * (Math.PI / 2)) < 1e-3
  if (!axisAligned) return { ...o, x: round3(snapValue(target.x, g)), z: round3(snapValue(target.z, g)) }
  const quarter = ((q % 4) + 4) % 4
  const halfW = (f.maxX - f.minX) / 2
  const halfD = (f.maxZ - f.minZ) / 2
  const [hx, hz] = quarter % 2 === 0 ? [halfW, halfD] : [halfD, halfW]
  // ayak izi merkezinin nesne merkezine göre kayması (sandalye alanı)
  const cz = (f.maxZ + f.minZ) / 2
  const off = quarter === 0 ? { x: 0, z: cz } : quarter === 1 ? { x: cz, z: 0 } : quarter === 2 ? { x: 0, z: -cz } : { x: -cz, z: 0 }
  const fx = snapValue(target.x + off.x - hx, g) + hx - off.x
  const fz = snapValue(target.z + off.z - hz, g) + hz - off.z
  return { ...o, x: round3(fx), z: round3(fz) }
}

/** Yeni nesne için varsayılan alanlar */
export function newObjectDefaults(type: string): Partial<SceneObject> {
  const c = catalogOf(type)
  const out: Partial<SceneObject> = {}
  if (c?.chair) out.chair = c.chair.default
  if (c?.seats) {
    out.seats = Array.from({ length: c.seats }, () => null)
    out.status = 'normal'
  }
  return out
}
