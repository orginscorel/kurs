import { useEffect, useMemo } from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { BookOpenCheck, CalendarDays, ClipboardCheck, Compass, GraduationCap, Home, Megaphone, MessageSquareHeart, Package, ShieldCheck, TrendingUp, UserRound, Users, UsersRound, Wallet } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/app/auth'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { usePortalContext, usePortalStudent, useSelectedStudentId, type Summary } from './api'
import { PortalLayout, type PortalNavGroup } from './PortalLayout'
import PasswordSetup from './PasswordSetup'

/** Menü grupları (masaüstü kenar çubuğu + mobil menü aynı listeyi kullanır). */
function buildGroups(unread: number): PortalNavGroup[] {
  return [
    {
      label: 'Günlük',
      items: [
        { to: '/portal', label: 'Özet', icon: Home, end: true },
        { to: '/portal/program', label: 'Program', icon: CalendarDays },
        { to: '/portal/yoklama', label: 'Yoklama', icon: ClipboardCheck },
      ],
    },
    {
      label: 'Akademik',
      items: [
        { to: '/portal/sinavlar', label: 'Sınavlar', icon: GraduationCap },
        { to: '/portal/gelisim', label: 'Gelişim', icon: TrendingUp },
        { to: '/portal/odevler', label: 'Ödevler', icon: BookOpenCheck },
        { to: '/portal/geri-bildirim', label: 'Geri bildirim', icon: MessageSquareHeart },
      ],
    },
    {
      label: 'Kurum',
      items: [
        { to: '/portal/ogretmenler', label: 'Öğretmenler', icon: UsersRound },
        { to: '/portal/duyurular', label: 'Duyurular', icon: Megaphone, badge: unread },
        { to: '/portal/rehberlik', label: 'Rehberlik', icon: Compass },
        { to: '/portal/disiplin', label: 'Disiplin', icon: ShieldCheck },
      ],
    },
    {
      label: 'Hesap',
      items: [
        { to: '/portal/paketlerim', label: 'Paketlerim', icon: Package },
        { to: '/portal/odemeler', label: 'Ödemeler', icon: Wallet },
        { to: '/portal/profil', label: 'Profilim', icon: UserRound },
      ],
    },
  ]
}
/** Mobil alt çubukta sabit duran sekmeler; kalanlar "Menü" altında. */
const TABS = ['/portal', '/portal/program', '/portal/odevler', '/portal/sinavlar']

export default function PortalShell() {
  const me = useAuth((s) => s.me)
  const imp = me?.impersonation ?? null

  if (me && me.user.user_type !== 'student' && me.user.user_type !== 'guardian') {
    // Personel doğrudan /portal'a gelirse: portal yalnız öğrenci/veli hesabıyla açılır
    return (
      <div className="grid min-h-dvh place-items-center bg-bg px-4">
        <EmptyState
          icon={<UserRound />}
          title="Öğrenci ve veli portalı"
          description="Bu alan öğrenci ve veli hesaplarına özeldir. Portalı görmek için öğrenci ya da veli profilindeki “… olarak giriş yap” düğmesini kullanın."
          action={<ButtonLink to="/ogrenciler" variant="primary">Öğrencilere git</ButtonLink>}
        />
      </div>
    )
  }

  // İlk giriş / sıfırlanmış şifre: kendi şifresini belirlemeden portal açılmaz (önizleme muaf)
  if (me?.user.must_change_password && !imp) return <PasswordSetup />

  return <PortalFrame />
}

function PortalFrame() {
  const me = useAuth((s) => s.me)
  const logout = useAuth((s) => s.logout)
  const load = useAuth((s) => s.load)
  const qc = useQueryClient()
  const navigate = useNavigate()
  const isGuardian = me?.user.user_type === 'guardian'
  const userId = me?.user.id ?? 0

  // Veli: seçili öğrenci tercihini (oturum başına) yükle — ilk istekten önce
  const store = usePortalStudent()
  useEffect(() => {
    if (isGuardian && userId) store.init(userId)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isGuardian, userId])
  const studentId = useSelectedStudentId()
  const ctx = usePortalContext(isGuardian)

  useEffect(() => {
    if (!isGuardian) return
    if (ctx.data && ctx.data.selected_student_id !== studentId) {
      // Sunucunun doğruladığı seçim esas alınır
      store.set(userId, ctx.data.selected_student_id)
    } else if (ctx.error instanceof ApiError) {
      if (ctx.error.code === 'password_change_required') void load()
      else if (ctx.error.status === 403 && studentId) store.set(userId, null) // bayat tercih (öğrenci artık listede değil)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isGuardian, ctx.data, ctx.error, studentId, userId])

  const signOut = async () => {
    await logout()
    usePortalStudent.getState().reset()
    qc.clear()
    navigate('/giris', { replace: true })
  }

  const children = ctx.data?.students ?? []
  const ready = !isGuardian || (!!ctx.data && studentId === ctx.data.selected_student_id)
  const noChildren = isGuardian && ctx.error instanceof ApiError && ctx.error.status === 403 && !studentId && ctx.error.code !== 'password_change_required'

  // Özet önbelleği (Özet sayfasıyla aynı anahtar): okunmamış duyuru rozeti + "bugün" bilgisi
  // (veli: seçim sunucuda doğrulanmadan istek atılmaz — bayat tercihte gereksiz 403 olmasın)
  const summary = useQuery({
    queryKey: ['portal', 'summary', studentId, {}],
    queryFn: () => api.get<Summary & { unread_announcements?: number }>('/portal/summary', { student_id: studentId ?? undefined }),
    staleTime: 60_000,
    enabled: ready && !noChildren,
  })
  const unread = ready ? (summary.data?.unread_announcements ?? 0) : 0
  const groups = useMemo(() => buildGroups(unread), [unread])
  const todayLessons = ready && summary.data ? summary.data.today.lessons.filter((l) => l.status !== 'cancelled').length : null
  const openHomework = ready ? (summary.data?.homework.open_count ?? 0) : 0
  const ownClass = summary.data?.student.class_groups[0]?.name

  const subtitle = isGuardian
    ? `Veli${children.length ? ` · ${children.length} öğrenci` : ''}`
    : ['Öğrenci', ownClass, summary.data?.student.student_no ? `No ${summary.data.student.student_no}` : null].filter(Boolean).join(' · ')

  return (
    <PortalLayout
      homeTo="/portal"
      portalLabel={isGuardian ? 'Veli portalı' : 'Öğrenci portalı'}
      groups={groups}
      tabs={TABS}
      user={{ name: me?.user.name ?? '', avatar: isGuardian ? me?.user.avatar_url : (summary.data?.student.photo_url ?? me?.user.avatar_url), subtitle, profileTo: '/portal/profil' }}
      impersonationSuffix={isGuardian ? '(veli)' : undefined}
      switcher={isGuardian && children.length > 0 ? {
        students: children.map((c) => ({
          id: c.id,
          name: c.full_name,
          firstName: c.first_name,
          photo: c.photo_url,
          subtitle: [c.class_groups.join(', '), c.student_no ? `No ${c.student_no}` : null].filter(Boolean).join(' · ') || c.status_label,
        })),
        current: studentId,
        onPick: (id) => {
          if (id !== studentId) store.set(userId, id)
        },
      } : null}
      todayNote={todayLessons === null ? undefined : todayLessons ? `${todayLessons} ders` : 'ders yok'}
      actions={
        <>
          <ButtonLink to="/portal/program" size="sm" variant="outline" icon={<CalendarDays className="size-4" />}>Program</ButtonLink>
          <ButtonLink to="/portal/odevler" size="sm" variant="primary" icon={<BookOpenCheck className="size-4" />}>
            Ödevler{openHomework ? ` (${openHomework})` : ''}
          </ButtonLink>
        </>
      }
      onSignOut={signOut}
    >
      {noChildren ? (
        <EmptyState
          icon={<Users />}
          title="Görüntülenecek öğrenci yok"
          description={ctx.error instanceof ApiError ? ctx.error.message : 'Hesabınıza bağlı öğrenci bulunmuyor. Kurumla iletişime geçin.'}
        />
      ) : !ready ? (
        <div className="flex flex-col gap-4"><Skeleton className="h-16 w-2/3" /><Skeleton className="h-48" /></div>
      ) : (
        <Outlet key={studentId ?? 'self'} />
      )}
    </PortalLayout>
  )
}
