import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { RotateCcw, Sparkles } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Select } from '@/components/ui/form'
import { Alert, Badge, Skeleton } from '@/components/ui/feedback'
import { Drawer } from '@/components/ui/overlay'
import type { CurriculumData, CurriculumRow } from './types'

type Draft = Pick<CurriculumRow, 'subject_id' | 'hours' | 'teacher_id' | 'max_per_day' | 'block_size'>

const TRACK_OPTIONS = [
  { value: '', label: 'Alan yok' },
  { value: 'SAY', label: 'Sayısal' },
  { value: 'EA', label: 'Eşit ağırlık' },
  { value: 'SOZ', label: 'Sözel' },
  { value: 'DIL', label: 'Dil' },
  { value: 'TYT', label: 'TYT (temel)' },
  { value: 'LGS', label: 'LGS' },
]

/**
 * Sınıfa özel müfredat: ders × haftalık saat, sabit öğretmen, günlük üst sınır, blok ders.
 * Programdaki saatle aynı ve ek ayarı olmayan satırlar kaydedilmez (programı izler).
 */
export function CurriculumDrawer({ classId, onClose }: { classId: number; onClose: () => void }) {
  const can = useCan()
  const manage = can('academic.manage')
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['class-structure', 'curriculum', classId], queryFn: () => api.get<CurriculumData>(`/class-groups/${classId}/curriculum`) })
  const [rows, setRows] = useState<Record<number, Draft>>({})
  const [track, setTrack] = useState('')
  const [showAll, setShowAll] = useState(false)

  useEffect(() => {
    if (!q.data) return
    const next: Record<number, Draft> = {}
    q.data.rows.forEach((r) => (next[r.subject_id] = { subject_id: r.subject_id, hours: r.hours, teacher_id: r.teacher_id, max_per_day: r.max_per_day, block_size: r.block_size }))
    setRows(next)
    setTrack(q.data.class.track ?? '')
  }, [q.data])

  const data = q.data
  const total = useMemo(() => Object.values(rows).reduce((s, r) => s + (r.hours || 0), 0), [rows])
  const dirty = useMemo(() => {
    if (!data) return false
    if ((data.class.track ?? '') !== track) return true
    return data.rows.some((r) => {
      const d = rows[r.subject_id]
      return d && (d.hours !== r.hours || d.teacher_id !== r.teacher_id || d.max_per_day !== r.max_per_day || d.block_size !== r.block_size)
    })
  }, [data, rows, track])

  const set = (id: number, patch: Partial<Draft>) => setRows((prev) => ({ ...prev, [id]: { ...prev[id]!, ...patch } }))

  const save = useMutation({
    mutationFn: () => api.put<CurriculumData & { message: string }>(`/class-groups/${classId}/curriculum`, { track: track || null, rows: Object.values(rows) }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.setQueryData(['class-structure', 'curriculum', classId], r)
      qc.invalidateQueries({ queryKey: ['class-structure'] })
      qc.invalidateQueries({ queryKey: ['timetable', 'options'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Müfredat kaydedilemedi.'),
  })

  // Alan önerisi: sunucudaki öneri seçili alana göre; alan değiştiyse önce kaydetmek gerekir
  const suggestion = data && (data.class.track ?? '') === track ? data.rows.filter((r) => r.suggested !== null) : []
  const applySuggestion = () => {
    if (!data) return
    setRows((prev) => {
      const next = { ...prev }
      data.rows.forEach((r) => (next[r.subject_id] = { ...next[r.subject_id]!, hours: r.suggested ?? 0 }))
      return next
    })
  }
  const resetToProgram = () => {
    if (!data) return
    const next: Record<number, Draft> = {}
    data.rows.forEach((r) => (next[r.subject_id] = { subject_id: r.subject_id, hours: r.program_hours ?? 0, teacher_id: null, max_per_day: null, block_size: null }))
    setRows(next)
  }

  const visible = data?.rows.filter((r) => showAll || r.in_program || r.suggested !== null || (rows[r.subject_id]?.hours ?? 0) > 0 || r.overridden) ?? []
  const hidden = (data?.rows.length ?? 0) - visible.length
  const diff = data ? total - data.slots : 0

  return (
    <Drawer
      open
      onClose={onClose}
      width={760}
      title={data ? `${data.class.name} · ders saatleri` : 'Ders saatleri'}
      description={data ? `${data.class.program ?? 'Program yok'} · şablon: ${data.class.templates.map((t) => t.name).join(' + ') || 'atanmamış'}` : undefined}
      footer={manage && (
        <>
          <Button variant="ghost" onClick={onClose}>Kapat</Button>
          <Button variant="primary" disabled={!dirty} loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button>
        </>
      )}
    >
      {q.isLoading || !data ? (
        <div className="flex flex-col gap-2">{[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-10" />)}</div>
      ) : (
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Metric label="Bu sınıf" value={`${total} saat`} />
            <Metric label="Program" value={`${data.program_total} saat`} />
            <Metric label="Şablondaki dilim" value={data.slots ? `${data.slots}` : '—'} />
            <Metric label="Fark" value={data.slots ? (diff > 0 ? `+${diff} fazla` : diff < 0 ? `${-diff} boş` : 'Tam') : '—'} tone={data.slots && diff > 0 ? 'warning' : undefined} />
          </div>
          {data.slots === 0 && <Alert tone="warning">Sınıfa zaman şablonu atanmamış; bot bu sınıfa ders yerleştiremez.</Alert>}
          {data.slots > 0 && diff > 0 && <Alert tone="warning">Haftalık {total} saat, şablondaki {data.slots} dilime sığmaz. Saatleri azaltın ya da şablona dilim ekleyin.</Alert>}

          <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
            <Field label="Alan" className="sm:w-[200px]">
              <Select value={track} disabled={!manage} onChange={(e) => setTrack(e.target.value)} options={TRACK_OPTIONS} />
            </Field>
            {manage && (
              <div className="flex flex-wrap gap-2">
                <Button size="sm" icon={<Sparkles className="size-3.5" />} disabled={suggestion.length === 0} onClick={applySuggestion}
                  title={(data.class.track ?? '') !== track ? 'Alan değişti; öneri için önce kaydedin' : undefined}>
                  Alan önerisini uygula{suggestion.length ? ` (${data.suggestion_total} saat)` : ''}
                </Button>
                <Button size="sm" variant="ghost" icon={<RotateCcw className="size-3.5" />} onClick={resetToProgram}>Programa döndür</Button>
              </div>
            )}
          </div>
          {(data.class.track ?? '') !== track && <p className="text-[12px] text-ink-3">Alan değişikliği kaydedildiğinde yeni alanın müfredat önerisi görünür.</p>}

          <div className="overflow-x-auto scroll-thin rounded-[var(--radius-md)] ring-1 ring-line">
            <table className="tbl w-full min-w-[640px] text-left text-[13px]">
              <thead>
                <tr className="border-b border-line text-[12px] uppercase tracking-[0.04em] text-ink-3">
                  <th className="px-3 py-2 font-medium text-left">Ders</th>
                  <th className="px-2 py-2 font-medium text-center">Program</th>
                  <th className="px-2 py-2 font-medium text-center">Bu sınıf</th>
                  <th className="px-2 py-2 font-medium text-center">Sabit öğretmen</th>
                  <th className="px-2 py-2 font-medium text-center">Günde en fazla</th>
                  <th className="px-3 py-2 font-medium text-center">Blok</th>
                </tr>
              </thead>
              <tbody>
                {visible.map((r) => {
                  const d = rows[r.subject_id]
                  if (!d) return null
                  const changed = d.hours !== (r.program_hours ?? 0) || d.teacher_id || d.max_per_day || d.block_size
                  return (
                    <tr key={r.subject_id} className={cn('border-b border-line last:border-0', d.hours === 0 && 'text-ink-3')}>
                      <td className="px-3 py-1.5 text-left">
                        <span className="font-medium text-ink">{r.name}</span>
                        {changed ? <Badge className="ml-1.5">özel</Badge> : null}
                        {r.suggested !== null && d.hours !== r.suggested && <span className="ml-1.5 text-[12px] text-ink-3">öneri {r.suggested}</span>}
                      </td>
                      <td className="px-2 py-1.5 tabular text-ink-3 text-center">{r.program_hours ?? '—'}</td>
                      <td className="px-2 py-1.5 text-center">
                        <Input type="number" min={0} max={20} aria-label={`${r.name} haftalık saat`} className="h-8 w-[72px]" disabled={!manage}
                          value={d.hours} onChange={(e) => set(r.subject_id, { hours: Math.max(0, Math.min(20, Number(e.target.value) || 0)) })} />
                      </td>
                      <td className="px-2 py-1.5 text-center">
                        <Select className="w-[170px]" disabled={!manage || d.hours === 0} aria-label={`${r.name} sabit öğretmen`}
                          value={d.teacher_id ?? ''} onChange={(e) => set(r.subject_id, { teacher_id: e.target.value ? Number(e.target.value) : null })}
                          options={[{ value: '', label: r.teachers.length ? 'Bot seçsin' : 'Branş öğretmeni yok' }, ...r.teachers.map((t) => ({ value: t.id, label: t.name }))]} />
                      </td>
                      <td className="px-2 py-1.5 text-center">
                        <Select className="w-[110px]" disabled={!manage || d.hours === 0} aria-label={`${r.name} günlük üst sınır`}
                          value={d.max_per_day ?? ''} onChange={(e) => set(r.subject_id, { max_per_day: e.target.value ? Number(e.target.value) : null })}
                          options={[{ value: '', label: 'Genel' }, ...[1, 2, 3, 4].map((n) => ({ value: n, label: `${n} saat` }))]} />
                      </td>
                      <td className="px-3 py-1.5 text-center">
                        <Checkbox checked={d.block_size === 2} disabled={!manage || d.hours < 2} onChange={(v) => set(r.subject_id, { block_size: v ? 2 : null, max_per_day: v && d.max_per_day === 1 ? 2 : d.max_per_day })} label={<span className="text-[12px]">2'li</span>} />
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          {hidden > 0 && (
            <button type="button" className="self-start text-[12.5px] font-medium text-ink-2 hover:text-ink" onClick={() => setShowAll(true)}>
              Programda olmayan {hidden} dersi de göster
            </button>
          )}
          <p className="text-[12px] text-ink-3">
            Sabit öğretmen seçilirse bot bu dersi yalnız ona verir. "Günde en fazla" kesin kuraldır; "Genel" bot ayarındaki sınırı kullanır.
            Blok ders, dersin aynı gün ikişer saat art arda yapılmasını tercih eder.
          </p>
        </div>
      )}
    </Drawer>
  )
}

function Metric({ label, value, tone }: { label: string; value: string; tone?: 'warning' }) {
  return (
    <div className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2">
      <p className="text-[12px] text-ink-3">{label}</p>
      <p className={cn('text-[15px] font-semibold tabular', tone === 'warning' && 'text-warning')}>{value}</p>
    </div>
  )
}
