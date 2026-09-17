import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlarmClock, Check, ClipboardList } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { PageHeader, Tabs } from '@/components/ui/layout'
import { EmptyState, Badge, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/form'
import { cn } from '@/lib/cn'
import type { Task } from './types'

type Filter = 'open' | 'overdue' | 'today' | 'upcoming' | 'done'

const tabs: { value: Filter; label: string }[] = [
  { value: 'open', label: 'Açık' },
  { value: 'overdue', label: 'Geciken' },
  { value: 'today', label: 'Bugün' },
  { value: 'upcoming', label: 'Yaklaşan' },
  { value: 'done', label: 'Tamamlanan' },
]

export default function MyTasks() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [filter, setFilter] = useState<Filter>('open')
  const [snoozeId, setSnoozeId] = useState<number | null>(null)
  const [snoozeAt, setSnoozeAt] = useState('')

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['tasks', 'mine', filter],
    queryFn: () => api.get<Paginated<Task> & { meta: { counts: Record<string, number> } }>('/tasks/mine', { filter, per_page: 50 }),
    placeholderData: keepPreviousData,
  })

  const complete = useMutation({
    mutationFn: (id: number) => api.post(`/tasks/${id}/complete`),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['tasks', 'mine'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.'),
  })
  const reopen = useMutation({
    mutationFn: (id: number) => api.post(`/tasks/${id}/reopen`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tasks', 'mine'] }),
  })
  const snooze = useMutation({
    mutationFn: () => api.post(`/tasks/${snoozeId}/snooze`, { due_at: snoozeAt }),
    onSuccess: () => { toast.success('Görev ertelendi.'); setSnoozeId(null); setSnoozeAt(''); qc.invalidateQueries({ queryKey: ['tasks', 'mine'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Ertelenemedi.'),
  })

  const counts = data?.meta.counts ?? {}

  return (
    <div className="animate-fade-in">
      <PageHeader title="Görevlerim" description="CRM aramaları ve rehberlik takip hatırlatmaları" />

      <Tabs value={filter} onChange={setFilter} className="mb-4" tabs={tabs.map((t) => ({ ...t, count: t.value === 'done' ? null : (counts[t.value] ?? null) }))} />

      {isLoading ? (
        <div className="space-y-2">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-14" />)}</div>
      ) : (data?.data.length ?? 0) === 0 ? (
        <EmptyState icon={<ClipboardList />} title="Bu grupta görev yok" description="Yeni bir hatırlatma aday ya da öğrenci kaydından eklenebilir." />
      ) : (
        <ul className={cn('flex flex-col gap-2', isFetching && 'opacity-60')}>
          {data!.data.map((t) => {
            const overdue = !t.completed_at && t.due_at && new Date(t.due_at).getTime() < Date.now()
            return (
              <li key={t.id} className="flex items-center gap-3 rounded-[var(--radius-lg)] bg-surface ring-1 ring-line px-4 py-3">
                <button
                  type="button"
                  onClick={() => (t.completed_at ? reopen.mutate(t.id) : complete.mutate(t.id))}
                  className={cn('grid size-6 shrink-0 place-items-center rounded-full ring-1 transition-colors', t.completed_at ? 'bg-success text-white ring-success' : 'ring-line-strong hover:ring-primary hover:bg-primary-soft')}
                  aria-label={t.completed_at ? 'Yeniden aç' : 'Tamamla'}
                >
                  {t.completed_at && <Check className="size-3.5" />}
                </button>
                <div className="min-w-0 flex-1">
                  <p className={cn('text-[13.5px] font-medium', t.completed_at ? 'text-ink-3 line-through' : 'text-ink')}>{t.title}</p>
                  <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px]">
                    {t.taskable_label && (
                      <button type="button" className="text-primary hover:underline" onClick={() => t.taskable_link && navigate(t.taskable_link)}>{t.taskable_label}</button>
                    )}
                    {t.due_at && <span className={cn(overdue ? 'text-danger font-medium' : 'text-ink-3')}>{relative(t.due_at)} · {dateTime(t.due_at)}</span>}
                    {t.priority === 'high' && <Badge tone="danger">Öncelikli</Badge>}
                  </div>
                </div>
                {!t.completed_at && (
                  <Button size="icon-sm" variant="ghost" onClick={() => { setSnoozeId(t.id); setSnoozeAt('') }} aria-label="Ertele">
                    <AlarmClock className="size-4" />
                  </Button>
                )}
              </li>
            )
          })}
        </ul>
      )}

      {snoozeId !== null && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/25 p-4" onClick={() => setSnoozeId(null)}>
          <div className="w-full max-w-xs rounded-[var(--radius-lg)] bg-surface ring-1 ring-line p-4 shadow-[var(--shadow-pop)]" onClick={(e) => e.stopPropagation()}>
            <p className="mb-3 text-[13.5px] font-semibold text-ink">Görevi ertele</p>
            <Input type="datetime-local" value={snoozeAt} onChange={(e) => setSnoozeAt(e.target.value)} autoFocus />
            <div className="mt-3 flex justify-end gap-2">
              <Button size="sm" variant="ghost" onClick={() => setSnoozeId(null)}>Vazgeç</Button>
              <Button size="sm" variant="primary" disabled={!snoozeAt} loading={snooze.isPending} onClick={() => snooze.mutate()}>Ertele</Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
