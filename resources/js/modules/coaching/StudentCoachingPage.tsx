import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarDays, CheckCircle2, ClipboardList, Pencil, Plus, Trash2, UserCheck } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, dateTime } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Checkbox } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import type { CoachingOptions, PlanRow, SessionRow, StudentCoachingSummary } from './types'
import { AssignCoachModal } from './AssignCoachModal'
import { SessionFormDrawer } from './SessionFormDrawer'
import { PlanFormDrawer } from './PlanFormDrawer'

export default function StudentCoachingPage() {
  const { id } = useParams()
  const studentId = Number(id)
  const can = useCan()
  const manage = can('coaching.manage')
  const qc = useQueryClient()
  const navigate = useNavigate()

  const [assignOpen, setAssignOpen] = useState(false)
  const [removeCoach, setRemoveCoach] = useState(false)
  const [sessionForm, setSessionForm] = useState<{ id: number | null } | null>(null)
  const [deleteSession, setDeleteSession] = useState<number | null>(null)
  const [planForm, setPlanForm] = useState<{ plan: PlanRow | null } | null>(null)
  const [deletePlan, setDeletePlan] = useState<number | null>(null)

  const summary = useQuery({ queryKey: ['coaching', 'summary', studentId], queryFn: () => api.get<StudentCoachingSummary>(`/coaching/students/${studentId}/summary`) })
  const sessions = useQuery({ queryKey: ['coaching', 'sessions', 'student', studentId], queryFn: () => api.get<{ data: SessionRow[] }>('/coaching/sessions', { student_id: studentId, per_page: 50 }).then((r) => r.data) })
  const plans = useQuery({ queryKey: ['coaching', 'plans', 'student', studentId], queryFn: () => api.get<{ data: PlanRow[] }>(`/coaching/students/${studentId}/plans`).then((r) => r.data) })
  const options = useQuery({ queryKey: ['coaching', 'options'], queryFn: () => api.get<CoachingOptions>('/coaching/assignments/options'), staleTime: 5 * 60_000, enabled: manage })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['coaching'] })

  const unassign = useMutation({
    mutationFn: () => api.delete<{ message: string }>(`/coaching/students/${studentId}/coach`),
    onSuccess: (res) => { toast.success(res.message); setRemoveCoach(false); invalidate() },
    onError: (e) => { if (e instanceof ApiError) toast.error(e.firstError()) },
  })
  const removeSession = useMutation({
    mutationFn: (sid: number) => api.delete<{ message: string }>(`/coaching/sessions/${sid}`),
    onSuccess: (res) => { toast.success(res.message); setDeleteSession(null); invalidate() },
    onError: (e) => { if (e instanceof ApiError) toast.error(e.firstError()) },
  })
  const removePlan = useMutation({
    mutationFn: (pid: number) => api.delete<{ message: string }>(`/coaching/plans/${pid}`),
    onSuccess: (res) => { toast.success(res.message); setDeletePlan(null); invalidate() },
    onError: (e) => { if (e instanceof ApiError) toast.error(e.firstError()) },
  })
  const toggleItem = useMutation({
    mutationFn: ({ itemId, done }: { itemId: number; done: boolean }) => api.post<{ message: string }>(`/coaching/plan-items/${itemId}/toggle`, { is_done: done }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['coaching', 'plans', 'student', studentId] }),
    onError: (e) => { if (e instanceof ApiError) toast.error(e.firstError()) },
  })

  const s = summary.data
  const studentName = s?.student.full_name

  return (
    <div className="animate-fade-in">
      <PageHeader
        title={studentName ?? 'Öğrenci koçluğu'}
        description={s ? [s.student.student_no && `No: ${s.student.student_no}`, s.student.school_grade, s.student.field].filter(Boolean).join(' · ') : undefined}
        breadcrumbs={[{ label: 'Koçluk öğrencileri', to: '/kocluk/ogrenciler' }, { label: studentName ?? '—' }]}
        actions={manage && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setSessionForm({ id: null })}>Yeni görüşme</Button>}
      />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Koç kartı */}
        <Panel title="Koç">
          {summary.isLoading ? <Skeleton className="h-16" /> : (
            <div className="flex flex-col gap-3">
              {s?.coach ? (
                <div className="flex items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <span className="grid size-9 place-items-center rounded-full bg-primary-soft text-primary"><UserCheck className="size-4.5" /></span>
                    <div>
                      <p className="text-[14px] font-medium text-ink">{s.coach.name}</p>
                      <p className="text-[12px] text-ink-3">Atandı: {date(s.coach.assigned_at)}</p>
                    </div>
                  </div>
                </div>
              ) : (
                <EmptyState compact icon={<UserCheck />} title="Koç atanmadı" description={manage ? 'Aşağıdan bir koç atayın.' : undefined} />
              )}
              {manage && (
                <div className="flex gap-2">
                  <Button size="sm" variant={s?.coach ? 'secondary' : 'primary'} onClick={() => setAssignOpen(true)}>{s?.coach ? 'Koç değiştir' : 'Koç ata'}</Button>
                  {s?.coach && <Button size="sm" variant="ghost" onClick={() => setRemoveCoach(true)}>Kaldır</Button>}
                </div>
              )}
            </div>
          )}
        </Panel>

        {/* Görüşmeler */}
        <div className="lg:col-span-2">
          <Panel title="Koçluk görüşmeleri" actions={manage && <Button size="xs" icon={<Plus className="size-3.5" />} onClick={() => setSessionForm({ id: null })}>Görüşme ekle</Button>}>
            {sessions.isLoading ? <Skeleton className="h-24" /> : (sessions.data?.length ?? 0) === 0 ? (
              <EmptyState compact icon={<CalendarDays />} title="Görüşme kaydı yok" />
            ) : (
              <ol className="flex flex-col gap-3">
                {sessions.data!.map((m) => (
                  <li key={m.id} className="relative pl-4 border-l-2 border-line">
                    <div className="absolute -left-[5px] top-1.5 size-2 rounded-full bg-primary" />
                    <div className="flex flex-wrap items-center gap-2 text-[12.5px]">
                      <span className="text-ink-2 tabular">{dateTime(m.held_at)}</span>
                      {m.coach?.name && <span className="text-ink-3">· {m.coach.name}</span>}
                      {(m.focus || m.motivation) && <span className="text-ink-3">· {m.focus ? `Odak ${m.focus}/5` : ''}{m.focus && m.motivation ? ' · ' : ''}{m.motivation ? `Motivasyon ${m.motivation}/5` : ''}</span>}
                      {manage && (
                        <span className="ml-auto flex gap-1">
                          <button onClick={() => setSessionForm({ id: m.id })} className="text-ink-3 hover:text-ink" aria-label="Düzenle"><Pencil className="size-3.5" /></button>
                          <button onClick={() => setDeleteSession(m.id)} className="text-ink-3 hover:text-danger" aria-label="Sil"><Trash2 className="size-3.5" /></button>
                        </span>
                      )}
                    </div>
                    {m.topics && <p className="mt-1 text-[13.5px] text-ink-2 whitespace-pre-line">{m.topics}</p>}
                    {m.action_items && <p className="mt-0.5 text-[12.5px] text-ink-3"><span className="font-medium">Yapılacaklar:</span> {m.action_items}</p>}
                    {m.next_session_on && <p className="mt-0.5 text-[12px] text-ink-3">Sonraki görüşme: {date(m.next_session_on)}</p>}
                  </li>
                ))}
              </ol>
            )}
          </Panel>
        </div>
      </div>

      {/* Haftalık planlar */}
      <div className="mt-4">
        <Panel title="Haftalık çalışma planları" actions={manage && <Button size="xs" icon={<Plus className="size-3.5" />} onClick={() => setPlanForm({ plan: null })}>Plan ekle</Button>}>
          {plans.isLoading ? <Skeleton className="h-24" /> : (plans.data?.length ?? 0) === 0 ? (
            <EmptyState compact icon={<ClipboardList />} title="Plan yok" description={manage ? 'Bu öğrenci için haftalık çalışma planı oluşturun.' : undefined} />
          ) : (
            <div className="flex flex-col gap-4">
              {plans.data!.map((p) => (
                <div key={p.id} className="rounded-[var(--radius-md)] ring-1 ring-line p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      <Badge tone="neutral">{date(p.week_start)} haftası</Badge>
                      <span className="text-[12.5px] text-ink-3">{p.items_done}/{p.items_total} tamamlandı</span>
                    </div>
                    {manage && (
                      <div className="flex gap-1">
                        <button onClick={() => setPlanForm({ plan: p })} className="text-ink-3 hover:text-ink" aria-label="Düzenle"><Pencil className="size-3.5" /></button>
                        <button onClick={() => setDeletePlan(p.id)} className="text-ink-3 hover:text-danger" aria-label="Sil"><Trash2 className="size-3.5" /></button>
                      </div>
                    )}
                  </div>
                  {p.items_total > 0 && <ProgressBar value={p.progress} tone={p.progress >= 100 ? 'success' : 'primary'} className="mt-2" />}
                  {p.note && <p className="mt-2 text-[12.5px] text-ink-3">{p.note}</p>}
                  <ul className="mt-2 flex flex-col gap-1.5">
                    {p.items.map((it) => (
                      <li key={it.id} className="flex items-center gap-2 text-[13px]">
                        <Checkbox checked={it.is_done} disabled={!manage || toggleItem.isPending} onChange={(v) => toggleItem.mutate({ itemId: it.id, done: v })}
                          label={<span className={it.is_done ? 'text-ink-3 line-through' : 'text-ink'}>{it.subject}{it.target ? ` — ${it.target} ${it.target_kind_label ?? ''}` : ''}</span>} />
                        {it.is_done && <CheckCircle2 className="size-3.5 text-success" />}
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          )}
        </Panel>
      </div>

      {/* Modallar / çekmeceler */}
      {assignOpen && s && (
        <AssignCoachModal open onClose={() => setAssignOpen(false)} studentId={studentId} studentName={studentName} currentCoachId={s.coach?.id ?? null}
          onSaved={() => { setAssignOpen(false); invalidate() }} />
      )}
      <SessionFormDrawer open={!!sessionForm} onClose={() => setSessionForm(null)} sessionId={sessionForm?.id} studentId={studentId} studentName={studentName} options={options.data}
        onSaved={() => { setSessionForm(null); invalidate() }} />
      <PlanFormDrawer open={!!planForm} onClose={() => setPlanForm(null)} studentId={studentId} studentName={studentName} plan={planForm?.plan}
        onSaved={() => { setPlanForm(null); invalidate() }} />

      <ConfirmDialog open={removeCoach} onClose={() => setRemoveCoach(false)} onConfirm={() => unassign.mutate()} loading={unassign.isPending}
        danger confirmLabel="Kaldır" title="Koç atamasını kaldır" description="Öğrencinin aktif koçu kaldırılacak. Geçmiş kayıtlar korunur." />
      <ConfirmDialog open={deleteSession !== null} onClose={() => setDeleteSession(null)} onConfirm={() => deleteSession && removeSession.mutate(deleteSession)} loading={removeSession.isPending}
        danger confirmLabel="Sil" title="Görüşmeyi sil" description="Bu koçluk görüşmesi silinecek." />
      <ConfirmDialog open={deletePlan !== null} onClose={() => setDeletePlan(null)} onConfirm={() => deletePlan && removePlan.mutate(deletePlan)} loading={removePlan.isPending}
        danger confirmLabel="Sil" title="Planı sil" description="Bu haftalık çalışma planı ve kalemleri silinecek." />
      {summary.isError && <div className="mt-4"><Button variant="ghost" onClick={() => navigate('/kocluk/ogrenciler')}>Koçluk öğrencilerine dön</Button></div>}
    </div>
  )
}
