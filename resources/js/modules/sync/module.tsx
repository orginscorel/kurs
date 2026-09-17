import { GitCompareArrows, MonitorSmartphone } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'
import { isLocalNode } from '@/components/layout/SyncStatus'

const DevicesPage = lazyPage(() => import('./DevicesPage'))
const ConflictsPage = lazyPage(() => import('./ConflictsPage'))

/**
 * Eşitleme yönetimi: masaüstü (yerel kurulum) ve mobil cihazlar, eşitleme çakışmaları.
 * Üst çubuktaki durum göstergesi ortak kabukta (components/layout/SyncStatus) ve yalnız yerel kurulumda görünür.
 */
export default {
  id: 'sync',
  routes: [
    { path: 'ayarlar/bagli-cihazlar', element: page(<DevicesPage />) },
    { path: 'ayarlar/esitleme-cakismalari', element: page(<ConflictsPage />) },
  ],
  // Yerel kurulumda cihaz yönetimi yok (sunucuda yapılır)
  nav: isLocalNode() ? [] : [
    { section: 'settings', label: 'Bağlı cihazlar', to: '/ayarlar/bagli-cihazlar', icon: MonitorSmartphone, permission: 'sync.manage', order: 6 },
    { section: 'settings', label: 'Eşitleme çakışmaları', to: '/ayarlar/esitleme-cakismalari', icon: GitCompareArrows, permission: 'sync.manage', order: 7 },
  ],
  commands: isLocalNode() ? [] : [
    { id: 'sync-devices', label: 'Bağlı cihazlar', to: '/ayarlar/bagli-cihazlar', icon: MonitorSmartphone, permission: 'sync.manage', keywords: ['cihaz', 'masaüstü', 'mobil', 'eşitleme', 'kurum kodu'] },
    { id: 'sync-conflicts', label: 'Eşitleme çakışmaları', to: '/ayarlar/esitleme-cakismalari', icon: GitCompareArrows, permission: 'sync.manage', keywords: ['çakışma', 'mutabakat', 'eşitleme', 'çevrimdışı'] },
  ],
} satisfies ModuleDef
