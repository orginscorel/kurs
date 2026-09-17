import { useState } from 'react'
import { Link } from 'react-router-dom'
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react'
import { date, time, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { PortalTitle } from '@/modules/portal/ui'
import { shiftDay, useTeacherQuery, type SessionRow, type StudyRow } from '../api'
import { LessonStatus } from './TeacherHome'

type Data = { week_start: string; week_end: string; total_lessons: number; days: { date: string; lessons: SessionRow[]; study: StudyRow[] }[] }

export default function TeacherSchedule() {
  const today = todayISO()
  const [ref, setRef] = useState(today)
  const { data, isLoading } = useTeacherQuery<Data>(['schedule'], '/schedule', { date: ref })

  return (
    <div className="animate-fade-in">
      <PortalTitle title="Ders programım" description={data ? `Bu hafta ${data.total_lessons} ders` : 'Haftalık dersler ve etütler'} />

      <div className="mb-4 flex items-center justify-between gap-2 rounded-[var(--radius-lg)] bg-surface p-2 ring-1 ring-line">
        <Button variant="ghost" size="icon" onClick={() => setRef(shiftDay(data?.week_start ?? ref, -7))} aria-label="Önceki hafta"><ChevronLeft className="size-4" /></Button>
        <div className="text-center">
          <p className="text-[14.5px] font-semibold tabular">{data ? `${date(data.week_start)} – ${date(data.week_end)}` : '…'}</p>
          {ref !== today && <button className="text-[12.5px] font-medium text-primary" onClick={() => setRef(today)}>Bu haftaya dön</button>}
        </div>
        <Button variant="ghost" size="icon" onClick={() => setRef(shiftDay(data?.week_start ?? ref, 7))} aria-label="Sonraki hafta"><ChevronRight className="size-4" /></Button>
      </div>

      {isLoading || !data ? (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-40" />)}</div>
      ) : data.days.every((d) => d.lessons.length === 0 && d.study.length === 0) ? (
        <EmptyState icon={<CalendarDays />} title="Bu hafta için planlanmış dersiniz yok" />
      ) : (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {data.days.filter((d) => d.lessons.length > 0 || d.study.length > 0 || d.date === today).map((d) => (
            <section key={d.date} className={cn('min-w-0 rounded-[var(--radius-lg)] bg-surface ring-1 ring-line', d.date === today && 'ring-2 ring-primary')}>
              <header className="flex items-center justify-between px-4 pb-2 pt-3">
                <h2 className="text-[14.5px] font-semibold capitalize">{date(d.date, 'day')}</h2>
                <span className="flex items-center gap-1.5">
                  <span className="text-[12.5px] text-ink-3">{d.lessons.filter((l) => l.status !== 'cancelled').length} ders</span>
                  {d.date === today && <Badge tone="primary">Bugün</Badge>}
                </span>
              </header>
              {d.lessons.length === 0 && d.study.length === 0 ? (
                <p className="px-4 pb-4 text-[14px] text-ink-3">Ders yok.</p>
              ) : (
                <ul>
                  {d.lessons.map((l) => (
                    <li key={`l${l.id}`} className="border-t border-line">
                      <Link to={`/ogretmen/yoklama/${l.id}`} className="flex items-center gap-3 px-4 py-2.5 hover:bg-surface-2/60">
                        <span className="w-[88px] shrink-0 text-[12.5px] tabular text-ink-2">{time(l.starts_at)}–{time(l.ends_at)}</span>
                        <div className="min-w-0 flex-1">
                          <p className={cn('break-words text-[14.5px] font-medium', l.status === 'cancelled' && 'text-ink-3 line-through')}>{l.class_group} · {l.subject}</p>
                          <p className="break-words text-[12.5px] text-ink-3">{l.status === 'cancelled' ? (l.cancel_reason ?? 'İptal edildi') : [l.classroom, l.topic_note].filter(Boolean).join(' · ') || `${l.roster} öğrenci`}</p>
                          <div className="mt-1"><LessonStatus l={l} /></div>
                        </div>
                      </Link>
                    </li>
                  ))}
                  {d.study.map((st) => (
                    <li key={`s${st.id}`} className="flex items-center gap-3 border-t border-line bg-surface-2/50 px-4 py-2.5">
                      <span className="w-[88px] shrink-0 text-[12.5px] tabular text-ink-2">{time(st.starts_at)}–{time(st.ends_at)}</span>
                      <div className="min-w-0 flex-1">
                        <p className="break-words text-[14.5px] font-medium">{st.kind === 'private' ? 'Birebir ders' : 'Etüt'}{st.subject ? ` · ${st.subject}` : ''}</p>
                        <p className="break-words text-[12.5px] text-ink-3">{[st.classroom, st.topic, `${st.student_count} öğrenci`].filter(Boolean).join(' · ')}</p>
                      </div>
                      <Badge tone="accent">{st.status === 'requested' ? 'Onay bekliyor' : st.kind === 'private' ? 'Birebir' : 'Etüt'}</Badge>
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
