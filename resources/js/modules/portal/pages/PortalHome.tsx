import { Link } from 'react-router-dom'
import { ArrowRight, BookOpenCheck, CalendarDays, CheckCircle2, GraduationCap, Megaphone, Wallet } from 'lucide-react'
import { date, dateTime, duration, money, num, relative, time } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Panel } from '@/components/ui/layout'
import { attendanceTone, usePortal, type Summary, useVoice } from '../api'
import { useState } from 'react'
import { Button } from '@/components/ui/Button'
import { MiniStat } from '../ui'

function More({ to, children = 'Tümü' }: { to: string; children?: string }) {
  return (
    <Link to={to} className="inline-flex items-center gap-1 text-[12.5px] font-medium text-primary hover:underline">
      {children} <ArrowRight className="size-3.5" />
    </Link>
  )
}

export default function PortalHome() {
  const { data, isLoading, error } = usePortal<Summary>('summary', '/portal/summary')
  const v = useVoice()

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

  const s = data.student
  const now = Date.now()
  const lessons = data.today.lessons
  const current = lessons.find((l) => new Date(l.starts_at).getTime() <= now && new Date(l.ends_at).getTime() > now && l.status !== 'cancelled')
  const exam = data.last_exam
  const delta = exam && data.previous_exam_net !== null ? Number(exam.net) - Number(data.previous_exam_net) : null
  const f = data.finance
  const att = data.attendance
  const presence = data.today.presence

  return (
    <div className="animate-fade-in flex flex-col gap-5">
      <OverdueBanner />
      <section>
        <p className="text-[18px] font-semibold tracking-[-0.01em] text-ink">{s.full_name}</p>
        <p className="mt-0.5 text-[14px] text-ink-2">
          <span className="tabular">No {s.student_no}</span>
          {s.class_groups.length > 0 && <> · {s.class_groups.map((g) => g.name).join(', ')}</>}
          {s.class_groups[0]?.program && <> · {s.class_groups[0].program}</>}
        </p>
        {presence?.first_entry_at && (
          <p className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-success-soft px-2.5 py-1 text-[12.5px] font-medium text-success">
            <CheckCircle2 className="size-3.5" />
            {presence.is_inside ? `${v('Kurumdasın', 'Şu an kurumda')} · giriş ${time(presence.first_entry_at)}` : `Bugün ${duration(presence.minutes_inside)} ${v('kurumdaydın', 'kurumdaydı')}`}
          </p>
        )}
      </section>


      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Link to="/portal/sinavlar">
          <MiniStat
            label="Son sınav neti"
            value={exam ? num(exam.net, 2) : '—'}
            sub={exam ? (delta !== null ? `${delta >= 0 ? '+' : ''}${num(delta, 2)} önceki sınava göre` : exam.name) : 'Henüz sonuç yok'}
            tone={delta !== null ? (delta >= 0 ? 'success' : 'danger') : undefined}
          />
        </Link>
        <Link to="/portal/yoklama">
          <MiniStat
            label="Devamsızlık (30 gün)"
            value={`${num(att.absent_30)} ders`}
            sub={att.total_30 ? `%${Math.round((att.attended_30 / att.total_30) * 100)} katılım · ${num(att.late_30)} geç` : 'Kayıt yok'}
            tone={att.absent_30 >= 5 ? 'danger' : att.absent_30 >= 2 ? 'warning' : undefined}
          />
        </Link>
        <Link to="/portal/odevler">
          <MiniStat label="Bekleyen ödev" value={num(data.homework.open_count)} sub={data.homework.next[0] ? `Sıradaki ${date(data.homework.next[0].due_at)}` : 'Bekleyen ödev yok'} />
        </Link>
        <Link to="/portal/odemeler">
          <MiniStat
            label="Kalan borç"
            value={money(f.remaining, { short: true })}
            sub={f.next_installment ? `${f.next_installment.sequence}. taksit · ${date(f.next_installment.due_date)}` : 'Bekleyen taksit yok'}
            tone={Number(f.overdue) > 0 ? 'danger' : undefined}
          />
        </Link>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-5">
        <Panel className="lg:col-span-3" title={<span className="inline-flex items-center gap-1.5"><CalendarDays className="size-4 text-primary" /> Bugünkü dersler</span>} actions={<More to="/portal/program">Haftalık program</More>} flush>
          {lessons.length === 0 ? (
            <EmptyState compact icon={<CalendarDays />} title={v('Bugün dersin yok', 'Bugün dersi yok')} description={v('Haftalık programına göz atabilirsin.', 'Haftalık programa göz atabilirsiniz.')} />
          ) : (
            <ul>
              {lessons.map((l) => {
                const isNow = current?.id === l.id
                const past = new Date(l.ends_at).getTime() < now
                return (
                  <li key={l.id} className={cn('flex items-center gap-3 border-t border-line px-4 py-3', isNow && 'bg-primary-soft/60', past && !isNow && 'opacity-70')}>
                    <div className="w-14 shrink-0 text-[12.5px] tabular">
                      <p className="font-semibold">{time(l.starts_at)}</p>
                      <p className="text-ink-3">{time(l.ends_at)}</p>
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className={cn('break-words text-[14px] font-medium', l.status === 'cancelled' && 'line-through')}>{l.subject}</p>
                      <p className="break-words text-[12.5px] text-ink-3">{[l.teacher, l.classroom].filter(Boolean).join(' · ')}</p>
                    </div>
                    {l.status === 'cancelled' ? (
                      <Badge tone="neutral">İptal</Badge>
                    ) : l.attendance ? (
                      <Badge tone={attendanceTone[l.attendance] ?? 'neutral'} dot>{l.attendance_label}</Badge>
                    ) : isNow ? (
                      <Badge tone="primary" dot>Şu an</Badge>
                    ) : null}
                  </li>
                )
              })}
            </ul>
          )}
        </Panel>

        <div className="flex flex-col gap-4 lg:col-span-2">
          <Panel title={<span className="inline-flex items-center gap-1.5"><BookOpenCheck className="size-4 text-primary" /> Yaklaşan ödevler</span>} actions={<More to="/portal/odevler" />} flush>
            {data.homework.next.length === 0 ? (
              <p className="px-4 pb-4 text-[14px] text-ink-3">{v('Teslim tarihi yaklaşan ödevin yok.', 'Teslim tarihi yaklaşan ödevi yok.')}</p>
            ) : (
              <ul>
                {data.homework.next.map((h) => (
                  <li key={h.id} className="border-t border-line px-4 py-2.5">
                    <p className="break-words text-[14.5px] font-medium">{h.title}</p>
                    <p className="text-[12.5px] text-ink-3">{h.subject} · teslim {dateTime(h.due_at)} ({relative(h.due_at)})</p>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel title={<span className="inline-flex items-center gap-1.5"><GraduationCap className="size-4 text-primary" /> Son yoklama</span>}>
            {att.last ? (
              <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                  <p className="break-words text-[14.5px] font-medium">{att.last.subject}</p>
                  <p className="text-[12.5px] text-ink-3">{dateTime(att.last.starts_at)}</p>
                </div>
                <Badge tone={attendanceTone[att.last.status] ?? 'neutral'} dot>{att.last.status_label}{att.last.late_minutes ? ` · ${att.last.late_minutes} dk` : ''}</Badge>
              </div>
            ) : (
              <p className="text-[14px] text-ink-3">Henüz yoklama kaydı yok.</p>
            )}
          </Panel>

          {f.next_installment && (
            <Panel title={<span className="inline-flex items-center gap-1.5"><Wallet className="size-4 text-primary" /> Yaklaşan taksit</span>} actions={<More to="/portal/odemeler" />}>
              <div className="flex items-center justify-between gap-3 text-[14px]">
                <span className="text-ink-2">{f.next_installment.sequence}. taksit · {date(f.next_installment.due_date)}</span>
                <span className="font-semibold tabular">{money(f.next_installment.remaining)}</span>
              </div>
            </Panel>
          )}
        </div>
      </div>

      <Panel title={<span className="inline-flex items-center gap-1.5"><Megaphone className="size-4 text-primary" /> Duyurular</span>} actions={<More to="/portal/duyurular" />} flush>
        {data.announcements.length === 0 ? (
          <p className="px-4 pb-4 text-[14px] text-ink-3">Yeni duyuru yok.</p>
        ) : (
          <ul>
            {data.announcements.map((a) => (
              <li key={a.id} className="border-t border-line px-4 py-3">
                <div className="flex items-baseline justify-between gap-3">
                  <p className="min-w-0 break-words text-[14.5px] font-medium">{a.title}</p>
                  <span className="shrink-0 text-[12.5px] text-ink-3">{relative(a.published_at)}</span>
                </div>
                <p className="mt-0.5 line-clamp-2 text-[14px] text-ink-2">{a.body}</p>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  )
}

type OverdueAlert = {
  enabled: boolean
  role?: 'guardian' | 'student'
  date?: string
  count?: number
  amount?: string
  due_today?: { count: number; amount: string }
  children?: { student_id: number; full_name: string; count: number; amount: string }[]
}

function isDismissed(day: string) {
  try {
    return localStorage.getItem('ebe-dismiss:portal-overdue') === day
  } catch {
    return false
  }
}

/** Günlük gecikme uyarısı (veli: tüm çocuklar + kırılım). Günde bir kez kapatılabilir; kurum ayarıyla kapatılabilir. */
function OverdueBanner() {
  const { data } = usePortal<{ data: OverdueAlert }>('overdue-alert', '/portal/finance/overdue-alert')
  const [hidden, setHidden] = useState(false)
  const a = data?.data
  if (!a?.enabled || !a.date || hidden || isDismissed(a.date)) return null
  const hasOverdue = (a.count ?? 0) > 0
  const today = a.due_today?.count ?? 0
  if (!hasOverdue && today === 0) return null
  const hide = () => {
    try {
      localStorage.setItem('ebe-dismiss:portal-overdue', a.date!)
    } catch {
      /* yok say */
    }
    setHidden(true)
  }
  const guardian = a.role === 'guardian'
  return (
    <Alert
      tone={hasOverdue ? 'danger' : 'warning'}
      title={hasOverdue
        ? `Vadesi geçmiş ${num(a.count)} ${guardian ? 'ödemeniz' : 'ödemen'} var · toplam ${money(a.amount)}`
        : `Bugün vadesi dolan ${num(today)} ödeme · ${money(a.due_today?.amount)}`}
      action={
        <>
          <Link to="/portal/odemeler" className="text-[14px] font-medium text-ink underline-offset-2 hover:underline">Ödemeler</Link>
          <Button size="xs" variant="ghost" onClick={hide}>Bugün gizle</Button>
        </>
      }
    >
      {guardian && (a.children?.length ?? 0) > 1 ? (
        <ul className="mt-0.5 flex flex-col gap-0.5">
          {a.children!.map((c) => <li key={c.student_id} className="tabular">{c.full_name}: {num(c.count)} taksit · {money(c.amount)}</li>)}
        </ul>
      ) : hasOverdue ? (
        <>{today > 0 ? `Ayrıca bugün vadesi dolan ${num(today)} ödeme var. ` : ''}Ödeme için kurum muhasebesiyle görüşebilirsiniz.</>
      ) : null}
    </Alert>
  )
}
