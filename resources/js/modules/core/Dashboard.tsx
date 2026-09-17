import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import {
  AlarmClock, ArrowRight, ArrowUpRight, CircleDollarSign, ClipboardCheck, Gavel, GraduationCap, LogIn, LogOut, PhoneCall,
  Radio, ShieldAlert, UserPlus, UserX, Users,
} from 'lucide-react'
import { api } from '@/lib/api'
import { compactMoney, date, money, num, relative, time } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useAuth, useCan } from '@/app/auth'
import { Panel } from '@/components/ui/layout'
import { Alert, EmptyState, ProgressBar, Skeleton, StatusDot } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Segmented } from '@/components/ui/form'
import { axisProps, ChartTooltip, Legend, monthLabel, series } from '@/components/charts/ChartKit'
import { ErrorBoundary } from '@/components/layout/ErrorBoundary'

type DashboardData = {
  generated_at: string
  attention: { risk_high?: number; leads_due?: number; attendance_pending: number; discipline_students?: number; discipline_open?: number }
  institution: Record<string, number>
  finance?: Record<string, string | number>
  communication: { messages_today: number; messages_month: number; failed_today: number }
  upcoming_exams: { id: number; name: string; exam_date: string; status: string }[]
  next_lessons: { id: number; starts_at: string; ends_at: string; subject: string; class_group: string; classroom: string; teacher: string }[]
  charts: {
    finance?: { month: string; collections: number; income: number; expense: number; net: number }[]
    attendance: { date: string; present: number; late: number; absent: number; excused: number }[]
    occupancy: { id: number; name: string; capacity: number; students: number }[]
    exam_trend: { id: number; name: string; exam_date: string; type: string; avg_net: number; participants: number }[]
  }
}

type FeedItem = { id: number; kind: string; message: string; student_id: number | null; occurred_at: string }

export default function Dashboard() {
  const me = useAuth((s) => s.me)
  const can = useCan()
  const navigate = useNavigate()
  const isTeacher = me?.user.user_type === 'teacher'

  // Öğretmen kendi paneline; kurulumu tamamlanmamış kurumda süper yönetici kurulum sihirbazına
  useEffect(() => {
    if (isTeacher) navigate('/panelim', { replace: true })
    else if (me && me.is_super_admin && !me.institution.onboarding_completed) navigate('/kurulum', { replace: true })
  }, [me, isTeacher, navigate])

  const { data, isLoading } = useQuery({ queryKey: ['dashboard'], queryFn: () => api.get<DashboardData>('/dashboard'), refetchInterval: 60_000, enabled: !isTeacher && can('dashboard.view') })

  const i = data?.institution
  const f = data?.finance
  const arrivedPct = i?.expected_today ? Math.round((i.arrived_today / i.expected_today) * 100) : 0
  const fin = can('finance.view')

  const quick = [
    { show: can('payments.create'), to: '/finans/tahsilat', label: 'Tahsilat al', icon: <CircleDollarSign /> },
    { show: can('attendance.take') || can('attendance.view'), to: '/yoklama', label: 'Yoklama', icon: <ClipboardCheck /> },
    { show: can('students.create'), to: '/ogrenciler?yeni=1', label: 'Öğrenci ekle', icon: <UserPlus /> },
    { show: can('crm.view'), to: '/on-kayit?yeni=1', label: 'Ön kayıt', icon: <PhoneCall /> },
    { show: can('discipline.create'), to: '/disiplin/olaylar?yeni=1', label: 'Olay kaydet', icon: <Gavel /> },
  ].filter((q) => q.show)

  return (
    <div className="animate-fade-in flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <h1 className="text-[22px] font-semibold tracking-[-0.02em]">Genel Bakış</h1>
          {data && (
            <span className="inline-flex items-center gap-1.5 rounded-[3px] border border-line bg-surface px-2 py-0.5 text-[12px] text-ink-3" title="Veriler dakikada bir yenilenir">
              <StatusDot tone="success" pulse /> Canlı · {time(data.generated_at)}
            </span>
          )}
        </div>
        {quick.length > 0 && (
          <nav aria-label="Hızlı işlemler" className="flex flex-wrap gap-2">
            {quick.map((q) => (
              <ButtonLink key={q.to} to={q.to} size="sm" icon={<span className="[&>svg]:size-4">{q.icon}</span>}>{q.label}</ButtonLink>
            ))}
          </nav>
        )}
      </header>

      {fin && <OverdueBanner />}

      {/* Göstergeler: tek sırada kısa kutular */}
      <section className={cn('grid grid-cols-2 gap-3 md:grid-cols-3', fin ? 'xl:grid-cols-6' : 'xl:grid-cols-4')}>
        <Kpi loading={isLoading} to="/yoklama/canli" label="Bugün gelen" value={num(i?.arrived_today)} suffix={`/ ${num(i?.expected_today)}`}
          bar={{ value: arrivedPct, tone: 'success' }} note={`%${arrivedPct} · ${num(i?.inside_now)} kişi içeride`} />
        <Kpi loading={isLoading} to="/yoklama/devamsizlik" label="Gelmeyen" value={num(i?.not_arrived_today)} tone={(i?.not_arrived_today ?? 0) > 0 ? 'danger' : undefined}
          note={`${num(i?.late_today)} geç kalan`} />
        <Kpi loading={isLoading} to="/takvim?gorunum=gun" label="Dersler" value={num(i?.lessons_in_progress)} suffix={`derste · ${num(i?.lessons_today)}`}
          note={`${num(i?.lessons_done)} ders bitti · ${num(i?.teachers_teaching_today)} öğretmen`} />
        <Kpi loading={isLoading} to="/ogrenciler" label="Aktif öğrenci" value={num(i?.students_active)} note={`${num(i?.students_total)} kayıt`} />
        {fin && (
          <>
            <Kpi loading={isLoading} to="/finans/tahsilatlar" label="Bugün tahsilat" value={money(f?.collected_today, { short: true })}
              note={`Bu ay ${compactMoney(f?.collected_month)}`} />
            <Kpi loading={isLoading} to="/finans/takip" label="Gecikmiş alacak" value={compactMoney(f?.overdue_amount)}
              tone={Number(f?.overdue_amount ?? 0) > 0 ? 'danger' : undefined} note={`${num(f?.overdue_students)} öğrenci`} />
          </>
        )}
      </section>

      <section className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <div className="flex min-w-0 flex-col gap-4 lg:col-span-8">
          <ErrorBoundary name="dashboard.performance"><PerformancePanel data={data} loading={isLoading} /></ErrorBoundary>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <ErrorBoundary name="dashboard.next-lessons"><NextLessons data={data} loading={isLoading} /></ErrorBoundary>
            <ErrorBoundary name="dashboard.occupancy"><OccupancyPanel data={data} loading={isLoading} /></ErrorBoundary>
          </div>
        </div>
        <div className="flex min-w-0 flex-col gap-4 lg:col-span-4">
          <ErrorBoundary name="dashboard.attention"><AttentionPanel data={data} loading={isLoading} /></ErrorBoundary>
          <ErrorBoundary name="dashboard.live-feed"><LiveFeed /></ErrorBoundary>
        </div>
      </section>
    </div>
  )
}

function Kpi({
  label, value, suffix, note, tone, to, bar, loading,
}: {
  label: string; value: ReactNode; suffix?: string; note?: ReactNode; tone?: 'danger'; to?: string; bar?: { value: number; tone: 'success' | 'primary' }; loading?: boolean
}) {
  const body = (
    <div className={cn('group flex h-full flex-col rounded-[var(--radius-md)] border bg-surface px-4 py-3 transition-colors',
      tone === 'danger' ? 'border-danger/30 border-l-[3px] border-l-danger' : 'border-line', to && 'hover:border-line-strong hover:bg-surface-2/40')}>
      <span className="flex items-center justify-between text-[12.5px] font-medium text-ink-2">
        {label}
        {to && <ArrowUpRight className="size-3.5 text-ink-3 opacity-0 transition-opacity group-hover:opacity-100" />}
      </span>
      {loading ? <Skeleton className="mt-2 h-7 w-20" /> : (
        <p className={cn('mt-1.5 truncate text-[24px] font-semibold leading-tight tracking-[-0.02em] tabular', tone === 'danger' ? 'text-danger' : 'text-ink')}>
          {value}{suffix && <span className="ml-1 text-[13px] font-medium tracking-normal text-ink-3">{suffix}</span>}
        </p>
      )}
      {bar && <ProgressBar value={bar.value} tone={bar.tone} className="mt-2 h-1.5" />}
      {note && <p className="mt-auto truncate pt-1.5 text-[12.5px] text-ink-3">{note}</p>}
    </div>
  )
  return to ? <Link to={to} className="block h-full">{body}</Link> : body
}

type PerfTab = 'finance' | 'attendance' | 'exams'

function PerformancePanel({ data, loading, className }: { data?: DashboardData; loading: boolean; className?: string }) {
  const can = useCan()
  const [tab, setTab] = useState<PerfTab>(can('finance.view') ? 'finance' : 'attendance')
  const examTypes = [...new Set((data?.charts.exam_trend ?? []).map((e) => e.type))]
  const [examType, setExamType] = useState('TYT')

  // Boş ayları baştan kırp: verisi olmayan aylar grafiği bozmasın
  const financeRows = useMemo(() => {
    const rows = data?.charts.finance ?? []
    const first = rows.findIndex((r) => r.income > 0 || r.expense > 0)
    return (first === -1 ? rows : rows.slice(Math.max(0, first - 1))).map((r) => ({ ...r, label: monthLabel(r.month) }))
  }, [data])

  const attendanceRows = (data?.charts.attendance ?? []).map((r) => {
    const total = r.present + r.late + r.absent + r.excused
    return { label: date(r.date).slice(0, 5), rate: total ? Math.round(((r.present + r.late) / total) * 1000) / 10 : 0, absent: r.absent, late: r.late }
  })
  // Eksen 10'ar adımlı ve %100'de biter: düzensiz tik değerleri (%71, %79…) okumayı zorlaştırıyor
  const attendanceFloor = Math.max(0, Math.min(90, Math.floor((Math.min(100, ...attendanceRows.map((r) => r.rate)) - 3) / 10) * 10))
  const examRows = (data?.charts.exam_trend ?? []).filter((e) => e.type === examType).map((e) => ({ label: date(e.exam_date).slice(0, 5), name: e.name, net: Number(e.avg_net) }))

  const tabs = [
    ...(can('finance.view') ? [{ value: 'finance' as const, label: 'Gelir / gider' }] : []),
    { value: 'attendance' as const, label: 'Devam' },
    { value: 'exams' as const, label: 'Sınav' },
  ]

  const summary: { label: string; value: string; tone?: string }[] = (() => {
    if (tab === 'finance' && data?.finance) {
      return [
        { label: 'Bu ay gelir', value: compactMoney(data.finance.income_month) },
        { label: 'Bu ay gider', value: compactMoney(data.finance.expense_month) },
        { label: 'Net', value: compactMoney(data.finance.net_month), tone: Number(data.finance.net_month) < 0 ? 'text-danger' : 'text-success' },
      ]
    }
    if (tab === 'attendance' && attendanceRows.length) {
      const avg = attendanceRows.reduce((a, r) => a + r.rate, 0) / attendanceRows.length
      return [
        { label: '14 gün ortalama', value: `%${num(avg, 1)}` },
        { label: 'Son gün', value: `%${num(attendanceRows.at(-1)!.rate, 1)}` },
        { label: 'Toplam yok', value: num(attendanceRows.reduce((a, r) => a + r.absent, 0)) },
      ]
    }
    if (tab === 'exams' && examRows.length) {
      const change = examRows.length > 1 ? examRows.at(-1)!.net - examRows[0]!.net : 0
      return [
        { label: 'Son ortalama', value: `${num(examRows.at(-1)!.net, 1)} net` },
        { label: 'Değişim', value: `${change >= 0 ? '+' : ''}${num(change, 1)}`, tone: change >= 0 ? 'text-success' : 'text-danger' },
        { label: 'Sınav', value: num(examRows.length) },
      ]
    }
    return []
  })()

  return (
    <Panel className={className} title="Performans" actions={<Segmented size="sm" value={tab} onChange={setTab} options={tabs} />}>
      {loading ? (
        <Skeleton className="h-[300px]" />
      ) : (
        <>
          <div className="mb-4 flex flex-wrap items-end gap-x-8 gap-y-2">
            {summary.map((s) => (
              <div key={s.label}>
                <p className="text-[12px] text-ink-3">{s.label}</p>
                <p className={cn('text-[18px] font-semibold tracking-tight tabular', s.tone)}>{s.value}</p>
              </div>
            ))}
            {tab === 'exams' && examTypes.length > 1 && (
              <Segmented className="ml-auto" size="sm" value={examType} onChange={setExamType} options={examTypes.map((t) => ({ value: t, label: t.replace('AYT_', 'AYT ') }))} />
            )}
            {tab === 'finance' && <Legend className="ml-auto" items={[{ label: 'Gelir', color: series[0]! }, { label: 'Gider', color: series[1]! }]} />}
          </div>

          <div className="h-[260px]">
            {tab === 'finance' && (
              <ResponsiveContainer>
                <BarChart data={financeRows} margin={{ top: 4, right: 0, left: 0, bottom: 0 }} barGap={2} barCategoryGap="28%">
                  <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
                  <XAxis dataKey="label" {...axisProps} dy={6} />
                  <YAxis {...axisProps} width={52} tickFormatter={(v) => compactMoney(v).replace(' ₺', '')} />
                  <Tooltip cursor={{ fill: 'var(--surface-2)', radius: 6 }} content={<ChartTooltip formatValue={(v) => money(v, { short: true })} />} />
                  <Bar dataKey="income" name="Gelir" fill={series[0]} radius={[4, 4, 0, 0]} maxBarSize={28} />
                  <Bar dataKey="expense" name="Gider" fill={series[1]} radius={[4, 4, 0, 0]} maxBarSize={28} />
                </BarChart>
              </ResponsiveContainer>
            )}
            {tab === 'attendance' &&
              (attendanceRows.length === 0 ? (
                <EmptyState compact icon={<ClipboardCheck />} title="Henüz yoklama verisi yok" />
              ) : (
                <ResponsiveContainer>
                  <LineChart data={attendanceRows} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                    <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
                    <XAxis dataKey="label" {...axisProps} dy={6} />
                    <YAxis {...axisProps} width={44} domain={[attendanceFloor, 100]} ticks={Array.from({ length: (100 - attendanceFloor) / 10 + 1 }, (_, k) => attendanceFloor + k * 10)} tickFormatter={(v) => `%${v}`} />
                    <Tooltip cursor={{ stroke: 'var(--line-strong)' }} content={<ChartTooltip formatValue={(v) => `%${num(v, 1)}`} />} />
                    <Line type="linear" dataKey="rate" name="Devam oranı" stroke={series[0]} strokeWidth={2} dot={{ r: 3.5, fill: 'var(--surface)', stroke: series[0], strokeWidth: 2 }} activeDot={{ r: 5, stroke: 'var(--surface)', strokeWidth: 2 }} />
                  </LineChart>
                </ResponsiveContainer>
              ))}
            {tab === 'exams' &&
              (examRows.length === 0 ? (
                <EmptyState compact icon={<GraduationCap />} title="Yayımlanmış sınav sonucu yok" />
              ) : (
                <ResponsiveContainer>
                  <LineChart data={examRows} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                    <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
                    <XAxis dataKey="label" {...axisProps} dy={6} />
                    <YAxis {...axisProps} width={44} domain={[(min: number) => Math.floor((min - 3) / 5) * 5, (max: number) => Math.ceil((max + 3) / 5) * 5]} allowDecimals={false} tickCount={6} tickFormatter={(v) => num(v, 0)} />
                    <Tooltip cursor={{ stroke: 'var(--line-strong)' }} content={<ChartTooltip formatLabel={(l) => examRows.find((r) => r.label === l)?.name ?? l} formatValue={(v) => `${num(v, 2)} net`} />} />
                    <Line type="linear" dataKey="net" name="Kurum ortalaması" stroke={series[0]} strokeWidth={2} dot={{ r: 3.5, fill: 'var(--surface)', stroke: series[0], strokeWidth: 2 }} activeDot={{ r: 5, stroke: 'var(--surface)', strokeWidth: 2 }} />
                  </LineChart>
                </ResponsiveContainer>
              ))}
          </div>
        </>
      )}
    </Panel>
  )
}

function AttentionPanel({ data, loading }: { data?: DashboardData; loading: boolean }) {
  const can = useCan()
  if (loading || !data) {
    return (
      <Panel title="Dikkat gerektirenler">
        <Skeleton className="h-[300px]" />
      </Panel>
    )
  }

  const items = [
    { show: data.institution.not_arrived_today! > 0, icon: <UserX />, tone: 'danger', title: `${data.institution.not_arrived_today} öğrenci bugün gelmedi`, sub: 'Dersi başlamış, giriş kaydı yok', to: '/yoklama/devamsizlik' },
    { show: can('finance.view') && Number(data.finance?.overdue_students ?? 0) > 0, icon: <AlarmClock />, tone: 'danger', title: `${data.finance?.overdue_students} öğrencinin ödemesi gecikti`, sub: `${compactMoney(data.finance?.overdue_amount)} gecikmiş alacak`, to: '/finans/takip' },
    { show: (data.attention.risk_high ?? 0) > 0, icon: <ShieldAlert />, tone: 'warning', title: `${data.attention.risk_high} öğrenci yüksek risk grubunda`, sub: 'Devamsızlık, net düşüşü veya ödev', to: '/ogrenciler?risk=high' },
    { show: data.attention.attendance_pending > 0, icon: <ClipboardCheck />, tone: 'warning', title: `${data.attention.attendance_pending} dersin yoklaması girilmedi`, sub: 'Bugün biten dersler', to: '/yoklama' },
    { show: (data.attention.discipline_students ?? 0) > 0, icon: <ShieldAlert />, tone: 'warning', title: `${data.attention.discipline_students} öğrenci disiplin uyarı eşiğini aştı`, sub: 'Dönem disiplin puanı', to: '/disiplin/dikkat?seviye=uyari' },
    { show: (data.attention.discipline_open ?? 0) > 0, icon: <ClipboardCheck />, tone: 'info', title: `${data.attention.discipline_open} disiplin olayı inceleme bekliyor`, sub: 'Açık / incelemede', to: '/disiplin/olaylar?status=open%2Creview' },
    { show: (data.attention.leads_due ?? 0) > 0, icon: <PhoneCall />, tone: 'info', title: `${data.attention.leads_due} ön kayıt bugün aranmalı`, sub: 'Planlanan arama', to: '/on-kayit' },
    ...Object.values(data.upcoming_exams ?? {}).slice(0, 2).map((e) => ({ show: true, icon: <GraduationCap />, tone: 'primary', title: e.name, sub: `${date(e.exam_date, 'long')} · yaklaşan sınav`, to: '' })),
  ].filter((x) => x.show)

  // Yalnız gerçek sorun kırmızı; diğerleri nötr (renk kalabalığı yok)
  const toneClass: Record<string, string> = {
    danger: 'bg-danger-soft/60 text-danger', warning: 'bg-surface-2 text-ink', info: 'bg-surface-2 text-ink-2', primary: 'bg-surface-2 text-ink-2',
  }

  return (
    <Panel title="Dikkat gerektirenler" flush>
      {items.length === 0 ? (
        <EmptyState compact icon={<ClipboardCheck />} title="Her şey yolunda" description="Şu an aksiyon bekleyen bir durum yok." />
      ) : (
        <ul className="px-2 pb-2">
          {items.map((item, idx) => {
            const inner = (
              <>
                <span className={cn('grid size-9 shrink-0 place-items-center rounded-[var(--radius-sm)] [&_svg]:size-[18px]', toneClass[item.tone])}>{item.icon}</span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13.5px] font-medium text-ink">{item.title}</span>
                  <span className="block truncate text-[12.5px] text-ink-3">{item.sub}</span>
                </span>
                {item.to && <ArrowRight className="size-4 text-ink-3 opacity-0 transition-opacity group-hover:opacity-100" />}
              </>
            )
            return (
              <li key={idx}>
                {item.to ? (
                  <Link to={item.to} className="group flex items-center gap-3 rounded-[var(--radius-md)] px-2.5 py-2.5 transition-colors hover:bg-surface-2">{inner}</Link>
                ) : (
                  <div className="flex items-center gap-3 px-2.5 py-2.5">{inner}</div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </Panel>
  )
}

const feedMeta: Record<string, { icon: ReactNode; className: string }> = {
  entry: { icon: <LogIn />, className: 'text-success' },
  risk: { icon: <ShieldAlert />, className: 'text-danger' },
  exit: { icon: <LogOut />, className: 'text-ink-3' },
  absent: { icon: <UserX />, className: 'text-danger' },
  late: { icon: <AlarmClock />, className: 'text-warning' },
  payment: { icon: <CircleDollarSign />, className: 'text-primary' },
  exam: { icon: <GraduationCap />, className: 'text-info' },
  enrollment: { icon: <Users />, className: 'text-accent' },
}

function LiveFeed() {
  const [items, setItems] = useState<FeedItem[]>([])
  const [fresh, setFresh] = useState<Set<number>>(new Set())
  const lastId = useRef(0)
  const can = useCan()
  const isTeacher = useAuth((s) => s.me?.user.user_type === 'teacher')
  // Öğretmen hesabı panelim'e yönlenirken akış isteği 403 üretmesin
  const initial = useQuery({ queryKey: ['feed', 'initial'], queryFn: () => api.get<{ data: FeedItem[] }>('/dashboard/feed'), staleTime: 0, enabled: !isTeacher && can('dashboard.view') })

  useEffect(() => {
    if (initial.data) {
      setItems(initial.data.data)
      lastId.current = initial.data.data[0]?.id ?? 0
    }
  }, [initial.data])

  useEffect(() => {
    if (!initial.data) return
    const t = setInterval(async () => {
      if (document.hidden) return
      try {
        const res = await api.get<{ data: FeedItem[] }>('/dashboard/feed', { after_id: lastId.current })
        if (res.data.length) {
          lastId.current = res.data[0]!.id
          setItems((prev) => [...res.data, ...prev].slice(0, 40))
          setFresh(new Set(res.data.map((r) => r.id)))
        }
      } catch {
        /* sonraki turda tekrar denenir */
      }
    }, 5000)
    return () => clearInterval(t)
  }, [initial.data])

  return (
    <Panel title={<span className="inline-flex items-center gap-2">Canlı akış <StatusDot tone="success" pulse /></span>} flush>
      <div className="max-h-[420px] overflow-y-auto scroll-thin px-4 pb-3">
        {initial.isLoading ? (
          <Skeleton className="h-full" />
        ) : items.length === 0 ? (
          <EmptyState compact icon={<Radio />} title="Henüz hareket yok" />
        ) : (
          <ol>
            {items.map((item) => {
              const meta = feedMeta[item.kind] ?? feedMeta.exit!
              const row = (
                <>
                  <span className={cn('mt-0.5 shrink-0 [&_svg]:size-4', meta.className)}>{meta.icon}</span>
                  <span className="min-w-0 flex-1 text-[13px] leading-snug text-ink-2">{item.message}</span>
                  <span className="shrink-0 text-[12px] text-ink-3 tabular" title={relative(item.occurred_at)}>{time(item.occurred_at)}</span>
                </>
              )
              return (
                <li key={item.id} className={cn(fresh.has(item.id) && 'animate-slide-up')}>
                  {item.student_id ? (
                    <Link to={`/ogrenciler/${item.student_id}`} className="flex items-start gap-3 py-2 hover:text-ink">{row}</Link>
                  ) : (
                    <div className="flex items-start gap-3 py-2">{row}</div>
                  )}
                </li>
              )
            })}
          </ol>
        )}
      </div>
    </Panel>
  )
}

function NextLessons({ data, loading }: { data?: DashboardData; loading: boolean }) {
  const now = Date.now()
  return (
    <Panel title="Sıradaki dersler" actions={<Link to="/takvim?gorunum=ajanda" className="inline-flex items-center gap-1 text-[12.5px] text-ink-3 hover:text-ink">Tümü <ArrowRight className="size-3.5" /></Link>}>
      {loading ? (
        <Skeleton className="h-[280px]" />
      ) : !data?.next_lessons.length ? (
        <EmptyState compact icon={<ClipboardCheck />} title="Önümüzdeki 24 saatte ders yok" />
      ) : (
        <ul className="flex flex-col">
          {data.next_lessons.slice(0, 5).map((l) => {
            const live = new Date(l.starts_at.replace(' ', 'T')).getTime() <= now
            const tomorrow = date(l.starts_at.replace(' ', 'T')) !== date(new Date())
            return (
              <li key={l.id} className="flex items-center gap-3 border-b border-line/70 py-2.5 last:border-0">
                <span className="w-11 shrink-0 text-[13px] font-semibold tabular">{time(l.starts_at.replace(' ', 'T'))}{tomorrow && <span className="block text-[10.5px] font-normal text-ink-3">yarın</span>}</span>
                <span className={cn('size-1.5 shrink-0 rounded-full', live ? 'bg-success animate-pulse-dot' : 'bg-line-strong')} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13.5px] font-medium">{l.subject}</p>
                  <p className="truncate text-[12px] text-ink-3">{l.class_group} · {l.teacher} · {l.classroom}</p>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </Panel>
  )
}

function OccupancyPanel({ data, loading }: { data?: DashboardData; loading: boolean }) {
  const rows = [...(data?.charts.occupancy ?? [])].sort((a, b) => b.students / b.capacity - a.students / a.capacity)
  return (
    <Panel title="Sınıf doluluğu">
      {loading ? (
        <Skeleton className="h-[280px]" />
      ) : (
        <ul className="flex flex-col gap-3">
          {rows.slice(0, 7).map((g) => {
            const rate = g.capacity ? (g.students / g.capacity) * 100 : 0
            return (
              <li key={g.id} className="grid grid-cols-[88px_1fr_44px] items-center gap-3 text-[12.5px]">
                <span className="truncate font-medium">{g.name}</span>
                <ProgressBar value={rate} tone={rate >= 100 ? 'warning' : 'primary'} />
                <span className="text-right text-ink-3 tabular">{g.students}/{g.capacity}</span>
              </li>
            )
          })}
        </ul>
      )}
    </Panel>
  )
}

type OverdueAlertData = {
  date: string
  installments: { count: number; amount: string; students: number }
  invoices: { count: number; amount: string }
  due_today: { count: number; amount: string }
}

const dismissKey = 'ebe-dismiss:dashboard-overdue'
function readDismissed(day: string) {
  try {
    return localStorage.getItem(dismissKey) === day
  } catch {
    return false
  }
}

/** Günlük gecikme uyarısı: vadesi geçmiş taksit + tahsil edilmemiş fatura, bugün vadesi dolanlar. "Bugün gizle" ertesi gün sıfırlanır. */
function OverdueBanner() {
  const { data } = useQuery({
    queryKey: ['finance', 'overdue-alert'],
    queryFn: () => api.get<{ data: OverdueAlertData }>('/finance/overdue-alert').then((r) => r.data),
    refetchInterval: 300_000,
  })
  const [hidden, setHidden] = useState(false)
  if (!data || hidden || readDismissed(data.date)) return null
  const { installments: inst, invoices: inv, due_today: today } = data
  if (inst.count === 0 && inv.count === 0 && today.count === 0) return null
  const hide = () => {
    try {
      localStorage.setItem(dismissKey, data.date)
    } catch {
      /* yok say */
    }
    setHidden(true)
  }
  const parts = [
    inst.count > 0 && `${num(inst.count)} taksit · ${money(inst.amount)} (${num(inst.students)} öğrenci)`,
    inv.count > 0 && `${num(inv.count)} fatura · ${money(inv.amount)}`,
  ].filter(Boolean)
  return (
    <Alert
      tone={inst.count > 0 || inv.count > 0 ? 'danger' : 'warning'}
      title={parts.length ? `Vadesi geçmiş ödemeler: ${parts.join(' + ')}` : `Bugün vadesi dolan ${num(today.count)} taksit`}
      action={
        <>
          {inst.count > 0 && <Link to="/finans/takip" className="text-[13px] font-medium text-ink underline-offset-2 hover:underline">Gecikme takibi</Link>}
          {inv.count > 0 && <Link to="/finans/faturalar?status=issued" className="text-[13px] font-medium text-ink underline-offset-2 hover:underline">Faturalar</Link>}
          <Button size="xs" variant="ghost" onClick={hide}>Bugün gizle</Button>
        </>
      }
    >
      {today.count > 0 ? (
        <Link to="/finans/alacaklar?status=pending" className="hover:underline">Bugün vadesi dolan: {num(today.count)} taksit · {money(today.amount)}</Link>
      ) : (
        'Bugün vadesi dolan taksit yok.'
      )}
    </Alert>
  )
}
