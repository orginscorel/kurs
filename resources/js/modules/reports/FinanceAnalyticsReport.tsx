import { Link, Navigate } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { BarChart3, Info } from 'lucide-react'
import { api } from '@/lib/api'
import { compactMoney, money, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel, Stat } from '@/components/ui/layout'
import { Alert, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Select } from '@/components/ui/form'
import { axisProps, ChartTooltip, gridProps, Legend, series } from '@/components/charts/ChartKit'
import type { AnalyticsColumn, AnalyticsReport, AnalyticsTable } from '@/modules/finance/ledger'
import { DateRange, ExportButton, presetRange, ReportFrame, reportPage, useUrlFilters } from './shared'

const OPTIONS: { value: string; label: string }[] = [
  { value: 'income-statement', label: 'Gelir tablosu' },
  { value: 'cash-flow', label: 'Nakit akışı' },
  { value: 'collection-performance', label: 'Tahsilat performansı' },
  { value: 'aging', label: 'Yaşlandırma' },
  { value: 'invoice-vat', label: 'Fatura ve KDV' },
  { value: 'program-profitability', label: 'Program kârlılığı' },
]

function fmt(v: string | number | null | undefined, type: AnalyticsColumn['type']): string {
  if (v === null || v === undefined || v === '') return '—'
  switch (type) {
    case 'money':
      return money(v)
    case 'percent':
      return `%${String(v).replace('.', ',')}`
    case 'number':
      return typeof v === 'number' ? num(v) : String(v).replace('.', ',')
    default:
      return String(v)
  }
}

const isNeg = (v: unknown) => typeof v === 'string' && v.startsWith('-')

function FinanceAnalyticsReport() {
  const can = useCan()
  const def = presetRange('year')
  const [f, setF] = useUrlFilters({ rapor: 'income-statement', from: def.from, to: def.to })
  const key = OPTIONS.some((o) => o.value === f.rapor) ? f.rapor : 'income-statement'
  // Mizan tek yerde: Finans > Muhasebe > Mizan. Eski ?rapor=trial-balance bağlantısı oraya gider.
  const toTrialBalance = f.rapor === 'trial-balance'
  const isAging = key === 'aging'
  const query = { from: f.from, to: f.to }
  const enabled = can('reports.view') && can('reports.finance') && !toTrialBalance

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'analytics', key, query],
    queryFn: () => api.get<{ data: AnalyticsReport }>(`/reports/finance-analytics/${key}`, query).then((r) => r.data),
    placeholderData: keepPreviousData,
    enabled,
  })
  const r = data && data.key === key ? data : undefined

  const chartTable: AnalyticsTable | undefined = r?.chart ? (r.chart.table !== undefined ? r.tables[r.chart.table] : r.tables.find((t) => t.chart)) : undefined
  const chartRows = chartTable && r?.chart
    ? chartTable.rows.map((row) => ({ label: String(row[r.chart!.x] ?? ''), ...Object.fromEntries(r.chart!.series.map((s) => [s.key, Number(row[s.key] ?? 0)])) }))
    : []
  const hasChart = chartRows.some((row) => r!.chart!.series.some((s) => (row as Record<string, number | string>)[s.key] !== 0))
  const empty = !!r && r.tables.every((t) => t.rows.length === 0)

  if (toTrialBalance) return <Navigate to="/finans/muhasebe?sekme=mizan" replace />

  return (
    <ReportFrame
      reportKey="finance-analytics"
      actions={
        <>
          <ExportButton path={`/reports/finance-analytics/${key}/export`} query={query} name={`${key}.xlsx`} disabled={!r} />
          <ExportButton path={`/reports/finance-analytics/${key}/pdf`} query={query} name={`${key}.pdf`} kind="pdf" disabled={!r} />
        </>
      }
      filters={
        <div className="flex w-full flex-col gap-2">
          <div className="lg:hidden">
            <Select aria-label="Analiz" value={key} onChange={(e) => setF({ rapor: e.target.value })} options={OPTIONS} />
          </div>
          <div className="hidden flex-wrap gap-1 lg:flex">
            {OPTIONS.map((o) => (
              <button
                key={o.value}
                type="button"
                onClick={() => setF({ rapor: o.value })}
                className={cn('h-8 rounded-[var(--radius-sm)] px-3 text-[13px] font-medium transition-colors',
                  key === o.value ? 'bg-primary-soft text-primary-ink ring-1 ring-primary/30' : 'text-ink-2 hover:bg-surface-2 hover:text-ink')}
              >
                {o.label}
              </button>
            ))}
          </div>
          {isAging ? (
            <p className="text-[12.5px] text-ink-3">Yaşlandırma raporu her zaman bugünkü duruma göre hazırlanır; tarih filtresi uygulanmaz.</p>
          ) : (
            <div className="flex flex-wrap items-center gap-2">
              <DateRange from={f.from} to={f.to} presets={['month', 'last_month', 'year', 'last12']} onChange={(x) => setF(x)} />
            </div>
          )}
          {can('finance.accounting') && (
            <p className="text-[12.5px] text-ink-3">
              Mizan, yevmiye ve hesap planı tek yerde: <Link to="/finans/muhasebe?sekme=mizan" className="text-primary hover:underline">Finans &gt; Muhasebe &gt; Mizan</Link>
            </p>
          )}
        </div>
      }
    >
      {isLoading || !r ? (
        <div className="flex flex-col gap-3">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-[92px]" />)}</div>
          <Skeleton className="h-[260px]" />
        </div>
      ) : (
        <div className={cn('flex flex-col gap-4', isFetching && 'opacity-70')}>
          {r.description && <p className="text-[13px] text-ink-2">{r.description}</p>}

          {!!r.kpis?.length && (
            <div className={cn('grid grid-cols-2 gap-3', r.kpis.length >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3')}>
              {r.kpis.map((k) => (
                <Stat
                  key={k.label}
                  label={k.label}
                  tone={k.tone ?? undefined}
                  value={k.type === 'money' ? compactMoney(k.value) : fmt(k.value, k.type)}
                  sub={k.type === 'money' ? money(k.value) : undefined}
                />
              ))}
            </div>
          )}

          {empty ? (
            <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
              <EmptyState icon={<BarChart3 />} title="Bu aralıkta veri yok" description="Tarih aralığını genişleterek tekrar deneyin." />
            </div>
          ) : (
            <>
              {r.chart && chartTable && hasChart && (
                <Panel title={chartTable.title}>
                  <Legend className="mb-2" items={r.chart.series.map((s, i) => ({ label: s.label, color: series[i]! }))} />
                  <div className="h-[250px]">
                    <ResponsiveContainer>
                      <BarChart data={chartRows} margin={{ top: 6, right: 4, left: 0, bottom: 0 }} barCategoryGap="24%">
                        <CartesianGrid {...gridProps} />
                        <XAxis dataKey="label" {...axisProps} dy={6} minTickGap={8} />
                        <YAxis {...axisProps} width={56} tickFormatter={(v) => compactMoney(v).replace(' ₺', '')} />
                        <Tooltip cursor={{ fill: 'var(--surface-2)' }} content={<ChartTooltip formatValue={(v) => money(v)} />} />
                        {r.chart.series.map((s, i) => (
                          <Bar key={s.key} dataKey={s.key} name={s.label} fill={series[i]} radius={[3, 3, 0, 0]} maxBarSize={26} />
                        ))}
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                </Panel>
              )}

              {r.tables.map((t) => (
                <Panel key={t.title} title={t.title} description={`${t.rows.length} satır`} flush>
                  {t.rows.length === 0 ? (
                    <EmptyState compact title="Kayıt yok" />
                  ) : (
                    <div className="overflow-x-auto scroll-thin">
                      <table className="tbl w-full text-[13px]">
                        <thead>
                          <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
                            {t.columns.map((c, ci) => (
                              <th key={c.key} className={cn('h-9 px-3 font-medium whitespace-nowrap', ci === 0 ? 'text-left' : 'text-center')}>{c.label}</th>
                            ))}
                          </tr>
                        </thead>
                        <tbody>
                          {t.rows.map((row, i) => (
                            <tr key={i} className="border-b border-line last:border-0 hover:bg-surface-2/50">
                              {t.columns.map((c, ci) => (
                                <td key={c.key} className={cn('px-3 py-2', ci === 0 ? 'text-left' : 'text-center', c.type !== 'text' && 'tabular whitespace-nowrap', c.type === 'money' && isNeg(row[c.key]) && 'text-danger')}>
                                  {fmt(row[c.key], c.type)}
                                </td>
                              ))}
                            </tr>
                          ))}
                        </tbody>
                        {t.totals && (
                          <tfoot>
                            <tr className="border-t-2 border-line-strong bg-surface-2/60 font-semibold">
                              {t.columns.map((c, ci) => (
                                <td key={c.key} className={cn('px-3 py-2', ci === 0 ? 'text-left' : 'text-center', c.type !== 'text' && 'tabular whitespace-nowrap', c.type === 'money' && isNeg(t.totals![c.key]) && 'text-danger')}>
                                  {c.key in t.totals! ? fmt(t.totals![c.key], c.type) : ''}
                                </td>
                              ))}
                            </tr>
                          </tfoot>
                        )}
                      </table>
                    </div>
                  )}
                </Panel>
              ))}
            </>
          )}

          {!!r.notes?.length && (
            <Alert tone="info" icon={<Info />} title="Nasıl hesaplanır?">
              <ul className="list-disc space-y-0.5 pl-4">
                {r.notes.map((n) => <li key={n}>{n}</li>)}
              </ul>
            </Alert>
          )}
        </div>
      )}
    </ReportFrame>
  )
}

export default reportPage('finance-analytics', FinanceAnalyticsReport)
