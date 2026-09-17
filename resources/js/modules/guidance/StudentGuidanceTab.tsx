import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Target, Users } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { dateTime, num } from '@/lib/format'
import { useCan } from '@/app/auth'
import { Panel } from '@/components/ui/layout'
import { Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import type { GoalCompare, MeetingOptions, MeetingRow, StudentGoal } from './types'
import { MeetingFormDrawer } from './MeetingFormDrawer'
import { GoalFormDrawer } from './GoalFormDrawer'

function CompareLine({ label, c }: { label: string; c: GoalCompare }) {
  if (c.target === null) return null
  const tone = c.pct === null ? 'primary' : c.pct >= 90 ? 'success' : c.pct >= 70 ? 'warning' : 'danger'
  return (
    <div>
      <div className="flex items-center justify-between text-[12px] text-ink-2">
        <span>{label} · hedef {num(c.target, 1)}, gerçekleşen {c.actual !== null ? num(c.actual, 1) : '—'}</span>
        {c.pct !== null && <span className="tabular font-medium">%{num(c.pct, 0)}</span>}
      </div>
      <ProgressBar value={c.pct ?? 0} tone={tone} className="mt-1" />
    </div>
  )
}

/** Öğrenci 360° profiline gömülen rehberlik sekmesi: görüşme geçmişi + hedefler. */
export function StudentGuidanceTab({ studentId, studentName }: { studentId: number; studentName?: string }) {
  const can = useCan()
  const qc = useQueryClient()
  const [meetingForm, setMeetingForm] = useState<{ id: number | null } | null>(null)
  const [goalForm, setGoalForm] = useState<{ goal?: StudentGoal } | null>(null)

  const meetings = useQuery({
    queryKey: ['guidance', 'meetings', 'student', studentId],
    queryFn: () => api.get<Paginated<MeetingRow>>('/guidance/meetings', { student_id: studentId, per_page: 20 }),
    enabled: can('guidance.view'),
  })
  const goals = useQuery({
    queryKey: ['guidance', 'goals', 'student', studentId],
    queryFn: () => api.get<{ data: StudentGoal[] }>(`/guidance/students/${studentId}/goals`).then((r) => r.data),
    enabled: can('guidance.view'),
  })
  const options = useQuery({ queryKey: ['guidance', 'meetings', 'options'], queryFn: () => api.get<MeetingOptions>('/guidance/meetings/options'), enabled: can('guidance.view'), staleTime: 5 * 60_000 })

  if (!can('guidance.view')) return null

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['guidance', 'meetings', 'student', studentId] })
    qc.invalidateQueries({ queryKey: ['guidance', 'goals', 'student', studentId] })
  }

  return (
    <div className="flex flex-col gap-4">
      <Panel title="Hedefler" actions={can('guidance.manage') && <Button size="xs" icon={<Plus className="size-3.5" />} onClick={() => setGoalForm({})}>Hedef ekle</Button>}>
        {goals.isLoading ? <Skeleton className="h-20" /> : (goals.data?.length ?? 0) === 0 ? (
          <EmptyState compact icon={<Target />} title="Hedef tanımlanmadı" />
        ) : (
          <div className="flex flex-col gap-4">
            {goals.data!.map((g) => (
              <div key={g.id} className="rounded-[var(--radius-md)] ring-1 ring-line p-3">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="text-[13.5px] font-medium text-ink">{[g.university, g.department].filter(Boolean).join(' — ') || 'Genel hedef'}</p>
                    {g.target_rank && <p className="text-[12px] text-ink-3">Hedef sıralama: {num(g.target_rank)}</p>}
                  </div>
                  {can('guidance.manage') && <Button size="xs" variant="ghost" onClick={() => setGoalForm({ goal: g })}>Düzenle</Button>}
                </div>
                {g.progress && (
                  <div className="mt-2.5 flex flex-col gap-2">
                    <CompareLine label="TYT" c={g.progress.tyt} />
                    <CompareLine label="AYT" c={g.progress.ayt} />
                    {g.progress.subjects.map((s) => <CompareLine key={s.code} label={s.code} c={s} />)}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </Panel>

      <Panel title="Rehberlik görüşmeleri" actions={can('guidance.manage') && <Button size="xs" icon={<Plus className="size-3.5" />} onClick={() => setMeetingForm({ id: null })}>Görüşme ekle</Button>}>
        {meetings.isLoading ? <Skeleton className="h-20" /> : (meetings.data?.data.length ?? 0) === 0 ? (
          <EmptyState compact icon={<Users />} title="Görüşme kaydı yok" />
        ) : (
          <ol className="flex flex-col gap-3">
            {meetings.data!.data.map((m) => (
              <li key={m.id} className="relative pl-4 border-l-2 border-line cursor-pointer" onClick={() => m.id && setMeetingForm({ id: m.id })}>
                <div className="absolute -left-[5px] top-1 size-2 rounded-full bg-primary" />
                <div className="flex flex-wrap items-center gap-2 text-[12.5px]">
                  <Badge tone="neutral">{m.kind_label}</Badge>
                  <span className="text-ink-3">{dateTime(m.met_at)}</span>
                  {m.counselor?.name && <span className="text-ink-3">· Rehber öğretmen: {m.counselor.name}</span>}
                </div>
                <p className="mt-1 text-[13.5px] text-ink-2">{m.summary}</p>
                {m.goal && <p className="mt-0.5 text-[12.5px] text-ink-3">Hedef: {m.goal}</p>}
                {(m.motivation || m.study_discipline) && (
                  <p className="mt-0.5 text-[12px] text-ink-3">
                    {m.motivation ? `Motivasyon ${m.motivation}/5` : ''}{m.motivation && m.study_discipline ? ' · ' : ''}{m.study_discipline ? `Çalışma düzeni ${m.study_discipline}/5` : ''}
                  </p>
                )}
              </li>
            ))}
          </ol>
        )}
      </Panel>

      <MeetingFormDrawer open={!!meetingForm} onClose={() => setMeetingForm(null)} meetingId={meetingForm?.id} studentId={studentId} studentName={studentName} options={options.data}
        onSaved={() => { setMeetingForm(null); invalidate() }} />
      <GoalFormDrawer open={!!goalForm} onClose={() => setGoalForm(null)} studentId={studentId} studentName={studentName} goal={goalForm?.goal}
        onSaved={() => { setGoalForm(null); invalidate() }} />
    </div>
  )
}
