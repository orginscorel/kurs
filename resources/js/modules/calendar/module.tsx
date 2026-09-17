import { CalendarOff, CalendarRange } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const CalendarPage = lazyPage(() => import('./CalendarPage'))

/** Birleşik takvim: dersler, etüt, sınav, izin, tatil (menü bölümü: Akademik). */
export default {
  id: 'calendar',
  routes: [{ path: 'takvim', element: page(<CalendarPage />) }],
  nav: [{ section: 'academic', label: 'Takvim', to: '/takvim', icon: CalendarRange, permission: 'schedule.view', order: 1.5 }],
  commands: [
    { id: 'calendar', label: 'Takvim', to: '/takvim', icon: CalendarRange, permission: 'schedule.view', keywords: ['takvim', 'ajanda', 'tatil', 'sınav', 'izin', 'ical'] },
    { id: 'calendar-agenda', label: 'Bugünün ajandası', to: '/takvim?gorunum=ajanda', icon: CalendarOff, permission: 'schedule.view', keywords: ['ajanda', 'bugün', 'ders listesi'] },
  ],
} satisfies ModuleDef
