import { useMemo, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlarmClock, ArrowRight, BookOpen, CheckCircle2, ChevronDown, LogIn, Phone, Presentation, UserX, Users, Wallet } from 'lucide-react'
import { api } from '@/lib/api'
import { date, money, num, phone, time } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel, Stat, Tabs } from '@/components/ui/layout'
import { Avatar, Badge, EmptyState, ProgressBar, Skeleton, StatusDot, type Tone } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Mascot } from '@/components/app/Mascot'

type Session = {
  id: number; starts_at: string; ends_at: string; status: string; phase: 'upcoming' | 'in_progress' | 'done' | 'cancelled'
  subject: string; class_group: string; class_group_id: number; classroom: string; teacher: string; roster: number
  present: number; late: number; absent: number; excused: number; attendance_taken_at: string | null
}

type ClassNow = {
  id: number; name: string; program: string | null; roster: number; arrived: number; inside: number
  state: 'in_lesson' | 'next' | 'done' | 'no_lessons'; lessons_today: number; lessons_done: number
  session: null | { id: number; subject: string; teacher: string; classroom: string; starts_at: string; ends_at: string; present: number; late: number; absent: number }
}

type TodayData = {
  date: string
  summary: Record<string, number>
  finance: null | { expected: string; expected_open: string; collected: string; payments_count: number; due_today: { id: number; student_id: number; full_name: string; amount: string; paid_amount: string; sequence: number }[] }
  sessions: Session[]
  not_arrived: { id: number; full_name: string; student_no: string; class_group: string | null; guardian: string | null; guardian_phone: string | null; first_lesson_at: string | null }[]
  late: { id: number; student_id: number; full_name: string; late_minutes: number; subject: string; starts_at: string }[]
  classes: ClassNow[]
}

type NextLesson = { id: number; starts_at: string; subject: string; class_group: string; classroom: string; teacher: string }

const phaseBadge: Record<Session['phase'], ReactNode> = {
  upcoming: <Badge>Bekliyor</Badge>,
  in_progress: <Badge tone="success" dot>Derste</Badge>,
  done: <Badge tone="neutral">Bitti</Badge>,
  cancelled: <Badge tone="danger">İptal</Badge>,
}

/**
 * "Bugün" sekmesi — günün operasyonu tek ekranda:
 * üstte karar verdiren ölçüler (ders saati başlamadan sönük tek satır), altında
 * zaman şeridi (ders akışı + yarının ilk dersleri) ve sınıfların anlık durumu,
 * en altta gelmeyen / geç kalan öğrenciler. Başlık ve hızlı işlemler Home'dadır.
 */
export default function Today() {
  const [classFilter, setClassFilter] = useState<string>('all')
  const { data, isLoading } = useQuery({
    queryKey: ['operations', 'today'],
    queryFn: () => api.get<TodayData>('/operations/today'),
    refetchInterval: 30_000,
  })

  const sessions = data?.sessions ?? []
  const active = sessions.filter((s) => s.phase !== 'cancelled')
  const noMoreToday = !isLoading && active.length > 0 && active.every((s) => s.phase === 'done')

  return (
    <div className="flex flex-col gap-4">
      <DayStrip data={data} loading={isLoading} />

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
        <LessonTimeline
          className="xl:col-span-5 xl:order-2"
          sessions={sessions}
          loading={isLoading}
          classFilter={classFilter}
          onClearFilter={() => setClassFilter('all')}
          filterName={data?.classes.find((c) => String(c.id) === classFilter)?.name}
          noMoreToday={noMoreToday}
        />
        <ClassList
          className="xl:col-span-7 xl:order-1"
          classes={data?.classes}
          loading={isLoading}
          selected={classFilter}
          onSelect={(id) => setClassFilter((v) => (v === id ? 'all' : id))}
        />
      </div>

      <BottomRow data={data} loading={isLoading} />
    </div>
  )
}

/** Alt satır: gelmeyen/geç kalanlar ile (finans yetkisi varsa) bugün vadesi dolan taksitler yan yana. */
function BottomRow({ data, loading }: { data?: TodayData; loading: boolean }) {
  const can = useCan()
  const due = can('finance.view') ? (data?.finance?.due_today ?? []) : []
  if (!due.length) return <AbsencePanel data={data} loading={loading} wide />
  return (
    <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
      <div className="min-w-0 xl:col-span-7"><AbsencePanel data={data} loading={loading} /></div>
      <DueTodayPanel rows={due} className="xl:col-span-5" />
    </div>
  )
}

/**
 * Bugün tahsil edilecekler: bugün vadesi dolan açık taksitler (kalan tutara göre sıralı).
 * Satır, tahsilat ekranını o öğrenci ve o taksit seçili açar. Liste boşsa kutu hiç çıkmaz;
 * "bugün beklenen / açık" özeti zaten ölçü şeridinin sönük satırında duruyor.
 */
function DueTodayPanel({ rows, className }: { rows: NonNullable<TodayData['finance']>['due_today']; className?: string }) {
  const can = useCan()
  const [expanded, setExpanded] = useState(false)
  const visible = expanded ? rows : rows.slice(0, 8)
  const total = rows.reduce((sum, r) => sum + (Number(r.amount) - Number(r.paid_amount)), 0)

  return (
    <Panel
      className={cn('min-w-0', className)}
      title="Bugün tahsil edilecekler"
      description={`${num(rows.length)} taksit · ${money(total, { short: true })} açık`}
      actions={<Link to="/finans/alacaklar?status=pending" className="inline-flex min-h-[40px] items-center gap-1 text-[12.5px] text-ink-3 hover:text-ink">Alacaklar <ArrowRight className="size-3.5" /></Link>}
      flush
    >
      <ul className="px-1.5 pb-1">
        {visible.map((r) => {
          const remaining = Number(r.amount) - Number(r.paid_amount)
          const to = can('payments.create') ? `/finans/tahsilat?ogrenci=${r.student_id}&taksit=${r.id}` : `/ogrenciler/${r.student_id}`
          return (
            <li key={r.id}>
              <Link to={to} className="flex min-h-[44px] items-center gap-3 rounded-[var(--radius-sm)] px-2 py-2 transition-colors hover:bg-surface-2">
                <Avatar name={r.full_name} size={30} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13px] font-medium">{r.full_name}</span>
                  <span className="block truncate text-[12px] text-ink-3">{num(r.sequence)}. taksit{Number(r.paid_amount) > 0 && ` · ${money(r.paid_amount, { short: true })} ödendi`}</span>
                </span>
                <span className="shrink-0 text-[13px] font-semibold tabular">{money(remaining, { short: true })}</span>
              </Link>
            </li>
          )
        })}
      </ul>
      {rows.length > 8 && (
        <div className="border-t border-line px-2 py-1.5">
          <Button size="sm" variant="ghost" className="w-full" onClick={() => setExpanded((v) => !v)}>
            {expanded ? 'Kısalt' : `Tümünü göster (${rows.length - visible.length} taksit daha)`}
          </Button>
        </div>
      )}
    </Panel>
  )
}

/* ---------------------------------------------------------------- ölçüler */

/**
 * Gün ölçüleri: ders saati başlamadan yalnız planı gösterir (ders, beklenen öğrenci, ilk ders);
 * gelen/geç/gelmeyen gibi gün içinde anlam kazanan sıfırlar tek sönük satırda toplanır.
 * Ders başlayınca bunlar kutuya çıkar, boşalan ölçüler alt satıra iner.
 */
function DayStrip({ data, loading }: { data?: TodayData; loading: boolean }) {
  const can = useCan()
  const fin = can('finance.view') && !!data?.finance
  const s = data?.summary ?? {}
  const sessions = data?.sessions ?? []
  const active = sessions.filter((x) => x.phase !== 'cancelled')
  const doneCount = active.filter((x) => x.phase === 'done').length
  const firstUpcoming = active.find((x) => x.phase === 'upcoming')
  const started = (s.lessons_in_progress ?? 0) > 0 || (s.arrived ?? 0) > 0 || doneCount > 0
  const expected = s.expected ?? 0
  const arrivedPct = expected ? Math.round(((s.arrived ?? 0) / expected) * 100) : 0
  const f = data?.finance
  const collectPct = f && Number(f.expected) > 0 ? Math.round((Number(f.collected) / Number(f.expected)) * 100) : 0

  if (loading) {
    return (
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-5">
        {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-[86px] rounded-[var(--radius-lg)]" />)}
      </div>
    )
  }

  const tiles: { key: string; label: string; value: ReactNode; sub?: ReactNode; tone?: Tone; icon: ReactNode; to?: string }[] = []
  const quiet: { key: string; text: string; to?: string; tone?: 'warning' }[] = []

  tiles.push({
    key: 'ders',
    label: 'Bugünkü ders',
    value: num(s.lessons),
    sub: started
      ? `${num(s.lessons_in_progress)} derste · ${num(doneCount)} bitti`
      : `${num(s.teachers)} öğretmen · ${num(s.classrooms)} derslik`,
    icon: <BookOpen />,
    to: '/takvim?gorunum=gun',
  })
  tiles.push({ key: 'beklenen', label: 'Beklenen öğrenci', value: num(expected), sub: `${num(data?.classes.filter((c) => c.lessons_today > 0).length)} sınıf`, icon: <Users />, to: '/ogrenciler' })

  if (started) {
    tiles.push({
      key: 'geldi', label: 'Geldi', value: num(s.arrived), icon: <LogIn />,
      sub: expected ? `%${arrivedPct} · ${num(s.inside)} içeride` : `${num(s.inside)} içeride`,
      to: '/yoklama/canli',
    })
    // İlk ekranda en çok 5 sayı kalsın: geç kalan, gelmeyen kutusunun alt satırına iner
    if ((s.not_arrived ?? 0) > 0) {
      tiles.push({
        key: 'gelmedi', label: 'Gelmedi', value: num(s.not_arrived), tone: 'danger', icon: <UserX />,
        sub: (s.late ?? 0) > 0 ? `${num(s.late)} geç kalan` : 'geç kalan yok', to: '/yoklama/devamsizlik',
      })
    } else if ((s.late ?? 0) > 0) {
      tiles.push({ key: 'gec', label: 'Geç kaldı', value: num(s.late), tone: 'warning', icon: <AlarmClock />, sub: 'gelmeyen yok' })
    } else {
      quiet.push({ key: 'gelmedi', text: 'Gelmeyen ve geç kalan yok' })
    }
    quiet.push({ key: 'kadro', text: `${num(s.teachers)} öğretmen · ${num(s.classrooms)} derslik` })
  } else {
    if (firstUpcoming) {
      tiles.push({
        key: 'ilk', label: 'İlk ders', value: time(firstUpcoming.starts_at), icon: <Presentation />,
        sub: `${firstUpcoming.class_group} · ${firstUpcoming.subject}`, to: '/ders-programi',
      })
    }
    quiet.push({ key: 'yoklama', text: `Ders saati başlamadı · Geldi ${num(s.arrived)} · Geç kalan ${num(s.late)} · Gelmeyen ${num(s.not_arrived)}` })
  }

  if ((s.lessons_cancelled ?? 0) > 0) quiet.push({ key: 'iptal', text: `${num(s.lessons_cancelled)} ders iptal` })
  if ((s.attendance_pending ?? 0) > 0) quiet.push({ key: 'bekleyen', text: `${num(s.attendance_pending)} dersin yoklaması girilmedi`, to: '/yoklama', tone: 'warning' })

  if (fin && f) {
    const collected = Number(f.collected)
    const expectedMoney = Number(f.expected)
    if (collected > 0) {
      tiles.push({
        key: 'tahsilat', label: 'Tahsil edilen', value: money(f.collected, { short: true }), icon: <Wallet />,
        sub: expectedMoney > 0 ? `${f.payments_count} tahsilat · beklenenin %${collectPct}` : `${f.payments_count} tahsilat`,
        to: '/finans/tahsilatlar',
      })
      if (expectedMoney > 0) quiet.push({ key: 'beklenen-tahsilat', text: `Bugün beklenen ${money(f.expected, { short: true })} · ${money(f.expected_open, { short: true })} açık`, to: '/finans/alacaklar?status=pending' })
    } else if (expectedMoney > 0) {
      tiles.push({
        key: 'beklenen-tahsilat', label: 'Bugün beklenen tahsilat', value: money(f.expected, { short: true }), icon: <Wallet />,
        sub: `${money(f.expected_open, { short: true })} henüz açık`, to: '/finans/alacaklar?status=pending',
      })
      quiet.push({ key: 'tahsilat', text: 'Bugün tahsilat yok', to: '/finans/tahsilatlar' })
    } else {
      quiet.push({ key: 'tahsilat0', text: 'Bugün vadesi dolan taksit ve tahsilat yok', to: '/finans/alacaklar?status=pending' })
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <div className={cn('grid grid-cols-2 gap-3', tiles.length >= 5 ? 'md:grid-cols-3 xl:grid-cols-5' : tiles.length === 3 ? 'md:grid-cols-3' : 'md:grid-cols-4')}>
        {tiles.map((t) => (
          <Stat key={t.key} label={t.label} value={t.value} sub={t.sub} tone={t.tone} icon={t.icon} to={t.to} />
        ))}
      </div>
      {quiet.length > 0 && (
        <div className="flex flex-wrap items-center gap-x-1.5 gap-y-1 rounded-[var(--radius-sm)] bg-surface-2/70 px-3 py-2 text-[12.5px] text-ink-3">
          {quiet.map((q, i) => (
            <span key={q.key} className="inline-flex items-center gap-1.5">
              {i > 0 && <span className="text-line-strong">·</span>}
              {q.to ? (
                <Link to={q.to} className={cn('inline-flex min-h-[40px] items-center hover:text-ink hover:underline underline-offset-2 sm:min-h-[26px]', q.tone === 'warning' && 'text-warning')}>{q.text}</Link>
              ) : (
                <span className={cn(q.tone === 'warning' && 'text-warning')}>{q.text}</span>
              )}
            </span>
          ))}
        </div>
      )}
    </div>
  )
}

/* ------------------------------------------------------------ zaman şeridi */

/**
 * Ders akışı: günün tüm dersleri tek zaman şeridinde. Şu an işlenen ders vurgulu,
 * liste o anın etrafından başlar; günün dersleri bittiyse yarının ilk dersleri eklenir
 * (ayrı "Sıradaki dersler" kutusu yerine). Sınıf seçiliyse yalnız o sınıfın dersleri.
 */
function LessonTimeline({
  sessions, loading, classFilter, onClearFilter, filterName, noMoreToday, className,
}: {
  sessions: Session[]; loading: boolean; classFilter: string; onClearFilter: () => void; filterName?: string; noMoreToday: boolean; className?: string
}) {
  const can = useCan()
  const [expanded, setExpanded] = useState(false)
  const rows = useMemo(
    () => sessions.filter((r) => classFilter === 'all' || String(r.class_group_id) === classFilter),
    [sessions, classFilter],
  )

  // Günün dersleri bittiyse yarının ilk dersleri: pano verisi zaten önbellekte, ek yük yok
  const tomorrow = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api.get<{ next_lessons: NextLesson[] }>('/dashboard'),
    enabled: can('dashboard.view') && noMoreToday,
    staleTime: 60_000,
    select: (d) => (d.next_lessons ?? []).filter((l) => date(l.starts_at.replace(' ', 'T')) !== date(new Date())).slice(0, 3),
  })

  const firstPending = rows.findIndex((r) => r.phase !== 'done')
  const start = firstPending <= 0 ? 0 : firstPending - 1
  const visible = expanded ? rows : rows.slice(start, start + 8)
  const rest = rows.length - visible.length

  return (
    <Panel
      className={cn('min-w-0', className)}
      title="Ders akışı"
      description={filterName ? `${filterName} · ${rows.length} ders` : `Bugün ${rows.length} ders`}
      actions={
        filterName ? (
          <Button size="sm" variant="ghost" onClick={onClearFilter}>Filtreyi kaldır</Button>
        ) : (
          <Link to="/takvim?gorunum=ajanda" className="inline-flex min-h-[40px] items-center gap-1 text-[12.5px] text-ink-3 hover:text-ink">Takvim <ArrowRight className="size-3.5" /></Link>
        )
      }
      flush
    >
      {loading ? (
        <div className="p-4"><Skeleton className="h-56" /></div>
      ) : !rows.length ? (
        <div className="flex flex-col items-center gap-2 px-4 py-7 text-center">
          <Mascot size={64} state="empty" />
          <p className="text-[13.5px] font-semibold text-ink">{filterName ? `${filterName} sınıfının bugün dersi yok` : 'Bugün planlanmış ders yok'}</p>
          {can('schedule.view') && <ButtonLink size="sm" to="/ders-programi" className="mt-1">Ders programı</ButtonLink>}
        </div>
      ) : (
        <>
          <ul className="px-1.5 pb-1">
            {visible.map((row) => (
              <li key={row.id}>
                <div className={cn('flex items-center gap-2.5 rounded-[var(--radius-sm)] px-2 py-2 min-h-[44px]', row.phase === 'in_progress' && 'bg-success-soft/50')}>
                  <span className="w-[42px] shrink-0 text-[12.5px] font-semibold tabular leading-tight">
                    {time(row.starts_at)}
                    <span className="block text-[11px] font-normal text-ink-3">{time(row.ends_at)}</span>
                  </span>
                  <span className={cn('size-1.5 shrink-0 rounded-full', row.phase === 'in_progress' ? 'bg-success animate-pulse-dot' : row.phase === 'done' ? 'bg-line-strong' : 'bg-primary/50')} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13px] font-medium">{row.class_group} <span className="font-normal text-ink-2">· {row.subject}</span></span>
                    <span className="block truncate text-[12px] text-ink-3">{row.teacher} · {row.classroom}</span>
                  </span>
                  <span className="hidden shrink-0 items-center gap-2 text-[12px] tabular sm:flex">
                    {row.present + row.late + row.absent + row.excused > 0 ? (
                      <>
                        <span className="text-success">{row.present} var</span>
                        {row.late > 0 && <span className="text-warning">{row.late} geç</span>}
                        {row.absent > 0 && <span className="text-danger">{row.absent} yok</span>}
                      </>
                    ) : row.phase === 'done' ? (
                      <span className="text-ink-3">yoklama yok</span>
                    ) : null}
                  </span>
                  <span className="shrink-0">{phaseBadge[row.phase]}</span>
                </div>
              </li>
            ))}
          </ul>

          {tomorrow.data && tomorrow.data.length > 0 && (
            <ul className="border-t border-line px-3.5 py-2">
              {tomorrow.data.map((l) => (
                <li key={l.id} className="flex items-center gap-2.5 py-1.5 text-[12.5px] text-ink-3 min-h-[28px]">
                  <span className="w-[42px] shrink-0 tabular">{time(l.starts_at.replace(' ', 'T'))}</span>
                  <Badge tone="neutral">yarın</Badge>
                  <span className="min-w-0 flex-1 truncate">{l.class_group} · {l.subject} · {l.teacher}</span>
                </li>
              ))}
            </ul>
          )}

          {rest > 0 && (
            <div className="border-t border-line px-2 py-1.5">
              <Button size="sm" variant="ghost" className="w-full" onClick={() => setExpanded(true)}>Günün tamamını göster ({rest} ders daha)</Button>
            </div>
          )}
          {expanded && rows.length > 8 && (
            <div className="border-t border-line px-2 py-1.5">
              <Button size="sm" variant="ghost" className="w-full" onClick={() => setExpanded(false)}>Kısalt</Button>
            </div>
          )}
        </>
      )}
    </Panel>
  )
}

/* ---------------------------------------------------------------- sınıflar */

/**
 * Sınıflar: yalnız bugün dersi olan sınıflar tek satır hâlinde — ders, saat, öğretmen,
 * derslik ve doluluk. Derste olanlar üstte. Bugün dersi olmayanlar tek satırda toplanır,
 * tıklanınca açılır (bilgi kaybolmaz).
 */
function ClassList({
  classes, loading, selected, onSelect, className,
}: {
  classes?: ClassNow[]; loading: boolean; selected: string; onSelect: (id: string) => void; className?: string
}) {
  const can = useCan()
  const [expanded, setExpanded] = useState(false)
  const [showIdle, setShowIdle] = useState(false)
  const all = classes ?? []
  const rows = all.filter((c) => c.lessons_today > 0)
  const idle = all.filter((c) => c.lessons_today === 0)
  const visible = expanded ? rows : rows.slice(0, 10)
  const inLesson = rows.filter((c) => c.state === 'in_lesson').length
  const nextStart = rows.filter((c) => c.state === 'next').map((c) => c.session?.starts_at).filter(Boolean).sort()[0]

  const idleToggle = idle.length > 0 && (
    <>
      <button
        type="button"
        onClick={() => setShowIdle((v) => !v)}
        aria-expanded={showIdle}
        className="flex min-h-[40px] w-full items-center gap-2 border-t border-line px-3.5 text-left text-[12.5px] text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink"
      >
        <ChevronDown className={cn('size-3.5 shrink-0 transition-transform', showIdle && 'rotate-180')} />
        {showIdle ? `${num(idle.length)} sınıfın bugün dersi yok — gizle` : `+${num(idle.length)} sınıfın bugün dersi yok`}
      </button>
      {showIdle && (
        <ul className="px-1.5 pb-1">
          {idle.map((c) => (
            <li key={c.id}>
              <ClassRow c={c} selected={selected === String(c.id)} onSelect={() => onSelect(String(c.id))} />
            </li>
          ))}
        </ul>
      )}
    </>
  )

  return (
    <Panel
      className={cn('min-w-0', className)}
      title={<span className="inline-flex items-center gap-2">Sınıflar {inLesson > 0 && <StatusDot tone="success" pulse />}</span>}
      description={
        loading ? undefined
          : inLesson > 0 ? `${inLesson} sınıf derste · bugün dersi olan ${rows.length} sınıf`
            : nextStart ? `Şu an ders yok · ilk ders ${time(nextStart)} · ${rows.length} sınıfın dersi var`
              : `Bugün dersi olan sınıf yok · toplam ${all.length} sınıf`
      }
      actions={rows.length > 0 ? <span className="hidden text-[12px] text-ink-3 sm:inline">Satıra tıklayın: ders akışı o sınıfa göre süzülür</span> : undefined}
      flush
    >
      {loading ? (
        <div className="p-4"><Skeleton className="h-56" /></div>
      ) : all.length === 0 ? (
        <div className="flex flex-col items-center gap-2 px-4 py-7 text-center">
          <Mascot size={64} state="empty" />
          <p className="text-[13.5px] font-semibold text-ink">Aktif sınıf yok</p>
          <p className="text-[12.5px] text-ink-2">Sınıflar, sınıf yapısı ekranından açılır.</p>
          {can('academic.view') && <ButtonLink size="sm" to="/program-botu/sinif-yapisi" className="mt-1">Sınıf yapısına git</ButtonLink>}
        </div>
      ) : rows.length === 0 ? (
        <>
          <div className="flex flex-col items-center gap-2 px-4 py-6 text-center">
            <Mascot size={64} state="empty" />
            <p className="text-[13.5px] font-semibold text-ink">Bugün hiçbir sınıfın dersi yok</p>
            {can('schedule.view') && <ButtonLink size="sm" to="/ders-programi" className="mt-1">Ders programı</ButtonLink>}
          </div>
          {idleToggle}
        </>
      ) : (
        <>
          <ul className="px-1.5 pb-1">
            {visible.map((c) => (
              <li key={c.id}>
                <ClassRow c={c} selected={selected === String(c.id)} onSelect={() => onSelect(String(c.id))} />
              </li>
            ))}
          </ul>
          {rows.length > 10 && (
            <div className="border-t border-line px-2 py-1.5">
              <Button size="sm" variant="ghost" className="w-full" onClick={() => setExpanded((v) => !v)}>
                {expanded ? 'Kısalt' : `Bugün dersi olan tüm sınıflar (${rows.length - visible.length} sınıf daha)`}
              </Button>
            </div>
          )}
          {idleToggle}
        </>
      )}
    </Panel>
  )
}

function ClassRow({ c, selected, onSelect }: { c: ClassNow; selected: boolean; onSelect: () => void }) {
  const live = c.state === 'in_lesson'
  const insidePct = c.roster ? (c.inside / c.roster) * 100 : 0
  const lesson = c.session && (live || c.state === 'next')
  const label = live ? 'Derste' : c.state === 'next' ? `Sıradaki ${time(c.session?.starts_at)}` : c.state === 'done' ? 'Dersler bitti' : 'Ders yok'
  const detail = lesson
    ? `${c.session!.subject} · ${time(c.session!.starts_at)}–${time(c.session!.ends_at)}`
    : c.lessons_today ? `${c.lessons_done}/${c.lessons_today} ders tamamlandı` : 'Bugün ders yok'
  const who = lesson ? `${c.session!.teacher} · ${c.session!.classroom}` : (c.program ?? '')

  return (
    <button
      type="button"
      onClick={onSelect}
      aria-pressed={selected}
      className={cn(
        'flex w-full items-center gap-2.5 rounded-[var(--radius-sm)] px-2 py-2 text-left min-h-[44px] transition-colors hover:bg-surface-2',
        live && 'bg-success-soft/50',
        selected && 'ring-1 ring-inset ring-ink',
      )}
    >
      <span className={cn('size-1.5 shrink-0 rounded-full', live ? 'bg-success animate-pulse-dot' : c.state === 'next' ? 'bg-primary/50' : 'bg-line-strong')} />
      <span className="w-[62px] shrink-0 truncate text-[13px] font-semibold">{c.name}</span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-[12.5px] text-ink-2">{detail}</span>
        <span className="block truncate text-[12px] text-ink-3">{who}</span>
      </span>
      <span className="hidden w-[84px] shrink-0 sm:block"><ProgressBar value={insidePct} tone="success" /></span>
      <span className="w-[62px] shrink-0 text-right text-[12px] text-ink-3 tabular">{c.inside}/{c.roster} içeride</span>
      <span className="hidden w-[104px] shrink-0 text-right text-[12px] md:block">
        {live ? <Badge tone="success" dot>Derste</Badge> : <span className="text-ink-3">{label}</span>}
      </span>
    </button>
  )
}

/* ------------------------------------------------- gelmeyen / geç kalanlar */

function AbsencePanel({ data, loading, wide }: { data?: TodayData; loading: boolean; wide?: boolean }) {
  const [tab, setTab] = useState<'not_arrived' | 'late'>('not_arrived')
  const notArrived = data?.not_arrived ?? []
  const late = data?.late ?? []
  // Kimse gelmemiş ve kimse geç kalmamışsa ders saatinden önce boş kutu yer kaplamasın
  if (!loading && notArrived.length === 0 && late.length === 0) {
    return (
      <p className="rounded-[var(--radius-sm)] bg-surface-2/70 px-3 py-2 text-[12.5px] text-ink-3">
        Gelmeyen ve geç kalan öğrenci yok. <Link to="/yoklama/devamsizlik" className="inline-flex min-h-[40px] items-center hover:text-ink hover:underline underline-offset-2 sm:min-h-[26px]">Devamsızlık ekranı</Link>
      </p>
    )
  }

  return (
    <Panel flush>
      <Tabs
        className="px-2"
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'not_arrived', label: 'Gelmeyenler', count: notArrived.length },
          { value: 'late', label: 'Geç kalanlar', count: late.length },
        ]}
      />
      <div className="max-h-[360px] overflow-y-auto scroll-thin">
        {loading ? (
          <div className="p-4"><Skeleton className="h-40" /></div>
        ) : tab === 'not_arrived' ? (
          notArrived.length ? (
            <ul className={cn('md:grid md:grid-cols-2', wide && 'xl:grid-cols-3')}>
              {notArrived.map((st) => (
                <li key={st.id} className="flex items-center gap-3 border-b border-line px-4 py-2 last:border-0">
                  <Avatar name={st.full_name} size={30} />
                  <div className="min-w-0 flex-1">
                    <Link to={`/ogrenciler/${st.id}`} className="block truncate text-[13px] font-medium hover:text-primary">{st.full_name}</Link>
                    <p className="truncate text-[12px] text-ink-3">Sınıf {st.class_group ?? '—'} · ilk ders {time(st.first_lesson_at)}</p>
                  </div>
                  {st.guardian_phone && !st.guardian_phone.includes('•') && (
                    <a href={`tel:${st.guardian_phone.replace(/\D/g, '')}`} className="inline-flex h-10 items-center gap-1.5 rounded-[var(--radius-sm)] px-2 text-[12.5px] text-ink-2 hover:bg-surface-2" title={st.guardian ?? undefined}>
                      <Phone className="size-3.5" />
                      <span className="hidden tabular xl:inline">Veli {phone(st.guardian_phone)}</span>
                    </a>
                  )}
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState compact icon={<CheckCircle2 />} title="Herkes geldi" description="Dersi başlamış olup giriş yapmayan öğrenci yok." />
          )
        ) : late.length ? (
          <ul className={cn('md:grid md:grid-cols-2', wide && 'xl:grid-cols-3')}>
            {late.map((l) => (
              <li key={l.id} className="flex items-center gap-3 border-b border-line px-4 py-2 last:border-0">
                <Avatar name={l.full_name} size={30} />
                <div className="min-w-0 flex-1">
                  <Link to={`/ogrenciler/${l.student_id}`} className="block truncate text-[13px] font-medium hover:text-primary">{l.full_name}</Link>
                  <p className="truncate text-[12px] text-ink-3">{l.subject} · {time(l.starts_at)}</p>
                </div>
                <Badge tone="warning">{l.late_minutes} dk</Badge>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState compact icon={<AlarmClock />} title="Geç kalan yok" />
        )}
      </div>
    </Panel>
  )
}
