import type { LucideIcon } from 'lucide-react'
import type { RouteObject } from 'react-router-dom'

/**
 * MODÜL KAYDI
 *
 * Her modül `resources/js/modules/<ad>/module.tsx` dosyasında kendi rotalarını, menü
 * öğelerini ve komut paleti işlemlerini tanımlar. Uygulama bunları otomatik toplar;
 * yeni modül eklemek için ortak bir dosyayı (router, menü) düzenlemek GEREKMEZ.
 */

export type SectionKey =
  | 'main' | 'people' | 'students' | 'teachers' | 'guardians' | 'crm' | 'academic' | 'exams' | 'attendance' | 'guidance' | 'coaching' | 'discipline'
  | 'finance' | 'communication' | 'reports' | 'settings' | 'portal'

export const SECTIONS: Record<SectionKey, { label?: string; order: number }> = {
  main: { order: 0 },
  portal: { order: 5 },
  // Kişiler artık tek çatı yerine üç ayrı ana alandır (Öğrenciler / Veliler / Öğretmenler).
  // Modüller hâlâ section:'people' bildirir; navigation.ts › MOVE_TO bunları alt alanlara dağıtır.
  people: { label: 'Kişiler', order: 10 },
  students: { label: 'Öğrenciler', order: 10 },
  guardians: { label: 'Veliler', order: 11 },
  teachers: { label: 'Öğretmenler', order: 12 },
  crm: { label: 'Kayıt ve CRM', order: 20 },
  academic: { label: 'Akademik', order: 30 },
  coaching: { label: 'Koçluk', order: 35 },
  exams: { label: 'Sınav Merkezi', order: 40 },
  attendance: { label: 'Yoklama', order: 50 },
  guidance: { label: 'Rehberlik', order: 60 },
  discipline: { label: 'Disiplin', order: 65 },
  finance: { label: 'Finans', order: 70 },
  communication: { label: 'İletişim', order: 80 },
  reports: { label: 'Raporlar', order: 90 },
  settings: { label: 'Ayarlar', order: 100 },
}

export type ModuleNavItem = {
  section: SectionKey
  label: string
  to: string
  icon?: LucideIcon
  permission?: string | string[]
  userTypes?: string[]
  end?: boolean
  order?: number
}

export type ModuleCommand = {
  id: string
  label: string
  to: string
  icon: LucideIcon
  permission?: string | string[]
  hint?: string
  keywords?: string[]
}

export type ModuleDef = {
  id: string
  routes: RouteObject[]
  nav?: ModuleNavItem[]
  commands?: ModuleCommand[]
}

/**
 * Yayına giren modüller `modules.generated.ts` dosyasından gelir (scripts/gen-modules.mjs üretir):
 *   VITE_MODULES=core,students,academic node scripts/gen-modules.mjs && npx vite build
 * Listede olmayan (geliştirmesi süren) modül derlemeye hiç girmez; yayını bozamaz.
 */
export { loadedModules as modules } from './modules.generated'
