import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Gavel, Plus, ThumbsUp, UserX } from 'lucide-react'
import { api } from '@/lib/api'
import { date, dateTime } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import type { Defense, Sanction, Standing } from './types'
import { DEFENSE_TONE, INCIDENT_TONE, LevelBadge, PointsMeter, SANCTION_TONE, StatusBadge, useDisciplineOptions } from './ui'
import { IncidentFormDrawer } from './IncidentFormDrawer'

type Data = {
  standing: Standing & { term: { from: string; to: string; name: string } }
  suspended_today: boolean
  incidents: { id: number; incident_no: string; kind: string; occurred_at: string; status: string; status_label: string; outcome: string | null; severity: string; severity_label: string
    role: string; role_label: string; behavior: string | null; penalty_points: number; merit_points: number; location: string | null; reporter: string | null }[]
  sanctions: Sanction[]
  defenses: Defense[]
}

/** Öğrenci 360° profiline gömülen "Disiplin" sekmesi. */
export function StudentDisciplineTab({ studentId, studentName, studentNo }: { studentId: number; studentName: string; studentNo?: string }) {
  const can = useCan()
  const { data: opt } = useDisciplineOptions()
  const [form, setForm] = useState<null | 'negative' | 'positive'>(null)
  const { data, isLoading } = useQuery({
    queryKey: ['discipline', 'student', studentId],
    queryFn: () => api.get<{ data: Data }>(`/discipline/students/${studentId}`).then((r) => r.data),
  })
  if (isLoading || !data) return <Skeleton className="h-48" />
  const s = data.standing
  const active = data.sanctions.filter((x) => ['active', 'appealed', 'proposed'].includes(x.status))
  const pending = data.defenses.filter((d) => d.status === 'requested')

  return (
    <div className="flex flex-col gap-4">
      {data.suspended_today && <Alert tone="danger" icon={<UserX />} title="Öğrenci bugün uzaklaştırmada">Yoklamada “Disiplin: geçici uzaklaştırma” notuyla işlenir.</Alert>}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[320px_minmax(0,1fr)]">
        <Panel title="Dönem durumu" description={s.term.name}
          actions={can('discipline.create') && (
            <div className="flex gap-1">
              <Button size="sm" variant="success" aria-label="Olumlu davranış" icon={<ThumbsUp className="size-3.5" />} onClick={() => setForm('positive')} />
              <Button size="sm" variant="primary" icon={<Plus className="size-3.5" />} onClick={() => setForm('negative')}>Olay</Button>
            </div>
          )}>
          <div className="flex items-end justify-between gap-2">
            <div>
              <p className="text-[12px] text-ink-3">Net disiplin puanı</p>
              <p className={cn('text-[28px] font-semibold leading-tight tabular', s.level === 'critical' ? 'text-danger' : s.level === 'warning' ? 'text-warning' : 'text-ink')}>{s.net}</p>
            </div>
            <LevelBadge level={s.level} label={s.level_label} />
          </div>
          <PointsMeter net={s.net} settings={opt?.settings} className="mt-2" />
          <dl className="mt-3 grid grid-cols-2 gap-2 text-[12.5px]">
            <div className="rounded-[var(--radius-sm)] bg-danger-soft/60 px-2.5 py-1.5"><dt className="text-ink-3">Ceza puanı</dt><dd className="font-semibold tabular text-danger">{s.penalty}</dd></div>
            <div className="rounded-[var(--radius-sm)] bg-success-soft/60 px-2.5 py-1.5"><dt className="text-ink-3">Olumlu puan</dt><dd className="font-semibold tabular text-success">{s.merit}</dd></div>
            <div className="rounded-[var(--radius-sm)] bg-surface-2 px-2.5 py-1.5"><dt className="text-ink-3">Olay</dt><dd className="font-semibold tabular">{s.incidents}</dd></div>
            <div className="rounded-[var(--radius-sm)] bg-surface-2 px-2.5 py-1.5"><dt className="text-ink-3">Takdir</dt><dd className="font-semibold tabular">{s.positives}</dd></div>
          </dl>
          {(active.length > 0 || pending.length > 0) && (
            <div className="mt-3 flex flex-col gap-1.5">
              {active.map((x) => (
                <Link key={x.id} to={`/disiplin/olaylar/${x.incident_id}`} className="flex items-center justify-between gap-2 rounded-[var(--radius-sm)] px-2 py-1 text-[12.5px] ring-1 ring-line hover:bg-surface-2">
                  <span className="truncate">{x.type?.name}{x.starts_on ? ` · ${date(x.starts_on)}–${date(x.ends_on)}` : ''}</span>
                  <Badge tone={SANCTION_TONE[x.status]}>{x.status_label}</Badge>
                </Link>
              ))}
              {pending.map((d) => (
                <Link key={d.id} to={`/disiplin/olaylar/${d.incident_id}`} className="flex items-center justify-between gap-2 rounded-[var(--radius-sm)] px-2 py-1 text-[12.5px] ring-1 ring-line hover:bg-surface-2">
                  <span className="truncate">Savunma bekleniyor</span>
                  <Badge tone={d.overdue ? 'danger' : DEFENSE_TONE.requested}>son {date(d.due_on)}</Badge>
                </Link>
              ))}
            </div>
          )}
        </Panel>

        <Panel title="Kayıtlar" flush>
          {data.incidents.length === 0 ? (
            <EmptyState compact icon={<Gavel />} title="Disiplin kaydı yok" description="Olay ya da olumlu davranış kaydedildiğinde burada görünür." />
          ) : (
            <ul className="divide-y divide-line border-t border-line">
              {data.incidents.map((i) => (
                <li key={i.id}>
                  <Link to={`/disiplin/olaylar/${i.id}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 hover:bg-surface-2">
                    <span className={cn('w-12 shrink-0 text-right text-[13px] font-semibold tabular', i.kind === 'positive' ? 'text-success' : i.penalty_points ? 'text-danger' : 'text-ink-3')}>
                      {i.kind === 'positive' ? `+${i.merit_points}` : i.penalty_points ? `−${i.penalty_points}` : '0'}
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[13.5px] text-ink">{i.behavior ?? i.role_label}{i.role !== 'involved' && <Badge className="ml-1.5">{i.role_label}</Badge>}</span>
                      <span className="block text-[12px] tabular text-ink-3">{dateTime(i.occurred_at)} · {i.incident_no}{i.reporter ? ` · ${i.reporter}` : ''}</span>
                    </span>
                    {i.kind === 'positive' ? <Badge tone="success">Olumlu</Badge> : <StatusBadge status={i.status} label={i.outcome === 'unfounded' ? 'Asılsız' : i.status_label} map={INCIDENT_TONE} />}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <IncidentFormDrawer open={form !== null} defaultKind={form ?? 'negative'} student={{ id: studentId, full_name: studentName, student_no: studentNo ?? '' }} onClose={() => setForm(null)} />
    </div>
  )
}
