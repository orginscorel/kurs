import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, Search, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useDebounced } from '@/hooks/useListState'
import { Input } from '@/components/ui/form'
import { Avatar, Skeleton } from '@/components/ui/feedback'
import { COLOR_OPTIONS, colorOf, softStyle, vividColorOf } from './types'

/** Nötr etiket; kayıt rengi yalnız 6px soluk nokta olarak görünür. */
export function ColorChip({ color, children, className, strength }: { color?: string | null; children: ReactNode; className?: string; strength?: number }) {
  return (
    <span className={cn('inline-flex items-center gap-1.5 h-[22px] px-2 rounded-full text-[12px] font-medium border whitespace-nowrap', className)} style={softStyle(color, strength)}>
      {color && <span className="size-1.5 shrink-0 rounded-full" style={{ background: colorOf(color) }} />}
      {children}
    </span>
  )
}

export function ColorDot({ color, className }: { color?: string | null; className?: string }) {
  return <span className={cn('inline-block size-2.5 rounded-full shrink-0', className)} style={{ background: colorOf(color) }} />
}

/** Renk seçici: token'a eşlenen adlandırılmış renkler */
export function ColorPicker({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <div className="flex flex-wrap gap-2">
      {COLOR_OPTIONS.map((c) => (
        <button
          key={c.value}
          type="button"
          title={c.label}
          onClick={() => onChange(c.value)}
          className={cn('grid size-7 place-items-center rounded-full ring-2 ring-offset-2 ring-offset-surface transition-transform hover:scale-105', value === c.value ? 'ring-ink' : 'ring-transparent')}
          style={{ background: vividColorOf(c.value) }}
          aria-label={c.label}
        >
          {value === c.value && <Check className="size-3.5 text-white" strokeWidth={3} />}
        </button>
      ))}
    </div>
  )
}

export function SectionTitle({ children, action }: { children: ReactNode; action?: ReactNode }) {
  return (
    <div className="mb-3 flex items-center justify-between">
      <h3 className="text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">{children}</h3>
      {action}
    </div>
  )
}

export function MiniStat({ label, value, sub, tone }: { label: string; value: ReactNode; sub?: ReactNode; tone?: 'success' | 'warning' | 'danger' }) {
  const t = tone === 'success' ? 'text-success' : tone === 'warning' ? 'text-warning' : tone === 'danger' ? 'text-danger' : ''
  return (
    <div className="rounded-[var(--radius-md)] bg-surface-2/60 px-3 py-2.5 min-w-0">
      <p className="text-[12px] text-ink-3 truncate">{label}</p>
      <p className={cn('text-[18px] font-semibold tabular leading-tight mt-0.5', t)}>{value}</p>
      {sub && <p className="text-[12px] text-ink-3 mt-0.5 truncate">{sub}</p>}
    </div>
  )
}

type StudentHit = { id: number; student_no: string; full_name: string; photo_url: string | null; class_groups: { id: number; name: string }[] }

/**
 * Öğrenci seçici: ada/numaraya göre arar, çoklu seçim. `/students` ucunu kullanır (students.view gerekir).
 */
export function StudentPicker({
  selected,
  onChange,
  max,
  disabled,
  placeholder = 'Öğrenci adı ya da numarası',
}: {
  selected: { id: number; full_name: string }[]
  onChange: (list: { id: number; full_name: string }[]) => void
  max?: number
  disabled?: boolean
  placeholder?: string
}) {
  const [q, setQ] = useState('')
  const debounced = useDebounced(q, 250)
  const { data, isFetching } = useQuery({
    queryKey: ['students', 'pick', debounced],
    queryFn: () => api.get<Paginated<StudentHit>>('/students', { q: debounced, per_page: 8, status: 'current' }),
    enabled: debounced.trim().length >= 2,
  })
  const selectedIds = useMemo(() => new Set(selected.map((s) => s.id)), [selected])
  const hits = (data?.data ?? []).filter((h) => !selectedIds.has(h.id))
  const full = max !== undefined && selected.length >= max

  useEffect(() => {
    if (full) setQ('')
  }, [full])

  return (
    <div className="flex flex-col gap-2">
      {selected.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {selected.map((s) => (
            <span key={s.id} className="inline-flex items-center gap-1 h-7 pl-2.5 pr-1 rounded-full bg-primary-soft text-primary-ink text-[12.5px] font-medium">
              {s.full_name}
              {!disabled && (
                <button type="button" onClick={() => onChange(selected.filter((x) => x.id !== s.id))} className="grid size-5 place-items-center rounded-full hover:bg-primary/15" aria-label="Kaldır">
                  <X className="size-3" />
                </button>
              )}
            </span>
          ))}
        </div>
      )}
      {!disabled && !full && (
        <div className="relative">
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} leading={<Search />} />
          {debounced.trim().length >= 2 && (
            <div className="absolute left-0 right-0 top-full z-30 mt-1 max-h-64 overflow-y-auto scroll-thin rounded-[var(--radius-md)] bg-surface p-1 ring-1 ring-line shadow-[var(--shadow-pop)]">
              {isFetching && !data && <div className="p-2"><Skeleton className="h-4 w-1/2" /></div>}
              {!isFetching && hits.length === 0 && <p className="px-2.5 py-2 text-[12.5px] text-ink-3">Eşleşen öğrenci yok.</p>}
              {hits.map((h) => (
                <button
                  key={h.id}
                  type="button"
                  onClick={() => {
                    onChange([...selected, { id: h.id, full_name: h.full_name }])
                    setQ('')
                  }}
                  className="flex w-full items-center gap-2.5 rounded-[6px] px-2 py-1.5 text-left hover:bg-surface-2"
                >
                  <Avatar name={h.full_name} src={h.photo_url} size={26} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13px] font-medium">{h.full_name}</span>
                    <span className="block text-[12px] text-ink-3 tabular">Öğrenci no: {h.student_no}{h.class_groups.length ? ` · ${h.class_groups.map((g) => g.name).join(', ')}` : ''}</span>
                  </span>
                </button>
              ))}
            </div>
          )}
        </div>
      )}
      {full && <p className="text-[12px] text-ink-3">Kontenjan doldu ({max}).</p>}
    </div>
  )
}

/** Sayfa içi küçük istatistik satırı iskeleti */
export function StatRowSkeleton({ n = 4 }: { n?: number }) {
  return (
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
      {Array.from({ length: n }).map((_, i) => <Skeleton key={i} className="h-[76px] rounded-[var(--radius-md)]" />)}
    </div>
  )
}
