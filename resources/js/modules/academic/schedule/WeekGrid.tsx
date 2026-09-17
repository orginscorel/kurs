import { useMemo, useRef, useState, type CSSProperties } from 'react'
import { DndContext, PointerSensor, useDraggable, useDroppable, useSensor, useSensors, type DragEndEvent, type DragMoveEvent } from '@dnd-kit/core'
import { CSS } from '@dnd-kit/utilities'
import { Ban, CheckCircle2, DoorOpen, Lock, UserRound } from 'lucide-react'
import { cn } from '@/lib/cn'
import { colorOf, tintOf, toMinutes, toTime, type ScheduleView, type WeekData, type WeekItem, type WeekStudy } from '../types'

// 50 dk'lık ders kartına ders adı + saat + sınıf/öğretmen üç satırı sığsın
export const HOUR_PX = 76
const SNAP_MIN = 5

type Block = { key: string; weekday: number; start: number; end: number; lane: number; lanes: number; item?: WeekItem; study?: WeekStudy }

type Props = {
  data: WeekData
  canManage: boolean
  compact?: boolean
  onMove?: (item: WeekItem, weekday: number, startMin: number) => Promise<unknown> | void
  onSelect?: (item: WeekItem) => void
  onStudySelect?: (study: WeekStudy) => void
  onEmptyClick?: (weekday: number, startMin: number) => void
}

/** Aynı gün içinde çakışan blokları yan yana şeritlere yerleştirir. */
function assignLanes(blocks: Block[]): Block[] {
  const byDay = new Map<number, Block[]>()
  blocks.forEach((b) => byDay.set(b.weekday, [...(byDay.get(b.weekday) ?? []), b]))
  const out: Block[] = []
  byDay.forEach((list) => {
    list.sort((a, b) => a.start - b.start || a.end - b.end)
    let cluster: Block[] = []
    let clusterEnd = -1
    const flush = () => {
      const laneEnds: number[] = []
      cluster.forEach((b) => {
        let lane = laneEnds.findIndex((e) => e <= b.start)
        if (lane === -1) {
          lane = laneEnds.length
          laneEnds.push(b.end)
        } else laneEnds[lane] = b.end
        b.lane = lane
      })
      cluster.forEach((b) => (b.lanes = laneEnds.length))
      out.push(...cluster)
      cluster = []
    }
    list.forEach((b) => {
      if (cluster.length && b.start >= clusterEnd) flush()
      cluster.push(b)
      clusterEnd = Math.max(clusterEnd, b.end)
    })
    if (cluster.length) flush()
  })
  return out
}

export function WeekGrid({ data, canManage, compact, onMove, onSelect, onStudySelect, onEmptyClick }: Props) {
  const rangeStart = data.range.start * 60
  const rangeEnd = data.range.end * 60
  const hours = useMemo(() => Array.from({ length: data.range.end - data.range.start }, (_, i) => data.range.start + i), [data.range])
  const height = ((rangeEnd - rangeStart) / 60) * HOUR_PX
  const [drag, setDrag] = useState<{ id: number; weekday: number; start: number } | null>(null)
  const [busy, setBusy] = useState<number | null>(null)
  // Sürükleme bitince tarayıcı bir de click üretir; bunu boş hücre/blok tıklaması saymamak için kısa koruma
  const dragEndedAt = useRef(0)
  const recentlyDragged = () => Date.now() - dragEndedAt.current < 300
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }))

  const blocks = useMemo(() => {
    const raw: Block[] = [
      ...data.items.map((it) => ({ key: `s${it.id}`, weekday: it.weekday, start: toMinutes(it.starts_at), end: toMinutes(it.ends_at), lane: 0, lanes: 1, item: it })),
      ...data.studies.map((st) => ({ key: `e${st.id}`, weekday: st.weekday, start: toMinutes(st.starts_at), end: toMinutes(st.ends_at), lane: 0, lanes: 1, study: st })),
    ]
    return assignLanes(raw)
  }, [data])

  const project = (e: DragMoveEvent | DragEndEvent) => {
    const item = e.active.data.current as WeekItem | undefined
    if (!item) return null
    const weekday = (e.over?.data.current as { weekday: number } | undefined)?.weekday ?? item.weekday
    const dur = toMinutes(item.ends_at) - toMinutes(item.starts_at)
    let start = toMinutes(item.starts_at) + Math.round(((e.delta.y / HOUR_PX) * 60) / SNAP_MIN) * SNAP_MIN
    start = Math.max(rangeStart, Math.min(rangeEnd - dur, start))
    return { item, weekday, start }
  }

  const now = new Date()
  const nowMin = now.getHours() * 60 + now.getMinutes()
  const lastLabel = compact ? 'text-[10.5px]' : 'text-[11px]'

  return (
    <DndContext
      sensors={sensors}
      onDragMove={(e) => {
        const p = project(e)
        if (p) setDrag({ id: p.item.id, weekday: p.weekday, start: p.start })
      }}
      onDragCancel={() => setDrag(null)}
      onDragEnd={async (e) => {
        setDrag(null)
        dragEndedAt.current = Date.now()
        const p = project(e)
        if (!p || !onMove) return
        if (p.weekday === p.item.weekday && p.start === toMinutes(p.item.starts_at)) return
        setBusy(p.item.id)
        try {
          await onMove(p.item, p.weekday, p.start)
        } finally {
          setBusy(null)
        }
      }}
    >
      <div className="overflow-x-auto scroll-thin rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
        <div className={cn('min-w-[880px]', compact && 'min-w-[720px]')}>
          {/* Gün başlıkları */}
          <div className="grid border-b border-line" style={{ gridTemplateColumns: '56px repeat(7, minmax(0, 1fr))' }}>
            <div />
            {data.days.map((d) => (
              <div key={d.date} className={cn('px-2 py-2 text-center border-l border-line', d.is_today && 'bg-primary-soft/40')}>
                <p className={cn('text-[12px] font-medium', d.is_today ? 'text-primary-ink' : 'text-ink-2')}>{d.label}</p>
                <p className={cn('text-[11.5px] tabular', d.is_today ? 'text-primary-ink' : 'text-ink-3')}>{d.date.slice(8)}.{d.date.slice(5, 7)}</p>
              </div>
            ))}
          </div>

          <div className="grid" style={{ gridTemplateColumns: '56px repeat(7, minmax(0, 1fr))' }}>
            {/* Saat sütunu */}
            <div className="relative" style={{ height }}>
              {hours.map((h, i) => (
                <div key={h} className={cn('absolute right-2 -translate-y-1/2 text-ink-3 tabular', lastLabel)} style={{ top: i * HOUR_PX }}>
                  {i === 0 ? '' : `${String(h).padStart(2, '0')}:00`}
                </div>
              ))}
            </div>

            {data.days.map((d) => (
              <DayColumn
                key={d.date}
                weekday={d.weekday}
                isToday={d.is_today}
                height={height}
                hours={hours.length}
                nowTop={d.is_today && nowMin >= rangeStart && nowMin <= rangeEnd ? ((nowMin - rangeStart) / 60) * HOUR_PX : null}
                onEmptyClick={canManage && onEmptyClick ? (y) => { if (!recentlyDragged()) onEmptyClick(d.weekday, rangeStart + Math.floor(((y / HOUR_PX) * 60) / 30) * 30) } : undefined}
              >
                {blocks
                  .filter((b) => b.weekday === d.weekday)
                  .map((b) => {
                    const style: CSSProperties = {
                      top: ((b.start - rangeStart) / 60) * HOUR_PX + 1,
                      height: Math.max(22, ((b.end - b.start) / 60) * HOUR_PX - 2),
                      left: `calc(${(b.lane / b.lanes) * 100}% + 2px)`,
                      width: `calc(${100 / b.lanes}% - 4px)`,
                    }
                    if (b.item) {
                      return (
                        <LessonBlock
                          key={b.key}
                          item={b.item}
                          view={data.view}
                          style={style}
                          compact={compact}
                          draggable={canManage && !!onMove && b.item.active_this_week && b.item.session?.status !== 'cancelled' && !b.item.session?.attendance_taken}
                          busy={busy === b.item.id}
                          preview={drag?.id === b.item.id ? drag : null}
                          onClick={() => { if (!recentlyDragged()) onSelect?.(b.item!) }}
                        />
                      )
                    }
                    return (
                      <button
                        key={b.key}
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation()
                          onStudySelect?.(b.study!)
                        }}
                        className="absolute overflow-hidden rounded-[6px] border border-dashed px-1.5 py-1 text-left text-[11px] leading-tight hover:brightness-95"
                        style={{ ...style, borderColor: 'var(--line-strong)', background: 'var(--surface-2)', color: 'var(--ink-2)' }}
                      >
                        <span className="block font-medium truncate">{b.study!.kind === 'private' ? 'Birebir' : 'Etüt'}{b.study!.subject ? ` · ${b.study!.subject}` : ''}</span>
                        <span className="block truncate text-ink-3 tabular">{b.study!.starts_at}–{b.study!.ends_at}{b.study!.status === 'requested' ? ' · onay bekliyor' : ''}</span>
                      </button>
                    )
                  })}
              </DayColumn>
            ))}
          </div>
        </div>
      </div>
    </DndContext>
  )
}

function DayColumn({
  weekday, isToday, height, hours, nowTop, onEmptyClick, children,
}: { weekday: number; isToday: boolean; height: number; hours: number; nowTop: number | null; onEmptyClick?: (y: number) => void; children: React.ReactNode }) {
  const { setNodeRef, isOver } = useDroppable({ id: `day-${weekday}`, data: { weekday } })
  return (
    <div
      ref={setNodeRef}
      className={cn('relative border-l border-line transition-colors', isToday && 'bg-primary-soft/20', isOver && 'bg-primary-soft/50', onEmptyClick && 'cursor-cell')}
      style={{ height }}
      onClick={(e) => {
        if (!onEmptyClick) return
        const rect = e.currentTarget.getBoundingClientRect()
        onEmptyClick(e.clientY - rect.top)
      }}
    >
      {Array.from({ length: hours }).map((_, i) => (
        <div key={i} className="absolute inset-x-0 border-t border-line/70 pointer-events-none" style={{ top: i * HOUR_PX }}>
          <div className="border-t border-dashed border-line/50" style={{ marginTop: HOUR_PX / 2 - 1 }} />
        </div>
      ))}
      {nowTop !== null && (
        <div className="absolute inset-x-0 z-10 pointer-events-none" style={{ top: nowTop }}>
          <div className="h-px bg-danger" />
          <span className="absolute -left-1 -top-[3px] size-[7px] rounded-full bg-danger" />
        </div>
      )}
      {children}
    </div>
  )
}

function LessonBlock({
  item, view, style, compact, draggable, busy, preview, onClick,
}: { item: WeekItem; view: ScheduleView; style: CSSProperties; compact?: boolean; draggable: boolean; busy: boolean; preview: { weekday: number; start: number } | null; onClick: () => void }) {
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({ id: `sched-${item.id}`, data: item, disabled: !draggable })
  const c = colorOf(item.subject.color)
  const s = item.session
  const cancelled = s?.status === 'cancelled'
  const inactive = !item.active_this_week
  const secondary = view === 'class_group' ? item.teacher.name : view === 'classroom' ? item.class_group.name : view === 'teacher' ? item.class_group.name : `${item.class_group.name}`
  const room = s?.classroom_override?.name ?? item.classroom.name
  const dur = toMinutes(item.ends_at) - toMinutes(item.starts_at)
  // Kartın gerçek yüksekliğine göre kaç satır sığdığı: başlık ~16px, diğer satırlar ~14px, dikey boşluk 8px
  const px = typeof style.height === 'number' ? style.height : (dur / 60) * HOUR_PX
  const showTime = px >= 36 || !!preview
  const showSecondary = px >= 50
  // Derslik görünümünde derslik adı zaten başlıkta; tekrarlamayıp satırı kısa tut
  const showRoom = view !== 'classroom' && !compact

  return (
    <div
      ref={setNodeRef}
      {...attributes}
      {...listeners}
      onClick={(e) => {
        e.stopPropagation()
        if (!isDragging) onClick()
      }}
      className={cn(
        'absolute overflow-hidden rounded-[6px] border border-line border-l-[3px] bg-surface-2 px-1.5 py-1 text-left leading-tight select-none outline-none transition-[box-shadow,filter] hover:border-line-strong hover:brightness-[0.97]',
        draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
        isDragging && 'z-30 shadow-[var(--shadow-pop)] opacity-95',
        busy && 'animate-pulse',
        (cancelled || inactive) && 'opacity-60',
      )}
      style={{ ...style, borderLeftColor: cancelled ? 'var(--line-strong)' : c, background: cancelled || inactive ? undefined : tintOf(item.subject.color, 11), transform: CSS.Translate.toString(transform) }}
      role="button"
      tabIndex={0}
    >
      <div className="flex items-start justify-between gap-1">
        <span className={cn('font-semibold truncate', compact ? 'text-[11px]' : 'text-[12px]', cancelled ? 'line-through text-ink-3' : 'text-ink')}>
          {compact || dur < 40 ? item.subject.short_name ?? item.subject.name : item.subject.name}
        </span>
        <span className="flex items-center gap-0.5 shrink-0 text-ink-3">
          {item.is_locked && <Lock className="size-3" />}
          {cancelled && <Ban className="size-3" />}
          {s?.attendance_taken && !cancelled && <CheckCircle2 className="size-3" />}
          {s?.classroom_override && <DoorOpen className="size-3" />}
          {s?.teacher_override && <UserRound className="size-3" />}
        </span>
      </div>
      {preview ? (
        <span className="block text-[11px] font-medium tabular text-ink">{toTime(preview.start)}–{toTime(preview.start + dur)}</span>
      ) : (
        showTime && <span className="block truncate text-[11px] leading-[14px] tabular text-ink-2">{item.starts_at}–{item.ends_at}</span>
      )}
      {showSecondary && (
        <span className="block truncate text-[11px] leading-[14px] text-ink-3">
          {secondary}
          {showRoom ? ` · ${room}` : ''}
        </span>
      )}
    </div>
  )
}
