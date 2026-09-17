import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlarmClock, BookOpen, CheckCircle2, LogIn, Phone, Presentation, School, UserX, Users, Wallet } from 'lucide-react'
import { api } from '@/lib/api'
import { date, money, num, phone, time } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat, Tabs } from '@/components/ui/layout'
import { Avatar, Badge, EmptyState, ProgressBar, Skeleton, StatusDot } from '@/components/ui/feedback'
import { Segmented } from '@/components/ui/form'
import { ButtonLink } from '@/components/ui/Button'

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

const phaseBadge: Record<Session['phase'], React.ReactNode> = {
  upcoming: <Badge>Bekliyor</Badge>,
  in_progress: <Badge tone="success" dot>Devam ediyor</Badge>,
  done: <Badge tone="neutral">Bitti</Badge>,
  cancelled: <Badge tone="danger">İptal</Badge>,
}

export default function Today() {
  const can = useCan()
  const [tab, setTab] = useState<'not_arrived' | 'late'>('not_arrived')
  const [classFilter, setClassFilter] = useState<string>('all')
  const { data, isLoading } = useQuery({
    queryKey: ['operations', 'today'],
    queryFn: () => api.get<TodayData>('/operations/today'),
    refetchInterval: 30_000,
  })
  const s = data?.summary
  const sessions = (data?.sessions ?? []).filter((row) => classFilter === 'all' || String(row.class_group_id) === classFilter)

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Bugün"
        description={
          <span className="inline-flex items-center gap-2">
            {date(data?.date ?? new Date(), 'day')} · <StatusDot tone="success" pulse /> 30 sn'de bir yenilenir
          </span>
        }
      />

      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <Stat label="Beklenen öğrenci" icon={<Users />} loading={isLoading} value={num(s?.expected)} />
        <Stat label="Geldi" icon={<LogIn />} loading={isLoading} value={num(s?.arrived)} tone="success" sub={s?.expected ? `%${Math.round(((s.arrived ?? 0) / s.expected) * 100)} · ${num(s?.inside)} içeride` : undefined} />
        <Stat label="Geç kaldı" icon={<AlarmClock />} loading={isLoading} value={num(s?.late)} tone={s?.late ? 'warning' : undefined} />
        <Stat label="Gelmedi" icon={<UserX />} loading={isLoading} value={num(s?.not_arrived)} tone={s?.not_arrived ? 'danger' : undefined} />
        <Stat label="Ders" icon={<BookOpen />} loading={isLoading} value={num(s?.lessons)} sub={s?.lessons_cancelled ? `${s.lessons_cancelled} iptal` : `${num(s?.lessons_in_progress)} devam ediyor`} />
        <Stat label="Öğretmen" icon={<Presentation />} loading={isLoading} value={num(s?.teachers)} sub={s?.attendance_pending ? `${s.attendance_pending} yoklama bekliyor` : undefined} />
      </div>

      {can('finance.view') && data?.finance && (
        <div className="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
          <Stat label="Bugün beklenen tahsilat" icon={<Wallet />} value={money(data.finance.expected, { short: true })} sub={`${money(data.finance.expected_open, { short: true })} henüz açık`} />
          <Stat label="Tahsil edilen" icon={<CheckCircle2 />} value={money(data.finance.collected, { short: true })} tone="success" sub={`${data.finance.payments_count} tahsilat`} />
          <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line px-4 py-3.5 flex flex-col justify-center">
            <p className="text-[12.5px] font-medium text-ink-2 mb-2">Bugünkü vadelere göre</p>
            <ProgressBar value={Number(data.finance.expected) > 0 ? (Number(data.finance.collected) / Number(data.finance.expected)) * 100 : 0} tone="success" />
            <p className="mt-1.5 text-[12px] text-ink-3 tabular">
              %{Number(data.finance.expected) > 0 ? Math.round((Number(data.finance.collected) / Number(data.finance.expected)) * 100) : 0}
            </p>
          </div>
        </div>
      )}

      {/* Sınıflar şu an: her sınıfın kendi programı */}
      <div className="mt-7 mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-[15px] font-semibold">
          Sınıflar şu an <StatusDot tone="success" pulse />
        </h2>
        <p className="text-[12.5px] text-ink-3">Derste olan sınıflar önce listelenir</p>
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-3">
        {isLoading
          ? Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-[150px] rounded-[var(--radius-lg)]" />)
          : data?.classes.length === 0
            ? <EmptyState className="col-span-full" icon={<School />} title="Aktif sınıf yok" description="Sınıflar sınıf yapısından ya da Sınıflar ekranından açılır." action={can('academic.view') ? <ButtonLink to="/program-botu/sinif-yapisi">Sınıf yapısına git</ButtonLink> : undefined} />
            : data?.classes.map((c) => <ClassCard key={c.id} c={c} onSelect={() => setClassFilter(String(c.id))} selected={classFilter === String(c.id)} />)}
      </div>

      <div className="mt-6 grid grid-cols-1 xl:grid-cols-5 gap-4">
        <Panel
          title="Ders akışı"
          description={`${sessions.length} ders`}
          className="xl:col-span-3 min-w-0"
          actions={
            classFilter !== 'all' ? (
              <button onClick={() => setClassFilter('all')} className="text-[12.5px] text-ink-3 hover:text-ink">
                Filtreyi kaldır
              </button>
            ) : undefined
          }
          flush
        >
          {isLoading ? (
            <div className="p-4"><Skeleton className="h-64" /></div>
          ) : !sessions.length ? (
            <EmptyState compact icon={<BookOpen />} title="Bugün planlanmış ders yok" action={can('schedule.view') ? <ButtonLink size="sm" to="/ders-programi">Ders programı</ButtonLink> : undefined} />
          ) : (
            <div className="overflow-x-auto scroll-thin">
              <table className="tbl w-full min-w-[560px] text-[13px]">
                <thead>
                  <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
                    <th className="px-4 h-9 font-medium text-left">Saat</th>
                    <th className="fill px-3 font-medium text-center">Sınıf ve ders</th>
                    <th className="px-3 font-medium text-center">Yoklama</th>
                    <th className="px-4 font-medium text-center">Durum</th>
                  </tr>
                </thead>
                <tbody>
                  {sessions.map((row) => (
                    <tr key={row.id} className={cn('border-b border-line last:border-0', row.phase === 'in_progress' && 'bg-success-soft/40')}>
                      <td className="px-4 py-2.5 tabular whitespace-nowrap align-top text-left">
                        {time(row.starts_at)}<span className="text-ink-3">–{time(row.ends_at)}</span>
                      </td>
                      <td className="fill px-3 py-2.5 text-center">
                        <p className="font-medium">{row.class_group} <span className="font-normal text-ink-2">· {row.subject}</span></p>
                        <p className="text-[12px] text-ink-3">{row.teacher} · {row.classroom}</p>
                      </td>
                      <td className="px-3 py-2.5 text-center">
                        {row.present + row.late + row.absent + row.excused > 0 ? (
                          <span className="inline-flex flex-wrap items-center gap-x-2 tabular text-[12.5px]">
                            <span className="text-success">{row.present} var</span>
                            {row.late > 0 && <span className="text-warning">{row.late} geç</span>}
                            {row.absent > 0 && <span className="text-danger">{row.absent} yok</span>}
                          </span>
                        ) : (
                          <span className="text-ink-3 text-[12.5px]">{row.phase === 'upcoming' ? '—' : 'Bekleniyor'}</span>
                        )}
                      </td>
                      <td className="px-4 py-2.5 text-center">{phaseBadge[row.phase]}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <Panel className="xl:col-span-2 min-w-0" flush>
          <Tabs
            className="px-2"
            value={tab}
            onChange={setTab}
            tabs={[
              { value: 'not_arrived', label: 'Gelmeyenler', count: data?.not_arrived.length ?? null },
              { value: 'late', label: 'Geç kalanlar', count: data?.late.length ?? null },
            ]}
          />
          <div className="max-h-[520px] overflow-y-auto scroll-thin">
            {isLoading ? (
              <div className="p-4"><Skeleton className="h-48" /></div>
            ) : tab === 'not_arrived' ? (
              data?.not_arrived.length ? (
                <ul>
                  {data.not_arrived.map((st) => (
                    <li key={st.id} className="flex items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">
                      <Avatar name={st.full_name} size={32} />
                      <div className="min-w-0 flex-1">
                        <Link to={`/ogrenciler/${st.id}`} className="block truncate text-[13.5px] font-medium hover:text-primary">{st.full_name}</Link>
                        <p className="truncate text-[12.5px] text-ink-3">Sınıf {st.class_group ?? '—'} · ilk ders {time(st.first_lesson_at)}</p>
                      </div>
                      {st.guardian_phone && !st.guardian_phone.includes('•') && (
                        <a href={`tel:${st.guardian_phone.replace(/\D/g, '')}`} className="inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] px-2 h-8 text-[12.5px] text-ink-2 hover:bg-surface-2" title={st.guardian ?? undefined}>
                          <Phone className="size-3.5" />
                          <span className="hidden sm:inline tabular">Veli {phone(st.guardian_phone)}</span>
                        </a>
                      )}
                    </li>
                  ))}
                </ul>
              ) : (
                <EmptyState compact icon={<CheckCircle2 />} title="Herkes geldi" description="Dersi başlamış olup giriş yapmayan öğrenci yok." />
              )
            ) : data?.late.length ? (
              <ul>
                {data.late.map((l) => (
                  <li key={l.id} className="flex items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">
                    <Avatar name={l.full_name} size={32} />
                    <div className="min-w-0 flex-1">
                      <Link to={`/ogrenciler/${l.student_id}`} className="block truncate text-[13.5px] font-medium hover:text-primary">{l.full_name}</Link>
                      <p className="text-[12px] text-ink-3">{l.subject} · {time(l.starts_at)}</p>
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
      </div>
    </div>
  )
}

function ClassCard({ c, onSelect, selected }: { c: ClassNow; onSelect: () => void; selected: boolean }) {
  const stateBadge = {
    in_lesson: <Badge tone="success" dot>Derste</Badge>,
    next: <Badge>Sıradaki {time(c.session?.starts_at)}</Badge>,
    done: <Badge tone="neutral">Dersler bitti</Badge>,
    no_lessons: <Badge tone="neutral">Bugün ders yok</Badge>,
  }[c.state]
  const insidePct = c.roster ? (c.inside / c.roster) * 100 : 0

  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        'flex flex-col rounded-[var(--radius-lg)] bg-surface px-4 py-3.5 text-left ring-1 transition-shadow hover:shadow-[var(--shadow-soft)]',
        selected ? 'ring-ink' : c.state === 'in_lesson' ? 'ring-line-strong' : 'ring-line',
      )}
    >
      <div className="flex w-full items-center justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-[14px] font-semibold">{c.name}</p>
          <p className="truncate text-[12px] text-ink-3">{c.program ?? '—'}</p>
        </div>
        {stateBadge}
      </div>

      <div className="mt-3 min-h-[40px]">
        {c.session && (c.state === 'in_lesson' || c.state === 'next') ? (
          <>
            <p className="truncate text-[14px] font-medium">
              {c.session.subject} <span className="font-normal text-ink-3 tabular">{time(c.session.starts_at)}–{time(c.session.ends_at)}</span>
            </p>
            <p className="truncate text-[12px] text-ink-3">{c.session.teacher} · {c.session.classroom}</p>
          </>
        ) : (
          <p className="text-[12.5px] text-ink-3">{c.lessons_today ? `${c.lessons_done}/${c.lessons_today} ders tamamlandı` : 'Bu sınıfın bugün dersi yok'}</p>
        )}
      </div>

      <div className="mt-3 flex w-full items-center gap-2.5">
        <ProgressBar value={insidePct} tone="success" className="flex-1" />
        <span className="shrink-0 text-[12px] text-ink-2 tabular">
          {c.inside}/{c.roster} içeride
        </span>
      </div>
      {c.session && c.state === 'in_lesson' && c.session.present + c.session.late + c.session.absent > 0 && (
        <p className="mt-1.5 text-[12px] tabular">
          <span className="text-success">{c.session.present} var</span>
          {c.session.late > 0 && <span className="text-warning"> · {c.session.late} geç</span>}
          {c.session.absent > 0 && <span className="text-danger"> · {c.session.absent} yok</span>}
        </p>
      )}
    </button>
  )
}

export { Segmented }
