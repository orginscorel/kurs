import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ChevronLeft, ChevronRight, ClipboardCheck } from 'lucide-react'
import { date, time, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Alert, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Panel } from '@/components/ui/layout'
import { PortalTitle } from '@/modules/portal/ui'
import { shiftDay, useTeacherQuery, type PendingRow, type SessionRow } from '../api'
import { LessonStatus } from './TeacherHome'

type Data = { date: string; data: SessionRow[]; pending: PendingRow[]; edit_days: number }

/** Gün seçimi + o günün dersleri; eksik yoklamalar üstte. */
export default function TeacherAttendance() {
  const today = todayISO()
  const [day, setDay] = useState(today)
  const { data, isLoading } = useTeacherQuery<Data>(['attendance'], '/attendance', { date: day })
  const minDay = shiftDay(today, -(data?.edit_days ?? 7))

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Yoklama" description={`Kendi derslerinizin yoklaması · ${data?.edit_days ?? 7} gün geriye kadar düzeltilebilir`} />

      {data && data.pending.length > 0 && (
        <Panel title="Yoklaması eksik dersler" flush>
          <ul>
            {data.pending.map((p) => (
              <li key={p.id} className="border-t border-line">
                <Link to={`/ogretmen/yoklama/${p.id}`} className="flex items-center gap-3 px-4 py-2.5 hover:bg-surface-2/60">
                  <span className="w-24 shrink-0 text-[12.5px] tabular text-ink-2">{date(p.date).slice(0, 5)} · {time(p.starts_at)}</span>
                  <span className="min-w-0 flex-1 break-words text-[14.5px] font-medium">{p.class_group} · {p.subject}</span>
                  <span className="shrink-0 text-[12.5px] font-medium text-primary">Yoklama al</span>
                </Link>
              </li>
            ))}
          </ul>
        </Panel>
      )}

      <div className="flex items-center justify-between gap-2 rounded-[var(--radius-lg)] bg-surface p-2 ring-1 ring-line">
        <Button variant="ghost" size="icon" onClick={() => setDay(shiftDay(day, -1))} aria-label="Önceki gün"><ChevronLeft className="size-4" /></Button>
        <div className="text-center">
          <p className="text-[14.5px] font-semibold capitalize">{date(day, 'day')}</p>
          {day !== today && <button className="text-[12.5px] font-medium text-primary" onClick={() => setDay(today)}>Bugüne dön</button>}
        </div>
        <Button variant="ghost" size="icon" onClick={() => setDay(shiftDay(day, 1))} aria-label="Sonraki gün"><ChevronRight className="size-4" /></Button>
      </div>

      {day < minDay && <Alert tone="info">Bu tarihin yoklamaları yalnız görüntülenebilir; düzeltme süresi doldu.</Alert>}

      {isLoading || !data ? (
        <Skeleton className="h-48" />
      ) : data.data.length === 0 ? (
        <EmptyState icon={<ClipboardCheck />} title="Bu gün dersiniz yok" />
      ) : (
        <ul className="flex flex-col gap-2">
          {data.data.map((l) => (
            <li key={l.id}>
              <Link to={`/ogretmen/yoklama/${l.id}`} className={cn('flex flex-wrap items-center gap-3 rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line hover:ring-line-strong', l.can_take_attendance && !l.attendance_taken_at && 'ring-warning')}>
                <div className="w-14 shrink-0 text-[12.5px] tabular">
                  <p className="font-semibold">{time(l.starts_at)}</p>
                  <p className="text-ink-3">{time(l.ends_at)}</p>
                </div>
                <div className="min-w-0 flex-1">
                  <p className={cn('break-words text-[14px] font-medium', l.status === 'cancelled' && 'text-ink-3 line-through')}>{l.class_group} · {l.subject}</p>
                  <p className="break-words text-[12.5px] text-ink-3">
                    {[l.classroom, `${l.roster} öğrenci`, l.recorded ? `${l.recorded} kayıt · ${l.absent} yok · ${l.late} geç` : null].filter(Boolean).join(' · ')}
                  </p>
                </div>
                <LessonStatus l={l} />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
