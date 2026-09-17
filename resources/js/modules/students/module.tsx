import { ClipboardList, Contact, Printer, UserPlus, Users } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const StudentList = lazyPage(() => import('./StudentList'))
const StudentDetail = lazyPage(() => import('./StudentDetail'))
const GuardianList = lazyPage(() => import('./GuardianList'))
const GuardianDetail = lazyPage(() => import('./GuardianDetail'))
const RosterPrintPage = lazyPage(() => import('./RosterPrintPage'))
const GuardianMerge = lazyPage(() => import('./GuardianMerge'))

export default {
  id: 'students',
  routes: [
    { path: 'ogrenciler', element: page(<StudentList />) },
    { path: 'ogrenciler/liste-ciktisi', element: page(<RosterPrintPage />) },
    { path: 'ogrenciler/:id', element: page(<StudentDetail />) },
    { path: 'veliler', element: page(<GuardianList />) },
    { path: 'veliler/birlestir', element: page(<GuardianMerge />) },
    { path: 'veliler/:id', element: page(<GuardianDetail />) },
  ],
  nav: [
    { section: 'people', label: 'Öğrenciler', to: '/ogrenciler', icon: Users, permission: 'students.view', order: 1 },
    { section: 'people', label: 'Veliler', to: '/veliler', icon: Contact, permission: 'guardians.view', order: 2 },
    { section: 'people', label: 'Liste ve çizelge çıktıları', to: '/ogrenciler/liste-ciktisi', icon: Printer, permission: 'students.view', order: 9 },
    { section: 'attendance', label: 'Yoklama çizelgesi (çıktı)', to: '/ogrenciler/liste-ciktisi?tur=yoklama', icon: ClipboardList, permission: 'students.view', order: 9 },
  ],
  commands: [
    { id: 'students', label: 'Öğrenci ara', to: '/ogrenciler', icon: Users, permission: 'students.view', keywords: ['öğrenci', 'liste'] },
    { id: 'student-new', label: 'Yeni öğrenci', to: '/ogrenciler?yeni=1', icon: UserPlus, permission: 'students.create', hint: 'Kayıt', keywords: ['kayıt', 'ekle', 'yeni'] },
    { id: 'student-roster', label: 'Liste ve çizelge çıktıları (PDF/Excel)', to: '/ogrenciler/liste-ciktisi', icon: Printer, permission: 'students.view', keywords: ['liste', 'çıktı', 'yazdır', 'pdf', 'sınıf listesi'] },
    { id: 'attendance-sheet', label: 'Yoklama çizelgesi yazdır', to: '/ogrenciler/liste-ciktisi?tur=yoklama', icon: ClipboardList, permission: 'students.view', keywords: ['yoklama', 'çizelge', 'imza', 'yazdır'] },
    { id: 'guardian-merge', label: 'Mükerrer veli birleştir', to: '/veliler/birlestir', icon: Contact, permission: 'guardians.manage', keywords: ['mükerrer', 'birleştir', 'aynı telefon', 'veli'] },
    { id: 'guardians', label: 'Veli ara', to: '/veliler', icon: Contact, permission: 'guardians.view', keywords: ['veli', 'anne', 'baba'] },
  ],
} satisfies ModuleDef
