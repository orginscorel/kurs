import { Suspense, lazy, useEffect } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { CircleDollarSign, ClipboardCheck, Gavel, PhoneCall, UserPlus } from 'lucide-react'
import { date } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useAuth, useCan } from '@/app/auth'
import { Skeleton, StatusDot } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { ErrorBoundary } from '@/components/layout/ErrorBoundary'
import { OverdueBanner } from './OverdueBanner'

/** Ağır bölümler ayrı parça: Kurum özeti (grafikler) ancak sekmesi açılınca indirilir. */
const Today = lazy(() => import('./Today'))
const DashboardSummary = lazy(() => import('./Dashboard'))

type Tab = 'bugun' | 'ozet'
const STORE_KEY = 'kurs.home.sekme'

/**
 * Kontrol Merkezi: üstte başlık + hızlı işlemler + gecikme uyarısı, altında iki sekme.
 * "Bugün" günlük operasyon (dersler, sınıflar, gelmeyenler), "Kurum özeti" grafikler ve akış.
 * Sekme adreste taşınır (?sekme=ozet) ve son seçim hatırlanır; eski /bugun adresi buraya gelir.
 */
export default function Home() {
  const me = useAuth((s) => s.me)
  const can = useCan()
  const navigate = useNavigate()
  const isTeacher = me?.user.user_type === 'teacher'
  const [params, setParams] = useSearchParams()

  const showToday = can('operations.view')
  const showSummary = can('dashboard.view')

  // Adresteki sekme geçerli değilse yetkiye göre ilkine düşülür
  const asked = params.get('sekme') === 'ozet' ? 'ozet' : params.get('sekme') === 'bugun' ? 'bugun' : null
  const fallback: Tab = showToday ? 'bugun' : 'ozet'
  const tab: Tab = asked === 'ozet' && showSummary ? 'ozet' : asked === 'bugun' && showToday ? 'bugun' : fallback

  // Öğretmen kendi paneline; kurulumu tamamlanmamış kurumda süper yönetici kurulum sihirbazına
  useEffect(() => {
    if (isTeacher) navigate('/panelim', { replace: true })
    else if (me && me.is_super_admin && !me.institution.onboarding_completed) navigate('/kurulum', { replace: true })
  }, [me, isTeacher, navigate])

  // Adreste sekme yoksa en son kullanılan sekme açılır (paylaşılan bağlantı her zaman kendi sekmesini açar)
  useEffect(() => {
    if (asked) return
    let saved: string | null = null
    try {
      saved = localStorage.getItem(STORE_KEY)
    } catch {
      /* depolama kapalı: varsayılan sekme */
    }
    if (saved === 'ozet' && showSummary) setParams({ sekme: 'ozet' }, { replace: true })
  }, [asked, showSummary, setParams])

  const select = (next: Tab) => {
    try {
      localStorage.setItem(STORE_KEY, next)
    } catch {
      /* yalnız bu oturumda geçerli */
    }
    if (next === 'bugun') {
      const rest = new URLSearchParams(params)
      rest.delete('sekme')
      setParams(rest, { replace: true })
    } else {
      setParams({ sekme: next }, { replace: true })
    }
  }

  const quick = [
    { show: can('payments.create'), to: '/finans/tahsilat', label: 'Tahsilat al', icon: <CircleDollarSign /> },
    { show: can('attendance.take') || can('attendance.view'), to: '/yoklama', label: 'Yoklama', icon: <ClipboardCheck /> },
    { show: can('students.create'), to: '/ogrenciler?yeni=1', label: 'Öğrenci ekle', icon: <UserPlus /> },
    { show: can('crm.view'), to: '/on-kayit?yeni=1', label: 'Ön kayıt', icon: <PhoneCall /> },
    { show: can('discipline.create'), to: '/disiplin/olaylar?yeni=1', label: 'Olay kaydet', icon: <Gavel /> },
  ].filter((q) => q.show)

  const tabs: { value: Tab; label: string; hint: string; show: boolean }[] = [
    { value: 'bugun' as const, label: 'Bugün', hint: 'Dersler, sınıflar, gelmeyenler', show: showToday },
    { value: 'ozet' as const, label: 'Kurum özeti', hint: 'Grafikler, doluluk, canlı akış', show: showSummary },
  ].filter((t) => t.show)

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-[22px] font-semibold tracking-[-0.02em] leading-tight">Kontrol Merkezi</h1>
          <p className="mt-1 inline-flex items-center gap-2 text-[13px] text-ink-2">
            {date(new Date(), 'day')} · <StatusDot tone="success" pulse /> canlı
          </p>
        </div>
        {quick.length > 0 && (
          <nav aria-label="Hızlı işlemler" className="flex flex-wrap gap-2">
            {quick.map((q) => (
              <ButtonLink key={q.to} to={q.to} size="md" icon={<span className="[&>svg]:size-4">{q.icon}</span>}>{q.label}</ButtonLink>
            ))}
          </nav>
        )}
      </header>

      {can('finance.view') && <ErrorBoundary name="home.overdue"><OverdueBanner /></ErrorBoundary>}

      {tabs.length > 1 && (
        <div role="tablist" aria-label="Kontrol Merkezi bölümleri" className="flex gap-1 border-b border-line">
          {tabs.map((t) => (
            <button
              key={t.value}
              role="tab"
              type="button"
              aria-selected={tab === t.value}
              onClick={() => select(t.value)}
              title={t.hint}
              className={cn(
                'relative h-11 min-w-[112px] px-4 text-[14px] font-medium transition-colors',
                tab === t.value ? 'text-ink' : 'text-ink-3 hover:text-ink-2',
              )}
            >
              {t.label}
              {tab === t.value && <span className="absolute inset-x-2 -bottom-px h-[2px] rounded-full bg-ink" />}
            </button>
          ))}
        </div>
      )}

      {tab === 'bugun' && showToday && (
        <ErrorBoundary name="home.today">
          <Suspense fallback={<Skeleton className="h-[420px] rounded-[var(--radius-lg)]" />}><Today /></Suspense>
        </ErrorBoundary>
      )}

      {tab === 'ozet' && showSummary && (
        <ErrorBoundary name="home.summary">
          <Suspense fallback={<Skeleton className="h-[420px] rounded-[var(--radius-lg)]" />}><DashboardSummary /></Suspense>
        </ErrorBoundary>
      )}

      {!showToday && !showSummary && (
        <p className="text-[13px] text-ink-3">Bu ekranı görüntüleme yetkiniz yok. Soldaki menüden yetkili olduğunuz bölümlere geçebilirsiniz.</p>
      )}
    </div>
  )
}
