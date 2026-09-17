import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search, X } from 'lucide-react'
import { api } from '@/lib/api'
import { money } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useDebounced } from '@/hooks/useListState'
import { Avatar, Spinner } from '@/components/ui/feedback'
import { Field, Input, Select, Textarea } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import { toCents, type AccountRow } from './shared'

export type PickedStudent = { id: number; full_name: string; student_no: string; open_balance?: string | null; overdue_balance?: string | null; photo_url?: string | null }

/** Öğrenci arama (ad, numara, telefon). /students?q= kullanır. */
export function StudentPicker({
  value,
  onChange,
  placeholder = 'Öğrenci adı, numarası ya da telefon',
  autoFocus,
  className,
}: {
  value: PickedStudent | null
  onChange: (s: PickedStudent | null) => void
  placeholder?: string
  autoFocus?: boolean
  className?: string
}) {
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const debounced = useDebounced(q.trim(), 220)
  const ref = useRef<HTMLDivElement>(null)

  const { data, isFetching } = useQuery({
    queryKey: ['finance', 'student-search', debounced],
    queryFn: () => api.get<{ data: PickedStudent[] }>('/students', { q: debounced, per_page: 8, status: 'all' }),
    enabled: debounced.length >= 2,
    staleTime: 30_000,
  })
  const results = debounced.length >= 2 ? (data?.data ?? []) : []

  useEffect(() => {
    const close = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false)
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])
  useEffect(() => setActive(0), [debounced])

  if (value) {
    return (
      <div className={cn('flex items-center gap-3 rounded-[var(--radius-md)] bg-surface ring-1 ring-line px-3 py-2', className)}>
        <Avatar name={value.full_name} src={value.photo_url} size={34} />
        <div className="min-w-0 flex-1">
          <p className="truncate font-medium text-ink">{value.full_name}</p>
          <p className="text-[12px] text-ink-3 tabular">Öğrenci no: {value.student_no}</p>
        </div>
        <button type="button" onClick={() => onChange(null)} className="grid size-7 place-items-center rounded-[6px] text-ink-3 hover:bg-surface-2 hover:text-ink" aria-label="Öğrenciyi değiştir">
          <X className="size-4" />
        </button>
      </div>
    )
  }

  const pick = (s: PickedStudent) => {
    onChange(s)
    setQ('')
    setOpen(false)
  }

  return (
    <div ref={ref} className={cn('relative', className)}>
      <Input
        autoFocus={autoFocus}
        value={q}
        onChange={(e) => {
          setQ(e.target.value)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={(e) => {
          if (!results.length) return
          if (e.key === 'ArrowDown') {
            e.preventDefault()
            setActive((a) => Math.min(results.length - 1, a + 1))
          } else if (e.key === 'ArrowUp') {
            e.preventDefault()
            setActive((a) => Math.max(0, a - 1))
          } else if (e.key === 'Enter') {
            e.preventDefault()
            const s = results[active]
            if (s) pick(s)
          }
        }}
        placeholder={placeholder}
        leading={<Search />}
        trailing={isFetching ? <Spinner className="size-3.5" /> : undefined}
      />
      {open && debounced.length >= 2 && (
        <div className="absolute left-0 right-0 top-full z-40 mt-1 max-h-80 overflow-y-auto rounded-[var(--radius-md)] bg-surface p-1 ring-1 ring-line shadow-[var(--shadow-pop)] animate-slide-up scroll-thin">
          {results.length === 0 && !isFetching && <p className="px-3 py-3 text-[13px] text-ink-3">Eşleşen öğrenci yok.</p>}
          {results.map((s, i) => (
            <button
              key={s.id}
              type="button"
              onMouseEnter={() => setActive(i)}
              onClick={() => pick(s)}
              className={cn('flex w-full items-center gap-3 rounded-[6px] px-2.5 py-2 text-left', i === active ? 'bg-surface-2' : '')}
            >
              <Avatar name={s.full_name} src={s.photo_url} size={30} />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[13.5px] font-medium text-ink">{s.full_name}</span>
                <span className="block text-[12px] text-ink-3 tabular">Öğrenci no: {s.student_no}</span>
              </span>
              {s.open_balance !== undefined && s.open_balance !== null && (
                <span className="text-right">
                  <span className="block text-[12.5px] tabular text-ink-2">Kalan: {money(s.open_balance, { short: true })}</span>
                  {Number(s.overdue_balance) > 0 && <span className="block text-[12px] tabular text-danger">{money(s.overdue_balance, { short: true })} gecikmiş</span>}
                </span>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

/** Tutar girişi: metin olarak tutulur ("1.500,50" de kabul), float'a çevrilmez. */
export function MoneyInput({
  value,
  onChange,
  invalid,
  className,
  placeholder = '0,00',
  autoFocus,
  allowNegative,
  size = 'md',
}: {
  value: string
  onChange: (v: string) => void
  invalid?: boolean
  className?: string
  placeholder?: string
  autoFocus?: boolean
  allowNegative?: boolean
  size?: 'md' | 'lg'
}) {
  const bad = value.trim() !== '' && (toCents(value) === null || (!allowNegative && (toCents(value) ?? 0) < 0))
  return (
    <div className={cn('relative', className)}>
      <Input
        inputMode="decimal"
        autoFocus={autoFocus}
        value={value}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value.replace(allowNegative ? /[^\d.,-]/g : /[^\d.,]/g, ''))}
        invalid={invalid || bad}
        className={cn('pr-8 tabular text-right', size === 'lg' && 'h-12 text-[20px] font-semibold')}
      />
      <span className={cn('pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-3', size === 'lg' ? 'text-[16px]' : 'text-[13px]')}>₺</span>
    </div>
  )
}

/** Gerekçe zorunlu iptal penceresi (tahsilat, gelir/gider, transfer). */
export function VoidDialog({
  open,
  onClose,
  onConfirm,
  title,
  description,
  loading,
  children,
}: {
  open: boolean
  onClose: () => void
  onConfirm: (reason: string) => void
  title: string
  description?: ReactNode
  loading?: boolean
  children?: ReactNode
}) {
  const [reason, setReason] = useState('')
  useEffect(() => {
    if (open) setReason('')
  }, [open])
  const tooShort = reason.trim().length < 5
  return (
    <ConfirmDialog
      open={open}
      onClose={onClose}
      title={title}
      description={description}
      danger
      loading={loading}
      confirmLabel="İptal et"
      onConfirm={() => !tooShort && onConfirm(reason.trim())}
    >
      {children}
      <Field label="İptal gerekçesi" required hint="Denetim kaydına yazılır; en az 5 karakter." error={reason && tooShort ? 'Gerekçe en az 5 karakter olmalı.' : null}>
        <Textarea autoFocus value={reason} onChange={(e) => setReason(e.target.value)} rows={3} placeholder="Örn. Yanlış öğrenciye kaydedildi" maxLength={300} />
      </Field>
    </ConfirmDialog>
  )
}

export function AccountSelect({
  accounts,
  value,
  onChange,
  placeholder = 'Hesap seçin',
  invalid,
  showBalance = true,
}: {
  accounts: AccountRow[] | undefined
  value: string | number
  onChange: (v: string) => void
  placeholder?: string
  invalid?: boolean
  showBalance?: boolean
}) {
  return (
    <Select
      value={value}
      invalid={invalid}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      options={(accounts ?? []).filter((a) => a.is_active).map((a) => ({ value: a.id, label: `${a.name} · ${a.kind_label}${showBalance ? ` · ${money(a.balance, { short: true })}` : ''}` }))}
    />
  )
}

export function useAccounts() {
  return useQuery({ queryKey: ['finance', 'accounts'], queryFn: () => api.get<{ data: AccountRow[] }>('/finance/accounts'), staleTime: 30_000 })
}

/** Başlık + değer satırı (özet kutuları için sade) */
export function MetricRow({ label, value, tone, strong }: { label: ReactNode; value: ReactNode; tone?: 'danger' | 'success' | 'warning'; strong?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-3 py-1.5 text-[13px]">
      <span className="text-ink-2">{label}</span>
      <span className={cn('tabular', strong && 'font-semibold text-ink', tone === 'danger' && 'text-danger', tone === 'success' && 'text-success', tone === 'warning' && 'text-warning')}>{value}</span>
    </div>
  )
}

/** Yatay oran çubuğu (yaşlandırma, kategori dağılımı) */
export function ShareBar({ items }: { items: { key: string; value: number; color: string; label: string }[] }) {
  const total = items.reduce((s, i) => s + Math.max(0, i.value), 0)
  if (total <= 0) return <div className="h-2 rounded-full bg-surface-3" />
  return (
    <div className="flex h-2 w-full overflow-hidden rounded-full bg-surface-3">
      {items.map((i) =>
        i.value > 0 ? <span key={i.key} title={`${i.label}: ${money(i.value / 100, { short: true })}`} style={{ width: `${(i.value / total) * 100}%`, background: i.color }} className="h-full" /> : null,
      )}
    </div>
  )
}

/** Görünür etiketli tarih aralığı süzgeci (araç çubuğu için; mobilde tam genişlik). */
export function DateRange({ label, from, to, onChange }: { label: string; from?: string | null; to?: string | null; onChange: (key: 'from' | 'to', value: string | null) => void }) {
  return (
    <div className="flex w-full flex-wrap items-center gap-1.5 sm:w-auto">
      <span className="text-[12.5px] text-ink-3">{label}:</span>
      <Input type="date" aria-label={`${label} başlangıç`} title={`${label} başlangıç`} value={from ?? ''} onChange={(e) => onChange('from', e.target.value || null)} className="min-w-0 flex-1 sm:w-[150px] sm:flex-none" />
      <span className="text-ink-3">–</span>
      <Input type="date" aria-label={`${label} bitiş`} title={`${label} bitiş`} value={to ?? ''} onChange={(e) => onChange('to', e.target.value || null)} className="min-w-0 flex-1 sm:w-[150px] sm:flex-none" />
    </div>
  )
}
