import { localFootprint, mountOf, verticalRange } from '../catalog'
import type { Opening, Room, SceneObject, Vec2 } from '../types'
import { distPointSegment, edgeAt, pointInPolygon, segmentsCross } from './polygon'

/**
 * ÇARPIŞMA — nesne ayak izleri döndürülmüş dikdörtgenlerdir (OBB); ayırıcı eksen sınaması (SAT).
 * İki nesne yalnız dikey aralıkları da kesişiyorsa çakışır (ör. klima masanın üstünde durabilir).
 * Oda: ayak izi çokgenin içinde kalmalı (içbükey odalar için kenar kesişimi de denetlenir).
 * Kapı önü (kanat açılma alanı) ve pencere boşlukları da engeldir.
 */

export type Quad = [Vec2, Vec2, Vec2, Vec2]

/** Temas payı: yan yana (değen) iki nesne çakışma sayılmaz */
export const TOUCH_EPS = 0.004

export function rotatePoint(x: number, z: number, rot: number): Vec2 {
  const c = Math.cos(rot)
  const s = Math.sin(rot)
  return { x: x * c + z * s, z: -x * s + z * c }
}

export function toWorld(o: Pick<SceneObject, 'x' | 'z' | 'rot'>, lx: number, lz: number): Vec2 {
  const r = rotatePoint(lx, lz, o.rot)
  return { x: o.x + r.x, z: o.z + r.z }
}

export function toLocal(o: Pick<SceneObject, 'x' | 'z' | 'rot'>, wx: number, wz: number): Vec2 {
  return rotatePoint(wx - o.x, wz - o.z, -o.rot)
}

export function rectQuad(o: Pick<SceneObject, 'x' | 'z' | 'rot'>, f: { minX: number; maxX: number; minZ: number; maxZ: number }, shrink = 0): Quad {
  return [
    toWorld(o, f.minX + shrink, f.minZ + shrink),
    toWorld(o, f.maxX - shrink, f.minZ + shrink),
    toWorld(o, f.maxX - shrink, f.maxZ - shrink),
    toWorld(o, f.minX + shrink, f.maxZ - shrink),
  ]
}

export function footprintQuad(o: SceneObject, ceiling: number, shrink = 0): Quad {
  return rectQuad(o, localFootprint(o, ceiling), shrink)
}

function project(q: Vec2[], ax: Vec2): [number, number] {
  let min = Infinity
  let max = -Infinity
  for (const p of q) {
    const d = p.x * ax.x + p.z * ax.z
    if (d < min) min = d
    if (d > max) max = d
  }
  return [min, max]
}

/** İki dışbükey dörtgen (OBB) örtüşüyor mu — değme (eps kadar) örtüşme sayılmaz */
export function quadsOverlap(a: Vec2[], b: Vec2[], eps = TOUCH_EPS): boolean {
  for (const q of [a, b]) {
    for (let i = 0; i < q.length; i++) {
      const p1 = q[i]!
      const p2 = q[(i + 1) % q.length]!
      const ax = { x: -(p2.z - p1.z), z: p2.x - p1.x }
      const len = Math.hypot(ax.x, ax.z) || 1
      ax.x /= len
      ax.z /= len
      const [a0, a1] = project(a, ax)
      const [b0, b1] = project(b, ax)
      if (a1 - eps <= b0 || b1 - eps <= a0) return false
    }
  }
  return true
}

/** Dörtgen oda çokgeninin içinde mi (içbükey köşeler dahil) */
export function quadInsidePolygon(q: Vec2[], poly: Vec2[]): boolean {
  for (const p of q) if (!pointInPolygon(p, poly)) return false
  for (let i = 0; i < q.length; i++) {
    const a = q[i]!
    const b = q[(i + 1) % q.length]!
    for (let j = 0; j < poly.length; j++) {
      if (segmentsCross(a, b, poly[j]!, poly[(j + 1) % poly.length]!)) return false
    }
  }
  // İçbükey odada çokgen köşesi dörtgenin içine girmiş olabilir
  for (const v of poly) if (pointInConvex(v, q)) return false
  return true
}

function pointInConvex(p: Vec2, q: Vec2[]): boolean {
  let sign = 0
  for (let i = 0; i < q.length; i++) {
    const a = q[i]!
    const b = q[(i + 1) % q.length]!
    const c = (b.x - a.x) * (p.z - a.z) - (b.z - a.z) * (p.x - a.x)
    if (Math.abs(c) < 1e-6) return false
    const s = Math.sign(c)
    if (sign === 0) sign = s
    else if (s !== sign) return false
  }
  return true
}

// ------------------------------------------------------------------ açıklıklar

export type Zone = { id: string; kind: 'door' | 'window'; quad: Quad; range: [number, number]; label: string }

/** Kapının içe açılan kanadının süpürdüğü alan (içe açılmıyorsa 0,5 m geçiş payı) */
export function doorSwingDepth(o: Opening): number {
  return o.swing?.startsWith('out') ? 0.5 : o.width
}

/** Açıklık bölgeleri: kapı önü (zemin) ve pencere boşluğu (duvar yüzünde ince şerit) */
export function openingZones(room: Room, openings: Opening[]): Zone[] {
  const out: Zone[] = []
  for (const o of openings) {
    if (o.wall >= room.polygon.length) continue
    const e = edgeAt(room.polygon, o.wall)
    const inward = { x: -e.n.x, z: -e.n.z }
    const s0 = o.offset - o.width / 2
    const s1 = o.offset + o.width / 2
    const depth = o.kind === 'door' ? doorSwingDepth(o) : 0.06
    const at = (s: number, d: number): Vec2 => ({ x: e.a.x + e.dir.x * s + inward.x * d, z: e.a.z + e.dir.z * s + inward.z * d })
    out.push({
      id: o.id,
      kind: o.kind,
      quad: [at(s0, 0), at(s1, 0), at(s1, depth), at(s0, depth)],
      range: o.kind === 'door' ? [0, o.height] : [o.sill, o.sill + o.height],
      label: o.kind === 'door' ? 'kapı önü' : 'pencere',
    })
  }
  return out
}

// ------------------------------------------------------------------ nesne denetimi

export type CollisionResult = {
  ok: boolean
  /** çakışılan nesne kimlikleri */
  hits: string[]
  outside: boolean
  zones: string[]
}

const rangesOverlap = (a: [number, number], b: [number, number]) => a[0] < b[1] - 0.001 && b[0] < a[1] - 0.001

export type CollisionWorld = {
  room: Room
  zones: Zone[]
  /** diğer nesneler (denetlenenler hariç tutulur) */
  objects: SceneObject[]
}

/** Bir nesne (varsayılan konumunda) geçerli mi? `ignore` kimlikleri (ör. birlikte taşınan seçim) atlanır */
export function checkObject(o: SceneObject, world: CollisionWorld, ignore?: Set<string>): CollisionResult {
  const ceiling = world.room.ceiling
  const quad = footprintQuad(o, ceiling)
  const range = verticalRange(o, ceiling)
  const mount = mountOf(o)
  // Duvar nesneleri duvar yüzüne dayanır: içerde kalma denetimi 5 mm içeriden yapılır
  const inside = quadInsidePolygon(mount === 'floor' ? quad : footprintQuad(o, ceiling, 0.005), world.room.polygon)
  const hits: string[] = []
  for (const other of world.objects) {
    if (other.id === o.id || ignore?.has(other.id)) continue
    if (!rangesOverlap(range, verticalRange(other, ceiling))) continue
    if (quadsOverlap(quad, footprintQuad(other, ceiling))) hits.push(other.id)
  }
  const zones: string[] = []
  for (const z of world.zones) {
    if (!rangesOverlap(range, z.range)) continue
    if (quadsOverlap(quad, z.quad, 0.002)) zones.push(z.id)
  }
  return { ok: inside && hits.length === 0 && zones.length === 0, hits, outside: !inside, zones }
}

/** Birlikte taşınan nesneler grubu için toplu denetim; geçersiz olanların kimlikleri */
export function invalidIds(moved: SceneObject[], world: CollisionWorld): Set<string> {
  const ignore = new Set(moved.map((m) => m.id))
  const bad = new Set<string>()
  for (const m of moved) {
    const r = checkObject(m, world, ignore)
    if (!r.ok) bad.add(m.id)
  }
  // grup içi çakışma (ör. yapıştırılan kopyalar)
  for (let i = 0; i < moved.length; i++) {
    for (let j = i + 1; j < moved.length; j++) {
      const a = moved[i]!
      const b = moved[j]!
      if (!rangesOverlap(verticalRange(a, world.room.ceiling), verticalRange(b, world.room.ceiling))) continue
      if (quadsOverlap(footprintQuad(a, world.room.ceiling), footprintQuad(b, world.room.ceiling))) {
        bad.add(a.id)
        bad.add(b.id)
      }
    }
  }
  return bad
}

// ------------------------------------------------------------------ mesafe

export type Nearest = { dist: number; a: Vec2; b: Vec2; target: 'object' | 'wall'; id?: string }

function closestBetween(a: Vec2[], b: Vec2[], closedB = true): { dist: number; a: Vec2; b: Vec2 } {
  let best = { dist: Infinity, a: a[0]!, b: b[0]! }
  const nb = closedB ? b.length : b.length - 1
  for (const p of a) {
    for (let i = 0; i < nb; i++) {
      const r = distPointSegment(p, b[i]!, b[(i + 1) % b.length]!)
      if (r.dist < best.dist) best = { dist: r.dist, a: p, b: r.point }
    }
  }
  return best
}

/** İki dışbükey dörtgen arası en kısa mesafe ve uç noktaları (örtüşüyorsa 0) */
export function quadDistance(a: Vec2[], b: Vec2[]): { dist: number; a: Vec2; b: Vec2 } {
  if (quadsOverlap(a, b, 0)) return { dist: 0, a: a[0]!, b: a[0]! }
  const r1 = closestBetween(a, b)
  const r2 = closestBetween(b, a)
  return r1.dist <= r2.dist ? r1 : { dist: r2.dist, a: r2.b, b: r2.a }
}

/** Seçili nesnenin zemindeki en yakın komşusu (nesne ya da duvar) — "↔ 80 cm" etiketi için */
export function nearestNeighbor(o: SceneObject, room: Room, others: SceneObject[], ignore?: Set<string>): Nearest | null {
  const ceiling = room.ceiling
  const q = footprintQuad(o, ceiling)
  const range = verticalRange(o, ceiling)
  let best: Nearest | null = null
  for (const other of others) {
    if (other.id === o.id || ignore?.has(other.id)) continue
    if (mountOf(other) !== 'floor' && !rangesOverlap(range, verticalRange(other, ceiling))) continue
    const r = quadDistance(q, footprintQuad(other, ceiling))
    if (r.dist > 0 && (!best || r.dist < best.dist)) best = { ...r, target: 'object', id: other.id }
  }
  const poly = room.polygon
  const w1 = closestBetween(q, poly)
  const w2 = closestBetween(poly, q)
  const w = w1.dist <= w2.dist ? w1 : { dist: w2.dist, a: w2.b, b: w2.a }
  if (w.dist > 0.005 && (!best || w.dist < best.dist)) best = { ...w, target: 'wall' }
  return best
}
