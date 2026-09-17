import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { AlertTriangle, CalendarClock, Gavel, Hourglass, Plus, ShieldAlert, ThumbsUp, UserX, Users } from 'lucide-react'
import { api } from '@/lib/api'
import { date, dateTime, num, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat } from '@/components/ui/layout'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { axisProps, ChartTooltip, gridProps, Legend, series } from '@/components/charts/ChartKit'
import type { Overview as OverviewData } from './types'
import { INCIDENT_TONE, KindBadge, LevelBadge, PointsMeter, SeverityBadge, StatusBadge, useDisciplineOptions } from './ui'
import { IncidentFormDrawer } from './IncidentFormDrawer'

export default function Overview() {
  const can = useCan()
  const [form, setForm] = useState<null | 'negative' | 'positive'>(null)
  const { data: opt } = useDisciplineOptions()
  const { data, isLoading } = useQuery({
    queryKey: ['discipline', 'overview'],
    queryFn: () => api.get<{ data: OverviewData }>('/discipline/overview').then((r) => r.data),
    refetchInterval: 60_000,
  })
  const c = data?.counts
  const settings = opt?.settings

  return (
    <div className="animate-fade-in">
      <PageHeader title="Disiplin" description={data ? `${data.term.name} dönemi · olaylar, savunmalar, yaptırımlar ve kurul` : 'Olaylar, savunmalar, yaptırımlar ve kurul'}
        actions={can('discipline.create') && (
          <>
            <Button variant="success" icon={<ThumbsUp className="size-4" />} onClick={() => setForm('positive')}>Olumlu davranış</Button>
            <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setForm('negative')}>Olay kaydet</Button>
          </>
        )} />

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <Stat label="Bugün / bu hafta" icon={<CalendarClock />} loading={isLoading} value={`${num(c?.today)} / ${num(c?.week)}`} sub={`${num(c?.positives_week)} olumlu kayıt bu hafta`} to="/disiplin/olaylar" />
        <Stat label="Açık olay" icon={<AlertTriangle />} loading={isLoading} value={num(c?.open)} tone={c?.open ? 'warning' : undefined} sub={c?.appealed ? `${c.appealed} itirazda` : 'incelemede dahil'} to="/disiplin/olaylar?status=open%2Creview" />
        <Stat label="Süren yaptırım" icon={<Gavel />} loading={isLoading} value={num(c?.active_sanctions)} sub={c?.suspended_today ? `${c.suspended_today} öğrenci bugün uzaklaştırmada` : 'bugün uzaklaştırma yok'} tone={c?.suspended_today ? 'danger' : undefined} />
        <Stat label="Bekleyen savunma" icon={<Hourglass />} loading={isLoading} value={num(c?.pending_defenses)} tone={c?.overdue_defenses ? 'danger' : undefined} sub={c?.overdue_defenses ? `${c.overdue_defenses} tanesinin süresi geçti` : 'süresi geçen yok'} />
        <Stat label="Kurul bekleyen" icon={<ShieldAlert />} loading={isLoading} value={num(c?.proposed)} tone={c?.proposed ? 'warning' : undefined} sub="yaptırım önerisi" to="/disiplin/kurul" className="col-span-2 lg:col-span-1" />
      </div>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="Son kayıtlar" actions={<ButtonLink size="sm" variant="ghost" to="/disiplin/olaylar">Tümü</ButtonLink>} flush>
            {isLoading ? <div className="p-4"><Skeleton className="h-40" /></div> : !data?.recent.length ? (
              <EmptyState compact icon={<Gavel />} title="Henüz kayıt yok" description="İlk olayı ya da olumlu davranışı kaydedin." />
            ) : (
              <ul className="divide-y divide-line border-t border-line">
                {data.recent.map((r) => (
                  <li key={r.id}>
                    <Link to={`/disiplin/olaylar/${r.id}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 transition-colors hover:bg-surface-2">
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-[13.5px] font-medium text-ink">{r.students.map((s) => s.full_name).join(', ')}</span>
                        <span className="block truncate text-[12.5px] text-ink-2">{[...new Set(r.students.map((s) => s.behavior).filter(Boolean))].join(', ')} · {relative(r.occurred_at)}</span>
                      </span>
                      {r.kind === 'positive' ? <KindBadge kind="positive" /> : <SeverityBadge severity={r.severity} label={r.severity_label} />}
                      <StatusBadge status={r.status} label={r.status_label} map={INCIDENT_TONE} />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel title="Haftalık eğilim" description="Son 8 hafta · disiplin olayı ve olumlu davranış kaydı">
            {isLoading ? <Skeleton className="h-[220px]" /> : (
              <>
                <Legend className="mb-2" items={[{ label: 'Disiplin olayı', color: series[0]! }, { label: 'Olumlu davranış', color: series[1]! }]} />
                <div className="h-[210px]">
                  <ResponsiveContainer>
                    <BarChart data={data?.weekly ?? []} margin={{ top: 4, right: 0, left: 0, bottom: 0 }} barCategoryGap="24%">
                      <CartesianGrid {...gridProps} />
                      <XAxis dataKey="label" {...axisProps} dy={6} />
                      <YAxis {...axisProps} width={28} allowDecimals={false} />
                      <Tooltip cursor={{ fill: 'var(--surface-2)' }} content={<ChartTooltip formatLabel={(l) => `${l} haftası`} formatValue={(v) => num(v)} />} />
                      <Bar dataKey="incidents" name="Disiplin olayı" fill={series[0]} radius={[3, 3, 0, 0]} maxBarSize={20} />
                      <Bar dataKey="positives" name="Olumlu davranış" fill={series[1]} radius={[3, 3, 0, 0]} maxBarSize={20} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </>
            )}
          </Panel>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Panel title="Bekleyen savunmalar" flush>
              {!data?.pending_defenses.length ? <p className="px-4 pb-4 text-[13px] text-ink-3">Bekleyen savunma yok.</p> : (
                <ul className="divide-y divide-line border-t border-line">
                  {data.pending_defenses.map((d) => (
                    <li key={d.id}>
                      <Link to={`/disiplin/olaylar/${d.incident_id}`} className="flex items-center gap-2 px-4 py-2.5 text-[13px] hover:bg-surface-2">
                        <span className="min-w-0 flex-1 truncate text-ink">{d.student?.full_name}</span>
                        <Badge tone={d.overdue ? 'danger' : 'warning'}>{d.overdue ? 'Süresi geçti' : `Son ${date(d.due_on)}`}</Badge>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
            <Panel title="Bugün uzaklaştırmada" flush>
              {!data?.suspended_today.length ? <p className="px-4 pb-4 text-[13px] text-ink-3">Bugün uzaklaştırmada öğrenci yok.</p> : (
                <ul className="divide-y divide-line border-t border-line">
                  {data.suspended_today.map((s) => (
                    <li key={s.id}>
                      <Link to={`/disiplin/olaylar/${s.incident_id}`} className="flex items-center gap-2 px-4 py-2.5 text-[13px] hover:bg-surface-2">
                        <UserX className="size-4 shrink-0 text-danger" />
                        <span className="min-w-0 flex-1 truncate text-ink">{s.student?.full_name}</span>
                        <span className="shrink-0 tabular text-[12px] text-ink-3">{date(s.starts_on)} – {date(s.ends_on)}</span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          </div>
        </div>

        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="Dikkat gerektirenler" description={settings ? `Dönem net puanı · dikkat ${settings.threshold_watch}, uyarı ${settings.threshold_warning}, kritik ${settings.threshold_critical}` : undefined}
            actions={<Users className="size-4 text-ink-3" />} flush>
            {isLoading ? <div className="p-4"><Skeleton className="h-32" /></div> : !data?.attention.length ? (
              <EmptyState compact icon={<ThumbsUp />} title="Eşiği aşan öğrenci yok" description="Dönem puanı dikkat eşiğinin altında." />
            ) : (
              <ul className="divide-y divide-line border-t border-line">
                {data.attention.map((a) => (
                  <li key={a.student_id}>
                    <Link to={`/ogrenciler/${a.student_id}`} className="block px-4 py-2.5 hover:bg-surface-2">
                      <span className="flex items-center gap-2">
                        <span className="min-w-0 flex-1 truncate text-[13.5px] font-medium text-ink">{a.full_name}</span>
                        <LevelBadge level={a.level} label={a.level_label} />
                      </span>
                      <span className="mt-1.5 flex items-center gap-2">
                        <PointsMeter net={a.net} settings={settings} className="flex-1" />
                        <span className="w-24 shrink-0 text-right text-[12px] tabular text-ink-2">{a.net} puan · {a.incidents} olay</span>
                      </span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel title="Yaklaşan kurul" actions={<ButtonLink size="sm" variant="ghost" to="/disiplin/kurul">Kurul</ButtonLink>}>
            {!data?.meetings.length ? <p className="text-[13px] text-ink-3">Planlanmış toplantı yok{c?.proposed ? ` · ${c.proposed} öneri kurul bekliyor` : ''}.</p> : (
              <ul className="flex flex-col gap-2">
                {data.meetings.map((m) => (
                  <li key={m.id}>
                    <Link to={`/disiplin/kurul/${m.id}`} className="block rounded-[var(--radius-md)] bg-surface-2 px-3 py-2.5 ring-1 ring-line hover:ring-primary/40">
                      <span className="block text-[13.5px] font-medium text-ink">{m.title}</span>
                      <span className="block text-[12.5px] text-ink-2">{dateTime(m.scheduled_at)} · {m.pending_count ?? 0} bekleyen madde</span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel title="En sık davranışlar" description="Son 30 gün">
            {!data?.top_behaviors.length ? <p className="text-[13px] text-ink-3">Kayıt yok.</p> : (
              <ul className="flex flex-col gap-2">
                {data.top_behaviors.map((b) => {
                  const max = Math.max(1, ...data.top_behaviors.map((x) => x.count))
                  return (
                    <li key={b.id}>
                      <Link to={`/disiplin/olaylar?behavior_id=${b.id}`} className="flex items-center gap-3 text-[12.5px] hover:text-primary">
                        <span className="w-36 shrink-0 truncate text-ink-2">{b.name}</span>
                        <span className="relative h-2 flex-1 overflow-hidden rounded-full bg-surface-2"><span className="absolute inset-y-0 left-0 rounded-full bg-[var(--series-1)]" style={{ width: `${(b.count / max) * 100}%` }} /></span>
                        <span className="w-6 text-right tabular text-ink">{b.count}</span>
                      </Link>
                    </li>
                  )
                })}
              </ul>
            )}
          </Panel>
        </div>
      </div>

      <IncidentFormDrawer open={form !== null} defaultKind={form ?? 'negative'} onClose={() => setForm(null)} />
    </div>
  )
}
