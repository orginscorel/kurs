import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ChevronDown, FileDown, GraduationCap, TrendingUp } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { Panel } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Segmented } from '@/components/ui/form'
import { axisProps, ChartTooltip, gridProps } from '@/components/charts/ChartKit'
import { usePortal, type ExamRow, usePortalQuery, useVoice } from '../api'
import { MiniStat, PortalTitle } from '../ui'

type Data = { data: ExamRow[]; goal: { target_tyt_net: string | null; target_ayt_net: string | null } | null }

export default function PortalExams() {
  const { data, isLoading } = usePortal<Data>('exams', '/portal/exams')
  const exams = data?.data ?? []
  const types = useMemo(() => [...new Set(exams.map((e) => e.type))], [exams])
  const [picked, setPicked] = useState<string | null>(null)
  const type = picked ?? types[0] ?? 'TYT'
  const [open, setOpen] = useState<number | null>(null)
  const [downloading, setDownloading] = useState<number | null>(null)
  const v = useVoice()
  const pq = usePortalQuery()

  const ofType = exams.filter((e) => e.type === type)
  const trend = ofType.slice().reverse().map((e) => ({ label: date(e.exam_date).slice(0, 5), name: e.name, net: Number(e.net) }))
  const target = data?.goal ? Number(type === 'TYT' ? data.goal.target_tyt_net : data.goal.target_ayt_net) || null : null
  const best = ofType.length ? Math.max(...ofType.map((e) => Number(e.net))) : null
  const avg = ofType.length ? ofType.reduce((a, e) => a + Number(e.net), 0) / ofType.length : null

  const download = async (e: ExamRow) => {
    setDownloading(e.id)
    try {
      await api.download(`/portal/exams/${e.id}/pdf`, pq, 'sonuc-belgesi.pdf')
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Belge indirilemedi.')
    } finally {
      setDownloading(null)
    }
  }

  if (isLoading) return <div className="flex flex-col gap-4"><Skeleton className="h-10 w-1/2" /><Skeleton className="h-64" /><Skeleton className="h-40" /></div>

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title={v('Sınav sonuçlarım', 'Sınav sonuçları')} description={v('Yayımlanan deneme sonuçların, net gelişimin ve sonuç belgelerin', 'Yayımlanan deneme sonuçları, net gelişimi ve sonuç belgeleri')} />

      {exams.length === 0 ? (
        <EmptyState icon={<GraduationCap />} title={v('Henüz yayımlanmış sınav sonucun yok', 'Henüz yayımlanmış sınav sonucu yok')} />
      ) : (
        <>
          {types.length > 1 && <Segmented className="self-start" value={type} onChange={setPicked} options={types.map((t) => ({ value: t, label: t.replace('_', ' ') }))} />}

          <div className="grid grid-cols-3 gap-3">
            <MiniStat label="Son deneme neti" value={ofType[0] ? num(ofType[0].net, 2) : '—'} />
            <MiniStat label="En yüksek net" value={best === null ? '—' : num(best, 2)} />
            <MiniStat label={target ? 'Hedef net' : 'Ortalama net'} value={target ? num(target) : avg === null ? '—' : num(avg, 2)} />
          </div>

          <Panel title="Net gelişimi">
            {trend.length < 2 ? (
              <EmptyState compact icon={<TrendingUp />} title="Gelişim grafiği için en az iki sınav gerekli" />
            ) : (
              <div className="h-[220px]">
                <ResponsiveContainer>
                  <LineChart data={trend} margin={{ top: 10, right: 12, left: 0, bottom: 0 }}>
                    <CartesianGrid {...gridProps} />
                    <XAxis dataKey="label" {...axisProps} dy={6} />
                    <YAxis {...axisProps} width={36} domain={[(min: number) => Math.max(0, Math.floor((Math.min(min, target ?? min) - 5) / 10) * 10), (max: number) => Math.ceil((Math.max(max, target ?? max) + 5) / 10) * 10]} allowDecimals={false} />
                    <Tooltip cursor={{ stroke: 'var(--line-strong)' }} content={<ChartTooltip formatLabel={(l) => trend.find((t) => t.label === l)?.name ?? l} formatValue={(v) => `${num(v, 2)} net`} />} />
                    <Line type="linear" dataKey="net" name="Net" stroke="var(--series-1)" strokeWidth={2} dot={{ r: 3.5, fill: 'var(--surface)', stroke: 'var(--series-1)', strokeWidth: 2 }} activeDot={{ r: 5 }} />
                    {target && <Line type="linear" dataKey={() => target} name="Hedef" stroke="var(--ink-3)" strokeDasharray="4 4" strokeWidth={1.5} dot={false} />}
                  </LineChart>
                </ResponsiveContainer>
              </div>
            )}
          </Panel>

          <ul className="flex flex-col gap-2">
            {ofType.map((e) => {
              const isOpen = open === e.id
              return (
                <li key={e.id} className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
                  <button className="flex w-full items-center gap-3 px-4 py-3 text-left" onClick={() => setOpen(isOpen ? null : e.id)} aria-expanded={isOpen}>
                    <div className="min-w-0 flex-1">
                      <p className="break-words text-[14px] font-medium">{e.name}</p>
                      <p className="break-words text-[12.5px] text-ink-3">
                        {date(e.exam_date)} · {e.correct}D {e.wrong}Y {e.blank}B
                        {e.institution_rank ? ` · kurum ${e.institution_rank}/${e.participant_count}` : ''}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="text-[17px] font-semibold tabular">{num(e.net, 2)}</p>
                      <p className="text-[12.5px] text-ink-3">net</p>
                    </div>
                    <ChevronDown className={cn('size-4 shrink-0 text-ink-3 transition-transform', isOpen && 'rotate-180')} />
                  </button>
                  {isOpen && (
                    <div className="border-t border-line px-4 py-3">
                      {/* Mobil: ders kartları */}
                      <ul className="grid grid-cols-1 gap-2 min-[400px]:grid-cols-2 sm:hidden">
                        {e.sections.map((s) => (
                          <li key={s.code} className="rounded-[var(--radius-md)] bg-surface-2 px-3 py-2">
                            <div className="flex items-baseline justify-between gap-2">
                              <span className="min-w-0 break-words text-[14px] font-medium">{s.name}</span>
                              <span className="shrink-0 text-[15px] font-semibold tabular">{num(s.net, 2)}</span>
                            </div>
                            <p className="mt-0.5 text-[12.5px] text-ink-2 tabular">{s.correct} doğru · {s.wrong} yanlış · {s.blank} boş</p>
                          </li>
                        ))}
                      </ul>
                      <div className="hidden overflow-x-auto sm:block">
                        <table className="w-full text-[14px]">
                          <thead className="text-ink-3">
                            <tr className="text-[12.5px]"><th className="py-1 font-medium text-left">Ders</th><th className="font-medium text-center">Doğru</th><th className="font-medium text-center">Yanlış</th><th className="font-medium text-center">Boş</th><th className="font-medium text-center">Net</th></tr>
                          </thead>
                          <tbody className="tabular">
                            {e.sections.map((s) => (
                              <tr key={s.code} className="border-t border-line/70">
                                <td className="py-1.5 text-left">{s.name}</td>
                                <td className="text-center">{s.correct}</td>
                                <td className="text-center">{s.wrong}</td>
                                <td className="text-center">{s.blank}</td>
                                <td className="font-medium text-center">{num(s.net, 2)}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-[12.5px] text-ink-3">
                        <span>
                          {e.score ? `Puan ${num(e.score, 2)}` : ''}
                          {e.class_rank ? ` · sınıf sırası ${e.class_rank}` : ''}
                        </span>
                        <Button size="sm" variant="soft" icon={<FileDown className="size-4" />} loading={downloading === e.id} onClick={() => download(e)}>
                          Sonuç belgesi (PDF)
                        </Button>
                      </div>
                    </div>
                  )}
                </li>
              )
            })}
          </ul>
        </>
      )}
    </div>
  )
}
