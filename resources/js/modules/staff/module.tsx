import { GraduationCap, LayoutGrid, UserCog, UserPlus, Users } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const TeacherList = lazyPage(() => import('./TeacherList'))
const TeacherDetail = lazyPage(() => import('./TeacherDetail'))
const EmployeeList = lazyPage(() => import('./EmployeeList'))
const TeacherPanel = lazyPage(() => import('./TeacherPanel'))

export default {
  id: 'staff',
  routes: [
    { path: 'ogretmenler', element: page(<TeacherList />) },
    { path: 'ogretmenler/:id', element: page(<TeacherDetail />) },
    { path: 'personel', element: page(<EmployeeList />) },
    { path: 'panelim', element: page(<TeacherPanel />) },
  ],
  nav: [
    { section: 'people', label: 'Öğretmenler', to: '/ogretmenler', icon: GraduationCap, permission: 'teachers.view', order: 3 },
    { section: 'people', label: 'Personel', to: '/personel', icon: UserCog, permission: 'employees.view', order: 4 },
    { section: 'portal', label: 'Panelim', to: '/panelim', icon: LayoutGrid, userTypes: ['teacher'], order: 1 },
  ],
  commands: [
    { id: 'teachers', label: 'Öğretmen ara', to: '/ogretmenler', icon: GraduationCap, permission: 'teachers.view', keywords: ['öğretmen', 'branş'] },
    { id: 'teacher-new', label: 'Yeni öğretmen', to: '/ogretmenler?yeni=1', icon: UserPlus, permission: 'teachers.manage', keywords: ['öğretmen', 'ekle', 'yeni'] },
    { id: 'employees', label: 'Personel ara', to: '/personel', icon: Users, permission: 'employees.view', keywords: ['personel', 'çalışan'] },
  ],
} satisfies ModuleDef
