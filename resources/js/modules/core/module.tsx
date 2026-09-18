import { Navigate } from 'react-router-dom'
import { LayoutDashboard, Sparkles, UserCog } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const Home = lazyPage(() => import('./Home'))
const Account = lazyPage(() => import('./Account'))
const ChangelogPage = lazyPage(() => import('./ChangelogPage'))

export default {
  id: 'core',
  routes: [
    { index: true, element: page(<Home />) },
    // Bugün panosu Kontrol Merkezi'nin "Bugün" sekmesi oldu; eski adres oraya gider.
    { path: 'bugun', element: <Navigate to="/" replace /> },
    { path: 'hesabim', element: page(<Account />) },
    { path: 'yenilikler', element: page(<ChangelogPage />) },
  ],
  nav: [
    { section: 'main', label: 'Kontrol Merkezi', to: '/', icon: LayoutDashboard, permission: ['dashboard.view', 'operations.view'], end: true, order: 1 },
  ],
  commands: [
    { id: 'dashboard', label: 'Kontrol Merkezi · Bugün (dersler, sınıflar, gelmeyenler)', to: '/', icon: LayoutDashboard, permission: ['dashboard.view', 'operations.view'], keywords: ['pano', 'dashboard', 'bugün', 'günlük', 'gelmeyen', 'ders akışı', 'sınıflar'] },
    { id: 'dashboard-ozet', label: 'Kontrol Merkezi · Kurum özeti (grafikler, doluluk, canlı akış)', to: '/?sekme=ozet', icon: LayoutDashboard, permission: 'dashboard.view', keywords: ['özet', 'grafik', 'performans', 'doluluk', 'canlı akış', 'rapor'] },
    { id: 'changelog', label: 'Yenilikler (sürüm notları)', to: '/yenilikler', icon: Sparkles, keywords: ['sürüm', 'güncelleme', 'yenilik', 'changelog', 'versiyon'] },
    { id: 'account', label: 'Hesabım ve parola', to: '/hesabim', icon: UserCog, keywords: ['parola', 'şifre', 'oturum'] },
  ],
} satisfies ModuleDef
