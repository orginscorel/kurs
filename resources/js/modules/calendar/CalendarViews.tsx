import { useMemo, type CSSProperties } from 'react'
import { CalendarX2, Lock } from 'lucide-react'
import { cn } from '@/lib/cn'
import { date as fmtDate, todayISO } from '@/lib/format'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { monthCells } from '../academic/schedule/MonthView'
import { colorOf, tintOf, toMinutes, WEEKDAY_SHORT } from '../academic/types'
import { covers, type CalEvent } from './types'

const lineOf = (e: CalEvent) => (e.status === 'cancelled' ? 'var(--line-strong)' : e.phase === 'in_progress' ? 'var(--success)' : e.type === 'lesson' ? colorOf(e.color) : 'var(--line-strong)')

function AllDayChip({ e, onClick }: { e: CalEvent; onClick?: () => void }) {
  const label = e.type === 'holiday' ? 'Tatil' : e.type === 'exam' ? 'Sınav' : 'İzin'
  return (
    <button type="button" onClick={onClick} title={`${e.title} · ${e.subtitle}`}
      className={cn('flex w-full min-w-0 items-center gap-1 truncate rounded-[5px] px-1.5 py-0.5 text-left text-[11px]', e.type === 'holiday' ? 'bg-surface-3 text-ink' : 'border border-line bg-surface text-ink-2')}>
      <span className="shrink-0 text-ink-3">{label}</span><span className="truncate">{e.title}</span>
    </button>
  )
}

// ================================================================== ay
export function MonthGrid({ month, events, loading, onDay, onEvent }: { month: string; events?: CalEvent[]; loading: boolean; onDay: (d: string) => void; onEvent: (e: CalEvent) => void }) {
  const cells = useMemo(() => monthCells(month), [month])
  const today = todayISO()
  return (
    <div className="overflow-hidden rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
      <div className="grid grid-cols-7 border-b border-line">
        {WEEKDAY_SHORT.slice(1).map((d) => <div key={d} className="px-2 py-2 text-center text-[11.5px] font-medium text-ink-3">{d}</div>)}
      </div>
      <div className="grid grid-cols-7">
        {cells.map((c) => {
          const allDay = (events ?? []).filter((e) => e.all_day && covers(e, c.date))
          const timed = (events ?? []).filter((e) => !e.all_day && e.date === c.date)
          const lessons = timed.filter((e) => e.type === 'lesson')
          const cancelled = lessons.filter((e) => e.status === 'cancelled').length
          return (
            <div key={c.date} className={cn('flex min-h-[104px] flex-col gap-0.5 border-b border-r border-line p-1.5', !c.inMonth && 'bg-surface-2/40')}>
              <button type="button" onClick={() => onDay(c.date)} className="flex items-center justify-between rounded px-0.5 text-left hover:bg-surface-2">
                <span className={cn('text-[12px] tabular', c.date === today ? 'grid size-5 place-items-center rounded-full bg-ink font-semibold text-bg' : c.inMonth ? 'text-ink-2' : 'text-ink-3')}>{Number(c.date.slice(8))}</span>
                {lessons.length > 0 && <span className="text-[10.5px] tabular text-ink-3">{lessons.length}{cancelled ? ` · ${cancelled} iptal` : ''}</span>}
              </button>
              {loading && !events ? <Skeleton className="mt-1 h-3 w-3/4" /> : (
                <>
                  {allDay.slice(0, 2).map((e) => <AllDayChip key={e.key} e={e} onClick={() => onDay(c.date)} />)}
                  <div className="hidden flex-col gap-0.5 sm:flex">
                    {timed.slice(0, 3).map((e) => (
                      <button key={e.key} type="button" onClick={() => (e.type === 'lesson' ? onEvent(e) : onDay(c.date))} className={cn('flex min-w-0 items-center gap-1 rounded px-0.5 text-left text-[10.5px] leading-tight hover:bg-surface-2', e.status === 'cancelled' && 'text-ink-3 line-through')}>
                        <span className="h-2.5 w-[2px] shrink-0 rounded-full" style={{ background: lineOf(e) }} />
                        <span className="tabular text-ink-3">{e.starts_at}</span><span className="truncate">{e.short ?? e.title}</span>
                      </button>
                    ))}
                    {timed.length > 3 && <button type="button" onClick={() => onDay(c.date)} className="px-0.5 text-left text-[10.5px] text-ink-3 hover:text-ink">+{timed.length - 3} daha</button>}
                  </div>
                </>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

// ================================================================== hafta / gün
type Block = { e: CalEvent; start: number; end: number; lane: number; lanes: number; cluster: number }

function lanes(list: CalEvent[]): Block[] {
  const blocks = list.map((e) => ({ e, start: toMinutes(e.starts_at!), end: toMinutes(e.ends_at!), lane: 0, lanes: 1, cluster: 0 })).sort((a, b) => a.start - b.start || a.end - b.end)
  const out: Block[] = []
  let cluster: Block[] = []
  let clusterEnd = -1
  let clusterNo = 0
  const flush = () => {
    clusterNo++
    const ends: number[] = []
    cluster.forEach((b) => {
      let i = ends.findIndex((x) => x <= b.start)
      if (i === -1) { i = ends.length; ends.push(b.end) } else ends[i] = b.end
      b.lane = i
    })
    cluster.forEach((b) => { b.lanes = ends.length; b.cluster = clusterNo })
    out.push(...cluster)
    cluster = []
  }
  blocks.forEach((b) => {
    if (cluster.length && b.start >= clusterEnd) flush()
    cluster.push(b)
    clusterEnd = Math.max(clusterEnd, b.end)
  })
  if (cluster.length) flush()
  return out
}

const HOUR = 64
/** Haftalık görünümde bir gün sütununa yan yana sığan en fazla ders; fazlası tek özet kartında toplanır. */
const WEEK_MAX_LANES = 3
/** Günlük görünümde bir şeridin en dar genişliği (px); sığmazsa yatay kaydırma açılır. */
const DAY_LANE_MIN = 132

type Summary = { key: string; start: number; end: number; items: CalEvent[] }

function layoutDay(list: CalEvent[], collapse: boolean): { blocks: Block[]; summaries: Summary[]; maxLanes: number } {
  const all = lanes(list)
  const maxLanes = all.reduce((m, b) => Math.max(m, b.lanes), 1)
  if (!collapse) return { blocks: all, summaries: [], maxLanes }
  const blocks: Block[] = []
  const byCluster = new Map<number, Block[]>()
  all.forEach((b) => {
    if (b.lanes <= WEEK_MAX_LANES) blocks.push(b)
    else byCluster.set(b.cluster, [...(byCluster.get(b.cluster) ?? []), b])
  })
  const summaries = [...byCluster.entries()].map(([k, bs]) => ({
    key: `c${k}`, start: Math.min(...bs.map((b) => b.start)), end: Math.max(...bs.map((b) => b.end)), items: bs.map((b) => b.e),
  }))
  return { blocks, summaries, maxLanes }
}

export function TimeGrid({ days, events, loading, onEvent, onDay }: { days: string[]; events?: CalEvent[]; loading: boolean; onEvent: (e: CalEvent) => void; onDay?: (d: string) => void }) {
  const timed = (events ?? []).filter((e) => !e.all_day && e.starts_at && e.ends_at)
  const range = useMemo(() => {
    const starts = timed.map((e) => toMinutes(e.starts_at!))
    const ends = timed.map((e) => toMinutes(e.ends_at!))
    return { start: Math.min(9, starts.length ? Math.floor(Math.min(...starts) / 60) : 9), end: Math.max(18, ends.length ? Math.ceil(Math.max(...ends) / 60) : 18) }
  }, [timed])
  const week = days.length > 1
  const layouts = useMemo(() => new Map(days.map((d) => [d, layoutDay(timed.filter((e) => e.date === d), week)])), [days, timed, week])
  const hours = Array.from({ length: range.end - range.start }, (_, i) => range.start + i)
  const height = hours.length * HOUR
  const today = todayISO()
  const now = new Date()
  const nowMin = now.getHours() * 60 + now.getMinutes()
  const cols = `52px repeat(${days.length}, minmax(0, 1fr))`
  const dayMinWidth = week ? 760 : 52 + Math.max(1, layouts.get(days[0]!)?.maxLanes ?? 1) * DAY_LANE_MIN
  const crowded = week && [...layouts.values()].some((l) => l.summaries.length > 0)

  if (loading && !events) return <Skeleton className="h-[560px] rounded-[var(--radius-lg)]" />

  return (
    <div className="flex flex-col gap-2">
      {crowded && (
        <p className="px-1 text-[12px] text-ink-3">
          Aynı saatte {WEEK_MAX_LANES}'ten fazla ders olan dilimler tek kartta toplandı. Karta tıklayınca o günün tüm dersleri açılır; sınıf ya da öğretmen seçerek de daraltabilirsiniz.
        </p>
      )}
      <div className="overflow-x-auto scroll-thin rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
        <div style={{ minWidth: dayMinWidth }}>
          <div className="grid border-b border-line bg-surface-2" style={{ gridTemplateColumns: cols }}>
            <div />
            {days.map((d) => {
              const allDay = (events ?? []).filter((e) => e.all_day && covers(e, d))
              const count = timed.filter((e) => e.date === d && e.type === 'lesson' && e.status !== 'cancelled').length
              return (
                <div key={d} className={cn('flex min-w-0 flex-col gap-1 border-l border-line px-1.5 py-1.5', d === today && 'bg-primary-soft/70')}>
                  <button type="button" onClick={() => onDay?.(d)} disabled={!onDay} className="flex items-baseline justify-center gap-1.5 rounded text-center enabled:hover:bg-surface-2">
                    <span className="text-[11.5px] text-ink-3">{WEEKDAY_SHORT[((new Date(`${d}T12:00:00`).getDay() + 6) % 7) + 1]}</span>
                    <span className={cn('text-[13px] tabular', d === today ? 'font-semibold text-primary underline decoration-2 underline-offset-4' : 'text-ink-2')}>{Number(d.slice(8))}</span>
                    {week && count > 0 && <span className="text-[10.5px] tabular text-ink-3">· {count} ders</span>}
                  </button>
                  {allDay.map((e) => <AllDayChip key={e.key} e={e} />)}
                </div>
              )
            })}
          </div>
          <div className="grid" style={{ gridTemplateColumns: cols }}>
            <div className="relative" style={{ height }}>
              {hours.map((h, i) => i > 0 && <div key={h} className="absolute right-2 -translate-y-1/2 text-[11px] tabular text-ink-3" style={{ top: i * HOUR }}>{String(h).padStart(2, '0')}:00</div>)}
            </div>
            {days.map((d) => {
              const layout = layouts.get(d)!
              return (
                <div key={d} className={cn('relative border-l border-line', d === today && 'bg-primary-soft/25')} style={{ height }}>
                  {hours.map((_, i) => <div key={i} className="pointer-events-none absolute inset-x-0 border-t border-line/70" style={{ top: i * HOUR }} />)}
                  {d === today && nowMin >= range.start * 60 && nowMin <= range.end * 60 && (
                    <div className="pointer-events-none absolute inset-x-0 z-10 h-0.5 bg-danger" style={{ top: ((nowMin - range.start * 60) / 60) * HOUR }} />
                  )}
                  {layout.blocks.map((b) => {
                    const px = ((b.end - b.start) / 60) * HOUR
                    const cancelled = b.e.status === 'cancelled'
                    const style: CSSProperties = {
                      top: ((b.start - range.start * 60) / 60) * HOUR + 1, height: Math.max(20, px - 2),
                      left: `calc(${(b.lane / b.lanes) * 100}% + 2px)`, width: `calc(${100 / b.lanes}% - 4px)`, borderLeftColor: lineOf(b.e),
                      background: b.e.type === 'lesson' && !cancelled ? tintOf(b.e.color, 11) : undefined,
                    }
                    const narrow = week && b.lanes > 1
                    return (
                      <button key={b.e.key} type="button" onClick={() => onEvent(b.e)} style={style} title={`${b.e.title} · ${b.e.starts_at}–${b.e.ends_at}\n${b.e.subtitle}`}
                        className={cn('absolute overflow-hidden rounded-[6px] border border-l-[3px] bg-surface-2 px-1.5 py-1 text-left leading-tight transition-[filter,border-color] hover:brightness-[0.97] hover:border-line-strong',
                          b.e.type === 'study' ? 'border-dashed border-line-strong' : 'border-line', cancelled && 'opacity-60')}>
                        <span className={cn('flex items-center gap-1 truncate text-[11.5px] font-semibold', cancelled ? 'text-ink-3 line-through' : 'text-ink')}>
                          {b.e.lesson?.makeup_of_id && <span className="shrink-0 font-normal text-ink-3">Telafi ·</span>}
                          {px < 40 || narrow ? b.e.short ?? b.e.title : b.e.title}
                        </span>
                        {px >= 34 && <span className="block truncate text-[11px] tabular text-ink-2">{narrow ? b.e.lesson?.class_group.name ?? b.e.starts_at : `${b.e.starts_at}–${b.e.ends_at}`}</span>}
                        {px >= 50 && !narrow && <span className="block truncate text-[11px] text-ink-3">{b.e.subtitle}</span>}
                      </button>
                    )
                  })}
                  {layout.summaries.map((sm) => {
                    const px = ((sm.end - sm.start) / 60) * HOUR
                    const active = sm.items.filter((e) => e.status !== 'cancelled')
                    const cancelledN = sm.items.length - active.length
                    const shown = active.length ? active : sm.items
                    return (
                      <button key={sm.key} type="button" onClick={() => onDay?.(d)}
                        title={sm.items.map((e) => `${e.lesson?.class_group.name ?? ''} · ${e.title}${e.status === 'cancelled' ? ' (iptal)' : ''}`).join('\n')}
                        style={{ top: ((sm.start - range.start * 60) / 60) * HOUR + 1, height: Math.max(22, px - 2) }}
                        className="absolute inset-x-0.5 flex flex-col gap-0.5 overflow-hidden rounded-[6px] border border-primary/25 bg-primary-soft/80 px-1.5 py-1 text-left leading-tight transition-colors hover:border-primary/50 hover:bg-primary-soft">
                        <span className="flex items-baseline gap-1 truncate text-[11.5px]">
                          <span className="font-semibold text-primary-ink">{active.length} ders</span>
                          <span className="truncate tabular text-ink-3">{String(Math.floor(sm.start / 60)).padStart(2, '0')}:{String(sm.start % 60).padStart(2, '0')}{cancelledN ? ` · ${cancelledN} iptal` : ''}</span>
                        </span>
                        <span className="flex flex-wrap gap-[3px]">
                          {shown.slice(0, 16).map((e) => <span key={e.key} className="h-1.5 w-3 rounded-full" style={{ background: e.status === 'cancelled' ? 'var(--line-strong)' : colorOf(e.color) }} />)}
                        </span>
                        {px >= 56 && <span className="truncate text-[10.5px] text-ink-2">{shown.slice(0, 6).map((e) => e.short ?? e.title).join(' · ')}</span>}
                      </button>
                    )
                  })}
                </div>
              )
            })}
          </div>
        </div>
      </div>
    </div>
  )
}

// ================================================================== ajanda
export function AgendaList({ from, to, events, loading, onEvent }: { from: string; to: string; events?: CalEvent[]; loading: boolean; onEvent: (e: CalEvent) => void }) {
  const groups = useMemo(() => {
    const out: { day: string; items: CalEvent[] }[] = []
    if (!events) return out
    for (let d = from; d <= to; ) {
      const items = events.filter((e) => (e.all_day ? covers(e, d) : e.date === d))
      if (items.length) out.push({ day: d, items })
      const [y, m, dd] = d.split('-').map(Number)
      const next = new Date(y!, m! - 1, dd! + 1)
      d = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`
    }
    return out
  }, [events, from, to])

  if (loading && !events) return <div className="flex flex-col gap-2">{[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-14 rounded-[var(--radius-md)]" />)}</div>
  if (!groups.length) return <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line"><EmptyState icon={<CalendarX2 />} title="Bu aralıkta kayıt yok" description="Filtreleri değiştirin ya da ileri tarihe geçin." /></div>

  const today = todayISO()
  return (
    <div className="flex flex-col gap-4">
      {groups.map((g) => (
        <section key={g.day}>
          <h3 className={cn('mb-1.5 px-1 text-[12.5px] font-medium', g.day === today ? 'text-ink' : 'text-ink-3')}>{fmtDate(g.day, 'day')}{g.day === today ? ' · bugün' : ''}</h3>
          <ul className="divide-y divide-line overflow-hidden rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
            {g.items.map((e) => {
              const cancelled = e.status === 'cancelled'
              return (
                <li key={e.key}>
                  <button type="button" onClick={() => onEvent(e)} disabled={e.type !== 'lesson'} className="flex w-full items-center gap-3 px-3.5 py-2.5 text-left transition-colors enabled:hover:bg-surface-2/60">
                    <div className="w-[64px] shrink-0 tabular">
                      {e.all_day ? <p className="text-[12px] text-ink-3">Tüm gün</p> : <><p className="text-[13px] font-semibold">{e.starts_at}</p><p className="text-[11.5px] text-ink-3">{e.ends_at}</p></>}
                    </div>
                    <span className="h-8 w-[2px] shrink-0 rounded-full" style={{ background: lineOf(e) }} />
                    <div className="min-w-0 flex-1">
                      <p className={cn('truncate text-[13.5px] font-medium', cancelled && 'text-ink-3 line-through')}>
                        {e.type !== 'lesson' && <span className="font-normal text-ink-3">{e.type === 'holiday' ? 'Tatil · ' : e.type === 'exam' ? 'Sınav · ' : e.type === 'leave' ? '' : ''}</span>}
                        {e.lesson?.makeup_of_id && <span className="font-normal text-ink-3">Telafi · </span>}
                        {e.title}
                      </p>
                      <p className="truncate text-[12px] text-ink-3">{e.subtitle}</p>
                    </div>
                    <div className="hidden shrink-0 items-center gap-2 sm:flex">
                      {e.lesson?.attendance_taken && <span className="text-[12px] tabular text-ink-3">{e.lesson.present_count} var · {e.lesson.absent_count} yok</span>}
                      {cancelled ? <Badge>İptal</Badge> : e.phase === 'in_progress' ? <Badge tone="success" dot>Derste</Badge> : e.phase === 'pending' ? <Badge>Onay bekliyor</Badge> : null}
                      {e.lesson?.schedule_id === null && !e.lesson?.makeup_of_id && e.type === 'lesson' && <Lock className="size-3.5 text-ink-3" />}
                    </div>
                  </button>
                </li>
              )
            })}
          </ul>
        </section>
      ))}
    </div>
  )
}
