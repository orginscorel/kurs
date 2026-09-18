import { create } from 'zustand'
import type { Doc } from '../types'

/**
 * GERİ AL / YİNELE — belge anlık görüntüleri (değişmez güncellemeler sayesinde yapısal paylaşım: ucuz).
 * Her işlem (ekle, sil, taşı, döndür, öğrenci ata, duvar/köşe/kapı/pencere düzenle) bir adım.
 * Sürükleme gibi sürekli işlemler tek adım olarak (hareket başındaki belge) kaydedilir.
 */
export const HISTORY_LIMIT = 150

type HistoryState = {
  past: Doc[]
  future: Doc[]
  push: (doc: Doc) => void
  /** geri al: şu anki belgeyi ileri yığına atar, öncekini döndürür */
  undo: (current: Doc) => Doc | null
  redo: (current: Doc) => Doc | null
  clear: () => void
}

export const useHistory = create<HistoryState>((set, get) => ({
  past: [],
  future: [],
  push: (doc) => set((s) => ({ past: [...s.past.slice(-(HISTORY_LIMIT - 1)), doc], future: [] })),
  undo: (current) => {
    const { past, future } = get()
    const prev = past[past.length - 1]
    if (!prev) return null
    set({ past: past.slice(0, -1), future: [current, ...future].slice(0, HISTORY_LIMIT) })
    return prev
  },
  redo: (current) => {
    const { past, future } = get()
    const next = future[0]
    if (!next) return null
    set({ past: [...past, current].slice(-HISTORY_LIMIT), future: future.slice(1) })
    return next
  },
  clear: () => set({ past: [], future: [] }),
}))
