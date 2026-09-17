import { CalendarX2 } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { ColorChip } from '../ui'
import { colorOf, phaseLabel, phaseTone, type SessionRow } from '../types'

export function DayView({ rows, loading, onSelect, showGroup = true }: { rows?: SessionRow[]; loading: boolean; onSelect: (s: SessionRow) => void; showGroup?: boolean }) {
  if (loading && !rows) {
    return (
      <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line p-4 flex flex-col gap-3">
        {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-12" />)}
      </div>
    )
  }
  if (!rows?.length) {
    return (
      <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
        <EmptyState icon={<CalendarX2 />} title="Bu gün ders yok" description="Seçili gün için üretilmiş oturum bulunmuyor." />
      </div>
    )
  }
  return (
    <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line divide-y divide-line">
      {rows.map((s) => (
        <button
          key={s.id}
          type="button"
          onClick={() => onSelect(s)}
          className={cn('flex w-full items-center gap-3 px-3.5 py-2.5 text-left transition-colors hover:bg-surface-2/70', s.phase === 'cancelled' && 'opacity-60')}
        >
          <div className="w-[92px] shrink-0 tabular">
            <p className="text-[13.5px] font-semibold">{s.starts_at}</p>
            <p className="text-[11.5px] text-ink-3">{s.ends_at}</p>
          </div>
          <span className="h-9 w-[2px] rounded-full shrink-0" style={{ background: s.phase === 'cancelled' ? 'var(--line-strong)' : s.phase === 'in_progress' ? 'var(--success)' : colorOf(s.subject.color) }} />
          <div className="min-w-0 flex-1">
            <p className={cn('truncate text-[13.5px] font-medium', s.phase === 'cancelled' && 'line-through')}>
              {s.subject.name}{showGroup ? <span className="text-ink-3"> · {s.class_group.name}</span> : null}
            </p>
            <p className="truncate text-[12px] text-ink-3">{s.teacher.name} · {s.classroom.name}{s.topic_note ? ` · ${s.topic_note}` : ''}</p>
          </div>
          <div className="hidden sm:flex items-center gap-2 shrink-0">
            {s.attendance_taken && <span className="text-[12px] text-ink-3 tabular">{s.present_count} var · {s.absent_count} yok</span>}
            {s.phase === 'cancelled' ? <ColorChip>İptal</ColorChip> :<Badge tone={phaseTone[s.phase]} dot={s.phase === 'in_progress'}>{phaseLabel[s.phase]}</Badge>}
          </div>
        </button>
      ))}
    </div>
  )
}
