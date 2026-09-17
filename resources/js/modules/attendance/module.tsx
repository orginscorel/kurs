import { CheckSquare, QrCode, Radio, ScanLine, UserX } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const LivePresence = lazyPage(() => import('./LivePresence'))
const AttendanceTaking = lazyPage(() => import('./AttendanceTaking'))
const Absences = lazyPage(() => import('./Absences'))
const Devices = lazyPage(() => import('./Devices'))
const StudentQrCard = lazyPage(() => import('./StudentQrCard'))
const KioskScan = lazyPage(() => import('./KioskScan'))

export default {
  id: 'attendance',
  routes: [
    { path: 'yoklama', element: page(<AttendanceTaking />) },
    { path: 'yoklama/canli', element: page(<LivePresence />) },
    { path: 'yoklama/devamsizlik', element: page(<Absences />) },
    { path: 'yoklama/cihazlar', element: page(<Devices />) },
    { path: 'yoklama/kiosk', element: page(<KioskScan />) },
    { path: 'yoklama/ogrenciler/:id/qr', element: page(<StudentQrCard />) },
  ],
  nav: [
    { section: 'attendance', label: 'Canlı Giriş/Çıkış', to: '/yoklama/canli', icon: Radio, permission: 'presence.live', order: 1 },
    { section: 'attendance', label: 'Yoklama', to: '/yoklama', end: true, icon: CheckSquare, permission: 'attendance.view', order: 2 },
    { section: 'attendance', label: 'Devamsızlık', to: '/yoklama/devamsizlik', icon: UserX, permission: 'attendance.view', order: 3 },
    { section: 'attendance', label: 'Cihazlar', to: '/yoklama/cihazlar', icon: ScanLine, permission: 'devices.manage', order: 4 },
  ],
  commands: [
    { id: 'attendance-take', label: 'Yoklama Aç', to: '/yoklama', icon: CheckSquare, permission: 'attendance.view', keywords: ['yoklama', 'ders', 'var', 'yok'] },
    { id: 'attendance-live', label: 'Canlı giriş/çıkış', to: '/yoklama/canli', icon: Radio, permission: 'presence.live', hint: 'Yoklama', keywords: ['giriş', 'çıkış', 'kurumda'] },
    { id: 'attendance-absence-report', label: 'Devamsızlık raporu', to: '/yoklama/devamsizlik', icon: UserX, permission: 'attendance.view', hint: 'Yoklama', keywords: ['devamsızlık', 'rapor', 'yok'] },
    { id: 'attendance-devices', label: 'Cihazlar', to: '/yoklama/cihazlar', icon: ScanLine, permission: 'devices.manage', hint: 'Yoklama', keywords: ['cihaz', 'parmak izi', 'kart', 'jeton'] },
    { id: 'attendance-kiosk', label: 'QR okuma ekranı (kiosk)', to: '/yoklama/kiosk', icon: QrCode, permission: 'presence.live', hint: 'Yoklama', keywords: ['qr', 'kiosk', 'okuma'] },
  ],
} satisfies ModuleDef
