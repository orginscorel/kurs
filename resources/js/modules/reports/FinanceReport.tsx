import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { CalendarClock, HandCoins, Percent, Wallet } from 'lucide-react'
import { api } from '@/lib/api'
import { compactMoney, money, num, percent } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel, Stat } from '@/components/ui/layout'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { axisProps, ChartTooltip, gridProps, series } from '@/components/charts/ChartKit'
import { BarList, DateRange, ExportButton, presetRange, ReportFrame, reportPage, SimpleTable, Th, useUrlFilters } from './shared'

type Row = { period: string; label: string; collections: string; due: string; paid_on_due: string; collection_rate: number | null; payment_count: number }
type Finance = {
  from: string; to: string
  rows: Row[]
  totals: Omit<Row, 'period' | 'label'>
  by_method: { method: string; label: string; count: number; amount: string }[]
  voided_payments: { count: number; amount: string }
  receivables: { total: string; overdue: string; aging: { key: string; label: string; amount: string; count: number }[]; expected_next_7: string; expected_next_30: string }
}

const days = (a: string, b: string) => Math.round((new Date(`${b}T12:00:00`).getTime() - new Date(`${a}T12:00:00`).getTime()) / 86_400_000) + 1

/** Tahsilat ve alacak: /finance/reports verisi (reports.finance). Ayrıntılı gelir-gider için Finans > Raporlar. */
function FinanceReport() {
  const can = useCan()
  const def = presetRange('month')
  const [f, setF] = useUrlFilters({ from: def.from, to: def.to })
  const span = days(f.from, f.to)
  const group = span <= 31 ? 'day' : span <= 120 ? 'week' : 'month'
  const query = { from: f.from, to: f.to, group }
  const enabled = can('reports.view') && can('reports.finance')
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'report', query],
    queryFn: () => api.get<{ data: Finance }>('/finance/reports', query).then((r) => r.data),
    placeholderData: keepPreviousData,
    enabled,
  })
  const t = data?.totals
  const r = data?.receivables
  const chart = (data?.rows ?? []).map((x) => ({ label: x.label, collections: Number(x.collections) }))
  const hasCollections = chart.some((x) => x.collections > 0)
  const nothing = !isLoading && !hasCollections && Number(r?.total ?? 0) === 0

  return (
    <ReportFrame
      reportKey="finance"
      actions={
        <>
          <ExportButton path="/finance/reports/export" query={query} name="tahsilat-raporu.xlsx" disabled={nothing} />
          <ExportButton path="/finance/reports/pdf" query={query} name="tahsilat-raporu.pdf" kind="pdf" disabled={nothing} />
          <ExportButton path="/finance/payments/export" query={{ from: f.from, to: f.to, status: 'active' }} name="tahsilatlar.xlsx" label="Tahsilat listesi" permission={['reports.export', 'finance.view']} disabled={!hasCollections} />
          <ExportButton path="/finance/installments/export" query={{ status: 'open' }} name="alacaklar.xlsx" label="Alacak listesi" permission={['reports.export', 'finance.view']} disabled={Number(r?.total ?? 0) === 0} />
        </>
      }
      filters={<DateRange from={f.from} to={f.to} presets={['month', 'last_month', 'last30', 'year']} onChange={(x) => setF(x)} />}
    >
      <div className={cn('mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4', isFetching && !isLoading && 'opacity-70')}>
        <Stat label="Tahsilat" icon={<HandCoins />} loading={isLoading} value={compactMoney(t?.collections)} sub={`${num(t?.payment_count)} tahsilat${data?.voided_payments.count ? ` · ${data.voided_payments.count} iptal hariç` : ''}`} />
        <Stat label="Tahsilat oranı" icon={<Percent />} loading={isLoading} value={t?.collection_rate == null ? '—' : percent(t.collection_rate, 1)} sub={`vadesi gelen ${compactMoney(t?.due)}`} />
        <Stat label="Toplam alacak" icon={<Wallet />} loading={isLoading} value={compactMoney(r?.total)} sub="bugün itibarıyla" to={can('finance.view') ? '/finans/alacaklar' : undefined} />
        <Stat label="Gecikmiş alacak" icon={<CalendarClock />} loading={isLoading} value={compactMoney(r?.overdue)} tone={Number(r?.overdue ?? 0) > 0 ? 'danger' : undefined}
          sub={`7 gün içinde beklenen ${compactMoney(r?.expected_next_7)}`} />
      </div>

      {nothing ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<Wallet />} title="Bu aralıkta tahsilat ve açık alacak yok" description="Öğrenci kaydı ve ödeme planı oluşturuldukça bu rapor dolacak."
            action={can('payments.create') ? <ButtonLink to="/finans/tahsilat" variant="primary">Tahsilat al</ButtonLink> : undefined} />
        </div>
      ) : (
        <>
          <Panel title="Dönem tahsilatı" description={group === 'day' ? 'Günlük' : group === 'week' ? 'Haftalık' : 'Aylık'} className="mb-4"
            actions={can('finance.view') ? <ButtonLink to="/finans/raporlar" size="sm" variant="ghost">Gelir-gider ayrıntısı</ButtonLink> : undefined}>
            {isLoading ? <Skeleton className="h-[230px]" /> : !hasCollections ? <EmptyState compact title="Bu aralıkta tahsilat yok" /> : (
              <div className="h-[230px]">
                <ResponsiveContainer>
                  <BarChart data={chart} margin={{ top: 4, right: 0, left: 0, bottom: 0 }} barCategoryGap="22%">
                    <CartesianGrid {...gridProps} />
                    <XAxis dataKey="label" {...axisProps} dy={6} minTickGap={12} />
                    <YAxis {...axisProps} width={54} tickFormatter={(v) => compactMoney(v).replace(' ₺', '')} />
                    <Tooltip cursor={{ fill: 'var(--surface-2)' }} content={<ChartTooltip formatValue={(v) => money(v)} />} />
                    <Bar dataKey="collections" name="Tahsilat" fill={series[0]} radius={[3, 3, 0, 0]} maxBarSize={26} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            )}
          </Panel>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Panel title="Ödeme yöntemleri">
              {isLoading ? <Skeleton className="h-24" /> : !data?.by_method.length ? <EmptyState compact title="Tahsilat yok" /> : (
                <BarList items={data.by_method.map((m) => ({ label: m.label, value: Number(m.amount), hint: `${m.count} adet` }))} format={(n) => compactMoney(n)} />
              )}
            </Panel>
            <Panel title="Alacak yaşlandırma" description="Vadesi geçen taksitlerin gecikme süresine göre dağılımı">
              {isLoading ? <Skeleton className="h-24" /> : !r?.aging.some((a) => Number(a.amount) > 0) ? <EmptyState compact title="Gecikmiş alacak yok" /> : (
                <SimpleTable head={<tr><Th>Gecikme</Th><Th right>Taksit</Th><Th right>Tutar</Th></tr>} className="[&_table]:min-w-0">
                  {r.aging.map((a) => (
                    <tr key={a.key}><td>{a.label}</td><td className="text-right tabular text-ink-2">{a.count}</td><td className="text-right tabular font-medium">{money(a.amount)}</td></tr>
                  ))}
                </SimpleTable>
              )}
            </Panel>
          </div>
        </>
      )}
    </ReportFrame>
  )
}

export default reportPage('finance', FinanceReport)
