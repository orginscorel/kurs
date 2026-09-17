import { useState } from 'react'
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react'
import { date, time, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { attendanceTone, usePortal, type ClassGroupRow, type LessonRow, type StudyRow, useVoice } from '../api'
import { PortalTitle } from '../ui'

type Data = { week_start: string; week_end: string; class_groups: ClassGroupRow[]; days: { date: string; lessons: LessonRow[]; study: StudyRow[] }[] }

function shift(iso: string, days: number) {
  const [y, m, d] = iso.split('-').map(Number)
  const dt = new Date(Date.UTC(y!, m! - 1, d! + days))
  return dt.toISOString().slice(0, 10)
}

export default function PortalSchedule() {
  const [ref, setRef] = useState(todayISO())
  const { data, isLoading } = usePortal<Data>('schedule', '/portal/schedule', { date: ref })
  const today = todayISO()
  const v = useVoice()
  const groups = data?.class_groups ?? []

  return (
    <div className="animate-fade-in">
      <PortalTitle
        title={v('Ders programım', 'Ders programı')}
        description={groups.length ? groups.map((g) => [g.name, g.program, g.classroom].filter(Boolean).join(' · ')).join(' / ') : 'Haftalık dersler ve etütler'}
      />

      <div className="mb-4 flex items-center justify-between gap-2 rounded-[var(--radius-lg)] bg-surface p-2 ring-1 ring-line">
        <Button variant="ghost" size="icon" onClick={() => setRef(shift(data?.week_start ?? ref, -7))} aria-label="Önceki hafta"><ChevronLeft className="size-4" /></Button>
        <div className="text-center">
          <p className="text-[14.5px] font-semibold tabular">{data ? `${date(data.week_start)} – ${date(data.week_end)}` : '…'}</p>
          {ref !== today && <button className="text-[12.5px] font-medium text-primary" onClick={() => setRef(today)}>Bu haftaya dön</button>}
        </div>
        <Button variant="ghost" size="icon" onClick={() => setRef(shift(data?.week_start ?? ref, 7))} aria-label="Sonraki hafta"><ChevronRight className="size-4" /></Button>
      </div>

      {isLoading || !data ? (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-40" />)}</div>
      ) : data.days.every((d) => d.lessons.length === 0 && d.study.length === 0) ? (
        <EmptyState icon={<CalendarDays />} title="Bu hafta için planlanmış ders yok" description={groups.length ? undefined : v('Henüz bir sınıfa atanmadın.', 'Öğrenci henüz bir sınıfa atanmadı.')} />
      ) : (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {data.days.filter((d) => d.lessons.length > 0 || d.study.length > 0 || d.date === today).map((d) => (
            <section key={d.date} className={cn('min-w-0 rounded-[var(--radius-lg)] bg-surface ring-1 ring-line', d.date === today && 'ring-2 ring-primary')}>
              <header className="flex items-center justify-between px-4 pt-3 pb-2">
                <h2 className="text-[14.5px] font-semibold capitalize">{date(d.date, 'day')}</h2>
                {d.date === today && <Badge tone="primary">Bugün</Badge>}
              </header>
              {d.lessons.length === 0 && d.study.length === 0 ? (
                <p className="px-4 pb-4 text-[14px] text-ink-3">Ders yok.</p>
              ) : (
                <ul>
                  {d.lessons.map((l) => (
                    <li key={`l${l.id}`} className="flex items-center gap-3 border-t border-line px-4 py-2.5">
                      <span className="w-[88px] shrink-0 text-[12.5px] tabular text-ink-2">{time(l.starts_at)}–{time(l.ends_at)}</span>
                      <div className="min-w-0 flex-1">
                        <p className={cn('break-words text-[14.5px] font-medium', l.status === 'cancelled' && 'line-through text-ink-3')}>{l.subject}</p>
                        <p className="break-words text-[12.5px] text-ink-3">{[l.teacher, l.classroom].filter(Boolean).join(' · ')}</p>
                        {l.topic_note && <p className="break-words text-[12.5px] text-ink-2">Konu: {l.topic_note}</p>}
                      </div>
                      {l.status === 'cancelled' ? <Badge>İptal</Badge> : l.attendance ? <Badge tone={attendanceTone[l.attendance] ?? 'neutral'}>{l.attendance_label}</Badge> : null}
                    </li>
                  ))}
                  {d.study.map((st) => (
                    <li key={`s${st.id}`} className="flex items-center gap-3 border-t border-line bg-surface-2/50 px-4 py-2.5">
                      <span className="w-[88px] shrink-0 text-[12.5px] tabular text-ink-2">{time(st.starts_at)}–{time(st.ends_at)}</span>
                      <div className="min-w-0 flex-1">
                        <p className="break-words text-[14.5px] font-medium">{st.kind === 'private' ? 'Birebir ders' : 'Etüt'}{st.subject ? ` · ${st.subject}` : ''}</p>
                        <p className="break-words text-[12.5px] text-ink-3">{[st.teacher, st.classroom, st.topic].filter(Boolean).join(' · ')}</p>
                      </div>
                      <Badge tone="accent">{st.kind === 'private' ? 'Birebir' : 'Etüt'}</Badge>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          ))}
        </div>
      )}
    </div>
  )
}
