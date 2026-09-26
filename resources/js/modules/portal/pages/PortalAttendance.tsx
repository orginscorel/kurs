import { useState } from 'react'
import { ClipboardCheck } from 'lucide-react'
import { date, duration, num, time } from '@/lib/format'
import { Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Panel } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Segmented } from '@/components/ui/form'
import type { ListMeta } from '@/lib/api'
import { attendanceTone, usePortal, useVoice } from '../api'
import { ListCard, MiniStat, PortalTitle } from '../ui'

type Row = { id: number; date: string; status: string; status_label: string; late_minutes: number | null; starts_at: string; ends_at: string; subject: string; topics?: { id: number; name: string; outcome_code: string | null }[] }
type Data = {
  data: Row[]
  meta: ListMeta & {
    summary: { total: number; present: number; late: number; absent: number; excused: number; medical: number }
    by_subject: { subject: string; total: number; absent: number; late: number }[]
    presences: { date: string; first_entry_at: string | null; last_exit_at: string | null; minutes_inside: number; is_inside: number }[]
  }
}

export default function PortalAttendance() {
  const v = useVoice()
  const [status, setStatus] = useState<'all' | 'absent' | 'late'>('all')
  const [pageNo, setPageNo] = useState(1)
  const { data, isLoading } = usePortal<Data>('attendance', '/portal/attendance', { status: status === 'all' ? undefined : status, page: pageNo })
  const s = data?.meta.summary
  const monthly = usePortal<{ attendance_monthly: { month: string; total: number; absent: number; late: number; excused: number; rate: number | null }[] }>('progress', '/portal/progress')
  const rate = s && s.total ? Math.round(((s.present + s.late) / s.total) * 100) : null

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title={v('Yoklama geçmişim', 'Yoklama geçmişi')} description={v('Derslere katılım, devamsızlık ve kuruma giriş–çıkışların', 'Derslere katılım, devamsızlık ve kuruma giriş–çıkışlar')} />

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <MiniStat label="Katılım" value={rate === null ? '—' : `%${rate}`} sub={s ? `${num(s.total)} ders` : undefined} />
        <MiniStat label="Devamsızlık" value={s ? `${num(s.absent)} ders` : '—'} tone={s && s.absent >= 5 ? 'danger' : undefined} />
        <MiniStat label="Geç kalma" value={s ? num(s.late) : '—'} tone={s && s.late >= 5 ? 'warning' : undefined} />
        <MiniStat label="İzinli / raporlu" value={s ? num(s.excused + s.medical) : '—'} />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div className="flex min-w-0 flex-col gap-3 lg:col-span-2">
          <Segmented className="self-start" value={status} onChange={(v) => { setStatus(v); setPageNo(1) }} options={[{ value: 'all', label: 'Tümü' }, { value: 'absent', label: v('Gelmediğim', 'Gelmediği') }, { value: 'late', label: v('Geç kaldığım', 'Geç kaldığı') }]} />
          {isLoading || !data ? (
            <Skeleton className="h-64" />
          ) : data.data.length === 0 ? (
            <EmptyState compact icon={<ClipboardCheck />} title="Kayıt yok" />
          ) : (
            <>
              <ListCard>
                {data.data.map((r) => {
                  const missed = ['absent', 'excused', 'medical'].includes(r.status)
                  return (
                    <li key={r.id} className="flex flex-col gap-1.5 px-4 py-2.5">
                      <div className="flex items-center gap-3">
                        <div className="min-w-0 flex-1">
                          <p className="break-words text-[14.5px] font-medium">{r.subject}</p>
                          <p className="text-[12.5px] text-ink-3 tabular">{date(r.date, 'day')} · {time(r.starts_at)}</p>
                        </div>
                        <Badge tone={attendanceTone[r.status] ?? 'neutral'} dot>{r.status_label}{r.late_minutes ? ` · ${r.late_minutes} dk` : ''}</Badge>
                      </div>
                      {r.topics && r.topics.length > 0 && (
                        <div className="rounded-[var(--radius-sm)] bg-surface-2 px-2.5 py-1.5">
                          <p className={`mb-1 text-[11.5px] font-medium ${missed ? 'text-warning' : 'text-ink-3'}`}>
                            {missed ? 'Kaçırdığı konular' : 'İşlenen konular'}
                          </p>
                          <div className="flex flex-wrap gap-1">
                            {r.topics.map((t) => (
                              <span key={t.id} className="rounded bg-surface px-1.5 py-0.5 text-[11.5px] text-ink-2 ring-1 ring-line">
                                {t.outcome_code ? `${t.outcome_code} · ` : ''}{t.name}
                              </span>
                            ))}
                          </div>
                        </div>
                      )}
                    </li>
                  )
                })}
              </ListCard>
              {data.meta.last_page > 1 && (
                <div className="flex items-center justify-between text-[12.5px] text-ink-3">
                  <Button size="sm" disabled={pageNo <= 1} onClick={() => setPageNo((p) => p - 1)}>Önceki</Button>
                  <span className="tabular">{data.meta.page} / {data.meta.last_page}</span>
                  <Button size="sm" disabled={pageNo >= data.meta.last_page} onClick={() => setPageNo((p) => p + 1)}>Sonraki</Button>
                </div>
              )}
            </>
          )}
        </div>

        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="Aylara göre katılım" description="Son 6 ay" flush>
            {!monthly.data || monthly.data.attendance_monthly.length === 0 ? (
              <p className="px-4 pb-4 text-[14px] text-ink-3">{monthly.isLoading ? 'Yükleniyor…' : 'Kayıt yok.'}</p>
            ) : (
              <ul>
                {[...monthly.data.attendance_monthly].reverse().map((m) => (
                  <li key={m.month} className="border-t border-line px-4 py-2">
                    <div className="flex items-baseline justify-between gap-2 text-[14px]">
                      <span className="capitalize">{new Date(Number(m.month.slice(0, 4)), Number(m.month.slice(5, 7)) - 1, 1).toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' })}</span>
                      <span className={m.rate !== null && m.rate < 85 ? 'font-semibold text-danger tabular' : 'tabular text-ink-2'}>%{m.rate ?? '—'}</span>
                    </div>
                    <ProgressBar className="mt-1" value={m.rate ?? 0} tone={m.rate !== null && m.rate < 85 ? 'danger' : 'success'} />
                    <p className="mt-0.5 text-[12.5px] text-ink-3 tabular">{m.absent} gelmedi · {m.late} geç · {m.excused} izinli/raporlu</p>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          <Panel title="Derse göre devamsızlık" flush>
            {!data || data.meta.by_subject.length === 0 ? (
              <p className="px-4 pb-4 text-[14px] text-ink-3">Kayıt yok.</p>
            ) : (
              <ul>
                {data.meta.by_subject.map((b) => (
                  <li key={b.subject} className="flex items-center justify-between gap-3 border-t border-line px-4 py-2 text-[14px]">
                    <span className="min-w-0 break-words">{b.subject}</span>
                    <span className="shrink-0 tabular text-ink-2">
                      <b className={Number(b.absent) > 0 ? 'text-danger' : 'text-ink'}>{num(b.absent)}</b> / {num(b.total)}
                      {Number(b.late) > 0 && <span className="text-ink-3"> · {num(b.late)} geç</span>}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          <Panel title="Kuruma giriş–çıkış" description="Son 14 gün" flush>
            {!data || data.meta.presences.length === 0 ? (
              <p className="px-4 pb-4 text-[14px] text-ink-3">Kayıt yok.</p>
            ) : (
              <ul>
                {data.meta.presences.map((p) => (
                  <li key={p.date} className="flex items-center justify-between gap-3 border-t border-line px-4 py-2 text-[12.5px]">
                    <span>{date(p.date)}</span>
                    <span className="tabular text-ink-2">{time(p.first_entry_at)} – {p.is_inside ? 'içeride' : time(p.last_exit_at)} · {duration(p.minutes_inside)}</span>
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
