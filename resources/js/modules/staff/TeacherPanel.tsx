import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { BookOpen, CalendarCheck, ClipboardCheck, Megaphone, Users } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { ButtonLink } from '@/components/ui/Button'
import { date, dateTime, time } from '@/lib/format'
import { useAuth } from '@/app/auth'
import { PageHeader, Panel, Stat } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'

type Summary = {
  teacher: { id: number; full_name: string }
  today_lessons: { id: number; starts_at: string; ends_at: string; status: string; subject: string; class_group: string; classroom: string; attendance_taken_at: string | null }[]
  next_lesson: Summary['today_lessons'][number] | null
  my_classes: { id: number; name: string; student_count: number }[]
  pending_attendance: { id: number; date: string; starts_at: string; subject: string; class_group: string }[]
  homework_to_grade: { id: number; title: string; pending_count: number }[]
  upcoming_exams: { id: number; name: string; exam_date: string; type: string }[]
  announcements: { id: number; title: string; body: string; published_at: string }[]
}

export default function TeacherPanel() {
  const me = useAuth((s) => s.me)
  const { data, isLoading, error } = useQuery({ queryKey: ['teacher-panel'], queryFn: () => api.get<Summary>('/teacher-panel/summary') })

  if (error) {
    const notTeacher = error instanceof ApiError && error.status === 403
    return (
      <div className="animate-fade-in">
        <PageHeader title="Öğretmen panelim" description="Öğretmen hesaplarına özel günlük özet" />
        {notTeacher ? (
          <EmptyState
            icon={<Users />}
            title="Bu ekran öğretmen hesapları içindir"
            description="Hesabınız bir öğretmen kaydına bağlı değil. Öğretmenlerin derslerini Ders programı ekranından, öğretmen kayıtlarını Öğretmenler ekranından görebilirsiniz."
            action={<div className="flex flex-wrap justify-center gap-2"><ButtonLink variant="primary" to="/">Panoya dön</ButtonLink><ButtonLink to="/ogretmenler">Öğretmenler</ButtonLink></div>}
          />
        ) : (
          <Alert tone="danger" title="Panel yüklenemedi">{error.message}</Alert>
        )}
      </div>
    )
  }

  if (isLoading || !data) {
    return (
      <div className="animate-fade-in space-y-4">
        <Skeleton className="h-16 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  return (
    <div className="animate-fade-in">
      <PageHeader title={`Merhaba, ${me?.user.name.split(' ')[0] ?? ''}`} description="Bugünkü dersleriniz ve bekleyen işleriniz" />

      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <Stat label="Bugünkü ders" value={data.today_lessons.length} icon={<CalendarCheck />} />
        <Stat label="Sınıflarım" value={data.my_classes.length} icon={<Users />} />
        <Stat label="Yoklama bekleyen" value={data.pending_attendance.length} tone={data.pending_attendance.length > 0 ? 'warning' : undefined} icon={<ClipboardCheck />} />
        <Stat label="Kontrol edilecek ödev" value={data.homework_to_grade.reduce((a, h) => a + h.pending_count, 0)} icon={<BookOpen />} />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Panel title="Bugün derslerim" description={data.next_lesson ? `Sıradaki: ${data.next_lesson.subject} · ${time(data.next_lesson.starts_at)}` : undefined}>
          {data.today_lessons.length === 0 ? <EmptyState title="Bugün dersiniz yok" /> : (
            <div className="flex flex-col divide-y divide-line">
              {data.today_lessons.map((l) => (
                <div key={l.id} className="flex items-center justify-between py-2.5">
                  <div>
                    <p className="text-[13.5px] text-ink">{l.subject} · {l.class_group}</p>
                    <p className="text-[12px] text-ink-3 tabular">{time(l.starts_at)}–{time(l.ends_at)} · {l.classroom}</p>
                  </div>
                  <Badge tone={l.status === 'completed' ? 'success' : l.status === 'cancelled' ? 'danger' : 'neutral'}>{l.status === 'completed' ? 'Tamamlandı' : l.status === 'cancelled' ? 'İptal' : 'Planlandı'}</Badge>
                </div>
              ))}
            </div>
          )}
        </Panel>

        <Panel title="Sınıflarım">
          {data.my_classes.length === 0 ? <EmptyState title="Atanmış sınıf yok" /> : (
            <div className="grid grid-cols-2 gap-2.5">
              {data.my_classes.map((c) => (
                <div key={c.id} className="rounded-[var(--radius-md)] ring-1 ring-line p-3">
                  <p className="font-medium text-ink text-[13.5px]">{c.name}</p>
                  <p className="text-[12px] text-ink-3">{c.student_count} öğrenci</p>
                </div>
              ))}
            </div>
          )}
        </Panel>

        <Panel title="Yoklaması bekleyen dersler">
          {data.pending_attendance.length === 0 ? <EmptyState title="Bekleyen yoklama yok" /> : (
            <div className="flex flex-col divide-y divide-line">
              {data.pending_attendance.map((p) => (
                <div key={p.id} className="flex items-center justify-between py-2">
                  <span className="text-[13px] text-ink">{p.subject} · {p.class_group}</span>
                  <span className="text-[12px] text-ink-3 tabular">{date(p.date)} {time(p.starts_at)}</span>
                </div>
              ))}
            </div>
          )}
        </Panel>

        <Panel title="Kontrol edilecek ödevler">
          {data.homework_to_grade.length === 0 ? <EmptyState title="Bekleyen ödev yok" /> : (
            <div className="flex flex-col divide-y divide-line">
              {data.homework_to_grade.map((h) => (
                <Link key={h.id} to={`/odevler/${h.id}`} className="flex items-center justify-between py-2 hover:text-primary transition-colors">
                  <span className="text-[13px] text-ink">{h.title}</span>
                  <Badge tone="warning">{h.pending_count} bekliyor</Badge>
                </Link>
              ))}
            </div>
          )}
        </Panel>

        <Panel title="Yaklaşan sınavlar">
          {data.upcoming_exams.length === 0 ? <EmptyState title="Yaklaşan sınav yok" /> : (
            <div className="flex flex-col divide-y divide-line">
              {data.upcoming_exams.map((e) => (
                <div key={e.id} className="flex items-center justify-between py-2">
                  <span className="text-[13px] text-ink">{e.name}</span>
                  <span className="text-[12px] text-ink-3">{date(e.exam_date)}</span>
                </div>
              ))}
            </div>
          )}
        </Panel>

        <Panel title="Duyurular" bodyClassName="max-h-72 overflow-y-auto scroll-thin">
          {data.announcements.length === 0 ? <EmptyState icon={<Megaphone />} title="Duyuru yok" /> : (
            <div className="flex flex-col divide-y divide-line">
              {data.announcements.map((a) => (
                <div key={a.id} className="py-2.5">
                  <p className="text-[13.5px] font-medium text-ink">{a.title}</p>
                  <p className="text-[12.5px] text-ink-2 mt-0.5 line-clamp-2">{a.body}</p>
                  <p className="text-[12px] text-ink-3 mt-1">{dateTime(a.published_at)}</p>
                </div>
              ))}
            </div>
          )}
        </Panel>
      </div>
    </div>
  )
}
