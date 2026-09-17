import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Columns3, Inbox } from 'lucide-react'
import { cn } from '@/lib/cn'
import type { ListMeta } from '@/lib/api'
import { Checkbox } from './form'
import { EmptyState, Skeleton } from './feedback'
import { Button } from './Button'

/** Kişi/iletişim sütunları sola yaslanır */
const LEFT_KEY = /phone|email|mail|guardian|contact|veli|payer|buyer|^student$/i

export type Column<T> = {
  key: string
  header: ReactNode
  cell: (row: T) => ReactNode
  sortKey?: string
  /**
   * Hizalama (kullanıcı kuralı 17.09): ilk sütun ve kişi/iletişim sütunları sola, işlem sütunu sağa,
   * diğer her şey ortaya. Verilmezse ortalanır; 'right' de ortalanır (sağa yalnız işlem sütunu).
   * Anahtarında phone/email/guardian/contact geçen (ve 'student') sütunlar kendiliğinden sola yaslanır.
   */
  align?: 'left' | 'right' | 'center'
  width?: number | string
  /** Hücre içeriğinin en fazla genişliği (px); verilmezse ilk sütun 440, diğerleri 320 */
  maxWidth?: number
  /** Kolon seçicide gizlenebilir */
  hideable?: boolean
  defaultHidden?: boolean
  className?: string
  /** Mobil kartta gösterilmesin */
  mobileHidden?: boolean
  /** Mobil karttaki etiket (verilmezse başlık) */
  mobileLabel?: ReactNode
}

type Props<T> = {
  columns: Column<T>[]
  rows: T[] | undefined
  rowKey: (row: T) => string | number
  loading?: boolean
  meta?: ListMeta
  sort?: string
  onSort?: (sort: string) => void
  page?: number
  onPage?: (page: number) => void
  onRowClick?: (row: T) => void
  selectable?: boolean
  selected?: Set<string | number>
  onSelectedChange?: (s: Set<string | number>) => void
  bulkActions?: ReactNode
  empty?: ReactNode
  storageKey?: string
  toolbar?: ReactNode
  className?: string
  dense?: boolean
  /** Dar ekranda satırlar kart ızgarasına dönüşür (varsayılan). 'table' ile yatay kaydırmalı tabloda kalır. */
  mobile?: 'cards' | 'table'
  /** Mobil kartın tamamını özel çizmek için */
  mobileCard?: (row: T) => ReactNode
}

/**
 * Sunucu taraflı sayfalı veri tablosu: sıralama, kolon seçimi, toplu seçim, iskelet yükleme.
 * Kolon görünürlüğü kullanıcı başına tarayıcıda hatırlanır.
 */
export function DataTable<T>({
  columns,
  rows,
  rowKey,
  loading,
  meta,
  sort,
  onSort,
  onPage,
  onRowClick,
  selectable,
  selected,
  onSelectedChange,
  bulkActions,
  empty,
  storageKey,
  toolbar,
  className,
  dense,
  mobile = 'cards',
  mobileCard,
}: Props<T>) {
  const [hidden, setHidden] = useState<Set<string>>(() => {
    try {
      const saved = storageKey ? localStorage.getItem(`ebe-cols:${storageKey}`) : null
      if (saved) return new Set(JSON.parse(saved) as string[])
    } catch {
      /* yok say */
    }
    return new Set(columns.filter((c) => c.defaultHidden).map((c) => c.key))
  })
  const [pickerOpen, setPickerOpen] = useState(false)
  const pickerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!storageKey) return
    try {
      localStorage.setItem(`ebe-cols:${storageKey}`, JSON.stringify([...hidden]))
    } catch {
      /* yok say */
    }
  }, [hidden, storageKey])

  useEffect(() => {
    if (!pickerOpen) return
    const close = (e: MouseEvent) => pickerRef.current && !pickerRef.current.contains(e.target as Node) && setPickerOpen(false)
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [pickerOpen])

  const visible = useMemo(() => columns.filter((c) => !hidden.has(c.key)), [columns, hidden])
  const keys = rows?.map(rowKey) ?? []
  const selectedCount = selected?.size ?? 0
  const allSelected = keys.length > 0 && keys.every((k) => selected?.has(k))
  const someSelected = keys.some((k) => selected?.has(k))
  const hideableCols = columns.filter((c) => c.hideable)
  const pad = dense ? 'py-2' : 'py-2.5'
  // Hücre içeriği kendi genişliğinde kalır (üst sınırla); tablo tam genişlikte olduğundan artan alan
  // sütunlar arasına dağılır. İşlem sütunu (son, başlıksız) sağa yaslanır ve içeriği kadar yer kaplar.
  const last = visible[visible.length - 1]
  const trailingActions = !!last && (last.key === 'actions' || last.key === 'action' || !last.header)
  const alignOf = (col: Column<T>, i: number): 'left' | 'center' | 'right' => {
    if ((trailingActions && i === visible.length - 1) || col.key === 'actions') return 'right'
    if (i === 0 || col.align === 'left' || LEFT_KEY.test(col.key)) return 'left'
    return 'center'
  }
  const wrapCell = (col: Column<T>, i: number, content: ReactNode) => {
    if (col.width !== undefined) return content
    const a = alignOf(col, i)
    return (
      <div style={{ maxWidth: col.maxWidth ?? (i === 0 ? 440 : 320) }} className={cn('w-max', a === 'right' && 'ml-auto', a === 'center' && 'mx-auto')}>
        {content}
      </div>
    )
  }

  const toggleAll = () => {
    if (!onSelectedChange) return
    const next = new Set(selected)
    if (allSelected) keys.forEach((k) => next.delete(k))
    else keys.forEach((k) => next.add(k))
    onSelectedChange(next)
  }

  const toggleOne = (k: string | number) => {
    if (!onSelectedChange) return
    const next = new Set(selected)
    next.has(k) ? next.delete(k) : next.add(k)
    onSelectedChange(next)
  }

  const handleSort = (col: Column<T>) => {
    if (!col.sortKey || !onSort) return
    if (sort === col.sortKey) onSort(`-${col.sortKey}`)
    else if (sort === `-${col.sortKey}`) onSort(col.sortKey)
    else onSort(col.sortKey)
  }

  return (
    <div className={cn('rounded-[var(--radius-lg)] bg-surface ring-1 ring-line overflow-hidden', className)}>
      {(toolbar || hideableCols.length > 0 || selectedCount > 0) && (
        <div className="flex flex-wrap items-center gap-2 px-3 py-2.5 border-b border-line min-h-[52px]">
          {selectedCount > 0 ? (
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2 animate-fade-in">
              <span className="text-[13px] font-medium text-ink">{selectedCount} kayıt seçildi</span>
              <Button size="xs" variant="ghost" onClick={() => onSelectedChange?.(new Set())}>
                Seçimi temizle
              </Button>
              <div className="flex flex-wrap items-center gap-1.5 ml-auto">{bulkActions}</div>
            </div>
          ) : (
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2 [&>*]:max-w-full">{toolbar}</div>
          )}
          {hideableCols.length > 0 && selectedCount === 0 && (
            <div ref={pickerRef} className="relative">
              <Button size="sm" variant="ghost" icon={<Columns3 className="size-4" />} onClick={() => setPickerOpen((v) => !v)}>
                <span className="hidden sm:inline">Kolonlar</span>
              </Button>
              {pickerOpen && (
                <div className="absolute right-0 top-full mt-1 z-30 w-56 rounded-[var(--radius-md)] bg-surface p-2 ring-1 ring-line shadow-[var(--shadow-pop)] animate-slide-up">
                  {hideableCols.map((c) => (
                    <div key={c.key} className="px-1.5 py-1">
                      <Checkbox
                        checked={!hidden.has(c.key)}
                        onChange={(on) => {
                          const next = new Set(hidden)
                          on ? next.delete(c.key) : next.add(c.key)
                          setHidden(next)
                        }}
                        label={c.header}
                      />
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {mobile === 'cards' && (
        <div className="md:hidden">
          {loading && !rows ? (
            <div className="grid gap-2 p-2.5">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-24 rounded-[var(--radius-md)]" />)}</div>
          ) : (
            <ul className="grid grid-cols-1 gap-2 p-2.5 sm:grid-cols-2">
              {rows?.map((row) => {
                const k = rowKey(row)
                const isSelected = selected?.has(k)
                const [head, ...rest] = visible
                const action = trailingActions ? rest[rest.length - 1] : undefined
                const fields = rest.filter((c) => c !== action && !c.mobileHidden)
                return (
                  <li key={k} onClick={() => onRowClick?.(row)}
                    className={cn('min-w-0 rounded-[var(--radius-md)] border bg-surface p-3 shadow-[var(--shadow-soft)] transition-colors',
                      isSelected ? 'border-primary/50 bg-primary-soft/50' : 'border-line', onRowClick && 'cursor-pointer active:bg-surface-2')}>
                    {mobileCard ? mobileCard(row) : (
                      <>
                        <div className="flex items-start gap-2.5">
                          {selectable && <div className="pt-1" onClick={(e) => e.stopPropagation()}><Checkbox checked={!!isSelected} onChange={() => toggleOne(k)} /></div>}
                          <div className="min-w-0 flex-1">{head?.cell(row)}</div>
                          {action && <div className="flex max-w-[60%] shrink-0 flex-wrap justify-end gap-1" onClick={(e) => e.stopPropagation()}>{action.cell(row)}</div>}
                        </div>
                        {fields.length > 0 && (
                          <dl className="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-2 border-t border-line/70 pt-2.5 text-[13px]">
                            {fields.map((c) => (
                              <div key={c.key} className="min-w-0">
                                <dt className="text-[12px] font-medium text-ink-3">{c.mobileLabel ?? c.header}</dt>
                                <dd className="mt-0.5 min-w-0 break-words">{c.cell(row)}</dd>
                              </div>
                            ))}
                          </dl>
                        )}
                      </>
                    )}
                  </li>
                )
              })}
            </ul>
          )}
          {onSort && columns.some((c) => c.sortKey) && rows && rows.length > 1 && (
            <div className="flex items-center gap-2 border-t border-line px-3 py-2 text-[12.5px]">
              <span className="text-ink-3">Sırala</span>
              <select className="h-8 min-w-0 flex-1 rounded-[var(--radius-sm)] border border-line bg-surface px-2 text-[13px]" value={sort ?? ''} onChange={(e) => onSort(e.target.value)}>
                {!sort && <option value="">Varsayılan</option>}
                {visible.filter((c) => c.sortKey).flatMap((c) => [
                  <option key={c.key} value={c.sortKey}>{typeof c.header === 'string' ? c.header : c.key} ↑</option>,
                  <option key={`${c.key}-d`} value={`-${c.sortKey}`}>{typeof c.header === 'string' ? c.header : c.key} ↓</option>,
                ])}
              </select>
            </div>
          )}
        </div>
      )}

      <div className={cn('overflow-x-auto scroll-thin', mobile === 'cards' && 'hidden md:block')}>
        <table className="w-full border-collapse text-[13.5px]">
          <thead>
            <tr className="border-b border-line bg-surface-2">
              {selectable && (
                <th className="w-10 pl-3.5 pr-1">
                  <Checkbox checked={allSelected} indeterminate={!allSelected && someSelected} onChange={toggleAll} />
                </th>
              )}
              {visible.flatMap((col, i) => {
                const active = !!col.sortKey && (sort === col.sortKey || sort === `-${col.sortKey}`)
                const th = (
                  <th
                    key={col.key}
                    style={{ width: col.width }}
                    className={cn(
                      'h-10 px-3 text-[11.5px] font-semibold uppercase tracking-[0.05em] text-ink-3 whitespace-nowrap select-none',
                      trailingActions && i === visible.length - 1 && 'w-px',
                      alignOf(col, i) === 'right' ? 'text-right' : alignOf(col, i) === 'center' ? 'text-center' : 'text-left',
                      col.sortKey && 'cursor-pointer hover:text-primary',
                      active && 'text-primary',
                    )}
                    onClick={() => handleSort(col)}
                  >
                    <span className={cn('inline-flex items-center gap-1', alignOf(col, i) === 'right' && 'flex-row-reverse')}>
                      {col.header}
                      {active && (sort?.startsWith('-') ? <ArrowDown className="size-3.5" /> : <ArrowUp className="size-3.5" />)}
                    </span>
                  </th>
                )
                return [th]
              })}
            </tr>
          </thead>
          <tbody>
            {loading && !rows
              ? Array.from({ length: 8 }).map((_, i) => (
                  <tr key={i} className="border-b border-line last:border-0">
                    {selectable && <td className="pl-3.5" />}
                    {visible.map((c) => (
                      <td key={c.key} className={cn('px-3', pad)}>
                        <Skeleton className="h-3.5 w-24" />
                      </td>
                    ))}
                  </tr>
                ))
              : rows?.map((row) => {
                  const k = rowKey(row)
                  const isSelected = selected?.has(k)
                  return (
                    <tr
                      key={k}
                      onClick={() => onRowClick?.(row)}
                      className={cn(
                        'border-b border-line last:border-0 transition-colors',
                        onRowClick && 'cursor-pointer',
                        isSelected ? 'bg-primary-soft/60' : 'even:bg-surface-2/35 hover:bg-primary-soft/35',
                      )}
                    >
                      {selectable && (
                        <td className="pl-3.5 pr-1" onClick={(e) => e.stopPropagation()}>
                          <Checkbox checked={!!isSelected} onChange={() => toggleOne(k)} />
                        </td>
                      )}
                      {visible.flatMap((col, i) => {
                        const td = (
                          <td
                            key={col.key}
                            style={{ width: col.width }}
                            className={cn('px-3 align-middle', pad, alignOf(col, i) === 'right' ? 'text-right' : alignOf(col, i) === 'center' ? 'text-center' : 'text-left', col.align === 'right' && 'tabular', trailingActions && i === visible.length - 1 && 'w-px whitespace-nowrap', col.className)}
                          >
                            {wrapCell(col, i, col.cell(row))}
                          </td>
                        )
                        return [td]
                      })}
                    </tr>
                  )
                })}
          </tbody>
        </table>
      </div>
      {!loading && rows && rows.length === 0 && <div className="border-t border-line">{empty ?? <EmptyState compact icon={<Inbox />} title="Kayıt bulunamadı" description="Filtreleri gevşetmeyi ya da aramayı temizlemeyi deneyin." />}</div>}

      {meta && meta.total > 0 && (
        <div className={cn('flex flex-wrap items-center justify-between gap-2 border-t border-line px-3.5 py-2 text-[12.5px] text-ink-3', loading && 'opacity-60')}>
          <span className="tabular">
            {((meta.page - 1) * meta.per_page + 1).toLocaleString('tr-TR')}–{Math.min(meta.page * meta.per_page, meta.total).toLocaleString('tr-TR')} / {meta.total.toLocaleString('tr-TR')} kayıt
          </span>
          <div className="flex items-center gap-1">
            <Button size="icon-sm" variant="ghost" disabled={meta.page <= 1} onClick={() => onPage?.(meta.page - 1)} aria-label="Önceki sayfa">
              <ChevronLeft className="size-4" />
            </Button>
            <span className="px-2 tabular text-ink-2">
              {meta.page} / {meta.last_page}
            </span>
            <Button size="icon-sm" variant="ghost" disabled={meta.page >= meta.last_page} onClick={() => onPage?.(meta.page + 1)} aria-label="Sonraki sayfa">
              <ChevronRight className="size-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
