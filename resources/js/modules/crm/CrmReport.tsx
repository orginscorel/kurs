import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { TrendingUp } from 'lucide-react'
import { api } from '@/lib/api'
import { num, percent } from '@/lib/format'
import { axisProps, ChartTooltip, monthLabel, series } from '@/components/charts/ChartKit'
import { PageHeader, Panel, Stat, DescriptionList } from '@/components/ui/layout'
import { EmptyState, Skeleton, ProgressBar } from '@/components/ui/feedback'
import type { CrmReport as CrmReportData } from './types'

export default function CrmReport() {
  const { data, isLoading } = useQuery({ queryKey: ['crm', 'report'], queryFn: () => api.get<{ data: CrmReportData }>('/crm/report').then((r) => r.data) })

  const monthly = (data?.monthly_trend ?? []).map((r) => ({ label: monthLabel(r.ym), total: r.total, won: Number(r.won) }))
  const maxFunnel = Math.max(1, ...(data?.funnel ?? []).map((f) => f.count))

  return (
    <div className="animate-fade-in">
      <PageHeader title="CRM Raporu" description="Kaynak, aşama ve sorumlu bazında dönüşüm performansı" />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
        <Stat label="Toplam aday" value={isLoading ? '—' : num(data?.summary.total)} loading={isLoading} icon={<TrendingUp />} />
        <Stat label="Kayda dönüşen" value={isLoading ? '—' : num(data?.summary.won)} loading={isLoading} tone="success" />
        <Stat label="Dönüşüm oranı" value={isLoading ? '—' : percent(data?.summary.rate)} loading={isLoading} tone="primary" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <Panel title="Aylık yeni aday trendi" description="Yeni aday ve kayda dönüşenler">
          <div className="h-[240px]">
            {isLoading ? <Skeleton className="h-full" /> : monthly.length === 0 ? <EmptyState compact title="Veri yok" /> : (
              <ResponsiveContainer>
                <LineChart data={monthly} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                  <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
                  <XAxis dataKey="label" {...axisProps} dy={6} />
                  <YAxis {...axisProps} width={30} allowDecimals={false} />
                  <Tooltip cursor={{ stroke: 'var(--line-strong)' }} content={<ChartTooltip />} />
                  <Line type="linear" dataKey="total" name="Yeni aday" stroke={series[0]} strokeWidth={2} dot={{ r: 3.5, fill: 'var(--surface)', stroke: series[0], strokeWidth: 2 }} />
                  <Line type="linear" dataKey="won" name="Kayda dönüşen" stroke={series[1]} strokeWidth={2} dot={{ r: 3.5, fill: 'var(--surface)', stroke: series[1], strokeWidth: 2 }} />
                </LineChart>
              </ResponsiveContainer>
            )}
          </div>
        </Panel>

        <Panel title="Kaynak bazında dönüşüm" description="Kaynak başına aday sayısı ve dönüşüm oranı">
          <div className="h-[240px]">
            {isLoading ? <Skeleton className="h-full" /> : (data?.by_source.length ?? 0) === 0 ? <EmptyState compact title="Veri yok" /> : (
              <ResponsiveContainer>
                <BarChart data={data!.by_source} margin={{ top: 4, right: 0, left: 0, bottom: 0 }} barCategoryGap="28%">
                  <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
                  <XAxis dataKey="label" {...axisProps} dy={6} interval={0} angle={-20} textAnchor="end" height={46} />
                  <YAxis {...axisProps} width={30} allowDecimals={false} />
                  <Tooltip cursor={{ fill: 'var(--surface-2)', radius: 6 }} content={<ChartTooltip formatValue={(v, row) => row.dataKey === 'rate' ? percent(v) : num(v)} />} />
                  <Bar dataKey="total" name="Aday" fill={series[0]} radius={[4, 4, 0, 0]} maxBarSize={26} />
                  <Bar dataKey="won" name="Kayda dönüşen" fill={series[1]} radius={[4, 4, 0, 0]} maxBarSize={26} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>
        </Panel>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <Panel title="Aşama hunisi" description="Adayların güncel aşamalara göre dağılımı">
          {isLoading ? <Skeleton className="h-40" /> : (
            <div className="flex flex-col gap-2.5">
              {(data?.funnel ?? []).map((f) => (
                <div key={f.stage} className="flex items-center gap-3">
                  <span className="w-28 shrink-0 text-[12.5px] text-ink-2 truncate">{f.label}</span>
                  <div className="flex-1"><ProgressBar value={(f.count / maxFunnel) * 100} /></div>
                  <span className="w-8 shrink-0 text-right text-[12.5px] tabular text-ink">{f.count}</span>
                </div>
              ))}
            </div>
          )}
        </Panel>

        <Panel title="Sorumlu performansı" description="Sorumlu başına aday, dönüşüm ve kayıp">
          {isLoading ? <Skeleton className="h-40" /> : (data?.by_owner.length ?? 0) === 0 ? <EmptyState compact title="Veri yok" /> : (
            <div className="overflow-x-auto scroll-thin">
              <table className="tbl w-full text-[13px]">
                <thead><tr className="text-left text-ink-3 text-[12px]"><th className="pb-2 font-medium text-left">Takip eden personel</th><th className="pb-2 font-medium text-center">Ön kayıt</th><th className="pb-2 font-medium text-center">Kayıt oldu</th><th className="pb-2 font-medium text-center">Kaydolmadı</th><th className="pb-2 font-medium text-center">Kayda dönüşme oranı</th></tr></thead>
                <tbody>
                  {data!.by_owner.map((o) => (
                    <tr key={o.id} className="border-t border-line">
                      <td className="py-2 text-ink text-left">{o.name}</td>
                      <td className="py-2 tabular text-ink-2 text-center">{o.total}</td>
                      <td className="py-2 tabular text-success text-center">{o.won}</td>
                      <td className="py-2 tabular text-danger text-center">{o.lost}</td>
                      <td className="py-2 tabular text-ink font-medium text-center">{percent(o.rate)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      </div>

      <Panel title="En sık kaybedilme nedenleri">
        {isLoading ? <Skeleton className="h-24" /> : (data?.lost_reasons.length ?? 0) === 0 ? <EmptyState compact title="Kayıp kaydı yok" /> : (
          <DescriptionList columns={2} items={data!.lost_reasons.map((r) => ({ label: r.lost_reason, value: `${r.total} aday` }))} />
        )}
      </Panel>
    </div>
  )
}
