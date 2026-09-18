import type { CameraState, Doc, LayoutData, LayoutSettings, Opening, RoomPolygon, SceneObject } from '../types'
import { distPointSegment, edgeAt, edges, signedArea } from './polygon'

/** Belge dönüşümleri (sunucu JSON ⇄ düzenleyici) ve açıklık (kapı/pencere) yeniden eşleme — saf. */

let seq = 0
export function newId(prefix = 'o'): string {
  seq = (seq + 1) % 1_000_000
  return `${prefix}-${Date.now().toString(36)}${seq.toString(36)}${Math.random().toString(36).slice(2, 5)}`
}

export const DEFAULT_SETTINGS: LayoutSettings = { grid: 0.1, snap: true, labels: true }

export function docFromData(data: LayoutData): Doc {
  const objects: Record<string, SceneObject> = {}
  const order: string[] = []
  for (const raw of data.objects ?? []) {
    if (!raw || typeof raw.id !== 'string' || objects[raw.id]) continue
    objects[raw.id] = { ...raw, x: Number(raw.x) || 0, z: Number(raw.z) || 0, rot: Number(raw.rot) || 0 }
    order.push(raw.id)
  }
  let polygon: RoomPolygon = data.room.polygon.map((p) => ({ x: Number(p.x), z: Number(p.z) }))
  let openings = (data.openings ?? []).map((o) => ({ ...o }))
  if (signedArea(polygon) < 0) {
    // Saat yönünde gelmiş: ters çevir; duvar i → n−2−i, konum duvarın öbür ucundan ölçülür
    const n = polygon.length
    const lens = polygon.map((_, i) => edgeAt(polygon, i).len)
    polygon = polygon.slice().reverse()
    openings = openings.map((o) => ({ ...o, wall: (((n - 2 - o.wall) % n) + n) % n, offset: (lens[o.wall] ?? 0) - o.offset }))
  }
  return {
    room: {
      polygon,
      ceiling: Number(data.room.ceiling) || 3,
      wallThickness: Number(data.room.wallThickness) || 0.2,
      floor: data.room.floor,
    },
    openings,
    objects,
    order,
  }
}

export function dataFromDoc(doc: Doc, camera: CameraState | null, settings: LayoutSettings): LayoutData {
  return {
    schema: 1,
    room: { ...doc.room, polygon: doc.room.polygon.map((p) => ({ x: round(p.x), z: round(p.z) })) },
    openings: doc.openings,
    objects: doc.order.map((id) => doc.objects[id]!).filter(Boolean),
    camera,
    settings,
  }
}

const round = (n: number) => Math.round(n * 1000) / 1000

/** Açıklığı duvar boyuna sığdırır (merkez [w/2, L−w/2]) */
export function clampOpening(o: Opening, polygon: RoomPolygon): Opening {
  const wall = Math.min(Math.max(0, o.wall), polygon.length - 1)
  const len = edgeAt(polygon, wall).len
  const width = Math.min(o.width, Math.max(0.3, len - 0.1))
  const offset = Math.min(Math.max(o.offset, width / 2 + 0.05), len - width / 2 - 0.05)
  return { ...o, wall, width: round(width), offset: round(Number.isFinite(offset) ? offset : len / 2) }
}

export function openingCenter(o: Opening, polygon: RoomPolygon) {
  const e = edgeAt(polygon, Math.min(o.wall, polygon.length - 1))
  return { x: e.a.x + e.dir.x * o.offset, z: e.a.z + e.dir.z * o.offset }
}

/**
 * Köşe eklenip silinince duvar sıraları kayar: her açıklığın merkezi eski çokgende bulunur, yeni çokgende
 * en yakın duvara izdüşürülür. Köşe taşımada duvar sırası aynı kalır, konum sığdırılır.
 */
export function remapOpenings(openings: Opening[], before: RoomPolygon, after: RoomPolygon, sameTopology: boolean): Opening[] {
  if (sameTopology) return openings.map((o) => clampOpening(o, after))
  return openings.map((o) => {
    const c = openingCenter(o, before)
    let best = { wall: 0, dist: Infinity, s: 0 }
    for (const e of edges(after)) {
      const r = distPointSegment(c, e.a, e.b)
      if (r.dist < best.dist) best = { wall: e.i, dist: r.dist, s: r.t * e.len }
    }
    return clampOpening({ ...o, wall: best.wall, offset: best.s }, after)
  })
}
