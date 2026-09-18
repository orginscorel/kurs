import { create } from 'zustand'
import type { SceneObject, Vec2 } from '../types'

/**
 * SEÇİM ve GÖRÜNÜM DURUMU (belgeye girmez, geri almaya girmez): seçili nesneler/açıklık/köşe, araç,
 * görünüm kipi, panel sekmesi, ölçümler, sürükleme sırasında geçersiz (çakışan) nesneler, pano.
 */

export type Tool = 'select' | 'measure' | 'room'
export type ViewMode = '3d' | '2d' | 'split'
export type PanelTab = 'library' | 'props' | 'students' | 'room'
export type Measurement = { id: string; a: Vec2; b: Vec2 }

type SelectionState = {
  selected: string[]
  openingId: string | null
  vertex: number | null
  hover: string | null
  tool: Tool
  view: ViewMode
  tab: PanelTab
  measurements: Measurement[]
  pendingMeasure: Vec2 | null
  /** sürükleme/önizleme sırasında çakışan nesneler */
  invalid: Set<string>
  clipboard: SceneObject[]
  /** seçim kutusu (ekran pikselleri, tuval kutusuna göre) */
  marquee: { x0: number; y0: number; x1: number; y1: number } | null

  select: (ids: string[], additive?: boolean) => void
  toggle: (id: string) => void
  clear: () => void
  selectOpening: (id: string | null) => void
  selectVertex: (i: number | null) => void
  setHover: (id: string | null) => void
  setTool: (t: Tool) => void
  setView: (v: ViewMode) => void
  setTab: (t: PanelTab) => void
  addMeasurement: (m: Measurement) => void
  clearMeasurements: () => void
  setPendingMeasure: (p: Vec2 | null) => void
  setInvalid: (ids: Set<string>) => void
  setClipboard: (objs: SceneObject[]) => void
  setMarquee: (m: SelectionState['marquee']) => void
}

const EMPTY = new Set<string>()

export const useSelection = create<SelectionState>((set) => ({
  selected: [],
  openingId: null,
  vertex: null,
  hover: null,
  tool: 'select',
  view: '3d',
  tab: 'library',
  measurements: [],
  pendingMeasure: null,
  invalid: EMPTY,
  clipboard: [],
  marquee: null,

  select: (ids, additive) =>
    set((s) => {
      const selected = additive ? Array.from(new Set([...s.selected, ...ids])) : ids
      return { selected, openingId: null, vertex: null, tab: selected.length && s.tab !== 'students' ? 'props' : s.tab }
    }),
  toggle: (id) =>
    set((s) => {
      const selected = s.selected.includes(id) ? s.selected.filter((x) => x !== id) : [...s.selected, id]
      return { selected, openingId: null, vertex: null, tab: selected.length && s.tab !== 'students' ? 'props' : s.tab }
    }),
  clear: () => set((s) => ({ selected: [], openingId: null, vertex: null, tab: s.tab === 'props' ? 'library' : s.tab })),
  selectOpening: (id) => set((s) => ({ openingId: id, selected: [], vertex: null, tab: id ? 'room' : s.tab })),
  selectVertex: (vertex) => set({ vertex }),
  setHover: (hover) => set((s) => (s.hover === hover ? s : { hover })),
  setTool: (tool) => set((s) => ({ tool, pendingMeasure: null, vertex: tool === 'room' ? s.vertex : null, tab: tool === 'room' ? 'room' : s.tab })),
  setView: (view) => set({ view }),
  setTab: (tab) => set({ tab }),
  addMeasurement: (m) => set((s) => ({ measurements: [...s.measurements.slice(-9), m], pendingMeasure: null })),
  clearMeasurements: () => set({ measurements: [], pendingMeasure: null }),
  setPendingMeasure: (pendingMeasure) => set({ pendingMeasure }),
  setInvalid: (invalid) => set((s) => (s.invalid.size === 0 && invalid.size === 0 ? s : { invalid })),
  setClipboard: (clipboard) => set({ clipboard }),
  setMarquee: (marquee) => set({ marquee }),
}))

export const NO_INVALID = EMPTY
