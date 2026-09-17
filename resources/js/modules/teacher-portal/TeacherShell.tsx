import { useMemo } from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { BookOpenCheck, CalendarDays, ClipboardCheck, Gavel, GraduationCap, Home, Inbox, LayoutDashboard, Megaphone, NotebookPen, Timer, UserRound, Users } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/app/auth'
import { EmptyState } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import PasswordSetup from '@/modules/portal/PasswordSetup'
import { PortalLayout, type PortalNavGroup } from '@/modules/portal/PortalLayout'
import { TP, type Summary } from './api'

function buildGroups(b: { requests: number; announcements: number }): PortalNavGroup[] {
  return [
    {
      label: 'Günlük',
      items: [
        { to: '/ogretmen', label: 'Özet', icon: Home, end: true },
        { to: '/ogretmen/program', label: 'Program', icon: CalendarDays },
        { to: '/ogretmen/yoklama', label: 'Yoklama', icon: ClipboardCheck },
        { to: '/ogretmen/etut', label: 'Etüt', icon: Timer },
      ],
    },
    {
      label: 'Sınıflar',
      items: [
        { to: '/ogretmen/siniflar', label: 'Sınıflarım', icon: Users, match: ['/ogretmen/ogrenci'] },
        { to: '/ogretmen/odevler', label: 'Ödevler', icon: BookOpenCheck },
        { to: '/ogretmen/sinavlar', label: 'Sınavlar', icon: GraduationCap },
        { to: '/ogretmen/gozlemler', label: 'Gözlemler', icon: NotebookPen },
        { to: '/ogretmen/olay-bildir', label: 'Olay bildir', icon: Gavel },
      ],
    },
    {
      label: 'İletişim',
      items: [
        { to: '/ogretmen/talepler', label: 'Veli talepleri', icon: Inbox, badge: b.requests },
        { to: '/ogretmen/duyurular', label: 'Duyurular', icon: Megaphone, badge: b.announcements },
      ],
    },
    {
      label: 'Hesap',
      items: [{ to: '/ogretmen/profil', label: 'Profilim', icon: UserRound }],
    },
  ]
}
/** Mobil alt çubukta sabit sekmeler; kalanlar "Menü" altında. */
const TABS = ['/ogretmen', '/ogretmen/program', '/ogretmen/yoklama', '/ogretmen/odevler']

export default function TeacherShell() {
  const me = useAuth((s) => s.me)
  const imp = me?.impersonation ?? null

  if (me && !me.user.teacher_id) {
    return (
      <div className="grid min-h-dvh place-items-center bg-bg px-4">
        <EmptyState
          icon={<UserRound />}
          title="Öğretmen portalı"
          description="Bu alan öğretmen hesaplarına özeldir. Bir öğretmenin portalını görmek için öğretmen profilindeki “Öğretmen olarak giriş yap” düğmesini kullanın."
          action={<ButtonLink to="/ogretmenler" variant="primary">Öğretmenlere git</ButtonLink>}
        />
      </div>
    )
  }

  if (me?.user.must_change_password && !imp) return <PasswordSetup />

  return <TeacherFrame />
}

function TeacherFrame() {
  const me = useAuth((s) => s.me)
  const logout = useAuth((s) => s.logout)
  const qc = useQueryClient()
  const navigate = useNavigate()
  const imp = me?.impersonation ?? null

  // Rozetler + "bugün": özet önbelleğini paylaşır (Özet sayfasıyla aynı anahtar)
  const summary = useQuery({
    queryKey: ['teacher-portal', 'summary', {}],
    queryFn: () => api.get<Summary>(`${TP}/summary`),
    staleTime: 30_000,
    retry: false,
  })
  const requests = summary.data?.counts.open_requests ?? 0
  const announcements = summary.data?.counts.unread_announcements ?? 0
  const groups = useMemo(() => buildGroups({ requests, announcements }), [requests, announcements])
  const forbidden = summary.error instanceof ApiError && summary.error.status === 403

  const signOut = async () => {
    await logout()
    qc.clear()
    navigate('/giris', { replace: true })
  }

  const d = summary.data
  const todayLessons = d ? d.today.lessons.length : null
  const pending = d?.pending_attendance ?? []
  const toGrade = d?.counts.to_grade ?? 0
  const specialty = d?.teacher.specialty || d?.teacher.title || ''

  return (
    <PortalLayout
      homeTo="/ogretmen"
      portalLabel="Öğretmen portalı"
      groups={groups}
      tabs={TABS}
      user={{ name: me?.user.name ?? '', avatar: me?.user.avatar_url ?? d?.teacher.avatar_url, subtitle: ['Öğretmen', specialty].filter(Boolean).join(' · '), profileTo: '/ogretmen/profil' }}
      impersonationSuffix="(öğretmen)"
      accountExtra={me?.portal !== 'teacher' && !imp ? [{ to: '/', label: 'Yönetim paneli', icon: LayoutDashboard }] : undefined}
      todayNote={todayLessons === null ? undefined : todayLessons ? `${todayLessons} ders` : 'ders yok'}
      actions={
        <>
          {toGrade > 0 && (
            <ButtonLink to="/ogretmen/odevler" size="sm" variant="outline" icon={<BookOpenCheck className="size-4" />}>
              Değerlendir ({toGrade})
            </ButtonLink>
          )}
          <ButtonLink
            to={pending.length === 1 ? `/ogretmen/yoklama/${pending[0]!.id}` : '/ogretmen/yoklama'}
            size="sm"
            variant="primary"
            icon={<ClipboardCheck className="size-4" />}
          >
            {pending.length ? `Yoklama al (${pending.length})` : 'Yoklama'}
          </ButtonLink>
        </>
      }
      onSignOut={signOut}
    >
      {forbidden ? (
        <EmptyState icon={<UserRound />} title="Öğretmen portalı açılamadı" description={(summary.error as ApiError).message} />
      ) : (
        <Outlet />
      )}
    </PortalLayout>
  )
}
