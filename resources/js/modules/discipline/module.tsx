import { BookOpenCheck, Gavel, LayoutDashboard, Scale, ShieldAlert, ThumbsUp } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const Overview = lazyPage(() => import('./Overview'))
const IncidentList = lazyPage(() => import('./IncidentList'))
const IncidentDetail = lazyPage(() => import('./IncidentDetail'))
const BoardList = lazyPage(() => import('./Board').then((m) => ({ default: m.BoardList })))
const BoardDetail = lazyPage(() => import('./Board').then((m) => ({ default: m.BoardDetail })))
const Catalog = lazyPage(() => import('./Catalog'))
const AttentionList = lazyPage(() => import('./AttentionList'))

/** Disiplin ve cezai işlem sistemi (/disiplin). Yetkiler: discipline.view/create/decide/board/settings/export. */
export default {
  id: 'discipline',
  routes: [
    { path: 'disiplin', element: page(<Overview />) },
    { path: 'disiplin/olaylar', element: page(<IncidentList />) },
    { path: 'disiplin/olaylar/:id', element: page(<IncidentDetail />) },
    { path: 'disiplin/dikkat', element: page(<AttentionList />) },
    { path: 'disiplin/kurul', element: page(<BoardList />) },
    { path: 'disiplin/kurul/:id', element: page(<BoardDetail />) },
    { path: 'disiplin/katalog', element: page(<Catalog />) },
  ],
  nav: [
    { section: 'discipline', label: 'Genel bakış', to: '/disiplin', icon: LayoutDashboard, permission: 'discipline.view', order: 1, end: true },
    { section: 'discipline', label: 'Olaylar', to: '/disiplin/olaylar', icon: Gavel, permission: 'discipline.view', order: 2 },
    { section: 'discipline', label: 'Dikkat gerektirenler', to: '/disiplin/dikkat', icon: ShieldAlert, permission: 'discipline.view', order: 3 },
    { section: 'discipline', label: 'Kurul', to: '/disiplin/kurul', icon: Scale, permission: 'discipline.view', order: 4 },
    { section: 'discipline', label: 'Katalog ve ayarlar', to: '/disiplin/katalog', icon: BookOpenCheck, permission: 'discipline.view', order: 5 },
  ],
  commands: [
    { id: 'discipline-new', label: 'Olay kaydet', to: '/disiplin/olaylar?yeni=1', icon: Gavel, permission: 'discipline.create', hint: 'Disiplin', keywords: ['disiplin', 'olay', 'tutanak', 'ceza', 'uyarı', 'kaydet'] },
    { id: 'discipline-positive', label: 'Olumlu davranış kaydet', to: '/disiplin/olaylar?yeni=olumlu', icon: ThumbsUp, permission: 'discipline.create', hint: 'Disiplin', keywords: ['takdir', 'teşekkür', 'ödül', 'olumlu'] },
    { id: 'discipline-home', label: 'Disiplin', to: '/disiplin', icon: Scale, permission: 'discipline.view', keywords: ['disiplin', 'kurul', 'yaptırım', 'savunma'] },
  ],
} satisfies ModuleDef
