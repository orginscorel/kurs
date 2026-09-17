import { useMemo } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Percent, UserCheck, UserPlus } from 'lucide-react'
import { api } from '@/lib/api'
import { num, percent } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { DescriptionList, Panel, Stat } from '@/components/ui/layout'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { Select } from '@/components/ui/form'
import { axisProps, ChartTooltip, gridProps, Legend, monthLabel, series } from '@/components/charts/ChartKit'
import { BarList, DateRange, ExportButton, presetRange, ReportFrame, reportPage, SimpleTable, Th, useUrlFilters } from './shared'
import type { LeadsData } from './types'

const rate = (won: number, total: number) => (total > 0 ? Math.round((won * 1000) / total) / 10 : null)
const ymLabel = (ym: string) => `${monthLabel(ym)} ${ym.slice(2, 4)}`

/** Ön kayıt dönüşüm raporu (eski /crm/rapor içeriği + kaynak ve ay kırılımı). */
function LeadsReport() {
  const can = useCan()
  const def = presetRange('last12')
  const [f, setF] = useUrlFilters({ from: def.from, to: def.to, source: '' })
  const query = { from: f.from, to: f.to, source: f.source }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['reports', 'leads', query],
    queryFn: () => api.get<{ data: LeadsData }>('/reports/leads', query).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
  const s = data?.summary
  const empty = !isLoading && !s?.total
  const monthly = (data?.monthly_trend ?? []).map((m) => ({ label: ymLabel(m.ym), total: Number(m.total), won: Number(m.won) }))

  // Ay × kaynak matrisi
  const matrix = useMemo(() => {
    const rows = data?.by_source_month ?? []
    const months = [...new Set(rows.map((r) => r.ym))].sort()
    const sources = [...new Map(rows.map((r) => [r.source, r.label])).entries()]
    const cell = new Map(rows.map((r) => [`${r.ym}|${r.source}`, r]))
    return { months, sources, cell }
  }, [data])

  return (
    <ReportFrame
      reportKey="leads"
      actions={<ExportButton path="/reports/leads/export" query={query} name="on-kayit-raporu.xlsx" disabled={empty} />}
      filters={
        <>
          <DateRange from={f.from} to={f.to} presets={['last12', 'year', 'month', 'last30']} onChange={(r) => setF(r)} />
          <Select className="w-full sm:ml-auto sm:w-[180px]" value={f.source} onChange={(e) => setF({ source: e.target.value })} placeholder="Tüm kaynaklar"
            options={Object.entries(data?.sources ?? {}).map(([value, label]) => ({ value, label }))} />
        </>
      }
    >
      <div className={cn('mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3', isFetching && !isLoading && 'opacity-70')}>
        <Stat label="Ön kayıt" icon={<UserPlus />} loading={isLoading} value={num(s?.total)} sub="seçilen aralıkta açılan" />
        <Stat label="Kayda dönen" icon={<UserCheck />} loading={isLoading} value={num(s?.won)} />
        <Stat label="Dönüşüm oranı" icon={<Percent />} loading={isLoading} value={s?.total ? percent(s.rate, 1) : '—'} />
      </div>

      {empty ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<UserPlus />} title="Bu aralıkta ön kayıt yok" description="Ön kayıt ekranından aday girildikçe kaynak ve dönüşüm bilgisi burada görünür."
            action={can('crm.manage') ? <ButtonLink to="/on-kayit?yeni=1" variant="primary">Ön kayıt ekle</ButtonLink> : undefined} />
        </div>
      ) : (
        <>
          <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Panel title="Aylık ön kayıt" description="Açılan ön kayıt ve bunlardan kayda dönenler">
              {isLoading ? <Skeleton className="h-[230px]" /> : (
                <>
                  <Legend className="mb-2" items={[{ label: 'Ön kayıt', color: series[1]! }, { label: 'Kayda dönen', color: series[0]! }]} />
                  <div className="h-[220px]">
                    <ResponsiveContainer>
                      <BarChart data={monthly} margin={{ top: 4, right: 0, left: 0, bottom: 0 }} barCategoryGap="24%" barGap={2}>
                        <CartesianGrid {...gridProps} />
                        <XAxis dataKey="label" {...axisProps} dy={6} />
                        <YAxis {...axisProps} width={30} allowDecimals={false} />
                        <Tooltip cursor={{ fill: 'var(--surface-2)' }} content={<ChartTooltip formatValue={(v) => num(v)} />} />
                        <Bar dataKey="total" name="Ön kayıt" fill={series[1]} radius={[3, 3, 0, 0]} maxBarSize={20} />
                        <Bar dataKey="won" name="Kayda dönen" fill={series[0]} radius={[3, 3, 0, 0]} maxBarSize={20} />
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                </>
              )}
            </Panel>
            <Panel title="Nereden duydular" description="Her kaynaktan gelen ön kayıt ve kayda dönüşen sayısı">
              {isLoading ? <Skeleton className="h-40" /> : (
                <SimpleTable head={<tr><Th>Nereden duydu</Th><Th right>Ön kayıt</Th><Th right>Kayıt oldu</Th><Th right>Kayda dönüşme oranı</Th></tr>} className="[&_table]:min-w-[320px]">
                  {data!.by_source.map((x) => (
                    <tr key={x.source}>
                      <td><button type="button" className="text-ink hover:text-primary" onClick={() => setF({ source: x.source })}>{x.label}</button></td>
                      <td className="text-right tabular">{x.total}</td>
                      <td className="text-right tabular">{x.won}</td>
                      <td className="text-right tabular font-medium">{percent(x.rate, 1)}</td>
                    </tr>
                  ))}
                </SimpleTable>
              )}
            </Panel>
          </div>

          {matrix.months.length > 0 && (
            <Panel title="Aylara ve kaynaklara göre" description="Her hücre: ön kayıt sayısı / kayıt olan sayısı" className="mb-4">
              <SimpleTable head={<tr><Th>Ay</Th>{matrix.sources.map(([k, l]) => <Th key={k} right>{l}</Th>)}<Th right>Toplam</Th><Th right>Kayda dönüşme oranı</Th></tr>}>
                {matrix.months.map((m) => {
                  const cells = matrix.sources.map(([k]) => matrix.cell.get(`${m}|${k}`))
                  const total = cells.reduce((a, c) => a + (c?.total ?? 0), 0)
                  const won = cells.reduce((a, c) => a + (c?.won ?? 0), 0)
                  return (
                    <tr key={m}>
                      <td className="whitespace-nowrap">{ymLabel(m)}</td>
                      {cells.map((c, i) => <td key={i} className="text-right tabular text-ink-2">{c ? `${c.total} / ${c.won}` : '—'}</td>)}
                      <td className="text-right tabular font-medium">{total} / {won}</td>
                      <td className="text-right tabular">{percent(rate(won, total), 1)}</td>
                    </tr>
                  )
                })}
              </SimpleTable>
            </Panel>
          )}

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Panel title="Aşama dağılımı" description="Ön kayıtların güncel aşaması">
              {isLoading ? <Skeleton className="h-40" /> : <BarList items={(data?.funnel ?? []).map((x) => ({ label: x.label, value: x.count }))} />}
            </Panel>
            <Panel title="Takip eden personel performansı">
              {isLoading ? <Skeleton className="h-40" /> : !data?.by_owner.length ? <EmptyState compact title="Takip eden personel atanmış ön kayıt yok" /> : (
                <SimpleTable head={<tr><Th>Takip eden personel</Th><Th right>Ön kayıt</Th><Th right>Kayıt oldu</Th><Th right>Kaydolmadı</Th><Th right>Kayda dönüşme oranı</Th></tr>} className="[&_table]:min-w-[360px]">
                  {data.by_owner.map((o) => (
                    <tr key={o.id}><td>{o.name}</td><td className="text-right tabular">{o.total}</td><td className="text-right tabular">{o.won}</td><td className="text-right tabular text-ink-2">{o.lost}</td><td className="text-right tabular font-medium">{percent(o.rate, 1)}</td></tr>
                  ))}
                </SimpleTable>
              )}
            </Panel>
          </div>

          {(data?.lost_reasons.length ?? 0) > 0 && (
            <Panel title="En sık kaybedilme nedenleri" className="mt-4">
              <DescriptionList columns={2} items={data!.lost_reasons.map((r) => ({ label: r.lost_reason, value: `${r.total} aday` }))} />
            </Panel>
          )}
        </>
      )}
    </ReportFrame>
  )
}

export default reportPage('leads', LeadsReport)
