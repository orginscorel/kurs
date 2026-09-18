import { create } from 'zustand'
import type { RosterStudent } from '../types'

/**
 * OTURMA KİPİ — sınıf oturma planı ekranında (oda düzeni salt okunur) öğrenci listesi sınıfın kendi öğrencileridir.
 * `active` false iken (oda düzenleyicisi) masalardaki öğrenci adları/renkleri gösterilmez: oturma sınıf bazındadır.
 */
export type SeatingStudent = RosterStudent & { gender?: 'female' | 'male' | null }

type SeatingContext = {
  active: boolean
  students: SeatingStudent[]
  hasGender: boolean
  set: (p: Partial<Omit<SeatingContext, 'set'>>) => void
}

export const useSeatingContext = create<SeatingContext>((set) => ({
  active: false,
  students: [],
  hasGender: false,
  set: (p) => set(p),
}))
