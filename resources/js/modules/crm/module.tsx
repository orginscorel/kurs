import { Navigate } from 'react-router-dom'
import { ClipboardList, ClipboardPlus, UserPlus, Users } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const PreRegistrationList = lazyPage(() => import('./PreRegistrationList'))
const MyTasks = lazyPage(() => import('./MyTasks'))

export default {
  id: 'crm',
  routes: [
    { path: 'on-kayit', element: page(<PreRegistrationList />) },
    // Eski adresler: aday panosu Ön Kayıt'a, CRM raporu Rapor Merkezi'ne taşındı (LeadBoard/CrmReport dosyaları kullanılmıyor)
    { path: 'crm/adaylar', element: <Navigate to="/on-kayit" replace /> },
    { path: 'crm/rapor', element: <Navigate to="/raporlar/on-kayit" replace /> },
    { path: 'gorevlerim', element: page(<MyTasks />) },
  ],
  nav: [
    { section: 'crm', label: 'Ön Kayıt', to: '/on-kayit', icon: ClipboardPlus, permission: 'crm.view', order: 1 },
    { section: 'crm', label: 'Görevlerim', to: '/gorevlerim', icon: ClipboardList, order: 2 },
  ],
  commands: [
    { id: 'crm-new-lead', label: 'Yeni ön kayıt', to: '/on-kayit?yeni=1', icon: UserPlus, permission: 'crm.manage', hint: 'Ön Kayıt', keywords: ['ön kayıt', 'aday', 'ekle', 'yeni'] },
    { id: 'crm-leads', label: 'Ön kayıtlar', to: '/on-kayit', icon: Users, permission: 'crm.view', keywords: ['ön kayıt', 'aday', 'arama', 'takip'] },
    { id: 'crm-my-tasks', label: 'Görevlerim', to: '/gorevlerim', icon: ClipboardList, keywords: ['görev', 'hatırlatma', 'takip'] },
  ],
} satisfies ModuleDef
