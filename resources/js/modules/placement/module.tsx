import { ArrowLeftRight, LayoutGrid, Sparkles } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const PlacementPage = lazyPage(() => import('./PlacementPage'))

export default {
  id: 'placement',
  routes: [{ path: 'yerlestirme', element: page(<PlacementPage />) }],
  nav: [
    { section: 'people', label: 'Sınıflar ve yerleştirme', to: '/yerlestirme', icon: LayoutGrid, permission: 'academic.view', order: 3 },
  ],
  commands: [
    { id: 'placement-auto', label: 'Otomatik sınıf yerleştir', to: '/yerlestirme?islem=otomatik', icon: Sparkles, permission: 'academic.manage', hint: 'Sınıflar', keywords: ['yerleştir', 'şube', 'dağıt', 'sınıf', 'otomatik'] },
    { id: 'placement-change', label: 'Sınıf değiştir', to: '/yerlestirme?islem=degistir', icon: ArrowLeftRight, permission: 'academic.view', hint: 'Sınıflar', keywords: ['şube değiştir', 'sınıf değişimi', 'takas', 'transfer'] },
  ],
} satisfies ModuleDef
