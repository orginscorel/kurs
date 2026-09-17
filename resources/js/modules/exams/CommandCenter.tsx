import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { BarChart3, CalendarClock, GraduationCap, TrendingDown, TrendingUp, Users } from 'lucide-react'
import { api } from '@/lib/api'
import { date, time } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader, Panel, Stat } from '@/components/ui/layout'
import { Badge, EmptyState, Skeleton, StatusDot } from '@/components/ui/feedback'
import { Segmented } from '@/components/ui/form'
import { ButtonLink } from '@/components/ui/Button'
import { axisProps, ChartTooltip, gridProps, Legend, series } from '@/components/charts/ChartKit'
import { net, rateTone, statusLabel, statusTone, type DashboardData, type Mover } from './types'
import { RateBar } from './shared'

type Group = 'TYT' | 'AYT' | 'LGS'
const GROUPS: Group[] = ['TYT', 'AYT', 'LGS']

/** Akademik Komuta Merkezi: kurum trendi, sınıf karşılaştırması, gelişim/düşüş, en zor/en başarılı konular. */
export default function CommandCenter() {
  const { data, isLoading } = useQuery({ queryKey: ['exams', 'dashboard'], queryFn: () => api.get<DashboardData>('/exams/dashboard'), refetchInterval: 120_000 })
  const available = useMemo(() => GROUPS.filter((g) => data?.kpis[g]), [data])
  const [group, setGroup] = useState<Group | null>(null)
  const active = group && available.includes(group) ? group : (available[0] ?? 'TYT')

  if (isLoading || !data) return <div className="animate-fade-in"><Skeleton className="h-7 w-72 mb-6" /><div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-[92px] rounded-[var(--radius-lg)]" />)}</div><Skeleton className="h-80 rounded-[var(--radius-lg)]" /></div>

  const trend = data.trend.filter((e) => e.type_group === active)
  const classes = data.classes.filter((c) => c.types[active]).sort((a, b) => (b.types[active]?.avg_net ?? 0) - (a.types[active]?.avg_net ?? 0))
  const movers = { up: data.movers.up.filter((m) => m.type_group === active), down: data.movers.down.filter((m) => m.type_group === active) }

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Akademik Komuta Merkezi"
        description={<span className="inline-flex items-center gap-2">Kurum sınav performansı <span className="inline-flex items-center gap-1.5 text-ink-3">· <StatusDot tone="success" pulse /> {time(data.generated_at)}</span></span>}
        actions={available.length > 1 ? <Segmented value={active} onChange={(v) => setGroup(v)} options={available.map((g) => ({ value: g, label: g }))} /> : undefined}
      />

      {data.totals.published === 0 ? (
        <EmptyState icon={<BarChart3 />} title="Henüz yayımlanmış deneme yok" description="Sonuçlar yayımlandıkça kurum trendi, sınıf karşılaştırması ve konu analizleri burada oluşur." action={<ButtonLink variant="primary" to="/sinavlar">Denemelere git</ButtonLink>} />
      ) : (
        <>
          <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
            {GROUPS.map((g) => {
              const k = data.kpis[g]
              return <Stat key={g} label={`${g} kurum ortalaması`} icon={<GraduationCap />} value={k ? net(k.avg_net) : '—'} delta={k?.delta !== null && k?.delta !== undefined ? Number(((k.delta / Math.max(1, k.avg_net - k.delta)) * 100).toFixed(1)) : undefined} deltaLabel={k ? `${k.exam} · ${k.participants} öğr.` : 'deneme yok'} to={k ? `/sinavlar/${k.exam_id}` : undefined} />
            })}
            <Stat label="Yayımlanan deneme" icon={<BarChart3 />} value={data.totals.published} sub={`${data.totals.results.toLocaleString('tr-TR')} sonuç`} />
            <Stat label="Sınava giren öğrenci" icon={<Users />} value={data.totals.students_tested} sub="son 12 deneme" />
            <Stat label="Yaklaşan deneme" icon={<CalendarClock />} value={data.upcoming.length} sub={data.upcoming[0] ? `${data.upcoming[0].name.slice(0, 22)} · ${date(data.upcoming[0].exam_date)}` : 'planlanmış yok'} to="/sinavlar?status=upcoming" />
          </div>

          <div className="mt-4 grid grid-cols-1 xl:grid-cols-3 gap-4">
            <Panel title={`${active} ortalama net trendi`} description="Yayımlanan her deneme için kurum ortalaması ve en yüksek net" className="xl:col-span-2">
              {trend.length === 0 ? <EmptyState compact title="Bu türde yayımlanmış deneme yok" /> : (
                <>
                  <div className="h-72">
                    <ResponsiveContainer>
                      <LineChart data={trend.map((e) => ({ label: date(e.exam_date).slice(0, 5), name: e.name, avg: e.avg_net, max: e.max_net, n: e.participants }))} margin={{ top: 10, right: 12, left: -16, bottom: 0 }}>
                        <CartesianGrid {...gridProps} />
                        <XAxis dataKey="label" {...axisProps} />
                        <YAxis {...axisProps} />
                        <Tooltip content={<ChartTooltip formatLabel={(l) => trend.find((e) => date(e.exam_date).slice(0, 5) === l)?.name ?? l} formatValue={(v, r) => (r.dataKey === 'n' ? `${v}` : net(v))} />} />
                        <Line type="monotone" dataKey="avg" name="Ortalama net" stroke={series[0]} strokeWidth={2.5} dot={{ r: 3 }} activeDot={{ r: 5 }} />
                        <Line type="monotone" dataKey="max" name="En yüksek net" stroke={series[1]} strokeWidth={1.5} strokeDasharray="4 3" dot={false} />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>
                  <Legend className="mt-2" items={[{ label: 'Ortalama net', color: series[0]! }, { label: 'En yüksek net', color: series[1]! }]} />
                </>
              )}
            </Panel>

            <Panel title="Sınıf karşılaştırması" description={`${active} · son 12 denemenin ortalaması`}>
              {classes.length === 0 ? <p className="text-[12.5px] text-ink-3">Sınıf bilgisi olan sonuç yok.</p> : (
                <div className="h-72">
                  <ResponsiveContainer>
                    <BarChart data={classes.map((c) => ({ name: c.name, avg: c.types[active]!.avg_net, exams: c.types[active]!.exams }))} layout="vertical" margin={{ top: 0, right: 12, left: 8, bottom: 0 }}>
                      <CartesianGrid {...gridProps} horizontal={false} vertical />
                      <XAxis type="number" {...axisProps} />
                      <YAxis type="category" dataKey="name" {...axisProps} width={78} />
                      <Tooltip content={<ChartTooltip formatValue={(v) => net(v)} />} cursor={{ fill: 'var(--surface-2)' }} />
                      <Bar dataKey="avg" name="Ortalama net" fill={series[0]} radius={[0, 4, 4, 0]} barSize={14} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}
            </Panel>
          </div>

          <div className="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
            <MoverPanel title="En yüksek gelişim gösteren öğrenciler" icon={<TrendingUp className="text-success" />} items={movers.up} up />
            <MoverPanel title="Düşüş yaşayan öğrenciler" icon={<TrendingDown className="text-danger" />} items={movers.down} />
          </div>

          <div className="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
            <TopicPanel title="En zor konular" description="Kurum geneli, kümülatif" items={data.topics.hardest} />
            <TopicPanel title="En başarılı konular" description="Kurum geneli, kümülatif" items={data.topics.easiest} />
            <Panel title="Yaklaşan denemeler" actions={<Link to="/sinavlar" className="text-[12.5px] text-primary hover:underline">Tümü</Link>}>
              {data.upcoming.length === 0 ? <p className="text-[12.5px] text-ink-3">Planlanmış deneme yok.</p> : (
                <ul className="flex flex-col divide-y divide-line">
                  {data.upcoming.map((u) => (
                    <li key={u.id} className="flex items-center gap-2 py-2 text-[13px]">
                      <span className="w-14 tabular text-ink-3">{date(u.exam_date).slice(0, 5)}</span>
                      <Link to={`/sinavlar/${u.id}`} className="flex-1 truncate font-medium hover:text-primary">{u.name}</Link>
                      <Badge tone={statusTone[u.status]}>{statusLabel[u.status]}</Badge>
                    </li>
                  ))}
                </ul>
              )}
              <div className="mt-3 border-t border-line pt-3">
                <p className="mb-1.5 text-[12px] font-medium text-ink-3">Ders başarı oranları</p>
                <ul className="flex flex-col gap-1.5">{data.topics.subjects.slice(0, 8).map((s) => <li key={s.id} className="flex items-center gap-2 text-[12.5px]"><span className="w-28 truncate">{s.name}</span><RateBar rate={s.rate} className="flex-1" /></li>)}</ul>
              </div>
            </Panel>
          </div>
        </>
      )}
    </div>
  )
}

function MoverPanel({ title, icon, items, up }: { title: string; icon: React.ReactNode; items: Mover[]; up?: boolean }) {
  return (
    <Panel title={<span className="inline-flex items-center gap-2 [&_svg]:size-4">{icon}{title}</span>} description="Aynı türdeki ilk ve son denemelerin ortalaması karşılaştırılır">
      {items.length === 0 ? <p className="text-[12.5px] text-ink-3">Karşılaştırma için en az iki deneme gerekir.</p> : (
        <ul className="flex flex-col divide-y divide-line">
          {items.map((m) => (
            <li key={m.student_id} className="flex items-center gap-3 py-2 text-[13px]">
              <div className="min-w-0 flex-1">
                <Link to={`/ogrenciler/${m.student_id}`} className="block truncate font-medium hover:text-primary">{m.name}</Link>
                <p className="text-[12px] text-ink-3">{m.class_name ?? '—'} · {m.exams} deneme</p>
              </div>
              <Spark values={m.nets} up={!!up} />
              <span className="tabular text-ink-3 text-[12px] w-24 text-right">{net(m.from)} → {net(m.to)}</span>
              <span className={cn('tabular font-semibold w-14 text-right', up ? 'text-success' : 'text-danger')}>{m.delta > 0 ? '+' : ''}{net(m.delta, 1)}</span>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  )
}

function Spark({ values, up }: { values: number[]; up: boolean }) {
  const w = 64, h = 20
  const min = Math.min(...values), max = Math.max(...values)
  const pts = values.map((v, i) => `${(i / Math.max(1, values.length - 1)) * w},${h - ((v - min) / Math.max(1, max - min)) * (h - 4) - 2}`).join(' ')
  return <svg width={w} height={h} className="shrink-0"><polyline points={pts} fill="none" stroke={up ? 'var(--success)' : 'var(--danger)'} strokeWidth={1.5} strokeLinejoin="round" strokeLinecap="round" /></svg>
}

function TopicPanel({ title, description, items }: { title: string; description: string; items: DashboardData['topics']['hardest'] }) {
  return (
    <Panel title={title} description={description}>
      {items.length === 0 ? <p className="text-[12.5px] text-ink-3">Yeterli veri yok.</p> : (
        <ul className="flex flex-col gap-2">
          {items.map((t) => (
            <li key={t.id} className="flex items-center gap-2 text-[13px]">
              <span className="flex-1 truncate">{t.name} <span className="text-ink-3">· {t.subject}</span></span>
              <RateBar rate={t.rate} className="w-32" />
              <Badge tone={rateTone(t.rate)} className="hidden sm:inline-flex">{t.asked} soru</Badge>
            </li>
          ))}
        </ul>
      )}
      <Link to="/kazanim-analizi" className="mt-3 inline-block text-[12.5px] text-primary hover:underline">Kazanım analizi →</Link>
    </Panel>
  )
}
