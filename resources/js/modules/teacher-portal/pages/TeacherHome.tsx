import { Link } from 'react-router-dom'
import { ArrowRight, BookOpenCheck, CalendarDays, ClipboardCheck, GraduationCap, Inbox, Megaphone } from 'lucide-react'
import { date, dateTime, relative, time } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Panel } from '@/components/ui/layout'
import { MiniStat } from '@/modules/portal/ui'
import { useTeacherQuery, type Summary, type SessionRow } from '../api'

function greeting() {
  const h = new Date().getHours()
  return h < 6 ? 'İyi geceler' : h < 12 ? 'Günaydın' : h < 18 ? 'İyi günler' : 'İyi akşamlar'
}

function More({ to, children = 'Tümü' }: { to: string; children?: string }) {
  return (
    <Link to={to} className="inline-flex items-center gap-1 text-[12.5px] font-medium text-primary hover:underline">
      {children} <ArrowRight className="size-3.5" />
    </Link>
  )
}

export function LessonStatus({ l }: { l: SessionRow }) {
  if (l.status === 'cancelled') return <Badge>İptal</Badge>
  if (l.attendance_taken_at) {
    return <Badge tone={l.absent > 0 ? 'warning' : 'success'} dot>Yoklama alındı{l.absent > 0 ? ` · ${l.absent} yok` : ''}</Badge>
  }
  if (l.can_take_attendance) return <Badge tone="danger" dot>Yoklama bekliyor</Badge>
  return <Badge tone="neutral">Planlandı</Badge>
}

export default function TeacherHome() {
  const { data, isLoading, error } = useTeacherQuery<Summary>(['summary'], '/summary')

  if (error) return <Alert tone="danger">Bilgiler yüklenemedi. Lütfen sayfayı yenileyin.</Alert>
  if (isLoading || !data) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton className="h-16 w-2/3" />
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">{Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-20" />)}</div>
        <Skeleton className="h-48" />
      </div>
    )
  }

  const t = data.teacher
  const c = data.counts
  const now = Date.now()
  const lessons = data.today.lessons
  const current = lessons.find((l) => l.status !== 'cancelled' && new Date(l.starts_at).getTime() <= now && new Date(l.ends_at).getTime() > now)
  const next = lessons.find((l) => l.status !== 'cancelled' && new Date(l.starts_at).getTime() > now)

  return (
    <div className="animate-fade-in flex flex-col gap-5">
      <section>
        <p className="text-[14px] text-ink-3">{date(data.today.date, 'day')}</p>
        <h1 className="mt-0.5 text-[22px] font-semibold tracking-[-0.02em] sm:text-[26px]">{greeting()}, {t.first_name} Hocam</h1>
        <p className="mt-1 text-[14px] text-ink-2">
          {[t.title, `${c.classes} sınıf`, `${c.students} öğrenci`, `bu hafta ${c.week_lessons} ders`].filter(Boolean).join(' · ')}
        </p>
        {(current || next) && (
          <p className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-2.5 py-1 text-[12.5px] font-medium text-primary-ink">
            <CalendarDays className="size-3.5" />
            {current ? `Şu an: ${current.subject} · ${current.class_group} (${time(current.ends_at)}'e kadar)` : `Sıradaki: ${next!.subject} · ${next!.class_group} · ${time(next!.starts_at)}`}
          </p>
        )}
      </section>

      {data.pending_attendance.length > 0 && (
        <Alert tone="warning" title={`${data.pending_attendance.length} dersin yoklaması bekliyor`}>
          <span className="flex flex-wrap gap-x-3 gap-y-1">
            {data.pending_attendance.slice(0, 4).map((p) => (
              <Link key={p.id} to={`/ogretmen/yoklama/${p.id}`} className="font-medium underline">
                {p.class_group} · {p.subject} · {date(p.date).slice(0, 5)} {time(p.starts_at)}
              </Link>
            ))}
          </span>
        </Alert>
      )}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Link to="/ogretmen/yoklama"><MiniStat label="Bugünkü ders" value={lessons.filter((l) => l.status !== 'cancelled').length} sub={`${data.pending_attendance.length} yoklama bekliyor`} tone={data.pending_attendance.length ? 'warning' : undefined} /></Link>
        <Link to="/ogretmen/odevler?durum=to_grade"><MiniStat label="Kontrol edilecek teslim" value={c.to_grade} sub={`${c.open_homework} açık ödev`} tone={c.to_grade ? 'warning' : undefined} /></Link>
        <Link to="/ogretmen/talepler"><MiniStat label="Veli talebi" value={c.open_requests} sub={c.open_requests ? 'Yanıt bekliyor' : 'Bekleyen yok'} tone={c.open_requests ? 'danger' : undefined} /></Link>
        <Link to="/ogretmen/gozlemler"><MiniStat label="Bu hafta gözlem" value={c.observations_week} sub="öğrenci notu / puanı" /></Link>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-5">
        <Panel className="lg:col-span-3" title={<span className="inline-flex items-center gap-1.5"><CalendarDays className="size-4 text-primary" /> Bugünkü derslerim</span>} actions={<More to="/ogretmen/program">Haftalık program</More>} flush>
          {lessons.length === 0 ? (
            <EmptyState compact icon={<CalendarDays />} title="Bugün dersiniz yok" description="Haftalık programınıza göz atabilirsiniz." />
          ) : (
            <ul>
              {lessons.map((l) => {
                const isNow = current?.id === l.id
                return (
                  <li key={l.id} className={cn('border-t border-line', isNow && 'bg-primary-soft/60')}>
                    <Link to={`/ogretmen/yoklama/${l.id}`} className="flex items-center gap-3 px-4 py-3 hover:bg-surface-2/60">
                      <div className="w-14 shrink-0 text-[12.5px] tabular">
                        <p className="font-semibold">{time(l.starts_at)}</p>
                        <p className="text-ink-3">{time(l.ends_at)}</p>
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className={cn('break-words text-[14px] font-medium', l.status === 'cancelled' && 'line-through text-ink-3')}>{l.class_group} · {l.subject}</p>
                        <p className="break-words text-[12.5px] text-ink-3">{[l.classroom, `${l.roster} öğrenci`].filter(Boolean).join(' · ')}</p>
                      </div>
                      <LessonStatus l={l} />
                    </Link>
                  </li>
                )
              })}
            </ul>
          )}
        </Panel>

        <div className="flex flex-col gap-4 lg:col-span-2">
          <Panel title={<span className="inline-flex items-center gap-1.5"><BookOpenCheck className="size-4 text-primary" /> Kontrol bekleyen ödevler</span>} actions={<More to="/ogretmen/odevler" />} flush>
            {data.homework_to_grade.length === 0 ? (
              <p className="px-4 pb-4 text-[14px] text-ink-3">Değerlendirme bekleyen teslim yok.</p>
            ) : (
              <ul>
                {data.homework_to_grade.map((h) => (
                  <li key={h.id} className="border-t border-line">
                    <Link to={`/ogretmen/odevler/${h.id}`} className="flex items-center gap-3 px-4 py-2.5 hover:bg-surface-2/60">
                      <div className="min-w-0 flex-1">
                        <p className="break-words text-[14.5px] font-medium">{h.title}</p>
                        <p className="break-words text-[12.5px] text-ink-3">{[h.class_group, h.subject, `teslim ${date(h.due_at)}`].filter(Boolean).join(' · ')}</p>
                      </div>
                      <Badge tone="warning">{h.pending_count} teslim</Badge>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel title={<span className="inline-flex items-center gap-1.5"><GraduationCap className="size-4 text-primary" /> Yaklaşan sınavlar</span>} actions={<More to="/ogretmen/sinavlar" />} flush>
            {data.upcoming_exams.length === 0 ? (
              <p className="px-4 pb-4 text-[14px] text-ink-3">Önümüzdeki iki haftada sınav yok.</p>
            ) : (
              <ul>
                {data.upcoming_exams.map((e) => (
                  <li key={e.id} className="flex items-center justify-between gap-3 border-t border-line px-4 py-2.5">
                    <span className="min-w-0 break-words text-[14.5px]">{e.name}</span>
                    <span className="shrink-0 text-[12.5px] text-ink-3 tabular">{date(e.exam_date)} · {e.type}</span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          {c.open_requests > 0 && (
            <Panel title={<span className="inline-flex items-center gap-1.5"><Inbox className="size-4 text-primary" /> Veli talepleri</span>} actions={<More to="/ogretmen/talepler">Yanıtla</More>}>
              <p className="text-[14px] text-ink-2">{c.open_requests} veli talebi yanıtınızı bekliyor.</p>
            </Panel>
          )}
        </div>
      </div>

      <Panel title={<span className="inline-flex items-center gap-1.5"><Megaphone className="size-4 text-primary" /> Duyurular{c.unread_announcements > 0 && <Badge tone="danger">{c.unread_announcements} yeni</Badge>}</span>} actions={<More to="/ogretmen/duyurular" />} flush>
        {data.announcements.length === 0 ? (
          <p className="px-4 pb-4 text-[14px] text-ink-3">Yeni duyuru yok.</p>
        ) : (
          <ul>
            {data.announcements.map((a) => (
              <li key={a.id} className="border-t border-line px-4 py-3">
                <div className="flex items-baseline justify-between gap-3">
                  <p className="min-w-0 break-words text-[14.5px] font-medium">{a.title}</p>
                  <span className="shrink-0 text-[12.5px] text-ink-3" title={dateTime(a.published_at)}>{relative(a.published_at)}</span>
                </div>
                <p className="mt-0.5 line-clamp-2 text-[14px] text-ink-2">{a.body}</p>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <p className="flex items-center gap-1.5 text-[12.5px] text-ink-3"><ClipboardCheck className="size-3.5" /> Yoklama dersin başlamasına 15 dakika kala açılır ve 7 gün geriye kadar düzeltilebilir.</p>
    </div>
  )
}
