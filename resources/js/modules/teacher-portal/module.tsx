import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

/**
 * ÖĞRETMEN PORTALI — yalnız öğretmen hesabıyla (ya da personelin "öğretmen olarak giriş" önizlemesiyle) açılır.
 * Yönetim kabuğu yerine kendi sade kabuğunu kullanır (router ShellSwitch /ogretmen altını sarmaz).
 * Menüye (nav) öğe eklemez: personel menüsünde görünmez.
 */
const Shell = lazyPage(() => import('./TeacherShell'))
const Home = lazyPage(() => import('./pages/TeacherHome'))
const Schedule = lazyPage(() => import('./pages/TeacherSchedule'))
const Attendance = lazyPage(() => import('./pages/TeacherAttendance'))
const AttendanceTake = lazyPage(() => import('./pages/TeacherAttendanceTake'))
const Homework = lazyPage(() => import('./pages/TeacherHomework'))
const HomeworkDetail = lazyPage(() => import('./pages/TeacherHomeworkDetail'))
const Classes = lazyPage(() => import('./pages/TeacherClasses'))
const ClassDetail = lazyPage(() => import('./pages/TeacherClassDetail'))
const Seating = lazyPage(() => import('./pages/TeacherSeating'))
const Student = lazyPage(() => import('./pages/TeacherStudent'))
const Observations = lazyPage(() => import('./pages/TeacherObservations'))
const Requests = lazyPage(() => import('./pages/TeacherRequests'))
const Exams = lazyPage(() => import('./pages/TeacherExams'))
const Study = lazyPage(() => import('./pages/TeacherStudy'))
const Announcements = lazyPage(() => import('./pages/TeacherAnnouncements'))
const Profile = lazyPage(() => import('./pages/TeacherProfile'))
const Discipline = lazyPage(() => import('./pages/TeacherDiscipline'))
const NotFound = lazyPage(() => import('@/pages/NotFound'))

export default {
  id: 'teacher-portal',
  routes: [
    {
      path: 'ogretmen',
      element: page(<Shell />),
      children: [
        { index: true, element: page(<Home />) },
        { path: 'program', element: page(<Schedule />) },
        { path: 'yoklama', element: page(<Attendance />) },
        { path: 'yoklama/:id', element: page(<AttendanceTake />) },
        { path: 'odevler', element: page(<Homework />) },
        { path: 'odevler/:id', element: page(<HomeworkDetail />) },
        { path: 'siniflar', element: page(<Classes />) },
        { path: 'siniflar/:id', element: page(<ClassDetail />) },
        { path: 'siniflar/:id/oturma', element: page(<Seating />) },
        { path: 'ogrenci/:id', element: page(<Student />) },
        { path: 'gozlemler', element: page(<Observations />) },
        { path: 'olay-bildir', element: page(<Discipline />) },
        { path: 'talepler', element: page(<Requests />) },
        { path: 'sinavlar', element: page(<Exams />) },
        { path: 'etut', element: page(<Study />) },
        { path: 'duyurular', element: page(<Announcements />) },
        { path: 'profil', element: page(<Profile />) },
        { path: '*', element: page(<NotFound />) },
      ],
    },
  ],
} satisfies ModuleDef
