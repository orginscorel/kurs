import { Link } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { CheckCircle2, Coins, Mail, Send } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date as fmtDate, money, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel, Stat } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { axisProps, ChartTooltip, gridProps, Legend, monthLabel, series } from '@/components/charts/ChartKit'
import { BarList, DateRange, ReportFrame, reportPage, SimpleTable, Th, useUrlFilters } from './shared'

type ChannelTotals = { total: number; sent: number; delivered: number; failed: number; parts: number; success_rate: number | null; delivery_rate: number | null }
type Report = {
  from: string; to: string; unit_price: number | null
  totals: { sent: number; failed: number; success_rate: number | null; sms_parts: number; cost: number | null; campaigns: number }
  channels: Record<'sms' | 'email' | 'whatsapp', ChannelTotals>
  months: { ym: string; sms: number; email: number; whatsapp: number; total: number; sent: number; failed: number; sms_parts: number; success_rate: number | null; cost: number | null }[]
  by_source: { key: 'campaign' | 'automation' | 'announcement' | 'manual'; total: number; sent: number }[]
  campaigns: { id: number; name: string; channels: string[]; is_commercial: boolean; status: string; status_label: string; approved_at: string; sent: number; delivered: number; failed: number; skipped: number; sms_parts: number; cost: number | null }[]
}

const SOURCE_LABEL: Record<string, string> = { campaign: 'Toplu gönderim', automation: 'Otomatik bildirim', announcement: 'Duyuru', manual: 'Elle gönderim' }
const CHANNELS = [
  { key: 'sms', label: 'SMS', color: series[0]! },
  { key: 'email', label: 'E-posta', color: series[1]! },
  { key: 'whatsapp', label: 'WhatsApp', color: series[2]! },
] as const
const pct = (v: number | null | undefined) => (v == null ? '—' : `%${v.toLocaleString('tr-TR', { maximumFractionDigits: 1 })}`)

function CommunicationReport() {
  const can = useCan()
  const [f, setF] = useUrlFilters({ from: '', to: '' })
  const { data, isLoading, isFetching, isError, error } = useQuery({
    queryKey: ['reports', 'communication', f],
    queryFn: () => api.get<{ data: Report }>('/reports/communication', f),
    placeholderData: keepPreviousData,
  })
  const r = data?.data
  const t = r?.totals
  const from = f.from || r?.from || ''
  const to = f.to || r?.to || ''
  const empty = !isLoading && r && r.months.every((m) => m.total === 0)
  const monthData = (r?.months ?? []).map((m) => ({ ...m, label: monthLabel(m.ym) }))

  return (
    <ReportFrame
      reportKey="communication"
      filters={from ? <DateRange from={from} to={to} presets={['month', 'last_month', 'year', 'last12']} onChange={(x) => setF(x)} /> : undefined}
    >
      {isError && <Alert tone="danger" className="mb-4">{error instanceof ApiError ? error.firstError() : 'Rapor yüklenemedi.'}</Alert>}

      <div className={cn('mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4', isFetching && !isLoading && 'opacity-70')}>
        <Stat label="Gönderilen ileti" icon={<Send />} loading={isLoading} value={num(t?.sent)} sub={`${num(t?.failed)} başarısız`} />
        <Stat label="Başarı oranı" icon={<CheckCircle2 />} loading={isLoading} value={pct(t?.success_rate)} tone={t?.success_rate != null && t.success_rate < 90 ? 'warning' : undefined} sub="gönderilen / (gönderilen + başarısız)" />
        <Stat label="Harcanan SMS" icon={<Coins />} loading={isLoading} value={num(t?.sms_parts)} sub={t?.cost != null ? `≈ ${money(t.cost)}` : 'birim fiyat girilmedi'} />
        <Stat label="Toplu gönderim" icon={<Mail />} loading={isLoading} value={num(t?.campaigns)} sub="onaylanan kampanya" />
      </div>

      {empty ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<Send />} title="Bu aralıkta gönderim yok" description="SMS, e-posta ya da WhatsApp gönderildikçe burada aylık özet oluşur."
            action={can('messages.campaign') ? <ButtonLink variant="primary" to="/iletisim/toplu-gonderim/yeni">Yeni toplu gönderim</ButtonLink> : undefined} />
        </div>
      ) : (
        <>
          <div className="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <Panel title="Aylık gönderim" description={r ? `${fmtDate(r.from)} – ${fmtDate(r.to)} · kanala göre gönderilen ileti` : undefined}>
              {isLoading ? <Skeleton className="h-[240px]" /> : (
                <>
                  <Legend className="mb-2" items={CHANNELS.map((c) => ({ label: c.label, color: c.color }))} />
                  <div className="h-[230px]">
                    <ResponsiveContainer>
                      <BarChart data={monthData} margin={{ top: 4, right: 0, left: 0, bottom: 0 }} barCategoryGap="24%">
                        <CartesianGrid {...gridProps} />
                        <XAxis dataKey="label" {...axisProps} dy={6} minTickGap={6} />
                        <YAxis {...axisProps} width={36} allowDecimals={false} />
                        <Tooltip cursor={{ fill: 'var(--surface-2)' }} content={<ChartTooltip formatValue={(v) => num(v)} />} />
                        {CHANNELS.map((c, i) => (
                          <Bar key={c.key} dataKey={c.key} name={c.label} stackId="ch" fill={c.color} maxBarSize={22}
                            stroke="var(--surface)" strokeWidth={1} radius={i === CHANNELS.length - 1 ? [3, 3, 0, 0] : [0, 0, 0, 0]} />
                        ))}
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                </>
              )}
            </Panel>
            <Panel title="Aylık başarı oranı" description="Sağlayıcının kabul ettiği iletilerin payı">
              {isLoading ? <Skeleton className="h-[240px]" /> : (
                <div className="h-[250px]">
                  <ResponsiveContainer>
                    <LineChart data={monthData} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                      <CartesianGrid {...gridProps} />
                      <XAxis dataKey="label" {...axisProps} dy={6} minTickGap={6} />
                      <YAxis {...axisProps} width={40} domain={[0, 100]} tickFormatter={(v) => `%${v}`} />
                      <Tooltip content={<ChartTooltip formatValue={(v) => pct(v)} />} />
                      <Line type="linear" dataKey="success_rate" name="Başarı oranı" stroke={series[0]} strokeWidth={2} dot={{ r: 3 }} connectNulls={false} />
                    </LineChart>
                  </ResponsiveContainer>
                </div>
              )}
            </Panel>
          </div>

          <div className="mb-4 grid grid-cols-1 gap-4 2xl:grid-cols-2">
            <Panel title="Kanal özeti">
              {isLoading || !r ? <Skeleton className="h-32" /> : (
                <SimpleTable head={<tr><Th>Kanal</Th><Th right>Gönderilen</Th><Th right>İletilen</Th><Th right>Başarısız</Th><Th right>Başarı</Th></tr>}>
                  {CHANNELS.map((c) => {
                    const x = r.channels[c.key]
                    return (
                      <tr key={c.key}>
                        <td className="font-medium text-ink"><span className="mr-2 inline-block size-2 rounded-[3px]" style={{ background: c.color }} />{c.label}</td>
                        <td className="text-right tabular">{num(x.sent)}</td>
                        <td className="text-right tabular">{c.key === 'email' ? '—' : num(x.delivered)}</td>
                        <td className={cn('text-right tabular', x.failed > 0 && 'text-danger')}>{num(x.failed)}</td>
                        <td className="text-right tabular">{pct(x.success_rate)}</td>
                      </tr>
                    )
                  })}
                </SimpleTable>
              )}
            </Panel>
            <Panel title="Gönderim kaynağı" description="Gönderilen ileti sayısı">
              {isLoading ? <Skeleton className="h-32" /> : (
                <BarList items={(r?.by_source ?? []).map((s) => ({ label: SOURCE_LABEL[s.key] ?? s.key, value: s.sent, hint: `${num(s.total)} kayıt` }))} />
              )}
            </Panel>
          </div>

          <Panel title="Aylık döküm" className="mb-4" description={r?.unit_price != null ? `SMS birim fiyatı ${money(r.unit_price)} ile tahmini maliyet` : 'Maliyet için Ayarlar › Mesaj kanalları › SMS birim fiyatını girin'}>
            {isLoading ? <Skeleton className="h-32" /> : (
              <SimpleTable head={<tr><Th>Ay</Th><Th right>SMS</Th><Th right>E-posta</Th><Th right>WhatsApp</Th><Th right>Başarısız</Th><Th right>Başarı</Th><Th right>SMS parça</Th><Th right>Maliyet</Th></tr>}>
                {[...(r?.months ?? [])].reverse().map((m) => (
                  <tr key={m.ym}>
                    <td className="text-ink">{new Date(`${m.ym}-01`).toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' })}</td>
                    <td className="text-right tabular">{num(m.sms)}</td>
                    <td className="text-right tabular">{num(m.email)}</td>
                    <td className="text-right tabular">{num(m.whatsapp)}</td>
                    <td className={cn('text-right tabular', m.failed > 0 && 'text-danger')}>{num(m.failed)}</td>
                    <td className="text-right tabular">{pct(m.success_rate)}</td>
                    <td className="text-right tabular">{num(m.sms_parts)}</td>
                    <td className="text-right tabular">{m.cost == null ? '—' : money(m.cost)}</td>
                  </tr>
                ))}
              </SimpleTable>
            )}
          </Panel>

          <Panel title="Toplu gönderimler" description="Aralıkta onaylanan son 15 gönderim">
            {isLoading ? <Skeleton className="h-32" /> : !r?.campaigns.length ? <p className="text-[13px] text-ink-3">Bu aralıkta toplu gönderim yok.</p> : (
              <SimpleTable head={<tr><Th>Gönderim</Th><Th>Durum</Th><Th right>Gönderilen</Th><Th right>İletilen</Th><Th right>Başarısız</Th><Th right>Atlanan</Th><Th right>SMS</Th><Th right>Maliyet</Th></tr>}>
                {r.campaigns.map((c) => (
                  <tr key={c.id}>
                    <td>
                      {can(['messages.campaign', 'messages.campaign_send'])
                        ? <Link to={`/iletisim/toplu-gonderim/${c.id}`} className="font-medium text-ink hover:text-primary">{c.name}</Link>
                        : <span className="font-medium text-ink">{c.name}</span>}
                      <span className="block text-[12px] text-ink-3">{fmtDate(c.approved_at)} · {c.channels.map((x) => (x === 'sms' ? 'SMS' : 'E-posta')).join(' + ')}{c.is_commercial ? ' · ticari' : ''}</span>
                    </td>
                    <td><Badge tone={c.status === 'completed' ? 'success' : c.status === 'cancelled' ? 'warning' : 'info'}>{c.status_label}</Badge></td>
                    <td className="text-right tabular">{num(c.sent)}</td>
                    <td className="text-right tabular">{num(c.delivered)}</td>
                    <td className={cn('text-right tabular', c.failed > 0 && 'text-danger')}>{num(c.failed)}</td>
                    <td className="text-right tabular text-ink-3">{num(c.skipped)}</td>
                    <td className="text-right tabular">{num(c.sms_parts)}</td>
                    <td className="text-right tabular">{c.cost == null ? '—' : money(c.cost)}</td>
                  </tr>
                ))}
              </SimpleTable>
            )}
          </Panel>
        </>
      )}
    </ReportFrame>
  )
}

export default reportPage('communication', CommunicationReport)
