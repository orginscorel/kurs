import { Armchair, Box, Plus } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

/**
 * 3D DERSLİK TASARIMI VE OTURMA DÜZENİ
 * Sayfalar tembel yüklenir: three.js / @react-three yalnız bu rotalarda iner (ana paket büyümez).
 * Arka uç: routes/api/classroom-design.php · yetki: academic.view (görme), classroom_layouts.manage (düzenleme)
 */
const LayoutListPage = lazyPage(() => import('./pages/LayoutListPage'))
const LayoutEditorPage = lazyPage(() => import('./pages/LayoutEditorPage'))
const LayoutWizardPage = lazyPage(() => import('./pages/LayoutWizardPage'))

export default {
  id: 'classroom-design',
  routes: [
    { path: 'derslik-tasarimi', element: page(<LayoutListPage />) },
    { path: 'derslik-tasarimi/yeni', element: page(<LayoutWizardPage />) },
    { path: 'derslik-tasarimi/:id', element: page(<LayoutEditorPage />) },
  ],
  nav: [{ section: 'academic', label: 'Derslik tasarımı', to: '/derslik-tasarimi', icon: Armchair, permission: 'academic.view', order: 2.5 }],
  commands: [
    { id: 'classroom-design', label: 'Derslik tasarımı (3D)', to: '/derslik-tasarimi', icon: Box, permission: 'academic.view', hint: 'Oturma düzeni', keywords: ['derslik', '3d', 'oturma', 'masa', 'sıra', 'yerleşim', 'plan', 'oda'] },
    { id: 'classroom-design-new', label: 'Yeni derslik tasarımı', to: '/derslik-tasarimi/yeni', icon: Plus, permission: 'classroom_layouts.manage', hint: 'Oda planı çiz', keywords: ['derslik', 'oda', 'çiz', 'yeni', '3d'] },
  ],
} satisfies ModuleDef
