import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, BookOpenCheck, ClipboardCheck, GraduationCap, NotebookPen, Plus, Target, TriangleAlert } from 'lucide-react'
import { ApiError } from '@/lib/api'
import { date, dateTime, time } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Avatar, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Panel } from '@/components/ui/layout'
import { MiniStat } from '@/modules/portal/ui'
import { attendanceTone, homeworkTone, useTeacherCan, useTeacherQuery, type ObservationOptions, type ObservationRow } from '../api'
import { ObservationForm } from '../ObservationForm'
import { ObservationList } from '../ObservationList'
import { usePortalPageTitle } from '@/modules/portal/PortalLayout'

type Data = {
  student: {
    id: number; full_name: string; first_name: string; student_no: string; photo_url: string | null
    school_name: string | null; school_grade: string | null; field: string | null; status_label: string
    class_groups: { id: number; name: string; program: string | null }[]
    target: { university: string | null; department: string | null; tyt: string | null; ayt: string | null }
  }
  attendance: {
    summary: { total: number; present: number; late: number; absent: number; excused: number }
    recent: { id: number; status: string; status_label: string; late_minutes: number | null; starts_at: string; subject: string; is_mine: boolean }[]
  }
  exams: { id: number; name: string; exam_date: string; type: string; net: string; correct: number; wrong: number; blank: number; class_rank: number | null; institution_rank: number | null; participant_count: number; sections: { name: string; net: string }[] }[]
  homework: { id: number; homework_id: number; title: string; due_at: string; status: string; status_label: string; score: number | null; submitted_at: string | null; subject: string; is_mine: boolean }[]
  observations: ObservationRow[]
  weak_topics: { topic: string; subject: string; asked: number; correct: number; wrong: number; rate: number }[]
  observation_options: ObservationOptions
  can: { observe: boolean }
}

export default function TeacherStudent() {
  const { id } = useParams()
  const navigate = useNavigate()
  const can = useTeacherCan()
  const { data, isLoading, error } = useTeacherQuery<Data>(['students', 'detail', id], `/students/${id}`)
  const [adding, setAdding] = useState(false)

  usePortalPageTitle(data?.student?.full_name)
  if (error) {
    return <EmptyState icon={<TriangleAlert />} title="Öğrenci açılamadı" description={error instanceof ApiError ? error.message : undefined} action={<ButtonLink to="/ogretmen/siniflar?sekme=ogrenciler">Öğrencilerime dön</ButtonLink>} />
  }
  if (isLoading || !data) return <div className="flex flex-col gap-3"><Skeleton className="h-20" /><Skeleton className="h-96" /></div>

  const s = data.student
  const a = data.attendance.summary
  const attended = a.present + a.late
  const rate = a.total ? Math.round(attended / a.total * 100) : null
  const mine = data.homework.filter((h) => h.is_mine)
  const mineDone = mine.filter((h) => h.status === 'submitted' || h.status === 'late').length
  const scores = mine.filter((h) => h.score !== null)
  const avgScore = scores.length ? Math.round(scores.reduce((x, h) => x + (h.score ?? 0), 0) / scores.length) : null
  const points = data.observations.reduce((x, o) => x + o.points, 0)
  const lastExam = data.exams[0]
  const prevExam = data.exams.find((e, i) => i > 0 && e.type === lastExam?.type)
  const diff = lastExam && prevExam ? Number(lastExam.net) - Number(prevExam.net) : null
  const target = [s.target.university, s.target.department].filter(Boolean).join(' · ')

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <div>
        <button onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/ogretmen/siniflar'))} className="hidden items-center gap-1 text-[12.5px] font-medium text-ink-3 hover:text-ink md:inline-flex"><ArrowLeft className="size-3.5" /> Geri</button>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <Avatar name={s.full_name} src={s.photo_url} size={52} />
          <div className="min-w-0 flex-1">
            <h1 className="break-words text-[20px] font-semibold tracking-[-0.02em] sm:text-[22px]">{s.full_name}</h1>
            <p className="text-[14px] text-ink-3">
              Öğrenci no: <span className="tabular">{s.student_no}</span>
              {s.class_groups.length > 0 && <> · Sınıf: {s.class_groups.map((g) => g.name).join(', ')}</>}
              {s.school_name && <> · Okulu: {s.school_name}{s.school_grade ? ` (${s.school_grade})` : ''}</>}
            </p>
            {target && <p className="mt-0.5 inline-flex items-center gap-1 text-[12.5px] text-ink-2"><Target className="size-3.5 text-primary" /> Hedef: {target}</p>}
          </div>
          {data.can.observe && can('observations') && (
            <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>Gözlem ekle</Button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <MiniStat label="Devam oranı" value={rate !== null ? `%${rate}` : '—'} sub={`${a.absent} kez gelmedi · ${a.late} kez geç`} tone={rate !== null && rate < 85 ? 'warning' : undefined} />
        <MiniStat label="Verdiğim ödevler (yapılan)" value={mine.length ? `${mineDone}/${mine.length}` : '—'} sub={avgScore !== null ? `ortalama puan ${avgScore}` : 'henüz puan yok'} />
        <MiniStat label="Son deneme neti" value={lastExam?.net ?? '—'} sub={diff !== null ? `${diff >= 0 ? '+' : ''}${diff.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} önceki sınava göre` : lastExam?.name} tone={diff !== null ? (diff >= 0 ? 'success' : 'danger') : undefined} />
        <MiniStat label="Davranış puanı" value={points > 0 ? `+${points}` : points} sub={`${data.observations.length} gözlem notu`} tone={points > 0 ? 'success' : points < 0 ? 'danger' : undefined} />
      </div>

      <div className="grid gap-4 lg:grid-cols-5">
        <div className="flex flex-col gap-4 lg:col-span-3">
          <Panel title={<span className="inline-flex items-center gap-1.5"><NotebookPen className="size-4 text-primary" /> Gözlem notları</span>} flush>
            {data.observations.length === 0 ? (
              <p className="px-4 pb-4 text-[14px] text-ink-3">Bu öğrenci için henüz gözlem notu yok.</p>
            ) : <div className="border-t border-line"><ObservationList rows={data.observations} /></div>}
          </Panel>

          <Panel title={<span className="inline-flex items-center gap-1.5"><BookOpenCheck className="size-4 text-primary" /> Ödevler</span>} description="Sizin ve diğer öğretmenlerin ödevleri" flush>
            {data.homework.length === 0 ? <p className="px-4 pb-4 text-[14px] text-ink-3">Ödev yok.</p> : (
              <ul>
                {data.homework.map((h) => {
                  const body = (
                    <>
                      <span className="min-w-0 flex-1">
                        <span className="block break-words text-[14.5px] font-medium">{h.title}</span>
                        <span className="block break-words text-[12.5px] text-ink-3">{h.subject} · son teslim {date(h.due_at)}{!h.is_mine ? ' · başka öğretmenin ödevi' : ''}</span>
                      </span>
                      {h.score !== null && <span className="text-[14px] font-semibold tabular" title="Puan">{h.score} puan</span>}
                      <Badge tone={homeworkTone[h.status] ?? 'neutral'} dot>{h.status_label}</Badge>
                    </>
                  )
                  return (
                    <li key={h.id} className="border-t border-line">
                      {h.is_mine ? (
                        <Link to={`/ogretmen/odevler/${h.homework_id}`} className="flex items-center gap-3 px-4 py-2.5 hover:bg-surface-2/60">{body}</Link>
                      ) : <div className="flex items-center gap-3 px-4 py-2.5">{body}</div>}
                    </li>
                  )
                })}
              </ul>
            )}
          </Panel>
        </div>

        <div className="flex flex-col gap-4 lg:col-span-2">
          <Panel title={<span className="inline-flex items-center gap-1.5"><GraduationCap className="size-4 text-primary" /> Sınav sonuçları</span>} flush>
            {data.exams.length === 0 ? <p className="px-4 pb-4 text-[14px] text-ink-3">Yayımlanmış sonuç yok.</p> : (
              <ul>
                {data.exams.slice(0, 6).map((e) => (
                  <li key={e.id} className="border-t border-line px-4 py-2.5">
                    <div className="flex items-baseline justify-between gap-3">
                      <span className="min-w-0 break-words text-[14.5px] font-medium">{e.name}</span>
                      <span className="shrink-0 text-[14px] font-semibold tabular">{e.net} net</span>
                    </div>
                    <p className="text-[12.5px] text-ink-3">{date(e.exam_date)} · {e.type}{e.class_rank ? ` · sınıf sırası ${e.class_rank}` : ''}{e.institution_rank ? ` · kurum sırası ${e.institution_rank}/${e.participant_count}` : ''}</p>
                    {e.sections.length > 0 && (
                      <p className="mt-1 flex flex-wrap gap-x-2.5 gap-y-0.5 text-[12.5px] text-ink-2">
                        {e.sections.map((x) => <span key={x.name}>{x.name} <b className="tabular">{x.net}</b></span>)}
                      </p>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          {data.weak_topics.length > 0 && (
            <Panel title="Zorlandığı konular" description="Sınavlarda en düşük doğru oranı" >
              <ul className="flex flex-col gap-2.5">
                {data.weak_topics.map((t) => (
                  <li key={`${t.subject}-${t.topic}`}>
                    <div className="flex items-baseline justify-between gap-2 text-[12.5px]">
                      <span className="min-w-0 break-words">{t.topic} <span className="text-ink-3">· {t.subject}</span></span>
                      <span className="shrink-0 tabular text-ink-2">%{t.rate}</span>
                    </div>
                    <ProgressBar className="mt-1" value={t.rate} tone={t.rate < 40 ? 'danger' : t.rate < 60 ? 'warning' : 'primary'} />
                  </li>
                ))}
              </ul>
            </Panel>
          )}

          <Panel title={<span className="inline-flex items-center gap-1.5"><ClipboardCheck className="size-4 text-primary" /> Devamsızlık kayıtları</span>} flush>
            {data.attendance.recent.length === 0 ? <p className="px-4 pb-4 text-[14px] text-ink-3">Devamsızlık kaydı yok.</p> : (
              <ul>
                {data.attendance.recent.map((r) => (
                  <li key={r.id} className="flex items-center justify-between gap-3 border-t border-line px-4 py-2">
                    <span className="min-w-0 break-words text-[14px]">
                      {r.subject}{r.is_mine && <span className="text-ink-3"> · dersiniz</span>}
                      <span className="block text-[12.5px] text-ink-3 tabular">{dateTime(r.starts_at).slice(0, 10)} {time(r.starts_at)}</span>
                    </span>
                    <Badge tone={attendanceTone[r.status] ?? 'neutral'} dot>{r.status_label}{r.late_minutes ? ` ${r.late_minutes} dk` : ''}</Badge>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          <p className={cn('text-[12.5px] text-ink-3')}>Telefon, adres ve ödeme bilgileri öğretmen portalında gösterilmez.</p>
        </div>
      </div>

      {adding && <ObservationForm studentId={s.id} studentName={s.full_name} options={data.observation_options} onClose={() => setAdding(false)} />}
    </div>
  )
}
