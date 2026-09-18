import type { RoomPolygon, Vec2 } from '../types'

/**
 * Çokgen yardımcıları (saf; birim testli: utils/__tests__/polygon.test.ts).
 * Kural: oda çokgeni saat yönünün tersine (pozitif işaretli alan, z'yi y gibi sayarak) tutulur.
 * Kenar i: p[i] → p[i+1]; dış normal (dz, −dx)/uzunluk.
 */

export const EPS = 1e-9

export function signedArea(p: RoomPolygon): number {
  let s = 0
  for (let i = 0; i < p.length; i++) {
    const a = p[i]!
    const b = p[(i + 1) % p.length]!
    s += a.x * b.z - b.x * a.z
  }
  return s / 2
}

export function area(p: RoomPolygon): number {
  return Math.abs(signedArea(p))
}

/** Saat yönünün tersine çevirir (gerekirse ters sırayla) — yeni dizi */
export function normalizePolygon(p: RoomPolygon): RoomPolygon {
  const copy = p.map((v) => ({ x: v.x, z: v.z }))
  return signedArea(copy) < 0 ? copy.reverse() : copy
}

export function centroid(p: RoomPolygon): Vec2 {
  const a = signedArea(p)
  if (Math.abs(a) < EPS) {
    const n = p.length || 1
    return { x: p.reduce((s, v) => s + v.x, 0) / n, z: p.reduce((s, v) => s + v.z, 0) / n }
  }
  let cx = 0
  let cz = 0
  for (let i = 0; i < p.length; i++) {
    const v = p[i]!
    const w = p[(i + 1) % p.length]!
    const f = v.x * w.z - w.x * v.z
    cx += (v.x + w.x) * f
    cz += (v.z + w.z) * f
  }
  return { x: cx / (6 * a), z: cz / (6 * a) }
}

export function bbox(p: Vec2[]): { minX: number; maxX: number; minZ: number; maxZ: number; w: number; d: number } {
  let minX = Infinity
  let maxX = -Infinity
  let minZ = Infinity
  let maxZ = -Infinity
  for (const v of p) {
    if (v.x < minX) minX = v.x
    if (v.x > maxX) maxX = v.x
    if (v.z < minZ) minZ = v.z
    if (v.z > maxZ) maxZ = v.z
  }
  return { minX, maxX, minZ, maxZ, w: maxX - minX, d: maxZ - minZ }
}

export type Edge = { i: number; a: Vec2; b: Vec2; len: number; dir: Vec2; n: Vec2 }

export function edgeAt(p: RoomPolygon, i: number): Edge {
  const a = p[i]!
  const b = p[(i + 1) % p.length]!
  const dx = b.x - a.x
  const dz = b.z - a.z
  const len = Math.hypot(dx, dz) || EPS
  return { i, a, b, len, dir: { x: dx / len, z: dz / len }, n: { x: dz / len, z: -dx / len } }
}

export function edges(p: RoomPolygon): Edge[] {
  return p.map((_, i) => edgeAt(p, i))
}

/** Işın atma; sınır üzerindeki noktalar belirsizdir — sınır toleransı için insideWithMargin kullanın */
export function pointInPolygon(pt: Vec2, p: RoomPolygon): boolean {
  let inside = false
  for (let i = 0, j = p.length - 1; i < p.length; j = i++) {
    const a = p[i]!
    const b = p[j]!
    if (a.z > pt.z !== b.z > pt.z && pt.x < ((b.x - a.x) * (pt.z - a.z)) / (b.z - a.z) + a.x) inside = !inside
  }
  return inside
}

export function distPointSegment(pt: Vec2, a: Vec2, b: Vec2): { dist: number; point: Vec2; t: number } {
  const dx = b.x - a.x
  const dz = b.z - a.z
  const l2 = dx * dx + dz * dz
  let t = l2 < EPS ? 0 : ((pt.x - a.x) * dx + (pt.z - a.z) * dz) / l2
  t = Math.max(0, Math.min(1, t))
  const point = { x: a.x + dx * t, z: a.z + dz * t }
  return { dist: Math.hypot(pt.x - point.x, pt.z - point.z), point, t }
}

function orient(a: Vec2, b: Vec2, c: Vec2): number {
  return (b.x - a.x) * (c.z - a.z) - (b.z - a.z) * (c.x - a.x)
}

/** İki doğru parçası kesişiyor mu (uç noktada değme dahil değil, gerçek kesişim) */
export function segmentsCross(a: Vec2, b: Vec2, c: Vec2, d: Vec2): boolean {
  const o1 = orient(a, b, c)
  const o2 = orient(a, b, d)
  const o3 = orient(c, d, a)
  const o4 = orient(c, d, b)
  return o1 * o2 < -EPS && o3 * o4 < -EPS
}

/** Kendini kesen çokgen mi (komşu olmayan kenarlar) */
export function selfIntersects(p: RoomPolygon): boolean {
  const n = p.length
  for (let i = 0; i < n; i++) {
    const a = p[i]!
    const b = p[(i + 1) % n]!
    for (let j = i + 1; j < n; j++) {
      if (Math.abs(i - j) <= 1 || (i === 0 && j === n - 1)) continue
      if (segmentsCross(a, b, p[j]!, p[(j + 1) % n]!)) return true
    }
  }
  return false
}

export type PolygonProblem = { code: string; message: string }

/** Oda çokgeni geçerli mi? Türkçe açıklamalı sorun listesi döner (boşsa geçerli). */
export function validatePolygon(p: RoomPolygon): PolygonProblem[] {
  const out: PolygonProblem[] = []
  if (p.length < 3) return [{ code: 'few', message: 'Oda en az 3 köşeden oluşmalı.' }]
  if (p.length > 64) out.push({ code: 'many', message: 'En fazla 64 köşe olabilir.' })
  if (selfIntersects(p)) out.push({ code: 'cross', message: 'Duvarlar birbirini kesiyor; köşeleri düzeltin.' })
  const short = edges(p).filter((e) => e.len < 0.2)
  if (short.length) out.push({ code: 'short', message: `${short.map((e) => e.i + 1).join(', ')}. duvar 20 cm'den kısa.` })
  if (area(p) < 2) out.push({ code: 'area', message: 'Oda alanı 2 m²’den küçük olamaz.' })
  return out
}

/**
 * Dışa öteleme (duvar dış yüzü): her köşe iki komşu kenarın ötelenmiş doğrularının kesişimi (gönye).
 * Çok sivri köşelerde gönye 4 × kalınlıkla sınırlanır.
 */
export function offsetPolygon(p: RoomPolygon, t: number): RoomPolygon {
  const n = p.length
  const es = edges(p)
  const out: Vec2[] = []
  for (let i = 0; i < n; i++) {
    const prev = es[(i - 1 + n) % n]!
    const cur = es[i]!
    // ötelenmiş doğrular: prev.a + prev.n*t + s*prev.dir ; cur.a + cur.n*t + u*cur.dir
    const p1 = { x: prev.a.x + prev.n.x * t, z: prev.a.z + prev.n.z * t }
    const p2 = { x: cur.a.x + cur.n.x * t, z: cur.a.z + cur.n.z * t }
    const denom = prev.dir.x * cur.dir.z - prev.dir.z * cur.dir.x
    const v = p[i]!
    if (Math.abs(denom) < 1e-6) {
      out.push({ x: v.x + cur.n.x * t, z: v.z + cur.n.z * t })
      continue
    }
    const s = ((p2.x - p1.x) * cur.dir.z - (p2.z - p1.z) * cur.dir.x) / denom
    let q = { x: p1.x + prev.dir.x * s, z: p1.z + prev.dir.z * s }
    const miter = Math.hypot(q.x - v.x, q.z - v.z)
    if (miter > 4 * t) {
      const k = (4 * t) / miter
      q = { x: v.x + (q.x - v.x) * k, z: v.z + (q.z - v.z) * k }
    }
    out.push(q)
  }
  return out
}

/** Noktaya en yakın kenar */
export function nearestEdge(pt: Vec2, p: RoomPolygon): { edge: Edge; dist: number; point: Vec2; t: number } {
  let best: { edge: Edge; dist: number; point: Vec2; t: number } | null = null
  for (const e of edges(p)) {
    const r = distPointSegment(pt, e.a, e.b)
    if (!best || r.dist < best.dist) best = { edge: e, ...r }
  }
  return best!
}

// ------------------------------------------------------------------ şablonlar

export type TemplateKind = 'rect' | 'l' | 'u' | 'chamfer'

export type TemplateParams = { width: number; depth: number; cutW: number; cutD: number }

export const TEMPLATE_LABELS: Record<TemplateKind, string> = {
  rect: 'Dikdörtgen',
  l: 'L biçimli',
  u: 'U-girintili',
  chamfer: 'Kesik köşeli',
}

const r2 = (n: number) => Math.round(n * 100) / 100

/**
 * Hazır zemin şablonları. Genişlik (x) × derinlik (z); tahta duvarı her zaman duvar 0 (üst kenar).
 *  l       arka sağ köşeden cutW × cutD kesilir
 *  u       arka duvarın ortasından içeri cutW × cutD girinti (niş/kolon payı)
 *  chamfer arka sol köşe cutW × cutD pahlanır
 */
export function templatePolygon(kind: TemplateKind, t: TemplateParams): RoomPolygon {
  const W = r2(t.width)
  const D = r2(t.depth)
  const cw = r2(Math.min(t.cutW, W - 0.5))
  const cd = r2(Math.min(t.cutD, D - 0.5))
  switch (kind) {
    case 'l':
      return [
        { x: 0, z: 0 }, { x: W, z: 0 }, { x: W, z: r2(D - cd) }, { x: r2(W - cw), z: r2(D - cd) }, { x: r2(W - cw), z: D }, { x: 0, z: D },
      ]
    case 'u': {
      const x0 = r2((W - cw) / 2)
      const x1 = r2(x0 + cw)
      return [
        { x: 0, z: 0 }, { x: W, z: 0 }, { x: W, z: D }, { x: x1, z: D }, { x: x1, z: r2(D - cd) }, { x: x0, z: r2(D - cd) }, { x: x0, z: D }, { x: 0, z: D },
      ]
    }
    case 'chamfer':
      return [{ x: 0, z: 0 }, { x: W, z: 0 }, { x: W, z: D }, { x: cw, z: D }, { x: 0, z: r2(D - cd) }]
    default:
      return [{ x: 0, z: 0 }, { x: W, z: 0 }, { x: W, z: D }, { x: 0, z: D }]
  }
}

/** Kenarın ortasına köşe ekler; yeni köşe sırası i+1 */
export function insertVertex(p: RoomPolygon, edgeIndex: number, at?: Vec2): RoomPolygon {
  const e = edgeAt(p, edgeIndex)
  const v = at ?? { x: (e.a.x + e.b.x) / 2, z: (e.a.z + e.b.z) / 2 }
  const out = p.slice()
  out.splice(edgeIndex + 1, 0, { x: v.x, z: v.z })
  return out
}

export function removeVertex(p: RoomPolygon, index: number): RoomPolygon {
  if (p.length <= 3) return p
  return p.filter((_, i) => i !== index)
}

export function moveVertex(p: RoomPolygon, index: number, to: Vec2): RoomPolygon {
  return p.map((v, i) => (i === index ? { x: to.x, z: to.z } : v))
}

/** Çokgeni (0,0) köşesine hizalar: bbox sol-üst → orijin */
export function toOrigin(p: RoomPolygon): RoomPolygon {
  const b = bbox(p)
  return p.map((v) => ({ x: r2(v.x - b.minX), z: r2(v.z - b.minZ) }))
}
