import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { CalendarClock, Download, FileSpreadsheet, FileText, Percent, TrendingDown, TrendingUp, Wallet } from 'lucide-react'
import { api } from '@/lib/api'
import { compactMoney, money, num, percent, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat } from '@/components/ui/layout'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Segmented, Select } from '@/components/ui/form'
import { axisProps, ChartTooltip, gridProps, Legend, series } from '@/components/charts/ChartKit'
import { addDaysISO } from './shared'
import { ShareBar } from './components'

type Row = { period: string; start: string; label: string; collections: string; other_income: string; income: string; expense: string; net: string; due: string; paid_on_due: string; collection_rate: number | null; payment_count: number }
type Report = {
  from: string; to: string; group: string
  rows: Row[]
  totals: Omit<Row, 'period' | 'start' | 'label'>
  by_method: { method: string; label: string; count: number; amount: string }[]
  income_by_category: { name: string; count: number; amount: string }[]
  expense_by_category: { name: string; count: number; amount: string }[]
  voided_payments: { count: number; amount: string }
  receivables: { total: string; overdue: string; aging: { key: string; label: string; amount: string; count: number }[]; expected_next_7: string; expected_next_30: string }
}
type Resp = { data: Report; terms: { id: number; name: string; starts_on: string; ends_on: string; is_current: boolean }[] }

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

function preset(key: string): { from: string; to: string; group: 'day' | 'week' | 'month' } {
  const today = new Date()
  const t = todayISO()
  switch (key) {
    case 'today':
      return { from: t, to: t, group: 'day' }
    case 'week': {
      const dow = (today.getDay() + 6) % 7
      return { from: addDaysISO(t, -dow), to: t, group: 'day' }
    }
    case 'last_month': {
      const first = new Date(today.getFullYear(), today.getMonth() - 1, 1)
      const last = new Date(today.getFullYear(), today.getMonth(), 0)
      return { from: iso(first), to: iso(last), group: 'day' }
    }
    case 'year':
      return { from: `${today.getFullYear()}-01-01`, to: t, group: 'month' }
    default:
      return { from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to: t, group: 'day' }
  }
}

export default function Reports() {
  const can = useCan()
  const [period, setPeriod] = useState('month')
  const [range, setRange] = useState(preset('month'))
  const query = { from: range.from, to: range.to, group: range.group }
  const { data, isLoading, isFetching } = useQuery({ queryKey: ['finance', 'report', query], queryFn: () => api.get<Resp>('/finance/reports', query), placeholderData: keepPreviousData })
  const r = data?.data
  const t = r?.totals

  const choose = (key: string) => {
    setPeriod(key)
    if (key.startsWith('term:')) {
      const term = data?.terms.find((x) => String(x.id) === key.slice(5))
      if (term) setRange({ from: term.starts_on, to: term.ends_on < todayISO() ? term.ends_on : todayISO(), group: 'month' })
    } else if (key !== 'custom') setRange(preset(key))
  }

  const chartRows = useMemo(() => (r?.rows ?? []).map((x) => ({ label: x.label, income: Number(x.income), expense: Number(x.expense), rate: x.collection_rate })), [r])
  const hasData = chartRows.some((x) => x.income || x.expense)
  const maxExpense = Math.max(1, ...(r?.expense_by_category ?? []).map((c) => Number(c.amount)))

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Finans raporları"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Raporlar' }]}
        description="Ciro, gelir-gider, tahsilat oranı ve alacak durumu. İptal edilen kayıtlar toplamlara dahil değildir."
        actions={
          can('reports.export') && (
            <>
              <Button icon={<FileSpreadsheet className="size-4" />} onClick={() => api.download('/finance/reports/export', query, 'finans-raporu.xlsx').catch((e) => toast.error(e.message))}>Excel</Button>
              <Button icon={<FileText className="size-4" />} onClick={() => api.download('/finance/reports/pdf', query, 'finans-raporu.pdf').catch((e) => toast.error(e.message))}>PDF</Button>
            </>
          )
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2 rounded-[var(--radius-lg)] bg-surface p-3 ring-1 ring-line">
        <Segmented
          size="sm"
          value={period.startsWith('term:') ? 'term' : period}
          onChange={(v) => (v === 'term' ? choose(`term:${data?.terms.find((x) => x.is_current)?.id ?? data?.terms[0]?.id}`) : choose(v))}
          className="overflow-x-auto max-w-full"
          options={[
            { value: 'today', label: 'Bugün' },
            { value: 'week', label: 'Bu hafta' },
            { value: 'month', label: 'Bu ay' },
            { value: 'last_month', label: 'Geçen ay' },
            { value: 'year', label: 'Bu yıl' },
            { value: 'term', label: 'Dönem' },
            { value: 'custom', label: 'Özel' },
          ]}
        />
        {period.startsWith('term:') && (
          <Select className="w-40" value={period.slice(5)} onChange={(e) => choose(`term:${e.target.value}`)} options={(data?.terms ?? []).map((x) => ({ value: x.id, label: x.name }))} />
        )}
        <div className="flex items-center gap-1.5">
          <Input type="date" value={range.from} onChange={(e) => { setPeriod('custom'); setRange((x) => ({ ...x, from: e.target.value })) }} className="w-[150px]" aria-label="Başlangıç tarihi" title="Başlangıç tarihi" />
          <span className="text-ink-3">–</span>
          <Input type="date" value={range.to} onChange={(e) => { setPeriod('custom'); setRange((x) => ({ ...x, to: e.target.value })) }} className="w-[150px]" aria-label="Bitiş tarihi" title="Bitiş tarihi" />
        </div>
        <Segmented size="sm" className="sm:ml-auto" value={range.group} onChange={(g) => setRange((x) => ({ ...x, group: g }))} options={[{ value: 'day', label: 'Günlük' }, { value: 'week', label: 'Haftalık' }, { value: 'month', label: 'Aylık' }]} />
      </div>

      <div className={cn('grid grid-cols-2 lg:grid-cols-4 gap-3', isFetching && 'opacity-70')}>
        <Stat label="Öğrenci tahsilatı (ciro)" icon={<Wallet />} loading={isLoading} value={compactMoney(t?.collections)} sub={`${num(t?.payment_count)} tahsilat`} />
        <Stat label="Toplam gelir" icon={<TrendingUp />} loading={isLoading} tone="success" value={compactMoney(t?.income)} sub={`diğer gelir ${compactMoney(t?.other_income)}`} />
        <Stat label="Gider" icon={<TrendingDown />} loading={isLoading} value={compactMoney(t?.expense)} sub={r?.voided_payments.count ? `${r.voided_payments.count} iptal tahsilat hariç` : 'iptaller hariç'} />
        <Stat label="Net" icon={<Wallet />} loading={isLoading} tone={Number(t?.net ?? 0) < 0 ? 'danger' : 'success'} value={compactMoney(t?.net)} sub={`${r?.from ?? ''} – ${r?.to ?? ''}`} />
        <Stat label="Tahsilat oranı" icon={<Percent />} loading={isLoading} value={t?.collection_rate == null ? '—' : percent(t.collection_rate, 1)} sub={`vadesi gelen ${compactMoney(t?.due)}`} />
        <Stat label="Toplam alacak" icon={<CalendarClock />} loading={isLoading} value={compactMoney(r?.receivables.total)} sub="bugün itibarıyla" to="/finans/alacaklar" />
        <Stat label="Gecikmiş alacak" icon={<CalendarClock />} loading={isLoading} tone={Number(r?.receivables.overdue ?? 0) > 0 ? 'danger' : undefined} value={compactMoney(r?.receivables.overdue)} to="/finans/alacaklar?status=overdue" />
        <Stat label="Beklenen tahsilat" icon={<CalendarClock />} loading={isLoading} value={compactMoney(r?.receivables.expected_next_7)} sub={`7 gün · 30 gün ${compactMoney(r?.receivables.expected_next_30)}`} />
      </div>

      <div className="mt-4 grid grid-cols-1 xl:grid-cols-3 gap-4">
        <Panel title="Gelir ve gider" className="xl:col-span-2">
          {isLoading ? (
            <Skeleton className="h-[280px]" />
          ) : !hasData ? (
            <EmptyState compact icon={<TrendingUp />} title="Bu aralıkta hareket yok" />
          ) : (
            <>
              {/* Tek eksen: tahsilat oranı üstteki göstergede; iki farklı ölçekli seriyi aynı grafikte çift eksenle göstermiyoruz */}
              <Legend className="mb-2" items={[{ label: 'Gelir', color: series[0]! }, { label: 'Gider', color: series[1]! }]} />
              <div className="h-[270px]">
                <ResponsiveContainer>
                  <BarChart data={chartRows} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} barGap={2} barCategoryGap="24%">
                    <CartesianGrid {...gridProps} />
                    <XAxis dataKey="label" {...axisProps} interval="preserveStartEnd" minTickGap={16} dy={6} />
                    <YAxis tickFormatter={(v) => compactMoney(v).replace(' ₺', '')} width={52} {...axisProps} />
                    <Tooltip cursor={{ fill: 'var(--surface-2)', radius: 6 }} content={<ChartTooltip formatValue={(v) => money(v, { short: true })} />} />
                    <Bar dataKey="income" name="Gelir" fill={series[0]} radius={[4, 4, 0, 0]} maxBarSize={24} />
                    <Bar dataKey="expense" name="Gider" fill={series[1]} radius={[4, 4, 0, 0]} maxBarSize={24} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </>
          )}
        </Panel>

        <Panel title="Alacak yaşlandırma">
          {isLoading || !r ? (
            <Skeleton className="h-40" />
          ) : (
            <>
              <ShareBar items={r.receivables.aging.map((b, i) => ({ key: b.key, label: b.label, value: Math.round(Number(b.amount) * 100), color: i === 0 ? 'var(--line-strong)' : series[i]! }))} />
              <ul className="mt-3 divide-y divide-line">
                {r.receivables.aging.map((b, i) => (
                  <li key={b.key} className="flex items-center gap-2 py-1.5 text-[13px]">
                    <span className="size-2 rounded-[3px]" style={{ background: i === 0 ? 'var(--line-strong)' : series[i] }} />
                    <span className="flex-1 text-ink-2">{b.label}</span>
                    <span className="text-[12px] text-ink-3 tabular">{b.count}</span>
                    <span className="w-24 text-right tabular font-medium">{money(b.amount, { short: true })}</span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </Panel>
      </div>

      <div className="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Panel title="Tahsilat yöntemleri">
          {!r ? <Skeleton className="h-32" /> : r.by_method.length === 0 ? <p className="text-[13px] text-ink-3">Tahsilat yok.</p> : (
            <ul className="divide-y divide-line">
              {r.by_method.map((m) => (
                <li key={m.method} className="flex items-center justify-between py-1.5 text-[13px]"><span className="text-ink-2">{m.label} <span className="text-ink-3">· {m.count}</span></span><span className="tabular font-medium">{money(m.amount, { short: true })}</span></li>
              ))}
            </ul>
          )}
        </Panel>
        <Panel title="Gelir kalemleri">
          {!r ? <Skeleton className="h-32" /> : (
            <ul className="divide-y divide-line">
              {r.income_by_category.filter((c) => Number(c.amount) > 0).map((c) => (
                <li key={c.name} className="flex items-center justify-between py-1.5 text-[13px]"><span className="text-ink-2">{c.name}</span><span className="tabular font-medium">{money(c.amount, { short: true })}</span></li>
              ))}
              {r.income_by_category.every((c) => Number(c.amount) === 0) && <li className="py-1.5 text-[13px] text-ink-3">Gelir yok.</li>}
            </ul>
          )}
        </Panel>
        <Panel title="Gider kalemleri">
          {!r ? <Skeleton className="h-32" /> : r.expense_by_category.length === 0 ? <p className="text-[13px] text-ink-3">Gider yok.</p> : (
            <ul className="flex flex-col gap-2">
              {r.expense_by_category.map((c) => (
                <li key={c.name} className="text-[13px]">
                  <div className="flex items-center justify-between"><span className="text-ink-2">{c.name}</span><span className="tabular font-medium">{money(c.amount, { short: true })}</span></div>
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-3"><div className="h-full rounded-full" style={{ width: `${(Number(c.amount) / maxExpense) * 100}%`, background: series[1] }} /></div>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <Panel title="Dönem dökümü" className="mt-4" flush actions={can('reports.export') ? <Button size="xs" variant="ghost" icon={<Download className="size-3.5" />} onClick={() => api.download('/finance/reports/export', query, 'finans-raporu.xlsx').catch((e) => toast.error(e.message))}>Excel</Button> : undefined}>
        {!r ? (
          <div className="px-4 pb-4"><Skeleton className="h-40" /></div>
        ) : (
          <div className="overflow-x-auto scroll-thin max-h-[520px]">
            <table className="tbl w-full text-[13px]">
              <thead className="sticky top-0 bg-surface">
                <tr className="border-y border-line bg-surface-2 text-[12px] text-ink-3">
                  <th className="h-9 px-4 font-medium text-left">Dönem</th>
                  <th className="px-3 font-medium text-center">Tahsilat</th>
                  <th className="px-3 font-medium text-center">Diğer gelir</th>
                  <th className="px-3 font-medium text-center">Gider</th>
                  <th className="px-3 font-medium text-center">Net (gelir − gider)</th>
                  <th className="px-3 font-medium hidden md:table-cell text-center">Vadesi gelen taksit</th>
                  <th className="px-4 font-medium text-center">Tahsilat oranı</th>
                </tr>
              </thead>
              <tbody>
                {r.rows.map((x) => (
                  <tr key={x.period} className="border-b border-line">
                    <td className="px-4 py-2 whitespace-nowrap text-left">{x.label}</td>
                    <td className="px-3 py-2 tabular text-center">{money(x.collections, { short: true })}</td>
                    <td className="px-3 py-2 tabular text-ink-2 text-center">{money(x.other_income, { short: true })}</td>
                    <td className="px-3 py-2 tabular text-ink-2 text-center">{money(x.expense, { short: true })}</td>
                    <td className={cn('px-3 py-2 tabular font-medium text-center', Number(x.net) < 0 && 'text-danger')}>{money(x.net, { short: true })}</td>
                    <td className="px-3 py-2 tabular text-ink-2 hidden md:table-cell text-center">{money(x.due, { short: true })}</td>
                    <td className="px-4 py-2 tabular text-center">{x.collection_rate == null ? '—' : percent(x.collection_rate, 1)}</td>
                  </tr>
                ))}
                <tr className="bg-surface-2/60 font-semibold">
                  <td className="px-4 py-2.5 text-left">Toplam</td>
                  <td className="px-3 py-2.5 tabular text-center">{money(r.totals.collections, { short: true })}</td>
                  <td className="px-3 py-2.5 tabular text-center">{money(r.totals.other_income, { short: true })}</td>
                  <td className="px-3 py-2.5 tabular text-center">{money(r.totals.expense, { short: true })}</td>
                  <td className={cn('px-3 py-2.5 tabular text-center', Number(r.totals.net) < 0 && 'text-danger')}>{money(r.totals.net, { short: true })}</td>
                  <td className="px-3 py-2.5 tabular hidden md:table-cell text-center">{money(r.totals.due, { short: true })}</td>
                  <td className="px-4 py-2.5 tabular text-center">{r.totals.collection_rate == null ? '—' : percent(r.totals.collection_rate, 1)}</td>
                </tr>
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </div>
  )
}
