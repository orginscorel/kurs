import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Award, ChevronDown, Sigma, Trophy, Users } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat } from '@/components/ui/layout'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { axisProps, ChartTooltip, gridProps, series } from '@/components/charts/ChartKit'
import type { ExamRow } from './types'
import { ExamPicker } from './shared'
import { ResultsTable } from './ResultsTable'

/** /reports/exams yanıtının bu sayfada kullanılan bölümü (sınav özeti ve sınıf kırılımı). */
type ExamSummaryData = {
  report: null | {
    summary: { participants: number; avg_net: number | null; avg_score: number | null; max_net: number | null; min_net: number | null; max_score: number | null }
    by_class: { id: number | null; name: string; participants: number; avg_net: number | null; avg_score: number | null; max_net: number | null }[]
  }
}

const n2 = (v: number | null | undefined) => (v === null || v === undefined ? '—' : num(v, 2))

/**
 * Sınav özeti: katılım ve ortalama kutuları her zaman görünür, sınıf kırılımı katlanır
 * (varsayılan kapalı — ekran şişmesin). Veri kaynağı, bu sayfayla birleşen eski
 * "Sınav sonuçları özeti" raporuyla aynı uçtur (/reports/exams).
 */
function ExamSummarySection({ examId }: { examId: number }) {
  const [open, setOpen] = useState(false)
  const { data, isLoading } = useQuery({
    queryKey: ['reports', 'exams', examId],
    queryFn: () => api.get<{ data: ExamSummaryData }>('/reports/exams', { exam_id: examId }).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
  const rep = data?.report
  const s = rep?.summary
  const classes = (rep?.by_class ?? []).filter((c) => c.id !== null)
  const chart = classes.map((c) => ({ name: c.name, avg_net: c.avg_net ?? 0 }))

  if (!isLoading && !s?.participants) return null

  return (
    <div className="mb-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Katılan öğrenci" icon={<Users />} loading={isLoading} value={num(s?.participants)} />
        <Stat label="Ortalama net" icon={<Sigma />} loading={isLoading} value={n2(s?.avg_net)} sub={`En düşük net: ${n2(s?.min_net)}`} />
        <Stat label="Ortalama puan" loading={isLoading} value={n2(s?.avg_score)} sub={`En yüksek puan: ${n2(s?.max_score)}`} />
        <Stat label="En yüksek net" icon={<Award />} loading={isLoading} value={n2(s?.max_net)} />
      </div>

      {classes.length > 0 && (
        <>
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            aria-expanded={open}
            className="mt-2 inline-flex h-9 items-center gap-1.5 rounded-[var(--radius-sm)] px-2.5 text-[13px] font-medium text-ink-2 transition-colors hover:bg-surface-2 hover:text-ink"
          >
            <ChevronDown className={cn('size-4 transition-transform', open && 'rotate-180')} />
            Sınıf özeti ({classes.length} sınıf)
          </button>

          {open && (
            <div className="mt-2 grid grid-cols-1 gap-4 xl:grid-cols-2">
              <Panel title="Sınıf ortalamaları" description="Her sınıfın ortalama neti">
                <div style={{ height: Math.max(140, chart.length * 30 + 30) }}>
                  <ResponsiveContainer>
                    <BarChart data={chart} layout="vertical" margin={{ top: 0, right: 12, left: 0, bottom: 0 }} barCategoryGap="28%">
                      <CartesianGrid {...gridProps} vertical horizontal={false} />
                      <XAxis type="number" {...axisProps} allowDecimals />
                      <YAxis type="category" dataKey="name" {...axisProps} width={90} />
                      <Tooltip cursor={{ fill: 'var(--surface-2)' }} content={<ChartTooltip formatValue={(v) => num(v, 2)} />} />
                      <Bar dataKey="avg_net" name="Ortalama net" fill={series[0]} radius={[0, 3, 3, 0]} maxBarSize={18} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </Panel>

              <Panel title="Sınıf kırılımı" flush>
                <div className="overflow-x-auto scroll-thin">
                  <table className="tbl w-full text-[13px]">
                    <thead>
                      <tr className="border-y border-line bg-surface-2 text-[12px] text-ink-3">
                        <th className="h-9 px-4 text-left font-medium">Sınıf</th>
                        <th className="px-3 text-right font-medium">Katılan</th>
                        <th className="px-3 text-right font-medium">Ort. net</th>
                        <th className="px-3 text-right font-medium">Ort. puan</th>
                        <th className="px-4 text-right font-medium">En yüksek net</th>
                      </tr>
                    </thead>
                    <tbody>
                      {classes.map((c) => (
                        <tr key={c.id} className="border-b border-line">
                          <td className="px-4 py-2 text-left font-medium">{c.name}</td>
                          <td className="px-3 py-2 text-right tabular">{c.participants}</td>
                          <td className="px-3 py-2 text-right tabular font-medium">{n2(c.avg_net)}</td>
                          <td className="px-3 py-2 text-right tabular">{n2(c.avg_score)}</td>
                          <td className="px-4 py-2 text-right tabular text-ink-2">{n2(c.max_net)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Panel>
            </div>
          )}
        </>
      )}
    </div>
  )
}

/** Sonuçlar: sınav seçici + sınav özeti + sonuç tablosu (varsayılan: son yayımlanan sınav). */
export default function ResultsPage() {
  const can = useCan()
  const [params, setParams] = useSearchParams()
  const selected = params.get('sinav') ?? ''
  const latest = useQuery({ queryKey: ['exams', 'latest-published'], queryFn: () => api.get<Paginated<ExamRow>>('/exams', { per_page: 1, status: 'results_published', sort: '-exam_date' }), enabled: !selected })
  const examId = selected ? Number(selected) : latest.data?.data[0]?.id

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Sınav Sonuçları"
        description="Sınav özeti, sınıf kırılımı ve öğrenci sonuç tablosu. Satıra tıklayarak cevap analizi ve sonuç kartına ulaşın."
        actions={
          <div className="flex flex-wrap items-center gap-2 w-full sm:w-auto min-w-0">
            <ExamPicker value={examId ? String(examId) : ''} onChange={(v) => setParams((p) => { v ? p.set('sinav', v) : p.delete('sinav'); p.delete('page'); p.delete('q'); p.delete('class_group_id'); return p })} className="w-full sm:w-[320px] min-w-0" />
            {examId && <ButtonLink to={`/sinavlar/${examId}`} variant="ghost">Sınav sayfası</ButtonLink>}
          </div>
        }
      />
      {!selected && latest.isLoading ? (
        <Skeleton className="h-72 rounded-[var(--radius-lg)]" />
      ) : examId ? (
        <>
          {can('reports.view') && <ExamSummarySection key={examId} examId={examId} />}
          <ResultsTable key={examId} examId={examId} storageKey="results-page" />
          {can('reports.view') && (
            <p className="mt-3 px-1 text-[12px] text-ink-3">
              Kurum geneli sınav trendi için <Link to="/sinav-analizleri" className="text-primary hover:underline">Analizler</Link>, konu bazlı başarı için <Link to="/kazanim-analizi" className="text-primary hover:underline">Kazanım analizi</Link>.
            </p>
          )}
        </>
      ) : (
        <EmptyState icon={<Trophy />} title="Yayımlanmış sonuç yok" description="Bir deneme yayımlandığında sonuçları burada görebilirsiniz." action={<ButtonLink to="/sinavlar" variant="primary">Denemelere git</ButtonLink>} />
      )}
    </div>
  )
}
