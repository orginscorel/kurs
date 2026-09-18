import { useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Columns3, Inbox } from 'lucide-react'
import { cn } from '@/lib/cn'
import type { ListMeta } from '@/lib/api'
import { Checkbox } from './form'
import { EmptyState, Skeleton } from './feedback'
import { Button } from './Button'

/** Kişi/iletişim sütunları sola yaslanır */
const LEFT_KEY = /phone|email|mail|guardian|contact|veli|payer|buyer|^student$/i
/** Sayı/tutar/tarih gibi dar ve sabit kalması gereken sütunlar */
const NARROW_KEY = /^(date|due|when|at|created_at|updated_at|amount|total|net|vat|paid|remaining|balance|qty|stock|hours|seq|no|rank|score)$/i

export type ColumnPriority = 1 | 2 | 3 | 4

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
  /** Hücre içeriğinin en fazla genişliği (px); verilmezse ilk sütun 340, dar sütunlar 150, diğerleri 230 */
  maxWidth?: number
  /**
   * Öncelik (18.09 — yatay kaydırmayı kaldıran sistem):
   *  1 = her zaman görünür (ilk sütun ve işlem sütunu kendiliğinden 1'dir)
   *  2 = önemli, en son gizlenir
   *  3 = varsayılan (kolon seçicide gizlenebilir olanlar kendiliğinden 3'tür)
   *  4 = ikincil, ilk gizlenen
   * Tablo kapsayıcıya sığmadığında yüksek sayıdan başlayarak (ve sağdan sola) sütunlar
   * tablodan çıkarılır; içerikleri satırın altındaki etiketli "ikinci satır"da görünmeye devam eder.
   */
  priority?: ColumnPriority
  /** Sığma hesabında kullanılan yaklaşık en az genişlik (px) */
  minW?: number
  /** Tek satırda kalsın, taşarsa üç nokta (uzun ad/adres/açıklama için) */
  truncate?: boolean
  /** truncate ile birlikte tam metni title olarak verir */
  title?: (row: T) => string | undefined
  /** Dar ekranda tablodan çıkınca ikinci satırda da gösterme */
  detailHidden?: boolean
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
  /** Öncelik sistemini kapat (yalnız kaydırmalı kalması gereken özel tablolar için) */
  responsive?: boolean
}

/**
 * Sunucu taraflı sayfalı veri tablosu: sıralama, kolon seçimi, toplu seçim, iskelet yükleme.
 * Kolon görünürlüğü kullanıcı başına tarayıcıda hatırlanır.
 *
 * Genişlik disiplini (18.09): tablo asla yatay kaydırılmaz. Kapsayıcı daraldıkça düşük öncelikli
 * sütunlar tablodan çıkar ve satırın altında etiketli ikinci satırda görünür. Kullanıcının kolon
 * seçimi bu davranışın üstündedir (seçtiği sütun kapalıysa hiç gösterilmez).
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
  responsive = true,
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

  const last = visible[visible.length - 1]
  const trailingActions = !!last && (last.key === 'actions' || last.key === 'action' || !last.header)
  const isActionCol = (col: Column<T>, i: number) => (trailingActions && i === visible.length - 1) || col.key === 'actions' || col.key === 'action'

  // ——— Öncelik tabanlı sütun sistemi ———————————————————————————————
  // Öncelik verilmemişse: ilk sütun ve işlem sütunu 1, kolon seçicide gizlenebilenler 3, diğerleri 2.
  const prioOf = (col: Column<T>, i: number): ColumnPriority => {
    if (col.priority) return col.priority
    if (i === 0 || isActionCol(col, i)) return 1
    return col.hideable ? 3 : 2
  }
  const estWidth = (col: Column<T>, i: number) => {
    if (col.minW) return col.minW
    if (typeof col.width === 'number') return col.width
    // Tahminler bilerek ihtiyatlıdır: az sütun düşürülür, gerçek taşma ölçümle düzeltilir
    // (tablo gereksiz yere boşalmasın).
    if (i === 0) return 200
    if (isActionCol(col, i)) return 88
    if (NARROW_KEY.test(col.key)) return 92
    return 118
  }

  const wrapRef = useRef<HTMLDivElement>(null)
  const [cw, setCw] = useState(0)
  // Ölçüme dayalı düzeltme: tahmin yetmezse bir sütun daha düşür. İmza değişince sıfırlanır.
  const [fix, setFix] = useState<{ sig: string; n: number }>({ sig: '', n: 0 })

  useEffect(() => {
    const el = wrapRef.current
    if (!el) return
    const apply = (w: number) => setCw((p) => (Math.abs(p - w) < 1 ? p : Math.round(w)))
    apply(el.clientWidth)
    if (typeof ResizeObserver === 'undefined') return
    const ro = new ResizeObserver((entries) => apply(entries[0].contentRect.width))
    ro.observe(el)
    return () => ro.disconnect()
  }, [])

  const dropOrder = useMemo(() => {
    if (!responsive) return [] as string[]
    return visible
      .map((c, i) => ({ key: c.key, i, p: prioOf(c, i) }))
      .filter((x) => x.p > 1)
      .sort((a, b) => b.p - a.p || b.i - a.i)
      .map((x) => x.key)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible, responsive, trailingActions])

  const sig = `${cw}|${visible.map((c) => c.key).join(',')}`
  const extraDrop = fix.sig === sig ? fix.n : 0

  const estDrop = useMemo(() => {
    if (!responsive || !cw) return 0
    const w = new Map(visible.map((c, i) => [c.key, estWidth(c, i)]))
    let total = (selectable ? 44 : 0) + visible.reduce((s, c) => s + (w.get(c.key) ?? 0), 0)
    let n = 0
    while (total > cw && n < dropOrder.length) {
      total -= w.get(dropOrder[n]) ?? 0
      n++
    }
    return n
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cw, visible, selectable, dropOrder, responsive])

  const dropN = Math.min(dropOrder.length, Math.max(estDrop, extraDrop))
  const droppedKeys = useMemo(() => new Set(dropOrder.slice(0, dropN)), [dropOrder, dropN])
  const shown = useMemo(() => visible.filter((c) => !droppedKeys.has(c.key)), [visible, droppedKeys])
  const detailCols = useMemo(() => visible.filter((c) => droppedKeys.has(c.key) && !c.detailHidden), [visible, droppedKeys])

  // Tahmin yetmediyse (içerik beklenenden geniş) ölçerek bir sütun daha düşür; sığana kadar yinelenir.
  useLayoutEffect(() => {
    const el = wrapRef.current
    if (!responsive || !el || !cw) return
    if (el.scrollWidth - el.clientWidth > 1 && dropN < dropOrder.length) setFix({ sig, n: dropN + 1 })
  })

  const shownLast = shown[shown.length - 1]
  const shownTrailingActions = !!shownLast && (shownLast.key === 'actions' || shownLast.key === 'action' || !shownLast.header)
  const alignOf = (col: Column<T>, i: number): 'left' | 'center' | 'right' => {
    if ((shownTrailingActions && i === shown.length - 1) || col.key === 'actions') return 'right'
    if (i === 0 || col.align === 'left' || LEFT_KEY.test(col.key)) return 'left'
    return 'center'
  }
  const wrapCell = (col: Column<T>, i: number, row: T) => {
    const content = col.cell(row)
    if (col.width !== undefined && !col.truncate) return content
    const a = alignOf(col, i)
    const max = col.maxWidth ?? (i === 0 ? 340 : NARROW_KEY.test(col.key) ? 150 : 230)
    return (
      <div
        style={{ maxWidth: max }}
        title={col.title?.(row)}
        className={cn(
          col.truncate ? 'w-full min-w-0 truncate' : 'w-max [overflow-wrap:anywhere]',
          a === 'right' && 'ml-auto',
          a === 'center' && !col.truncate && 'mx-auto',
        )}
      >
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

  const span = shown.length + (selectable ? 1 : 0)
  const detailMax = cw ? Math.max(120, cw - 28) : undefined

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
                <div className="absolute right-0 top-full mt-1 z-30 w-64 rounded-[var(--radius-md)] bg-surface p-2 ring-1 ring-line shadow-[var(--shadow-pop)] animate-slide-up">
                  {hideableCols.map((c) => (
                    <div key={c.key} className="px-1.5 py-1">
                      <Checkbox
                        checked={!hidden.has(c.key)}
                        onChange={(on) => {
                          const next = new Set(hidden)
                          on ? next.delete(c.key) : next.add(c.key)
                          setHidden(next)
                        }}
                        label={
                          <span className="inline-flex items-center gap-1.5">
                            {c.header}
                            {droppedKeys.has(c.key) && <span className="text-[11px] text-ink-3">· satır altında</span>}
                          </span>
                        }
                      />
                    </div>
                  ))}
                  {detailCols.length > 0 && (
                    <p className="mt-1 border-t border-line px-1.5 pt-1.5 text-[11.5px] leading-snug text-ink-3">
                      Ekran dar olduğu için {detailCols.length} sütun satırın altına indi; bilgi kaybolmaz.
                    </p>
                  )}
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
                          {selectable && <div className="pt-1 -m-1 p-1" onClick={(e) => e.stopPropagation()}><Checkbox checked={!!isSelected} onChange={() => toggleOne(k)} /></div>}
                          <div className="min-w-0 flex-1 [overflow-wrap:anywhere]">{head?.cell(row)}</div>
                          {action && <div className="flex max-w-[60%] shrink-0 flex-wrap justify-end gap-1 [&_button]:min-h-[40px] [&_a]:min-h-[40px]" onClick={(e) => e.stopPropagation()}>{action.cell(row)}</div>}
                        </div>
                        {fields.length > 0 && (
                          <dl className="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-2 border-t border-line/70 pt-2.5 text-[13px]">
                            {fields.map((c) => (
                              <div key={c.key} className="min-w-0">
                                <dt className="text-[12px] font-medium text-ink-3">{c.mobileLabel ?? c.header}</dt>
                                <dd className="mt-0.5 min-w-0 [overflow-wrap:anywhere] break-words">{c.cell(row)}</dd>
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
              <select className="h-10 min-w-0 flex-1 rounded-[var(--radius-sm)] border border-line bg-surface px-2 text-[13px]" value={sort ?? ''} onChange={(e) => onSort(e.target.value)}>
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

      <div ref={wrapRef} className={cn('overflow-x-auto scroll-thin', mobile === 'cards' && 'hidden md:block')}>
        <table className="w-full border-collapse text-[13.5px]">
          <thead>
            <tr className="border-b border-line bg-surface-2">
              {selectable && (
                <th className="w-10 pl-3.5 pr-1">
                  <Checkbox checked={allSelected} indeterminate={!allSelected && someSelected} onChange={toggleAll} />
                </th>
              )}
              {shown.map((col, i) => {
                const active = !!col.sortKey && (sort === col.sortKey || sort === `-${col.sortKey}`)
                return (
                  <th
                    key={col.key}
                    style={{ width: col.width }}
                    className={cn(
                      'h-10 px-3 text-[11.5px] font-semibold uppercase tracking-[0.05em] text-ink-3 whitespace-nowrap select-none',
                      shownTrailingActions && i === shown.length - 1 && 'w-px',
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
              })}
            </tr>
          </thead>
          {loading && !rows ? (
            <tbody>
              {Array.from({ length: 8 }).map((_, i) => (
                <tr key={i} className="border-b border-line last:border-0">
                  {selectable && <td className="pl-3.5" />}
                  {shown.map((c) => (
                    <td key={c.key} className={cn('px-3', pad)}>
                      <Skeleton className="h-3.5 w-24" />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          ) : (
            rows?.map((row, ri) => {
              const k = rowKey(row)
              const isSelected = selected?.has(k)
              const details = detailCols.length > 0
              const bg = isSelected ? 'bg-primary-soft/60' : ri % 2 === 1 ? 'bg-surface-2/35' : ''
              return (
                <tbody
                  key={k}
                  onClick={() => onRowClick?.(row)}
                  className={cn('transition-colors', bg, onRowClick && 'cursor-pointer', !isSelected && 'hover:bg-primary-soft/35')}
                >
                  <tr className={cn(!details && 'border-b border-line')}>
                    {selectable && (
                      <td className="pl-3.5 pr-1" onClick={(e) => e.stopPropagation()}>
                        <Checkbox checked={!!isSelected} onChange={() => toggleOne(k)} />
                      </td>
                    )}
                    {shown.map((col, i) => (
                      <td
                        key={col.key}
                        style={{ width: col.width }}
                        className={cn(
                          'px-3 align-middle',
                          pad,
                          alignOf(col, i) === 'right' ? 'text-right' : alignOf(col, i) === 'center' ? 'text-center' : 'text-left',
                          col.align === 'right' && 'tabular',
                          shownTrailingActions && i === shown.length - 1 && 'w-px whitespace-nowrap',
                          col.className,
                        )}
                      >
                        {wrapCell(col, i, row)}
                      </td>
                    ))}
                  </tr>
                  {details && (
                    <tr className="border-b border-line">
                      <td colSpan={span} className="px-3 pb-2 pt-0">
                        <dl style={{ maxWidth: detailMax }} className="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-[12.5px]">
                          {detailCols.map((c) => (
                            <div key={c.key} className="flex min-w-0 max-w-full items-baseline gap-1.5">
                              <dt className="shrink-0 text-[11px] font-semibold uppercase tracking-[0.04em] text-ink-3">{c.mobileLabel ?? c.header}</dt>
                              <dd className="min-w-0 [overflow-wrap:anywhere] text-ink-2">{c.cell(row)}</dd>
                            </div>
                          ))}
                        </dl>
                      </td>
                    </tr>
                  )}
                </tbody>
              )
            })
          )}
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
