import { BookOpen, Bot, CalendarClock, CalendarDays, CalendarPlus, ClipboardList, ClipboardPlus, Clock3, LayoutGrid, UserRoundCheck } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const SchedulePage = lazyPage(() => import('./SchedulePage'))
const AcademicSetup = lazyPage(() => import('./AcademicSetup'))
const ClassGroupList = lazyPage(() => import('./ClassGroupList'))
const ClassGroupDetail = lazyPage(() => import('./ClassGroupDetail'))
const AcademicStructure = lazyPage(() => import('./AcademicStructure'))
const ProgramDetail = lazyPage(() => import('./ProgramDetail'))
const SubjectDetail = lazyPage(() => import('./SubjectDetail'))
const ClassroomDetail = lazyPage(() => import('./ClassroomDetail'))
const StudyPage = lazyPage(() => import('./StudyPage'))
const AvailabilityPage = lazyPage(() => import('./AvailabilityPage'))
const HomeworkList = lazyPage(() => import('./HomeworkList'))
const HomeworkDetail = lazyPage(() => import('./HomeworkDetail'))
const TimetableBotPage = lazyPage(() => import('./timetable/TimetableBotPage'))
const RunDetailPage = lazyPage(() => import('./timetable/RunDetailPage'))
const TimeTemplatesPage = lazyPage(() => import('./timetable/TimeTemplatesPage'))
const SubstitutePage = lazyPage(() => import('./timetable/SubstitutePage'))
const ClassStructurePage = lazyPage(() => import('./timetable/ClassStructurePage'))

export default {
  id: 'academic',
  routes: [
    { path: 'ders-programi', element: page(<SchedulePage />) },
    { path: 'akademik/kurulum', element: page(<AcademicSetup />) },
    { path: 'siniflar', element: page(<ClassGroupList />) },
    { path: 'siniflar/:id', element: page(<ClassGroupDetail />) },
    { path: 'akademik', element: page(<AcademicStructure />) },
    { path: 'akademik/programlar/:id', element: page(<ProgramDetail />) },
    { path: 'akademik/dersler/:id', element: page(<SubjectDetail />) },
    { path: 'akademik/derslikler/:id', element: page(<ClassroomDetail />) },
    { path: 'etut', element: page(<StudyPage />) },
    { path: 'etut/uygunluk', element: page(<AvailabilityPage />) },
    { path: 'odevler', element: page(<HomeworkList />) },
    { path: 'odevler/:id', element: page(<HomeworkDetail />) },
    { path: 'program-botu', element: page(<TimetableBotPage />) },
    { path: 'program-botu/sablonlar', element: page(<TimeTemplatesPage />) },
    { path: 'program-botu/yedek', element: page(<SubstitutePage />) },
    { path: 'program-botu/sinif-yapisi', element: page(<ClassStructurePage />) },
    { path: 'program-botu/:id', element: page(<RunDetailPage />) },
  ],
  nav: [
    { section: 'academic', label: 'Kurulum', to: '/akademik/kurulum', icon: ClipboardPlus, permission: 'academic.manage', order: 0.5 },
    { section: 'academic', label: 'Ders programı', to: '/ders-programi', icon: CalendarDays, permission: 'schedule.view', order: 1 },
    { section: 'academic', label: 'Program botu', to: '/program-botu', icon: Bot, permission: 'schedule.view', order: 1.7 },
    { section: 'academic', label: 'Sınıflar', to: '/siniflar', icon: LayoutGrid, permission: 'academic.view', order: 2 },
    { section: 'academic', label: 'Programlar ve dersler', to: '/akademik', icon: BookOpen, permission: 'academic.view', order: 3 },
    { section: 'academic', label: 'Etüt ve birebir', to: '/etut', icon: CalendarClock, permission: 'study.view', order: 4 },
    { section: 'academic', label: 'Ödevler', to: '/odevler', icon: ClipboardList, permission: 'homework.view', order: 5 },
  ],
  commands: [
    { id: 'schedule', label: 'Ders programı', to: '/ders-programi', icon: CalendarDays, permission: 'schedule.view', keywords: ['program', 'takvim', 'haftalık', 'ders'] },
    { id: 'schedule-new', label: 'Ders oluştur', to: '/ders-programi?yeni=1', icon: CalendarPlus, permission: 'schedule.manage', hint: 'Programa ekle', keywords: ['ders', 'ekle', 'yeni', 'program'] },
    { id: 'study-new', label: 'Etüt planla', to: '/etut?yeni=1', icon: CalendarClock, permission: 'study.view', hint: 'Etüt / birebir', keywords: ['etüt', 'birebir', 'planla'] },
    { id: 'homework-new', label: 'Ödev ver', to: '/odevler?yeni=1', icon: ClipboardPlus, permission: 'homework.manage', hint: 'Sınıfa ya da öğrencilere', keywords: ['ödev', 'ver', 'yeni'] },
    { id: 'timetable-bot', label: 'Program botu', to: '/program-botu', icon: Bot, permission: 'schedule.view', hint: 'Otomatik ders programı', keywords: ['bot', 'otomatik', 'program', 'yerleştir', 'çizelge'] },
    { id: 'time-templates', label: 'Zaman şablonları', to: '/program-botu/sablonlar', icon: Clock3, permission: 'schedule.view', keywords: ['şablon', 'ders saati', 'dilim', 'teneffüs'] },
    { id: 'class-structure', label: 'Sınıf yapısı', to: '/program-botu/sinif-yapisi', icon: LayoutGrid, permission: 'academic.view', hint: 'Seviye, şube, alan, müfredat', keywords: ['şube', 'alan', 'sayısal', 'eşit ağırlık', 'müfredat', 'sınıf aç'] },
    { id: 'substitute', label: 'Yedek öğretmen', to: '/program-botu/yedek', icon: UserRoundCheck, permission: 'schedule.view', keywords: ['yedek', 'izin', 'vekil', 'telafi'] },
    { id: 'class-groups', label: 'Sınıflar', to: '/siniflar', icon: LayoutGrid, permission: 'academic.view', keywords: ['sınıf', 'şube'] },
    { id: 'class-group-new', label: 'Sınıf / şube ekle', to: '/siniflar?yeni=1', icon: LayoutGrid, permission: 'academic.manage', hint: 'Yeni sınıf', keywords: ['sınıf', 'şube', 'ekle', 'yeni', 'aç', 'oluştur'] },
    { id: 'academic', label: 'Programlar ve dersler', to: '/akademik', icon: BookOpen, permission: 'academic.view', keywords: ['program', 'ders', 'derslik', 'konu', 'kazanım'] },
  ],
} satisfies ModuleDef
