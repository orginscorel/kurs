import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Plus, Save, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { Alert, EmptyState, Skeleton } from '@/components/ui/feedback'
import { useAcademicOptions } from './hooks'
import { ColorChip } from './ui'
import { toMinutes, toTime, WEEKDAYS, type AvailabilityData } from './types'

type Slot = { weekday: number; starts_at: string; ends_at: string }

/** Öğretmen uygunluk takvimi: gün başına aralıklar; dersler bilgi olarak gösterilir. */
export default function AvailabilityPage() {
  const can = useCan()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const options = useAcademicOptions()
  const teacherId = Number(params.get('ogretmen')) || options.data?.my_teacher_id || options.data?.teachers[0]?.id || 0
  const [slots, setSlots] = useState<Slot[]>([])
  const [dirty, setDirty] = useState(false)

  const { data, isLoading } = useQuery({ queryKey: ['availability', teacherId], queryFn: () => api.get<AvailabilityData>(`/study/teachers/${teacherId}/availability`), enabled: teacherId > 0 })

  useEffect(() => {
    if (data) {
      setSlots(data.slots.map((s) => ({ weekday: s.weekday, starts_at: s.starts_at, ends_at: s.ends_at })))
      setDirty(false)
    }
  }, [data])

  const save = useMutation({
    mutationFn: () => api.put<{ message: string }>(`/study/teachers/${teacherId}/availability`, { slots }),
    onSuccess: (r) => {
      toast.success(r.message)
      setDirty(false)
      qc.invalidateQueries({ queryKey: ['availability', teacherId] })
      qc.invalidateQueries({ queryKey: ['study'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const byDay = useMemo(() => {
    const m = new Map<number, { idx: number; slot: Slot }[]>()
    slots.forEach((slot, idx) => m.set(slot.weekday, [...(m.get(slot.weekday) ?? []), { idx, slot }]))
    return m
  }, [slots])
  const update = (idx: number, patch: Partial<Slot>) => {
    setSlots((l) => l.map((s, i) => (i === idx ? { ...s, ...patch } : s)))
    setDirty(true)
  }
  const invalid = slots.some((s) => !s.starts_at || !s.ends_at || toMinutes(s.ends_at) <= toMinutes(s.starts_at))
  const total = slots.reduce((a, s) => a + Math.max(0, toMinutes(s.ends_at) - toMinutes(s.starts_at)), 0)

  return (
    <div className="animate-fade-in">
      <PageHeader
        breadcrumbs={[{ label: 'Etüt ve birebir', to: '/etut' }, { label: 'Öğretmen uygunluğu' }]}
        title="Öğretmen uygunluğu"
        description="Etüt ve birebir ders planlanabilecek saat aralıkları. Dersler otomatik olarak dolu sayılır."
        actions={
          can('study.manage') && (
            <Button variant="primary" icon={<Save className="size-4" />} loading={save.isPending} disabled={!dirty || invalid} onClick={() => save.mutate()}>Kaydet</Button>
          )
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Select value={teacherId || ''} onChange={(e) => setParams((p) => { p.set('ogretmen', e.target.value); return p })} placeholder="Öğretmen" options={(options.data?.teachers ?? []).map((t) => ({ value: t.id, label: t.name }))} className="w-full sm:w-[280px]" />
        {data && <span className="text-[12.5px] text-ink-3">Haftalık {Math.round(total / 60)} saat uygunluk · {data.lessons.length} ders</span>}
        {dirty && <span className="text-[12.5px] text-warning">Kaydedilmemiş değişiklik var</span>}
      </div>

      {!teacherId ? (
        <EmptyState title="Öğretmen seçin" />
      ) : isLoading || !data ? (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">{Array.from({ length: 7 }).map((_, i) => <Skeleton key={i} className="h-40 rounded-[var(--radius-lg)]" />)}</div>
      ) : (
        <>
          {invalid && <Alert tone="danger" className="mb-3">Bazı aralıklarda bitiş saati başlangıçtan önce ya da boş.</Alert>}
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
            {[1, 2, 3, 4, 5, 6, 7].map((d) => {
              const lessons = data.lessons.filter((l) => l.weekday === d)
              const rows = byDay.get(d) ?? []
              return (
                <Panel
                  key={d}
                  title={WEEKDAYS[d]}
                  description={rows.length ? `${rows.length} aralık` : 'Uygun değil'}
                  actions={can('study.manage') && <Button size="xs" variant="ghost" icon={<Plus className="size-3.5" />} onClick={() => { setSlots((l) => [...l, { weekday: d, starts_at: '12:00', ends_at: '15:00' }]); setDirty(true) }}>Aralık</Button>}
                >
                  <div className="flex flex-col gap-2">
                    {rows.map(({ idx, slot }) => (
                      <div key={idx} className="flex items-center gap-1.5">
                        <Input type="time" step={300} value={slot.starts_at} onChange={(e) => update(idx, { starts_at: e.target.value, ends_at: slot.ends_at && toMinutes(slot.ends_at) <= toMinutes(e.target.value) ? toTime(toMinutes(e.target.value) + 60) : slot.ends_at })} disabled={!can('study.manage')} />
                        <span className="text-ink-3">–</span>
                        <Input type="time" step={300} value={slot.ends_at} onChange={(e) => update(idx, { ends_at: e.target.value })} disabled={!can('study.manage')} />
                        {can('study.manage') && <Button size="icon-sm" variant="ghost" aria-label="Sil" onClick={() => { setSlots((l) => l.filter((_, i) => i !== idx)); setDirty(true) }}><Trash2 className="size-3.5 text-danger" /></Button>}
                      </div>
                    ))}
                    {rows.length === 0 && <p className="text-[12.5px] text-ink-3">Bu gün için uygunluk tanımlı değil.</p>}
                    {lessons.length > 0 && (
                      <div className="mt-2 border-t border-line pt-2">
                        <p className="mb-1 text-[12px] text-ink-3">Dersler</p>
                        <div className="flex flex-wrap gap-1">
                          {lessons.map((l) => <ColorChip key={l.id} color={l.color} className="text-[11px]"><span className="tabular">{l.starts_at}</span> {l.class_group}</ColorChip>)}
                        </div>
                      </div>
                    )}
                  </div>
                </Panel>
              )
            })}
          </div>
        </>
      )}
    </div>
  )
}
