import { Link } from 'react-router-dom'
import { MessageSquareHeart, Star } from 'lucide-react'
import { date, dateTime, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Panel } from '@/components/ui/layout'
import { homeworkTone, usePortal, useVoice } from '../api'
import { MiniStat, PortalTitle } from '../ui'

type Observation = { id: number; kind: string; kind_label: string; category_label: string; points: number; body: string; subject: string | null; teacher: string | null; created_at: string }
type Graded = { id: number; title: string; due_at: string; status: string; score: number | null; teacher_note: string | null; graded_at: string | null; subject: string; teacher: string | null }
type Data = {
  observations: Observation[]
  points_total: number
  homework: Graded[]
  homework_by_subject: { subject: string; count: number; average: number }[]
  homework_average: number | null
}

const kindTone: Record<string, 'success' | 'warning' | 'neutral'> = { positive: 'success', improve: 'warning', note: 'neutral' }
const LABELS: Record<string, string> = { assigned: 'Yapılacak', seen: 'Görüldü', submitted: 'Teslim edildi', late: 'Geç teslim', missed: 'Teslim edilmedi' }

export default function PortalFeedback() {
  const v = useVoice()
  const { data, isLoading } = usePortal<Data>('feedback', '/portal/feedback')
  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-16 w-2/3" /><Skeleton className="h-72" /></div>

  const positive = data.observations.filter((o) => o.kind === 'positive').length

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Öğretmen geri bildirimleri" description={v('Öğretmenlerinin senin için yazdığı notlar ve ödev puanların', 'Öğretmenlerin öğrenci için yazdığı notlar ve ödev puanları')} />
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <MiniStat label="Davranış puanı" value={data.points_total > 0 ? `+${data.points_total}` : data.points_total} tone={data.points_total > 0 ? 'success' : data.points_total < 0 ? 'danger' : undefined} />
        <MiniStat label="Olumlu not" value={positive} sub={`${data.observations.length} not`} />
        <MiniStat label="Ödev ortalaması" value={data.homework_average ?? '—'} sub="100 üzerinden" />
        <MiniStat label="Puanlanan ödev" value={data.homework.filter((h) => h.score !== null).length} />
      </div>

      <div className="grid gap-4 lg:grid-cols-5">
        <Panel className="lg:col-span-3" title={<span className="inline-flex items-center gap-1.5"><MessageSquareHeart className="size-4 text-primary" /> Öğretmen notları</span>} flush>
          {data.observations.length === 0 ? (
            <EmptyState compact icon={<Star />} title="Henüz not yok" description="Öğretmenler ders içi gözlemlerini paylaştıkça burada görünür." />
          ) : (
            <ul>
              {data.observations.map((o) => (
                <li key={o.id} className="flex gap-3 border-t border-line px-4 py-3">
                  <span className={cn('inline-flex h-7 min-w-10 shrink-0 items-center justify-center rounded-full px-2 text-[12.5px] font-semibold tabular',
                    o.points > 0 ? 'bg-success-soft text-success' : o.points < 0 ? 'bg-danger-soft text-danger' : 'bg-surface-2 text-ink-3')}>
                    {o.points > 0 ? `+${o.points}` : o.points}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                      <Badge tone={kindTone[o.kind] ?? 'neutral'}>{o.kind_label}</Badge>
                      <span className="text-[12.5px] text-ink-3">{o.category_label}</span>
                    </p>
                    <p className="mt-1 whitespace-pre-line text-[14.5px]">{o.body}</p>
                    <p className="mt-0.5 text-[12.5px] text-ink-3">{[o.teacher, o.subject].filter(Boolean).join(' · ')} · <span title={dateTime(o.created_at)}>{relative(o.created_at)}</span></p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <div className="flex flex-col gap-4 lg:col-span-2">
          <Panel title="Derslere göre ödev ortalaması">
            {data.homework_by_subject.length === 0 ? <p className="text-[14px] text-ink-3">Puanlanmış ödev yok.</p> : (
              <ul className="flex flex-col gap-2.5">
                {data.homework_by_subject.map((s) => (
                  <li key={s.subject}>
                    <div className="flex items-baseline justify-between gap-2 text-[14px]">
                      <span className="min-w-0 break-words">{s.subject} <span className="text-ink-3">· {s.count} ödev</span></span>
                      <span className="shrink-0 font-semibold tabular">{s.average}</span>
                    </div>
                    <ProgressBar className="mt-1" value={s.average} tone={s.average >= 70 ? 'success' : s.average >= 50 ? 'warning' : 'danger'} />
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          <Panel title="Değerlendirilen ödevler" flush>
            {data.homework.length === 0 ? <p className="px-4 pb-4 text-[14px] text-ink-3">Henüz değerlendirme yok.</p> : (
              <ul>
                {data.homework.map((h) => (
                  <li key={h.id} className="border-t border-line px-4 py-2.5">
                    <div className="flex items-start justify-between gap-2">
                      <span className="min-w-0">
                        <Link to={`/portal/odevler/${h.id}`} className="block break-words text-[14.5px] font-medium hover:underline">{h.title}</Link>
                        <span className="block break-words text-[12.5px] text-ink-3">{h.subject}{h.teacher ? ` · ${h.teacher}` : ''} · {date(h.due_at)}</span>
                      </span>
                      {h.score !== null ? <span className="shrink-0 text-[16px] font-semibold tabular">{h.score}</span> : <Badge tone={homeworkTone[h.status] ?? 'neutral'}>{LABELS[h.status] ?? h.status}</Badge>}
                    </div>
                    {h.teacher_note && <p className="mt-1 rounded-[var(--radius-sm)] bg-surface-2 px-2.5 py-1.5 text-[12.5px] text-ink-2">{h.teacher_note}</p>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </div>
      </div>
    </div>
  )
}
