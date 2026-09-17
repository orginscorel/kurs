import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle, ArrowRight, CalendarPlus, RefreshCcw, Search, ShieldAlert, StickyNote } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useListState, useDebounced } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { EmptyState, Badge, Skeleton, ProgressBar } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { cn } from '@/lib/cn'
import type { RiskOptions, RiskStudent } from './types'

const levelTone = { high: 'danger', medium: 'warning', low: 'success' } as const
const insightTone = { danger: 'text-danger', warning: 'text-warning', info: 'text-info', success: 'text-success' } as const

export default function RiskStudents() {
  const can = useCan()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const list = useListState({ filters: { level: '' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [noteFor, setNoteFor] = useState<RiskStudent | null>(null)
  const [noteBody, setNoteBody] = useState('')
  const [planFor, setPlanFor] = useState<RiskStudent | null>(null)
  const [planAt, setPlanAt] = useState('')

  const options = useQuery({ queryKey: ['risk', 'options'], queryFn: () => api.get<RiskOptions>('/risk/options'), staleTime: 5 * 60_000 })

  const query = { q: debounced || undefined, level: list.filters.level || undefined, class_group_id: list.filters.class_group_id, page: list.page }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['risk', 'students', query],
    queryFn: () => api.get<Paginated<RiskStudent>>('/risk/students', query),
    placeholderData: keepPreviousData,
  })

  const recalc = useMutation({
    mutationFn: (id: number) => api.post(`/risk/students/${id}/recalculate`),
    onSuccess: () => { toast.success('Risk puanı yeniden hesaplandı.'); qc.invalidateQueries({ queryKey: ['risk'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Hesaplanamadı.'),
  })

  const addNote = useMutation({
    mutationFn: () => api.post(`/students/${noteFor!.id}/notes`, { body: noteBody }),
    onSuccess: () => { toast.success('Not eklendi.'); setNoteFor(null); setNoteBody('') },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Not eklenemedi.'),
  })

  const planMeeting = useMutation({
    mutationFn: () => api.post(`/risk/students/${planFor!.id}/plan-meeting`, { due_at: planAt }),
    onSuccess: () => { toast.success('Görüşme planlandı.'); setPlanFor(null); setPlanAt(''); qc.invalidateQueries({ queryKey: ['tasks'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Planlanamadı.'),
  })

  return (
    <div className="animate-fade-in">
      <PageHeader title="Riskli Öğrenciler" description="Kural tabanlı risk puanı: devamsızlık, net değişimi, ödev tamamlama ve rehberlik aralığı" />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Öğrenci ara" aria-label="Öğrenci ara" leading={<Search />} className="w-full sm:w-[260px]" />
        <Select value={list.filters.level ?? ''} onChange={(e) => list.update({ filters: { level: e.target.value } })} placeholder="Orta ve yüksek risk" aria-label="Risk seviyesi"
          options={Object.entries(options.data?.levels ?? {}).map(([value, label]) => ({ value, label }))} className="w-full sm:w-[300px]" />
        <Select value={list.filters.class_group_id ?? ''} onChange={(e) => list.update({ filters: { class_group_id: e.target.value } })} placeholder="Tüm sınıflar"
          aria-label="Sınıf" options={(options.data?.class_groups ?? []).map((c: any) => ({ value: c.id, label: c.name }))} className="w-full sm:w-[180px]" />
      </div>

      {isLoading ? (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-56 rounded-[var(--radius-lg)]" />)}</div>
      ) : (data?.data.length ?? 0) === 0 ? (
        <EmptyState icon={<ShieldAlert />} title="Riskli öğrenci bulunamadı" description="Seçili filtrelerde risk taşıyan öğrenci yok." />
      ) : (
        <div className={cn('grid grid-cols-1 md:grid-cols-2 gap-3', isFetching && 'opacity-60')}>
          {data!.data.map((s) => (
            <div key={s.id} className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <button className="font-semibold text-[14.5px] text-ink hover:text-primary truncate" onClick={() => navigate(`/ogrenciler/${s.id}`)}>{s.full_name}</button>
                  <p className="text-[12.5px] text-ink-3 tabular">Öğrenci no: {s.student_no}{s.school_grade ? ` · Okul: ${s.school_grade}. sınıf` : ''}{s.class_groups.length ? ` · Kurs sınıfı: ${s.class_groups.map((g) => g.name).join(', ')}` : ''}</p>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <Badge tone={levelTone[s.level]}>Risk puanı: {s.score}</Badge>
                </div>
              </div>

              <div className="mt-3 flex flex-col gap-2">
                {s.factors.map((f) => (
                  <div key={f.key} className="flex items-center gap-2 text-[12.5px]">
                    <span className="w-28 shrink-0 text-ink-2 sm:w-32" title={f.label}>{f.label}</span>
                    <div className="flex-1"><ProgressBar value={(f.points / (f.weight || 1)) * 100} tone={f.points > f.weight * 0.6 ? 'danger' : f.points > f.weight * 0.3 ? 'warning' : 'primary'} /></div>
                    <span className="w-24 shrink-0 text-right tabular text-ink-2 sm:w-28">{f.value}</span>
                  </div>
                ))}
              </div>

              {s.insights.length > 0 && (
                <div className="mt-3 flex flex-col gap-1.5 rounded-[var(--radius-md)] bg-surface-2/60 p-2.5">
                  <div className="flex items-center gap-1.5 text-[12px] font-medium text-ink-3">
                    <AlertTriangle className="size-3.5" /> Sistem önerisi (kurallara göre)
                  </div>
                  {s.insights.slice(0, 3).map((ins, i) => (
                    <p key={i} className={cn('text-[12.5px]', insightTone[ins.tone])}>{ins.text}</p>
                  ))}
                </div>
              )}

              <p className="mt-2 text-[12px] text-ink-3">Son hesaplama: {relative(s.calculated_at)}</p>

              <div className="mt-3 flex flex-wrap gap-1.5">
                {can('guidance.manage') && (
                  <Button size="xs" icon={<CalendarPlus className="size-3.5" />} onClick={() => { setPlanFor(s); setPlanAt('') }}>Görüşme planla</Button>
                )}
                <Button size="xs" icon={<StickyNote className="size-3.5" />} onClick={() => { setNoteFor(s); setNoteBody('') }}>Not ekle</Button>
                <Button size="xs" variant="ghost" icon={<RefreshCcw className="size-3.5" />} loading={recalc.isPending} onClick={() => recalc.mutate(s.id)}>Yeniden hesapla</Button>
                <Button size="xs" variant="ghost" icon={<ArrowRight className="size-3.5" />} onClick={() => navigate(`/ogrenciler/${s.id}`)} className="ml-auto">Öğrenci profili</Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={!!noteFor} onClose={() => setNoteFor(null)} size="sm" title="Öğrenciye not ekle" description={noteFor?.full_name}
        footer={<><Button variant="ghost" onClick={() => setNoteFor(null)}>Vazgeç</Button><Button variant="primary" disabled={!noteBody.trim()} loading={addNote.isPending} onClick={() => addNote.mutate()}>Kaydet</Button></>}>
        <Field label="Not" required>
          <Textarea rows={3} value={noteBody} onChange={(e) => setNoteBody(e.target.value)} placeholder="Öğrenci profilindeki notlara eklenir" autoFocus />
        </Field>
      </Modal>

      <Modal open={!!planFor} onClose={() => setPlanFor(null)} size="sm" title="Görüşme planla" description={planFor?.full_name}
        footer={<><Button variant="ghost" onClick={() => setPlanFor(null)}>Vazgeç</Button><Button variant="primary" disabled={!planAt} loading={planMeeting.isPending} onClick={() => planMeeting.mutate()}>Planla</Button></>}>
        <Field label="Görüşme tarihi ve saati" required>
          <Input type="datetime-local" value={planAt} onChange={(e) => setPlanAt(e.target.value)} autoFocus />
        </Field>
        <p className="mt-2 text-[12.5px] text-ink-3">Size (ya da atanan rehbere) bir görev oluşturulur ve öğrencinin son görüşme kaydında varsa sonraki görüşme tarihi güncellenir.</p>
      </Modal>
    </div>
  )
}
