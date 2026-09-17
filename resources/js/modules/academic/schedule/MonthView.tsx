import { useMemo } from 'react'
import { cn } from '@/lib/cn'
import { todayISO } from '@/lib/format'
import { Skeleton } from '@/components/ui/feedback'
import { addDays, colorOf, WEEKDAY_SHORT, type SessionRow } from '../types'

/** Ay takvimi: her gün için oturum sayısı ve ilk üç ders; tıklanınca gün görünümü. */
export function MonthView({ month, rows, loading, onDay }: { month: string; rows?: SessionRow[]; loading: boolean; onDay: (date: string) => void }) {
  const cells = useMemo(() => monthCells(month), [month])
  const byDay = useMemo(() => {
    const m = new Map<string, SessionRow[]>()
    rows?.forEach((r) => m.set(r.date, [...(m.get(r.date) ?? []), r]))
    return m
  }, [rows])
  const today = todayISO()

  return (
    <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line overflow-hidden">
      <div className="grid grid-cols-7 border-b border-line">
        {WEEKDAY_SHORT.slice(1).map((d) => <div key={d} className="px-2 py-2 text-center text-[12px] font-medium text-ink-3">{d}</div>)}
      </div>
      <div className="grid grid-cols-7">
        {cells.map((c) => {
          const list = byDay.get(c.date) ?? []
          const cancelled = list.filter((s) => s.status === 'cancelled').length
          return (
            <button
              key={c.date}
              type="button"
              onClick={() => onDay(c.date)}
              className={cn('min-h-[92px] border-b border-r border-line p-1.5 text-left align-top transition-colors hover:bg-surface-2/70', !c.inMonth && 'bg-surface-2/40 text-ink-3', c.date === today && 'bg-primary-soft/30')}
            >
              <div className="flex items-center justify-between">
                <span className={cn('text-[12px] tabular', c.date === today ? 'font-semibold text-primary-ink' : c.inMonth ? 'text-ink-2' : 'text-ink-3')}>{Number(c.date.slice(8))}</span>
                {list.length > 0 && <span className="text-[10.5px] tabular text-ink-3">{list.length} ders{cancelled ? ` · ${cancelled} iptal` : ''}</span>}
              </div>
              {loading && !rows ? (
                <Skeleton className="mt-2 h-3 w-3/4" />
              ) : (
                <div className="mt-1 flex flex-col gap-0.5">
                  {list.slice(0, 3).map((s) => (
                    <span key={s.id} className={cn('flex items-center gap-1 truncate text-[10.5px] leading-tight', s.status === 'cancelled' && 'line-through opacity-60')}>
                      <span className="size-1.5 rounded-full shrink-0" style={{ background: colorOf(s.subject.color) }} />
                      <span className="tabular text-ink-3">{s.starts_at}</span>
                      <span className="truncate">{s.subject.short_name ?? s.subject.name}</span>
                    </span>
                  ))}
                  {list.length > 3 && <span className="text-[10.5px] text-ink-3">+{list.length - 3} daha</span>}
                </div>
              )}
            </button>
          )
        })}
      </div>
    </div>
  )
}

/** Ay görünümü hücreleri (6 hafta, Pazartesi başlangıç) */
export function monthCells(month: string) {
  const [y, m] = month.split('-').map(Number)
  const first = new Date(y!, m! - 1, 1)
  const offset = (first.getDay() + 6) % 7
  const start = addDays(`${month}-01`, -offset)
  return Array.from({ length: 42 }, (_, i) => {
    const date = addDays(start, i)
    return { date, inMonth: date.startsWith(month) }
  })
}
