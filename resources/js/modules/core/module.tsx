import { LayoutDashboard, Sparkles, Sun, UserCog } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const Dashboard = lazyPage(() => import('./Dashboard'))
const Today = lazyPage(() => import('./Today'))
const Account = lazyPage(() => import('./Account'))
const ChangelogPage = lazyPage(() => import('./ChangelogPage'))

export default {
  id: 'core',
  routes: [
    { index: true, element: page(<Dashboard />) },
    { path: 'bugun', element: page(<Today />) },
    { path: 'hesabim', element: page(<Account />) },
    { path: 'yenilikler', element: page(<ChangelogPage />) },
  ],
  nav: [
    { section: 'main', label: 'Kontrol Merkezi', to: '/', icon: LayoutDashboard, permission: 'dashboard.view', end: true, order: 1 },
    { section: 'main', label: 'Bugün', to: '/bugun', icon: Sun, permission: 'operations.view', order: 2 },
  ],
  commands: [
    { id: 'dashboard', label: 'Kontrol Merkezi', to: '/', icon: LayoutDashboard, permission: 'dashboard.view', keywords: ['pano', 'dashboard'] },
    { id: 'today', label: 'Bugünün operasyonu', to: '/bugun', icon: Sun, permission: 'operations.view', keywords: ['bugün', 'günlük', 'gelmeyen'] },
    { id: 'changelog', label: 'Yenilikler (sürüm notları)', to: '/yenilikler', icon: Sparkles, keywords: ['sürüm', 'güncelleme', 'yenilik', 'changelog', 'versiyon'] },
    { id: 'account', label: 'Hesabım ve parola', to: '/hesabim', icon: UserCog, keywords: ['parola', 'şifre', 'oturum'] },
  ],
} satisfies ModuleDef
