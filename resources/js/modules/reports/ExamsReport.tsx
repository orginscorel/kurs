import { Link } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Award, GraduationCap, Sigma, Users } from 'lucide-react'
import { api } from '@/lib/api'
import { date as fmtDate, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel, Stat } from '@/components/ui/layout'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { Select } from '@/components/ui/form'
import { axisProps, ChartTooltip, gridProps, series } from '@/components/charts/ChartKit'
import { ExportButton, ReportFrame, reportPage, SimpleTable, Th, useUrlFilters } from './shared'
import type { ExamsData } from './types'

const n2 = (v: number | null | undefined) => (v === null || v === undefined ? '—' : num(v, 2))

function ExamsReport() {
  const can = useCan()
  const [f, setF] = useUrlFilters({ exam_id: '', class_group_id: '' })
  const query = { exam_id: f.exam_id, class_group_id: f.class_group_id }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['reports', 'exams', query],
    queryFn: () => api.get<{ data: ExamsData }>('/reports/exams', query).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
  const rep = data?.report
  const s = rep?.summary
  const examId = f.exam_id || (data?.selected_exam_id ? String(data.selected_exam_id) : '')
  const noExams = !isLoading && (data?.exams.length ?? 0) === 0
  const chart = (rep?.by_class ?? []).map((c) => ({ name: c.name, avg_net: c.avg_net ?? 0 }))

  return (
    <ReportFrame
      reportKey="exams"
      actions={examId && s?.participants ? <ExportButton path="/reports/exams/export" query={{ exam_id: examId, class_group_id: f.class_group_id }} name="sinav-ozeti.xlsx" /> : undefined}
      filters={noExams ? undefined : (
        <>
          <Select className="w-full sm:w-[320px]" aria-label="Sınav" value={examId} onChange={(e) => setF({ exam_id: e.target.value, class_group_id: null })}
            options={(data?.exams ?? []).map((e) => ({ value: e.id, label: `${e.name}${e.exam_date ? ` · ${fmtDate(e.exam_date)}` : ''}${e.participants ? ` · ${e.participants} kişi` : ' · sonuç yok'}` }))} />
          <Select className="w-full sm:w-[180px]" aria-label="Sınıf" value={f.class_group_id} onChange={(e) => setF({ class_group_id: e.target.value })} placeholder="Tüm sınıflar"
            options={(rep?.by_class ?? []).filter((c) => c.id !== null).map((c) => ({ value: c.id!, label: c.name }))} />
          {rep && <Link to={`/sinavlar/${rep.exam.id}`} className="text-[13px] text-primary hover:underline sm:ml-auto">Sınav sayfası</Link>}
        </>
      )}
    >
      {noExams ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<GraduationCap />} title="Henüz sınav yok" description="Deneme sınavı oluşturup sonuçları yükledikçe bu rapor dolacak."
            action={can('exams.manage') ? <ButtonLink to="/sinavlar" variant="primary">Sınavlara git</ButtonLink> : undefined} />
        </div>
      ) : (
        <>
          <div className={cn('mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4', isFetching && !isLoading && 'opacity-70')}>
            <Stat label="Katılan öğrenci" icon={<Users />} loading={isLoading} value={num(s?.participants)} sub={rep?.exam.type ?? undefined} />
            <Stat label="Ortalama net" icon={<Sigma />} loading={isLoading} value={n2(s?.avg_net)} sub={`En düşük net: ${n2(s?.min_net)}`} />
            <Stat label="Ortalama puan" loading={isLoading} value={n2(s?.avg_score)} sub={`En yüksek puan: ${n2(s?.max_score)}`} />
            <Stat label="En yüksek net" icon={<Award />} loading={isLoading} value={n2(s?.max_net)} />
          </div>

          {!isLoading && !s?.participants ? (
            <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
              <EmptyState icon={<GraduationCap />} title="Bu sınav için sonuç yok" description="Optik okuma ya da elle giriş ile sonuçlar yüklendiğinde özet burada görünür."
                action={rep && can('exams.import') ? <ButtonLink to="/optik-okuma" variant="primary">Optik okuma</ButtonLink> : undefined} />
            </div>
          ) : (
            <>
              {chart.length > 1 && !f.class_group_id && (
                <Panel title="Sınıf ortalamaları" description="Her sınıfın ortalama neti" className="mb-4">
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
              )}

              {(rep?.by_class.length ?? 0) > 0 && !f.class_group_id && (
                <Panel title="Sınıf özeti" className="mb-4">
                  <SimpleTable head={<tr><Th>Sınıf</Th><Th right>Katılan öğrenci</Th><Th right>Ortalama net</Th><Th right>Ortalama puan</Th><Th right>En yüksek net</Th></tr>}>
                    {rep!.by_class.map((c) => (
                      <tr key={c.id ?? 'none'}>
                        <td>{c.id ? <button type="button" className="font-medium text-ink hover:text-primary" onClick={() => setF({ exam_id: examId, class_group_id: String(c.id) })}>{c.name}</button> : c.name}</td>
                        <td className="text-right tabular">{c.participants}</td>
                        <td className="text-right tabular font-medium">{n2(c.avg_net)}</td>
                        <td className="text-right tabular">{n2(c.avg_score)}</td>
                        <td className="text-right tabular text-ink-2">{n2(c.max_net)}</td>
                      </tr>
                    ))}
                  </SimpleTable>
                </Panel>
              )}

              <Panel title="Öğrenci sıralaması" description={rep ? `${rep.exam.name}${rep.exam.exam_date ? ` · ${fmtDate(rep.exam.exam_date)}` : ''}` : undefined}>
                {isLoading ? <Skeleton className="h-40" /> : (
                  <SimpleTable leftCols={2} head={<tr><Th right>Sıra</Th><Th>Öğrenci</Th><Th>Sınıf</Th><Th right>Doğru</Th><Th right>Yanlış</Th><Th right>Boş</Th><Th right>Net</Th><Th right>Puan</Th><Th right>Kurum sırası</Th></tr>}>
                    {rep!.rows.map((r, i) => (
                      <tr key={r.id}>
                        <td className="text-right tabular text-ink-3">{i + 1}</td>
                        <td><Link to={`/ogrenciler/${r.student_id}`} className="font-medium text-ink hover:text-primary">{r.full_name}</Link><span className="ml-1.5 text-[12px] tabular text-ink-3">No: {r.student_no}</span></td>
                        <td className="text-ink-2">{r.class_name ?? '—'}</td>
                        <td className="text-right tabular">{r.correct}</td>
                        <td className="text-right tabular">{r.wrong}</td>
                        <td className="text-right tabular text-ink-3">{r.blank}</td>
                        <td className="text-right tabular font-medium">{n2(r.net)}</td>
                        <td className="text-right tabular">{n2(r.score)}</td>
                        <td className="text-right tabular text-ink-2">{r.institution_rank ?? '—'}</td>
                      </tr>
                    ))}
                  </SimpleTable>
                )}
              </Panel>
            </>
          )}
        </>
      )}
    </ReportFrame>
  )
}

export default reportPage('exams', ExamsReport)
