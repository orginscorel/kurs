import { CalendarHeart, ShieldAlert, Target } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const MeetingList = lazyPage(() => import('./MeetingList'))
const RiskStudents = lazyPage(() => import('./RiskStudents'))
const GoalTracking = lazyPage(() => import('./GoalTracking'))

export default {
  id: 'guidance',
  routes: [
    { path: 'rehberlik/gorusmeler', element: page(<MeetingList />) },
    { path: 'rehberlik/riskli-ogrenciler', element: page(<RiskStudents />) },
    { path: 'rehberlik/hedefler', element: page(<GoalTracking />) },
  ],
  nav: [
    { section: 'guidance', label: 'Görüşmeler', to: '/rehberlik/gorusmeler', icon: CalendarHeart, permission: 'guidance.view', order: 1 },
    { section: 'guidance', label: 'Riskli Öğrenciler', to: '/rehberlik/riskli-ogrenciler', icon: ShieldAlert, permission: 'risk.view', order: 2 },
    { section: 'guidance', label: 'Hedef Takibi', to: '/rehberlik/hedefler', icon: Target, permission: 'guidance.view', order: 3 },
  ],
  commands: [
    { id: 'guidance-new-meeting', label: 'Görüşme kaydet', to: '/rehberlik/gorusmeler?yeni=1', icon: CalendarHeart, permission: 'guidance.manage', hint: 'Rehberlik', keywords: ['rehberlik', 'görüşme', 'kaydet'] },
    { id: 'guidance-risk', label: 'Riskli öğrenciler', to: '/rehberlik/riskli-ogrenciler', icon: ShieldAlert, permission: 'risk.view', keywords: ['risk', 'rehberlik'] },
  ],
} satisfies ModuleDef
