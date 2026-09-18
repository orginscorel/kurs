import { Navigate } from 'react-router-dom'
import { BarChart3, ClipboardCheck, Presentation, Users } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const ReportsHome = lazyPage(() => import('./ReportsHome'))
const StudentsReport = lazyPage(() => import('./StudentsReport'))
const AttendanceReport = lazyPage(() => import('./AttendanceReport'))
const LeadsReport = lazyPage(() => import('./LeadsReport'))
const TeacherLoadReport = lazyPage(() => import('./TeacherLoadReport'))
const DisciplineReport = lazyPage(() => import('./DisciplineReport'))
const FinanceAnalyticsReport = lazyPage(() => import('./FinanceAnalyticsReport'))
const CommunicationReport = lazyPage(() => import('./CommunicationReport'))

/**
 * Rapor merkezi (/raporlar). Menüde tek öğe; raporlar sayfa içindeki listeden seçilir
 * (her rapor reports.view + ilgili modül izni ister — menü izni "herhangi biri" mantığıyla çalıştığı için).
 */
export default {
  id: 'reports',
  routes: [
    { path: 'raporlar', element: page(<ReportsHome />) },
    { path: 'raporlar/ogrenciler', element: page(<StudentsReport />) },
    { path: 'raporlar/devamsizlik', element: page(<AttendanceReport />) },
    // Tahsilat ve alacak raporu Finans > Finans raporları ile birleşti (aynı /finance/reports verisi).
    { path: 'raporlar/tahsilat', element: <Navigate to="/finans/raporlar" replace /> },
    // Sınav sonuçları özeti, Sınavlar > Sonuçlar sayfasındaki "Sınav özeti" bölümüyle birleşti.
    { path: 'raporlar/sinavlar', element: <Navigate to="/sinav-sonuclari" replace /> },
    { path: 'raporlar/on-kayit', element: page(<LeadsReport />) },
    { path: 'raporlar/ogretmen-yuku', element: page(<TeacherLoadReport />) },
    { path: 'raporlar/disiplin', element: page(<DisciplineReport />) },
    { path: 'raporlar/finans-analiz', element: page(<FinanceAnalyticsReport />) },
    { path: 'raporlar/iletisim', element: page(<CommunicationReport />) },
  ],
  nav: [
    { section: 'reports', label: 'Rapor Merkezi', to: '/raporlar', icon: BarChart3, permission: 'reports.view', order: 1 },
  ],
  commands: [
    { id: 'reports-home', label: 'Raporlar', to: '/raporlar', icon: BarChart3, permission: 'reports.view', keywords: ['rapor', 'excel', 'pdf', 'istatistik'] },
    { id: 'reports-attendance', label: 'Devamsızlık raporu', to: '/raporlar/devamsizlik', icon: ClipboardCheck, permission: 'reports.view', hint: 'Raporlar', keywords: ['devamsızlık', 'yoklama', 'rapor'] },
    { id: 'reports-students', label: 'Öğrenci listesi raporu', to: '/raporlar/ogrenciler', icon: Users, permission: 'reports.view', hint: 'Raporlar', keywords: ['öğrenci', 'liste', 'excel'] },
    { id: 'reports-teachers', label: 'Öğretmen ders yükü', to: '/raporlar/ogretmen-yuku', icon: Presentation, permission: 'reports.view', hint: 'Raporlar', keywords: ['öğretmen', 'ders yükü', 'saat'] },
  ],
} satisfies ModuleDef
