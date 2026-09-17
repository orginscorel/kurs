import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarDays, Pencil, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { time } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { ConfirmDialog } from '@/components/ui/overlay'
import { ColorChip, MiniStat } from './ui'
import { colorOf, phaseLabel, phaseTone, WEEKDAYS, type ClassroomDetailData } from './types'
import { ClassroomFormDrawer } from './AcademicStructure'

export default function ClassroomDetail() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const manage = can('academic.manage')
  const { data, isLoading, error } = useQuery({ queryKey: ['classroom', id], queryFn: () => api.get<ClassroomDetailData>(`/classrooms/${id}`), refetchInterval: 60_000 })
  const [editOpen, setEditOpen] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['classroom', id] })
    qc.invalidateQueries({ queryKey: ['classrooms'] })
    qc.invalidateQueries({ queryKey: ['academic', 'options'] })
  }
  const del = useMutation({ mutationFn: () => api.delete<{ message: string }>(`/classrooms/${id}`), onSuccess: (r) => { toast.success(r.message); invalidate(); navigate('/akademik?sekme=derslikler') }, onError: (e) => { toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'); setConfirmDelete(false) } })

  if (error) return <EmptyState title="Derslik bulunamadı" action={<Button onClick={() => navigate('/akademik?sekme=derslikler')}>Dersliklere dön</Button>} />
  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-8 w-64" /><Skeleton className="h-24" /><Skeleton className="h-80" /></div>

  const c = data.classroom
  const current = data.today.find((s) => s.phase === 'in_progress')
  const next = data.today.find((s) => s.phase === 'upcoming')

  return (
    <div className="animate-fade-in">
      <PageHeader
        breadcrumbs={[{ label: 'Derslikler', to: '/akademik?sekme=derslikler' }, { label: c.name }]}
        title={<span className="flex items-center gap-2.5">{c.name}{!c.is_active && <Badge>Pasif</Badge>}</span>}
        description={`${c.kind_label} · ${c.capacity} kişi${c.floor ? ` · ${c.floor}` : ''}${c.features ? ` · ${c.features}` : ''}`}
        actions={
          <>
            {can('schedule.view') && <ButtonLink to={`/ders-programi?tur=classroom&id=${c.id}`} icon={<CalendarDays className="size-4" />}>Ders programı</ButtonLink>}
            {manage && <Button icon={<Pencil className="size-4" />} onClick={() => setEditOpen(true)}>Düzenle</Button>}
            {manage && <Button variant="ghost" size="icon" aria-label="Sil" onClick={() => setConfirmDelete(true)}><Trash2 className="size-4 text-danger" /></Button>}
          </>
        }
      />

      <div className="mb-4 grid grid-cols-2 lg:grid-cols-4 gap-3">
        <MiniStat label="Haftalık doluluk" value={`%${data.occupancy.weekly}`} sub={`${Math.round(data.occupancy.weekly_minutes / 60)} saat / 78 saat`} tone={data.occupancy.weekly >= 80 ? 'warning' : undefined} />
        <MiniStat label="Bugün" value={`${data.today.length} ders`} sub={data.study_today.length ? `+${data.study_today.length} etüt` : undefined} />
        <MiniStat label="Şu an" value={current ? current.class_group : 'Boş'} sub={current ? `${current.subject} · ${time(current.ends_at)}'e kadar` : next ? `Sıradaki ${time(next.starts_at)} ${next.class_group}` : 'Bugün başka ders yok'} tone={current ? 'success' : undefined} />
        <MiniStat label="Ana derslik" value={data.homeroom_of.length ? data.homeroom_of.map((g) => g.name).join(', ') : '—'} sub={data.homeroom_of.length ? 'sınıfları' : 'Sınıf atanmamış'} />
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_340px] gap-4 items-start">
        <div className="flex flex-col gap-4">
          <Panel title="Bugünkü saatler" description={data.today.length ? `${data.today.length} ders` : 'Bugün planlı ders yok'} flush>
            {data.today.length === 0 && data.study_today.length === 0 ? <EmptyState compact title="Bugün boş" /> : (
              <div className="divide-y divide-line">
                {data.today.map((s) => (
                  <div key={s.id} className={cn('flex items-center gap-3 px-4 py-2.5', s.phase === 'cancelled' && 'opacity-60')}>
                    <div className="w-[92px] shrink-0 tabular"><p className="text-[13.5px] font-semibold">{time(s.starts_at)}</p><p className="text-[12px] text-ink-3">{time(s.ends_at)}</p></div>
                    <span className="h-9 w-[3px] rounded-full shrink-0" style={{ background: colorOf(s.subject_color) }} />
                    <div className="min-w-0 flex-1">
                      <p className={cn('truncate text-[13.5px] font-medium', s.phase === 'cancelled' && 'line-through')}><Link to={`/siniflar/${s.class_group_id}`} className="hover:text-primary">{s.class_group}</Link> · {s.subject}</p>
                      <p className="truncate text-[12px] text-ink-3">{s.teacher}</p>
                    </div>
                    <Badge tone={phaseTone[s.phase]} dot={s.phase === 'in_progress'}>{phaseLabel[s.phase]}</Badge>
                  </div>
                ))}
                {data.study_today.map((s) => (
                  <div key={`st${s.id}`} className="flex items-center gap-3 px-4 py-2.5">
                    <div className="w-[92px] shrink-0 tabular"><p className="text-[13.5px] font-semibold">{time(s.starts_at)}</p><p className="text-[12px] text-ink-3">{time(s.ends_at)}</p></div>
                    <span className="h-9 w-[3px] rounded-full shrink-0 bg-line-strong" />
                    <div className="min-w-0 flex-1"><p className="truncate text-[13.5px] font-medium">{s.kind === 'private' ? 'Birebir' : 'Etüt'}{s.subject ? ` · ${s.subject}` : ''}{s.topic ? ` · ${s.topic}` : ''}</p><p className="truncate text-[12px] text-ink-3">{s.teacher}</p></div>
                    <Badge tone={s.status === 'requested' ? 'warning' : 'neutral'}>{s.status === 'requested' ? 'Onay bekliyor' : s.status === 'completed' ? 'Tamamlandı' : 'Planlı'}</Badge>
                  </div>
                ))}
              </div>
            )}
          </Panel>

          <Panel title="Bağlı ders programı" description={`${data.schedules.length} haftalık ders şablonu`} flush>
            {data.schedules.length === 0 ? <EmptyState compact title="Bu dersliğe atanmış ders yok" /> : (
              <div className="divide-y divide-line">
                {[1, 2, 3, 4, 5, 6, 7].filter((d) => data.schedules.some((s) => s.weekday === d)).map((d) => (
                  <div key={d} className="flex flex-col gap-1 px-4 py-2.5 sm:flex-row sm:items-start">
                    <p className="w-28 shrink-0 text-[12.5px] font-medium text-ink-2">{WEEKDAYS[d]}</p>
                    <div className="flex flex-wrap gap-1.5">
                      {data.schedules.filter((s) => s.weekday === d).map((s) => (
                        <Link key={s.id} to={`/siniflar/${s.class_group_id}`} className="inline-flex items-center gap-1.5 rounded-[6px] border-l-[3px] bg-surface-2 px-2 py-1 text-[12px] hover:brightness-95" style={{ borderLeftColor: colorOf(s.subject_color) }}>
                          <span className="tabular text-ink-3">{s.starts_at.slice(0, 5)}–{s.ends_at.slice(0, 5)}</span><span className="font-medium">{s.class_group}</span><span className="text-ink-3">· {s.subject}</span>
                        </Link>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Panel>
        </div>

        <Panel title="Günlük doluluk" description="08:00–21:00 penceresine göre">
          <div className="flex flex-col gap-2.5">
            {data.occupancy.per_day.map((d) => (
              <div key={d.weekday}>
                <div className="flex justify-between text-[12.5px]"><span className="text-ink-2">{d.label}</span><span className="tabular text-ink-3">{d.lessons} ders · %{d.occupancy}</span></div>
                <ProgressBar value={d.occupancy} tone={d.occupancy >= 80 ? 'warning' : 'primary'} className="mt-1" />
              </div>
            ))}
          </div>
          {data.homeroom_of.length > 0 && (
            <div className="mt-4 border-t border-line pt-3">
              <p className="mb-1.5 text-[12px] text-ink-3">Ana dersliği olduğu sınıflar</p>
              <div className="flex flex-wrap gap-1.5">{data.homeroom_of.map((g) => <Link key={g.id} to={`/siniflar/${g.id}`}><ColorChip>{g.name}</ColorChip></Link>)}</div>
            </div>
          )}
        </Panel>
      </div>

      <ClassroomFormDrawer open={editOpen} classroom={c} onClose={() => setEditOpen(false)} onSaved={invalidate} />
      <ConfirmDialog open={confirmDelete} onClose={() => setConfirmDelete(false)} onConfirm={() => del.mutate()} loading={del.isPending} danger title="Dersliği sil" confirmLabel="Sil" description="Ders programında kullanılan derslik silinemez; önce dersleri taşıyın." />
    </div>
  )
}
