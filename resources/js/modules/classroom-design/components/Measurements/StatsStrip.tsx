import { useMemo } from 'react'
import { useClassroom } from '../../state/classroomStore'
import { useSelection } from '../../state/selectionStore'
import { useSeatingContext } from '../../state/seatingContext'
import { computeStats } from '../../utils/seating'
import { formatArea } from '../../utils/measurement'
import { SEAT_COLORS } from '../../three/materials'
import { cn } from '@/lib/cn'

/** CANLI İSTATİSTİK ŞERİDİ: alan, masa, atanan, boş, kapasite, doluluk — belge her değiştiğinde anında */
export function StatsStrip({ className }: { className?: string }) {
  const doc = useClassroom((s) => s.doc)
  const roomCap = useClassroom((s) => s.classroomCapacity)
  const selected = useSelection((s) => s.selected.length)
  const invalid = useSelection((s) => s.invalid.size)
  const seating = useSeatingContext((s) => s.active)
  const st = useMemo(() => computeStats(doc), [doc])
  const perStudent = st.capacity ? st.area / st.capacity : 0
  const all: { label: string; value: string; title?: string; seat?: boolean }[] = [
    { label: 'Alan', value: formatArea(st.area) },
    { label: 'Masa', value: String(st.desks) },
    { label: 'Kapasite', value: String(st.capacity), title: 'Kullanılabilir oturak sayısı' },
    { label: 'Atanan', value: String(st.assigned), seat: true },
    { label: 'Boş', value: String(st.empty), seat: true },
    { label: 'Doluluk', value: `%${st.occupancy}`, seat: true },
    { label: 'Öğrenci başına', value: perStudent ? `${perStudent.toFixed(2).replace('.', ',')} m²` : '—' },
  ]
  const items = all.filter((i) => seating || !i.seat)
  return (
    <div className={cn('flex items-center gap-x-5 gap-y-1 overflow-x-auto scroll-thin whitespace-nowrap px-3 text-[12px]', className)} data-testid="stats-strip">
      {items.map((i) => (
        <span key={i.label} className="inline-flex items-baseline gap-1.5" title={i.title}>
          <span className="text-ink-3">{i.label}</span>
          <b className="font-semibold tabular-nums text-ink" data-stat={i.label}>{i.value}</b>
        </span>
      ))}
      {seating && (
        <span className="inline-flex h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-surface-3" aria-hidden>
          <span className="h-full" style={{ width: `${st.occupancy}%`, background: SEAT_COLORS.full }} />
        </span>
      )}
      {roomCap ? <span className="text-ink-3">Derslik kapasitesi <b className="tabular-nums text-ink-2">{roomCap}</b></span> : null}
      <span className="ml-auto inline-flex items-center gap-3 text-ink-3">
        {seating && (['empty', 'full', 'reserved', 'unavailable'] as const).map((k) => (
          <span key={k} className="inline-flex items-center gap-1">
            <span className="size-2.5 rounded-[2px]" style={{ background: SEAT_COLORS[k] }} />
            {k === 'empty' ? 'Boş' : k === 'full' ? 'Dolu' : k === 'reserved' ? 'Rezerve' : 'Kullanılamaz'}
          </span>
        ))}
        {selected > 0 && <span className="text-ink-2">{selected} seçili</span>}
        {invalid > 0 && <span className="font-medium text-danger">çakışma</span>}
      </span>
    </div>
  )
}
