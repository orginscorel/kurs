import { Compass, Target } from 'lucide-react'
import { date, dateTime, num } from '@/lib/format'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { Panel } from '@/components/ui/layout'
import { usePortal, useVoice } from '../api'
import { PortalTitle } from '../ui'

type Data = {
  counselor: string | null
  target: { university: string | null; department: string | null }
  goals: { id: number; university: string | null; department: string | null; target_rank: number | null; target_tyt_net: string | null; target_ayt_net: string | null; subject_targets: Record<string, number | string> | null }[]
  meetings: { id: number; met_at: string; kind_label: string; summary: string; goal: string | null; next_meeting_on: string | null; counselor: string | null }[]
}

export default function PortalGuidance() {
  const { data, isLoading } = usePortal<Data>('guidance', '/portal/guidance')
  const v = useVoice()
  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-32" /><Skeleton className="h-64" /></div>
  const goal = data.goals[0]
  const next = data.meetings.find((m) => m.next_meeting_on)?.next_meeting_on

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Rehberlik" description={data.counselor ? v(`Rehber öğretmenin: ${data.counselor}`, `Rehber öğretmeni: ${data.counselor}`) : v('Hedeflerin ve seninle paylaşılan görüşme özetleri', 'Hedefler ve sizinle paylaşılan görüşme özetleri')} />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Panel title={<span className="inline-flex items-center gap-1.5"><Target className="size-4 text-primary" /> {v('Hedefim', 'Hedefi')}</span>}>
          {goal || data.target.university || data.target.department ? (
            <div className="flex flex-col gap-3">
              <div>
                <p className="text-[16px] font-semibold">{goal?.department ?? data.target.department ?? '—'}</p>
                <p className="text-[14px] text-ink-2">{goal?.university ?? data.target.university ?? ''}</p>
              </div>
              {goal && (
                <div className="grid grid-cols-3 gap-2 text-center">
                  {[
                    ['Hedef sıralama', goal.target_rank ? num(goal.target_rank) : '—'],
                    ['TYT net', goal.target_tyt_net ? num(goal.target_tyt_net) : '—'],
                    ['AYT net', goal.target_ayt_net ? num(goal.target_ayt_net) : '—'],
                  ].map(([l, v]) => (
                    <div key={l} className="rounded-[var(--radius-sm)] bg-surface-2 px-2 py-2">
                      <p className="text-[12.5px] text-ink-3">{l}</p>
                      <p className="text-[15px] font-semibold tabular">{v}</p>
                    </div>
                  ))}
                </div>
              )}
              {goal?.subject_targets && Object.keys(goal.subject_targets).length > 0 && (
                <ul className="flex flex-wrap gap-1.5 text-[12.5px]">
                  {Object.entries(goal.subject_targets).map(([k, v]) => (
                    <li key={k} className="rounded-full bg-primary-soft px-2.5 py-1 text-primary-ink">{k}: <b className="tabular">{String(v)}</b></li>
                  ))}
                </ul>
              )}
            </div>
          ) : (
            <p className="text-[14px] text-ink-3">{v('Henüz hedef belirlenmemiş. Rehber öğretmeninle birlikte belirleyebilirsin.', 'Henüz hedef belirlenmemiş.')}</p>
          )}
        </Panel>
        <Panel title="Sıradaki görüşme">
          {next ? (
            <p className="text-[16px] font-semibold">{date(next, 'day')}</p>
          ) : (
            <p className="text-[14px] text-ink-3">Planlanmış bir görüşme görünmüyor.</p>
          )}
          <p className="mt-2 text-[12.5px] text-ink-3">{v('Görüşme özetleri yalnız rehber öğretmenin seninle paylaştığında burada görünür.', 'Görüşme özetleri yalnız rehber öğretmen velilerle paylaştığında burada görünür.')}</p>
        </Panel>
      </div>

      <Panel title="Görüşme özetleri" flush>
        {data.meetings.length === 0 ? (
          <EmptyState compact icon={<Compass />} title="Paylaşılan görüşme özeti yok" />
        ) : (
          <ul>
            {data.meetings.map((m) => (
              <li key={m.id} className="border-t border-line px-4 py-3">
                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                  <p className="text-[14.5px] font-medium">{m.kind_label}</p>
                  <span className="text-[12.5px] text-ink-3">{dateTime(m.met_at)}{m.counselor ? ` · ${m.counselor}` : ''}</span>
                </div>
                <p className="mt-1 whitespace-pre-line text-[14px] text-ink-2">{m.summary}</p>
                {m.goal && <p className="mt-1.5 text-[12.5px]"><span className="text-ink-3">Hedef:</span> {m.goal}</p>}
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  )
}
