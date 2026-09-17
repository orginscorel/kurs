import { MonitorDown } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'
import { isLocalNode } from '@/components/layout/SyncStatus'

const AppsPage = lazyPage(() => import('./AppsPage'))

/**
 * Masaüstü (ve ileride mobil) uygulama indirme sayfası. Veri: web kökündeki /desktop/release.json
 * (sunucuda kurs:desktop-release-sync yazar). Öğretmen portalı hesapları yönetim kabuğunu görmediği için
 * aynı sayfa /ogretmen/uygulamalar adresinde sade düzende de açılır.
 * Yerel kurulumda (masaüstü uygulamasının içi) menüde gösterilmez.
 */
export default {
  id: 'desktop-apps',
  routes: [
    { path: 'uygulamalar', element: page(<AppsPage />) },
    { path: 'ogretmen/uygulamalar', element: page(<AppsPage standalone />) },
  ],
  nav: isLocalNode() ? [] : [
    { section: 'settings', label: 'Uygulamalar', to: '/uygulamalar', icon: MonitorDown, order: 20 },
  ],
  commands: isLocalNode() ? [] : [
    { id: 'desktop-apps', label: 'Masaüstü uygulamasını indir', to: '/uygulamalar', icon: MonitorDown, keywords: ['uygulama', 'masaüstü', 'mac', 'macos', 'indir', 'dmg', 'çevrimdışı', 'windows', 'mobil'] },
  ],
} satisfies ModuleDef
