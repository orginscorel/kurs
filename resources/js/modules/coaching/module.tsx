import { CalendarCheck, ClipboardList, LayoutDashboard, Plus, UserCheck } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const CoachDashboard = lazyPage(() => import('./CoachDashboard'))
const CoachingStudents = lazyPage(() => import('./CoachingStudents'))
const CoachingPlans = lazyPage(() => import('./CoachingPlans'))
const StudentCoachingPage = lazyPage(() => import('./StudentCoachingPage'))

/**
 * KOÇLUK (akademik koçluk) modülü. Menü öğeleri şimdilik "Rehberlik" alanında görünür;
 * ayrı bir "Koçluk" üst başlığı için navigation.ts GROUPS/ORDER'a ekleme gerekir (RAPOR'a bakınız).
 */
export default {
  id: 'coaching',
  routes: [
    { path: 'kocluk/panom', element: page(<CoachDashboard />) },
    { path: 'kocluk/ogrenciler', element: page(<CoachingStudents />) },
    { path: 'kocluk/planlar', element: page(<CoachingPlans />) },
    { path: 'kocluk/ogrenci/:id', element: page(<StudentCoachingPage />) },
  ],
  nav: [
    { section: 'coaching', label: 'Koç Panom', to: '/kocluk/panom', icon: LayoutDashboard, permission: 'coaching.view', order: 1 },
    { section: 'coaching', label: 'Koçluk Öğrencileri', to: '/kocluk/ogrenciler', icon: UserCheck, permission: 'coaching.view', order: 2 },
    { section: 'coaching', label: 'Çalışma Planları', to: '/kocluk/planlar', icon: ClipboardList, permission: 'coaching.view', order: 3 },
  ],
  commands: [
    { id: 'coaching-dashboard', label: 'Koç panom', to: '/kocluk/panom', icon: LayoutDashboard, permission: 'coaching.view', hint: 'Koçluk', keywords: ['koç', 'koçluk', 'panom', 'pano'] },
    { id: 'coaching-students', label: 'Koçluk öğrencileri / koç ata', to: '/kocluk/ogrenciler', icon: UserCheck, permission: 'coaching.manage', hint: 'Koçluk', keywords: ['koç', 'ata', 'öğrenci', 'koçluk'] },
    { id: 'coaching-plans', label: 'Haftalık çalışma planları', to: '/kocluk/planlar', icon: CalendarCheck, permission: 'coaching.view', hint: 'Koçluk', keywords: ['plan', 'haftalık', 'çalışma', 'koçluk'] },
    { id: 'coaching-new-session', label: 'Koçluk görüşmesi kaydet', to: '/kocluk/ogrenciler?yeni=gorusme', icon: Plus, permission: 'coaching.manage', hint: 'Koçluk', keywords: ['koçluk', 'görüşme', 'kaydet'] },
  ],
} satisfies ModuleDef
