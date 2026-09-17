import { BarChart3, ClipboardList, FilePlus2, ScanLine, Target, Trophy } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const ExamList = lazyPage(() => import('./ExamList'))
const ExamDetail = lazyPage(() => import('./ExamDetail'))
const AnswerKeyEditor = lazyPage(() => import('./AnswerKeyEditor'))
const OpticalImportPage = lazyPage(() => import('./OpticalImportPage'))
const ResultsPage = lazyPage(() => import('./ResultsPage'))
const CommandCenter = lazyPage(() => import('./CommandCenter'))
const TopicAnalysisPage = lazyPage(() => import('./TopicAnalysisPage'))

export default {
  id: 'exams',
  routes: [
    { path: 'sinavlar', element: page(<ExamList />) },
    { path: 'sinavlar/:id', element: page(<ExamDetail />) },
    { path: 'sinavlar/:id/cevap-anahtari', element: page(<AnswerKeyEditor />) },
    { path: 'optik-okuma', element: page(<OpticalImportPage />) },
    { path: 'sinav-sonuclari', element: page(<ResultsPage />) },
    { path: 'sinav-analizleri', element: page(<CommandCenter />) },
    { path: 'kazanim-analizi', element: page(<TopicAnalysisPage />) },
  ],
  nav: [
    { section: 'exams', label: 'Denemeler', to: '/sinavlar', icon: ClipboardList, permission: 'exams.view', order: 1 },
    { section: 'exams', label: 'Optik Okuma', to: '/optik-okuma', icon: ScanLine, permission: 'exams.import', order: 2 },
    { section: 'exams', label: 'Sonuçlar', to: '/sinav-sonuclari', icon: Trophy, permission: 'exams.view', order: 3 },
    { section: 'exams', label: 'Analizler', to: '/sinav-analizleri', icon: BarChart3, permission: 'exams.view', order: 4 },
    { section: 'exams', label: 'Kazanım Analizi', to: '/kazanim-analizi', icon: Target, permission: 'exams.view', order: 5 },
  ],
  commands: [
    { id: 'exam-new', label: 'Sınav Oluştur', to: '/sinavlar?yeni=1', icon: FilePlus2, permission: 'exams.manage', hint: 'Deneme', keywords: ['deneme', 'sınav', 'yeni', 'oluştur', 'tyt', 'ayt', 'lgs'] },
    { id: 'optical-upload', label: 'Optik okuma yükle', to: '/optik-okuma', icon: ScanLine, permission: 'exams.import', hint: 'İçe aktar', keywords: ['optik', 'okuma', 'yükle', 'içe aktar', 'csv', 'txt'] },
    { id: 'exam-results', label: 'Sınav sonuçları', to: '/sinav-sonuclari', icon: Trophy, permission: 'exams.view', keywords: ['sonuç', 'net', 'sıralama', 'deneme'] },
    { id: 'exam-analytics', label: 'Akademik Komuta Merkezi', to: '/sinav-analizleri', icon: BarChart3, permission: 'exams.view', keywords: ['analiz', 'trend', 'sınıf karşılaştırma'] },
  ],
} satisfies ModuleDef
