import { create } from 'zustand'
import type { SceneObject, SeatStudent, Vec2 } from '../types'

/**
 * KÜTÜPHANEDEN / ÖĞRENCİ LİSTESİNDEN SÜRÜKLE-BIRAK (fare + dokunmatik; HTML5 DnD yerine işaretçi olayları).
 * Görünümler (3D sahne, 2D plan) ekran koordinatını sahneye çeviren bir "çözücü" kaydeder:
 *   zemin noktası (nesne bırakma) ve imlecin altındaki masa (öğrenci bırakma).
 */

export type DragPayload =
  | { kind: 'furniture'; type: string; label: string }
  | { kind: 'student'; student: SeatStudent; fromSeat?: { id: string; seat: number } | null }

export type DropHit = { point: Vec2 | null; objectId: string | null; seat: number | null }

export type Resolver = { el: () => HTMLElement | null; resolve: (clientX: number, clientY: number) => DropHit | null }

type DragState = {
  payload: DragPayload | null
  pointer: { x: number; y: number } | null
  /** zemin önizlemesi (nesne sürüklemesi) */
  preview: SceneObject | null
  previewValid: boolean
  previewReason: string | null
  /** öğrenci bırakma hedefi */
  target: { id: string; seat: number } | null
  start: (p: DragPayload, x: number, y: number) => void
  move: (x: number, y: number) => void
  setPreview: (o: SceneObject | null, valid: boolean, reason?: string | null) => void
  setTarget: (t: { id: string; seat: number } | null) => void
  end: () => void
}

export const useDrag = create<DragState>((set) => ({
  payload: null,
  pointer: null,
  preview: null,
  previewValid: false,
  previewReason: null,
  target: null,
  start: (payload, x, y) => set({ payload, pointer: { x, y }, preview: null, target: null }),
  move: (x, y) => set({ pointer: { x, y } }),
  setPreview: (preview, previewValid, previewReason = null) => set({ preview, previewValid, previewReason }),
  setTarget: (target) => set((s) => (s.target?.id === target?.id && s.target?.seat === target?.seat ? s : { target })),
  end: () => set({ payload: null, pointer: null, preview: null, target: null, previewReason: null }),
}))

/** Görünüm çözücüleri (3D, 2D) — modül düzeyinde kayıt */
const resolvers = new Map<string, Resolver>()

export function registerResolver(key: string, r: Resolver) {
  resolvers.set(key, r)
  return () => {
    if (resolvers.get(key) === r) resolvers.delete(key)
  }
}

/** İmleç hangi görünümün üstündeyse onun çözümü */
export function resolveAt(clientX: number, clientY: number): DropHit | null {
  for (const r of resolvers.values()) {
    const el = r.el()
    if (!el || el.offsetParent === null && getComputedStyle(el).position !== 'fixed') continue
    const b = el.getBoundingClientRect()
    if (b.width < 2 || b.height < 2) continue
    if (clientX < b.left || clientX > b.right || clientY < b.top || clientY > b.bottom) continue
    if (getComputedStyle(el).visibility === 'hidden') continue
    const hit = r.resolve(clientX, clientY)
    if (hit) return hit
  }
  return null
}
