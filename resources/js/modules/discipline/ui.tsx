import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { useDebounced } from '@/hooks/useListState'
import { cn } from '@/lib/cn'
import { Badge, type Tone } from '@/components/ui/feedback'
import { Input } from '@/components/ui/form'
import type { DisciplineOptions } from './types'

export const INCIDENT_TONE: Record<string, Tone> = { open: 'info', review: 'warning', decided: 'primary', appealed: 'accent', closed: 'neutral' }
export const SANCTION_TONE: Record<string, Tone> = { proposed: 'warning', active: 'danger', completed: 'success', expired: 'neutral', appealed: 'accent', overturned: 'neutral', cancelled: 'neutral' }
export const SEVERITY_TONE: Record<string, Tone> = { low: 'neutral', medium: 'warning', high: 'danger', critical: 'danger' }
export const LEVEL_TONE: Record<string, Tone> = { none: 'success', watch: 'info', warning: 'warning', critical: 'danger' }
export const DEFENSE_TONE: Record<string, Tone> = { requested: 'warning', submitted: 'success', waived: 'neutral' }
export const BOARD_TONE: Record<string, Tone> = { planned: 'info', held: 'success', cancelled: 'neutral' }

export const toneOf = (t: string | undefined | null): Tone => (['neutral', 'primary', 'success', 'warning', 'danger', 'info', 'accent'].includes(t ?? '') ? (t as Tone) : 'neutral')

export function StatusBadge({ status, label, map }: { status: string; label: string; map: Record<string, Tone> }) {
  return <Badge tone={map[status] ?? 'neutral'} dot>{label}</Badge>
}

export function SeverityBadge({ severity, label }: { severity: string; label: string }) {
  return <Badge tone={SEVERITY_TONE[severity] ?? 'neutral'} className={cn(severity === 'critical' && 'font-semibold')}>{label}</Badge>
}

export function KindBadge({ kind }: { kind: string }) {
  return kind === 'positive' ? <Badge tone="success">Olumlu</Badge> : null
}

export function LevelBadge({ level, label }: { level: string; label: string }) {
  return <Badge tone={LEVEL_TONE[level] ?? 'neutral'} dot>{label}</Badge>
}

export function useDisciplineOptions(all = false) {
  return useQuery({
    queryKey: ['discipline', 'options', all],
    queryFn: () => api.get<DisciplineOptions>('/discipline/options', all ? { all: 1 } : undefined),
    staleTime: 5 * 60_000,
  })
}

/** Yerel (Europe/Istanbul) datetime-local değeri. */
export function toLocalInput(iso?: string | Date | null): string {
  if (!iso) return ''
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Istanbul', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false })
    .formatToParts(typeof iso === 'string' ? new Date(iso) : iso)
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '00'
  return `${get('year')}-${get('month')}-${get('day')}T${get('hour') === '24' ? '00' : get('hour')}:${get('minute')}`
}

export type PickedStudent = { id: number; full_name: string; student_no: string }

/** Ad / numarayla çoklu öğrenci seçimi (seçilenler çip olarak görünür). */
export function StudentMultiPicker({ value, onChange, autoFocus, placeholder = 'Öğrenci ad ya da numara ile ara' }: {
  value: PickedStudent[]; onChange: (v: PickedStudent[]) => void; autoFocus?: boolean; placeholder?: string
}) {
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const debounced = useDebounced(q, 200)
  const results = useQuery({
    queryKey: ['students', 'quick-search', debounced],
    queryFn: () => api.get<Paginated<PickedStudent>>('/students', { q: debounced, per_page: 8 }),
    enabled: debounced.length >= 2,
  })
  const picked = new Set(value.map((v) => v.id))
  const add = (s: PickedStudent) => {
    if (!picked.has(s.id)) onChange([...value, { id: s.id, full_name: s.full_name, student_no: s.student_no }])
    setQ('')
    setOpen(false)
  }

  return (
    <div className="relative">
      {value.length > 0 && (
        <div className="mb-2 flex flex-wrap gap-1.5">
          {value.map((s) => (
            <span key={s.id} className="inline-flex h-7 items-center gap-1.5 rounded-full bg-primary-soft pl-2.5 pr-1 text-[12.5px] font-medium text-primary-ink ring-1 ring-inset ring-primary/20">
              {s.full_name}
              <button type="button" aria-label={`${s.full_name} kaldır`} onClick={() => onChange(value.filter((x) => x.id !== s.id))}
                className="grid size-5 place-items-center rounded-full hover:bg-primary/15"><X className="size-3.5" /></button>
            </span>
          ))}
        </div>
      )}
      <Input value={q} autoFocus={autoFocus} onChange={(e) => { setQ(e.target.value); setOpen(true) }} onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); const first = results.data?.data.find((s) => !picked.has(s.id)); if (first) add(first) } }}
        placeholder={placeholder} leading={<Search />} aria-label="Öğrenci ara" />
      {open && debounced.length >= 2 && (
        <div className="absolute z-30 mt-1 max-h-60 w-full overflow-y-auto scroll-thin rounded-[var(--radius-md)] bg-surface ring-1 ring-line shadow-[var(--shadow-pop)]">
          {results.isLoading ? <p className="px-3 py-2.5 text-[13px] text-ink-3">Aranıyor…</p> : (results.data?.data ?? []).length === 0 ? (
            <p className="px-3 py-2.5 text-[13px] text-ink-3">Sonuç yok</p>
          ) : (
            results.data!.data.map((s) => (
              <button key={s.id} type="button" disabled={picked.has(s.id)} onMouseDown={(e) => e.preventDefault()} onClick={() => add(s)}
                className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-[13px] hover:bg-surface-2 disabled:opacity-40">
                <span className="text-ink">{s.full_name}</span>
                <span className="tabular text-ink-3">{s.student_no}</span>
              </button>
            ))
          )}
        </div>
      )}
    </div>
  )
}

/** Puan göstergesi: dönem net puanı eşiklere göre dolan çubuk. */
export function PointsMeter({ net, settings, className }: { net: number; settings?: { threshold_watch: number; threshold_warning: number; threshold_critical: number }; className?: string }) {
  const max = Math.max(1, settings?.threshold_critical ?? 35)
  const pct = Math.min(100, (net / max) * 100)
  const tone = !settings ? 'bg-primary' : net >= settings.threshold_critical ? 'bg-danger' : net >= settings.threshold_warning ? 'bg-warning' : net >= settings.threshold_watch ? 'bg-info' : 'bg-success'
  return (
    <div className={cn('relative h-2 w-full overflow-hidden rounded-full bg-surface-2 ring-1 ring-inset ring-line', className)}>
      <div className={cn('absolute inset-y-0 left-0 rounded-full transition-[width]', tone)} style={{ width: `${pct}%` }} />
      {settings && [settings.threshold_watch, settings.threshold_warning].map((t) => (
        <span key={t} className="absolute inset-y-0 w-px bg-ink-3/40" style={{ left: `${(t / max) * 100}%` }} />
      ))}
    </div>
  )
}
