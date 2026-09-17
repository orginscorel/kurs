import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

/**
 * ÖĞRENCİ PORTALI — öğrenci hesabıyla (ya da personel önizlemesiyle) açılır.
 * Yönetim kabuğu yerine kendi sade kabuğunu kullanır (router ShellSwitch /portal altını sarmaz).
 * Menüye (nav) öğe eklemez: personel menüsünde görünmez.
 */
const PortalShell = lazyPage(() => import('./PortalShell'))
const Home = lazyPage(() => import('./pages/PortalHome'))
const Schedule = lazyPage(() => import('./pages/PortalSchedule'))
const Attendance = lazyPage(() => import('./pages/PortalAttendance'))
const Exams = lazyPage(() => import('./pages/PortalExams'))
const Homework = lazyPage(() => import('./pages/PortalHomework'))
const HomeworkDetail = lazyPage(() => import('./pages/PortalHomeworkDetail'))
const Progress = lazyPage(() => import('./pages/PortalProgress'))
const Feedback = lazyPage(() => import('./pages/PortalFeedback'))
const Teachers = lazyPage(() => import('./pages/PortalTeachers'))
const Finance = lazyPage(() => import('./pages/PortalFinance'))
const Guidance = lazyPage(() => import('./pages/PortalGuidance'))
const Announcements = lazyPage(() => import('./pages/PortalAnnouncements'))
const Profile = lazyPage(() => import('./pages/PortalProfile'))
const Discipline = lazyPage(() => import('./pages/PortalDiscipline'))

export default {
  id: 'portal',
  routes: [
    {
      path: 'portal',
      element: page(<PortalShell />),
      children: [
        { index: true, element: page(<Home />) },
        { path: 'program', element: page(<Schedule />) },
        { path: 'yoklama', element: page(<Attendance />) },
        { path: 'sinavlar', element: page(<Exams />) },
        { path: 'odevler', element: page(<Homework />) },
        { path: 'odevler/:id', element: page(<HomeworkDetail />) },
        { path: 'gelisim', element: page(<Progress />) },
        { path: 'geri-bildirim', element: page(<Feedback />) },
        { path: 'ogretmenler', element: page(<Teachers />) },
        { path: 'odemeler', element: page(<Finance />) },
        { path: 'rehberlik', element: page(<Guidance />) },
        { path: 'disiplin', element: page(<Discipline />) },
        { path: 'duyurular', element: page(<Announcements />) },
        { path: 'profil', element: page(<Profile />) },
      ],
    },
  ],
} satisfies ModuleDef
