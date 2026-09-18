import { create } from 'zustand'
import type { SceneObject } from '../types'

/** Akıllı yerleşim önizlemesi: üretilen (henüz uygulanmamış) masalar — 3D sahne ve 2D plan yarı saydam çizer */
export const useGhosts = create<{ list: SceneObject[]; set: (list: SceneObject[]) => void }>((set) => ({
  list: [],
  set: (list) => set({ list }),
}))
