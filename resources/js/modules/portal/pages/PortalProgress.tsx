import { useState } from 'react'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ArrowDownRight, ArrowUpRight, CalendarClock, ClipboardCheck, Minus, Target, TrendingUp } from 'lucide-react'
import { date } from '@/lib/format'
import { cn } from '@/lib/cn'
import { axisProps, ChartTooltip, series } from '@/components/charts/ChartKit'
import { Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Segmented } from '@/components/ui/form'
import { Panel } from '@/components/ui/layout'
import { usePortal, useVoice } from '../api'
import { MiniStat, PortalTitle } from '../ui'

type Subject = { code: string; name: string; last: number | null; previous: number | null; change: number | null; average: number | null; best: number | null }
type ExamType = { type: string; type_name: string; count: number; points: ({ exam: string; date: string; net: number } & Record<string, number | string>)[]; subjects: Subject[] }
type Topic = { id: number; topic: string; subject: string; subject_color: string | null; asked: number; correct: number; wrong: number; rate: number }
type Data = {
  exam_types: ExamType[]
  weak_topics: Topic[]
  strong_topics: Topic[]
  goal: { university: string | null; department: string | null; target_rank: number | null; target_tyt_net: string | null; target_ayt_net: string | null } | null
  upcoming_exams: { id: number; name: string; exam_date: string; publisher: string | null; type: string; type_name: string }[]
  attendance_monthly: { month: string; total: number; attended: number; absent: number; late: number; excused: number; rate: number | null }[]
}

const monthName = (ym: string) => {
  const [y, m] = ym.split('-').map(Number)
  return new Date(y!, m! - 1, 1).toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' })
}

function Change({ value }: { value: number | null }) {
  if (value === null) return <span className="text-ink-3">—</span>
  const Icon = value > 0 ? ArrowUpRight : value < 0 ? ArrowDownRight : Minus
  return (
    <span className={cn('inline-flex items-center gap-0.5 font-medium tabular', value > 0 ? 'text-success' : value < 0 ? 'text-danger' : 'text-ink-3')}>
      <Icon className="size-3.5" />{value > 0 ? '+' : ''}{value.toLocaleString('tr-TR', { maximumFractionDigits: 2 })}
    </span>
  )
}

export default function PortalProgress() {
  const v = useVoice()
  const { data, isLoading } = usePortal<Data>('progress', '/portal/progress')
  const [typeKey, setTypeKey] = useState<string | null>(null)

  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-16 w-2/3" /><Skeleton className="h-72" /></div>

  const current = data.exam_types.find((t) => t.type === typeKey) ?? data.exam_types[0]
  const pts = current?.points ?? []
  const last = pts[pts.length - 1]
  const prev = pts[pts.length - 2]
  const best = pts.length ? Math.max(...pts.map((p) => p.net)) : null
  const target = current && data.goal ? (current.type.startsWith('TYT') ? data.goal.target_tyt_net : current.type.startsWith('AYT') ? data.goal.target_ayt_net : null) : null
  const chart = pts.map((p) => ({ ...p, label: date(p.date).slice(0, 5) }))

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title={v('Gelişimim', 'Gelişim')} description={v('Deneme netlerinin seyri, zorlandığın konular ve devam durumun', 'Deneme netlerinin seyri, zorlanılan konular ve devam durumu')} />

      {data.goal && (data.goal.university || data.goal.department) && (
        <p className="inline-flex items-center gap-1.5 self-start rounded-full bg-primary-soft px-3 py-1 text-[12.5px] font-medium text-primary-ink">
          <Target className="size-3.5" /> Hedef: {[data.goal.university, data.goal.department].filter(Boolean).join(' · ')}
          {data.goal.target_rank ? ` · ${data.goal.target_rank.toLocaleString('tr-TR')}. sıra` : ''}
        </p>
      )}

      {!current ? (
        <EmptyState icon={<TrendingUp />} title="Henüz yayımlanmış sınav sonucu yok" description="Deneme sonuçları yayımlandıkça net gelişimi burada görünecek." />
      ) : (
        <>
          {data.exam_types.length > 1 && (
            <Segmented className="self-start" value={current.type} onChange={setTypeKey} options={data.exam_types.map((t) => ({ value: t.type, label: t.type_name || t.type }))} />
          )}
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <MiniStat label="Son deneme neti" value={last ? last.net.toLocaleString('tr-TR') : '—'} sub={last?.exam} />
            <MiniStat label="Önceki sınava göre" value={last && prev ? <Change value={Math.round((last.net - prev.net) * 100) / 100} /> : '—'} sub={prev?.exam} />
            <MiniStat label="En iyi net" value={best !== null ? best.toLocaleString('tr-TR') : '—'} sub={`${current.count} sınav`} />
            <MiniStat label="Hedef net" value={target ?? '—'} sub={target && last ? `${Math.max(0, Number(target) - last.net).toLocaleString('tr-TR', { maximumFractionDigits: 2 })} net kaldı` : 'tanımlı değil'} />
          </div>

          <Panel title={`${current.type_name || current.type} toplam net`}>
            {chart.length < 2 ? (
              <p className="text-[14px] text-ink-3">Grafik için en az iki sınav sonucu gerekir.</p>
            ) : (
              <div className="h-56 w-full">
                <ResponsiveContainer>
                  <LineChart data={chart} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                    <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
                    <XAxis dataKey="label" {...axisProps} dy={6} />
                    <YAxis {...axisProps} width={34} domain={[0, 'auto']} />
                    <Tooltip cursor={{ stroke: 'var(--line-strong)' }} content={<ChartTooltip formatLabel={(l) => chart.find((c) => c.label === l)?.exam ?? l} />} />
                    <Line type="linear" dataKey="net" name="Net" stroke={series[0]} strokeWidth={2} dot={{ r: 3.5, fill: 'var(--surface)', stroke: series[0], strokeWidth: 2 }} />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            )}
          </Panel>

          {current.subjects.length > 0 && (
            <Panel title="Derslere göre net" flush>
              {/* Mobil: ders kartları */}
              <ul className="grid grid-cols-1 gap-2 p-3 min-[400px]:grid-cols-2 sm:hidden">
                {current.subjects.map((s) => (
                  <li key={s.code} className="rounded-[var(--radius-md)] bg-surface-2 px-3 py-2.5">
                    <div className="flex items-baseline justify-between gap-2">
                      <span className="min-w-0 break-words text-[14px] font-medium">{s.name}</span>
                      <span className="shrink-0 text-[16px] font-semibold tabular">{s.last ?? '—'}</span>
                    </div>
                    <dl className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[12.5px] text-ink-2">
                      <div className="flex items-center gap-1"><dt className="text-ink-3">Öncekine göre</dt><dd><Change value={s.change} /></dd></div>
                      <div className="flex gap-1"><dt className="text-ink-3">Ortalama</dt><dd className="tabular">{s.average ?? '—'}</dd></div>
                      <div className="flex gap-1"><dt className="text-ink-3">En yüksek</dt><dd className="tabular">{s.best ?? '—'}</dd></div>
                    </dl>
                  </li>
                ))}
              </ul>
              <div className="hidden overflow-x-auto sm:block">
                <table className="w-full text-[14px]">
                  <thead>
                    <tr className="text-left text-[12.5px] text-ink-3">
                      <th className="px-4 pb-2 font-medium text-left">Ders</th>
                      <th className="px-2 pb-2 font-medium text-center">Son deneme neti</th>
                      <th className="px-2 pb-2 font-medium text-center">Öncekine göre</th>
                      <th className="px-2 pb-2 font-medium text-center">Ortalama net</th>
                      <th className="px-4 pb-2 font-medium text-center">En yüksek net</th>
                    </tr>
                  </thead>
                  <tbody>
                    {current.subjects.map((s) => (
                      <tr key={s.code} className="border-t border-line">
                        <td className="px-4 py-2 font-medium text-left">{s.name}</td>
                        <td className="px-2 py-2 tabular text-center">{s.last ?? '—'}</td>
                        <td className="px-2 py-2 text-center"><Change value={s.change} /></td>
                        <td className="px-2 py-2 tabular text-ink-2 text-center">{s.average ?? '—'}</td>
                        <td className="px-4 py-2 tabular text-ink-2 text-center">{s.best ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          )}
        </>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title={v('Zorlandığın konular', 'Zorlanılan konular')} description="Sınavlarda başarı oranı %70'in altında kalan konular">
          {data.weak_topics.length === 0 ? <p className="text-[14px] text-ink-3">Belirgin bir eksik konu yok.</p> : (
            <ul className="flex flex-col gap-2.5">
              {data.weak_topics.map((t) => (
                <li key={t.id}>
                  <div className="flex items-baseline justify-between gap-2 text-[14px]">
                    <span className="min-w-0 break-words">{t.topic} <span className="text-ink-3">· {t.subject}</span></span>
                    <span className="shrink-0 text-[12.5px] text-ink-2 tabular">{t.correct}/{t.asked} · %{t.rate}</span>
                  </div>
                  <ProgressBar className="mt-1" value={t.rate} tone={t.rate < 40 ? 'danger' : 'warning'} />
                </li>
              ))}
            </ul>
          )}
          {data.strong_topics.length > 0 && (
            <p className="mt-3 text-[12.5px] text-ink-2"><span className="text-ink-3">Güçlü konular:</span> {data.strong_topics.map((t) => t.topic).join(', ')}</p>
          )}
        </Panel>

        <div className="flex flex-col gap-4">
          <Panel title={<span className="inline-flex items-center gap-1.5"><ClipboardCheck className="size-4 text-primary" /> Aylık devam durumu</span>} flush>
            {data.attendance_monthly.length === 0 ? <p className="px-4 pb-4 text-[14px] text-ink-3">Yoklama kaydı yok.</p> : (
              <ul>
                {[...data.attendance_monthly].reverse().map((m) => (
                  <li key={m.month} className="border-t border-line px-4 py-2.5">
                    <div className="flex items-baseline justify-between gap-2 text-[14px]">
                      <span className="font-medium capitalize">{monthName(m.month)}</span>
                      <span className={cn('tabular', m.rate !== null && m.rate < 85 ? 'font-semibold text-danger' : 'text-ink-2')}>%{m.rate ?? '—'}</span>
                    </div>
                    <ProgressBar className="mt-1" value={m.rate ?? 0} tone={m.rate !== null && m.rate < 85 ? 'danger' : 'success'} />
                    <p className="mt-1 text-[12.5px] text-ink-3 tabular">{m.total} ders · {m.absent} gelmedi · {m.late} geç · {m.excused} izinli/raporlu</p>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          <Panel title={<span className="inline-flex items-center gap-1.5"><CalendarClock className="size-4 text-primary" /> Yaklaşan sınavlar</span>} flush>
            {data.upcoming_exams.length === 0 ? <p className="px-4 pb-4 text-[14px] text-ink-3">Önümüzdeki 60 günde sınav yok.</p> : (
              <ul>
                {data.upcoming_exams.map((e) => (
                  <li key={e.id} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-line px-4 py-2.5">
                    <span className="min-w-0 text-[14.5px]">{e.name}</span>
                    <span className="flex items-center gap-2 text-[12.5px] text-ink-2 tabular"><Badge tone="info">{e.type}</Badge>{date(e.exam_date)}</span>
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
