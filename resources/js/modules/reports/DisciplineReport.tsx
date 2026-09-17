import { Link } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Gavel, Repeat, Scale, ThumbsUp, UserX } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date as fmtDate, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel, Stat } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton, type Tone } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { Select } from '@/components/ui/form'
import { axisProps, ChartTooltip, gridProps, Legend, series } from '@/components/charts/ChartKit'
import { BarList, DateRange, ExportButton, ReportFrame, reportPage, SimpleTable, Th, useUrlFilters } from './shared'

type Report = {
  from: string; to: string
  totals: { incidents: number; positives: number; students: number; penalty: number; merit: number; open_incidents: number; appealed_incidents: number; sanctions: number; active_sanctions: number; suspension_days: number; repeaters: number }
  by_behavior: { id: number; name: string; kind: string; category: string; category_label: string; count: number; points: number }[]
  by_category: { key: string; label: string; kind: string; count: number }[]
  by_severity: { key: string; label: string; count: number }[]
  by_status: { key: string; label: string; count: number }[]
  by_sanction: { id: number; name: string; level: number; tone: string; count: number; active: number }[]
  by_class: { id: number | null; name: string; incidents: number; students: number; penalty: number; positives: number; sanctions: number }[]
  by_teacher: { user_id: number | null; name: string; incidents: number; positives: number }[]
  repeaters: { student_id: number; full_name: string; student_no: string; class_names: string; incidents: number; penalty: number; merit: number; net: number; level: string; level_label: string; sanctions: number; last_at: string }[]
  trend: { ym: string; label: string; incidents: number; positives: number; sanctions: number }[]
}
type Filters = {
  term: { from: string; to: string; name: string }
  class_groups: { id: number; name: string; is_active: boolean }[]
  behaviors: { id: number; name: string; kind: string }[]
  teachers: { id: number; name: string }[]
  categories: Record<string, string>
}

const LEVEL_TONE: Record<string, Tone> = { none: 'success', watch: 'info', warning: 'warning', critical: 'danger' }

function DisciplineReport() {
  const can = useCan()
  const [f, setF] = useUrlFilters({ from: '', to: '', class_group_id: '', behavior_id: '', category: '', teacher_id: '' })
  const query = { ...f }
  const { data, isLoading, isFetching, isError, error } = useQuery({
    queryKey: ['reports', 'discipline', query],
    queryFn: () => api.get<{ data: Report; filters: Filters }>('/reports/discipline', query),
    placeholderData: keepPreviousData,
  })
  const r = data?.data
  const flt = data?.filters
  const t = r?.totals
  const empty = !isLoading && !t?.incidents && !t?.positives
  const from = f.from || r?.from || ''
  const to = f.to || r?.to || ''
  const exportQuery = { ...query, from, to }
  const canExport = ['reports.export', 'discipline.export']

  return (
    <ReportFrame
      reportKey="discipline"
      actions={
        <>
          <ExportButton path="/reports/discipline/export" query={exportQuery} name="disiplin-raporu.xlsx" permission={canExport} disabled={empty} />
          <ExportButton path="/reports/discipline/pdf" query={exportQuery} name="disiplin-raporu.pdf" kind="pdf" permission={canExport} disabled={empty} />
        </>
      }
      filters={
        <>
          {from && <DateRange from={from} to={to} presets={['month', 'last_month', 'year', 'last12']} onChange={(x) => setF(x)} />}
          {flt && (f.from || f.to) && (
            <button type="button" className="text-[12.5px] text-primary hover:underline" onClick={() => setF({ from: null, to: null })}>Dönem: {flt.term.name}</button>
          )}
          <div className="grid w-full grid-cols-2 gap-2 sm:ml-auto sm:flex sm:w-auto">
            <Select aria-label="Sınıf" className="sm:w-[130px]" value={f.class_group_id} onChange={(e) => setF({ class_group_id: e.target.value })} placeholder="Tüm sınıflar"
              options={(flt?.class_groups ?? []).map((c) => ({ value: c.id, label: c.is_active ? c.name : `${c.name} (pasif)` }))} />
            <Select aria-label="Kategori" className="sm:w-[150px]" value={f.category} onChange={(e) => setF({ category: e.target.value })} placeholder="Tüm kategoriler"
              options={Object.entries(flt?.categories ?? {}).map(([value, label]) => ({ value, label }))} />
            <Select aria-label="Davranış" className="sm:w-[170px]" value={f.behavior_id} onChange={(e) => setF({ behavior_id: e.target.value })} placeholder="Tüm davranışlar"
              options={(flt?.behaviors ?? []).map((b) => ({ value: b.id, label: b.name }))} />
            <Select aria-label="Öğretmen" className="sm:w-[160px]" value={f.teacher_id} onChange={(e) => setF({ teacher_id: e.target.value })} placeholder="Tüm öğretmenler"
              options={(flt?.teachers ?? []).map((x) => ({ value: x.id, label: x.name }))} />
          </div>
        </>
      }
    >
      {isError && <Alert tone="danger" className="mb-4">{error instanceof ApiError ? error.firstError() : 'Rapor yüklenemedi.'}</Alert>}

      <div className={cn('mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4', isFetching && !isLoading && 'opacity-70')}>
        <Stat label="Disiplin olayı" icon={<Gavel />} loading={isLoading} value={num(t?.incidents)} sub={`${num(t?.students)} öğrenci · ${num(t?.penalty)} ceza puanı`} />
        <Stat label="Yaptırım" icon={<Scale />} loading={isLoading} value={num(t?.sanctions)} sub={`${num(t?.active_sanctions)} yürürlükte · ${num(t?.suspension_days)} uzaklaştırma günü`} />
        <Stat label="Tekrar eden öğrenci" icon={<Repeat />} loading={isLoading} value={num(t?.repeaters)} tone={t?.repeaters ? 'warning' : undefined} sub="2 ve üzeri olay" />
        <Stat label="Olumlu kayıt" icon={<ThumbsUp />} loading={isLoading} value={num(t?.positives)} sub={`${num(t?.merit)} olumlu puan`} />
      </div>

      {empty ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<Gavel />} title="Bu aralıkta disiplin kaydı yok" description="Filtreleri değiştirin ya da olay kaydedin."
            action={can('discipline.create') ? <ButtonLink to="/disiplin/olaylar?yeni=1" variant="primary">Olay kaydet</ButtonLink> : undefined} />
        </div>
      ) : (
        <>
          <Panel title="Aylık eğilim" description={r ? `${fmtDate(r.from)} – ${fmtDate(r.to)}` : undefined} className="mb-4">
            {isLoading ? <Skeleton className="h-[240px]" /> : (
              <>
                <Legend className="mb-2" items={[{ label: 'Disiplin olayı', color: series[0]! }, { label: 'Yaptırım', color: series[3]! }, { label: 'Olumlu kayıt', color: series[1]! }]} />
                <div className="h-[230px]">
                  <ResponsiveContainer>
                    <BarChart data={r?.trend ?? []} margin={{ top: 4, right: 0, left: 0, bottom: 0 }} barCategoryGap="20%">
                      <CartesianGrid {...gridProps} />
                      <XAxis dataKey="label" {...axisProps} dy={6} minTickGap={8} />
                      <YAxis {...axisProps} width={30} allowDecimals={false} />
                      <Tooltip cursor={{ fill: 'var(--surface-2)' }} content={<ChartTooltip formatValue={(v) => num(v)} />} />
                      <Bar dataKey="incidents" name="Disiplin olayı" fill={series[0]} radius={[3, 3, 0, 0]} maxBarSize={16} />
                      <Bar dataKey="sanctions" name="Yaptırım" fill={series[3]} radius={[3, 3, 0, 0]} maxBarSize={16} />
                      <Bar dataKey="positives" name="Olumlu kayıt" fill={series[1]} radius={[3, 3, 0, 0]} maxBarSize={16} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </>
            )}
          </Panel>

          <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Panel title="En sık davranışlar" description="Olaya karışan öğrenci sayısı">
              {isLoading ? <Skeleton className="h-40" /> : (
                <BarList items={(r?.by_behavior ?? []).filter((b) => b.kind === 'negative').slice(0, 10).map((b) => ({ label: b.name, value: b.count, hint: `${b.points} p` }))} />
              )}
            </Panel>
            <Panel title="Yaptırım dağılımı">
              {isLoading ? <Skeleton className="h-40" /> : !r?.by_sanction.length ? <p className="text-[13px] text-ink-3">Yaptırım yok.</p> : (
                <ul className="flex flex-col gap-2">
                  {r.by_sanction.map((s) => {
                    const max = Math.max(1, ...r.by_sanction.map((x) => x.count))
                    return (
                      <li key={s.id} className="flex items-center gap-3 text-[12.5px]">
                        <span className="grid size-5 shrink-0 place-items-center rounded-full bg-surface-2 text-[10.5px] font-semibold ring-1 ring-line">{s.level}</span>
                        <span className="w-36 shrink-0 truncate text-ink-2">{s.name}</span>
                        <span className="relative h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-2"><span className="absolute inset-y-0 left-0 rounded-full bg-[var(--series-4)]" style={{ width: `${(s.count / max) * 100}%` }} /></span>
                        <span className="w-8 text-right tabular text-ink">{s.count}</span>
                        <span className="hidden w-20 text-right text-ink-3 sm:inline">{s.active} yürürlükte</span>
                      </li>
                    )
                  })}
                </ul>
              )}
            </Panel>
            <Panel title="Kategori ve ciddiyet">
              {isLoading ? <Skeleton className="h-32" /> : (
                <>
                  <BarList items={(r?.by_category ?? []).filter((c) => c.kind === 'negative').map((c) => ({ label: c.label, value: c.count }))} />
                  <div className="mt-3 flex flex-wrap gap-1.5">
                    {(r?.by_severity ?? []).map((s) => <Badge key={s.key} tone={s.key === 'low' ? 'neutral' : s.key === 'medium' ? 'warning' : 'danger'}>{s.label}: {s.count}</Badge>)}
                    {(r?.by_status ?? []).filter((s) => s.count > 0).map((s) => <Badge key={s.key} tone="info">{s.label}: {s.count}</Badge>)}
                  </div>
                </>
              )}
            </Panel>
            <Panel title="Bildiren öğretmen / personel">
              {isLoading ? <Skeleton className="h-32" /> : (
                <ul className="flex flex-col divide-y divide-line text-[13px]">
                  <li className="flex items-center gap-3 pb-1.5 text-[12px] text-ink-3"><span className="flex-1">Ad</span><span className="w-14 text-right">Olay</span><span className="w-14 text-right">Olumlu</span></li>
                  {(r?.by_teacher ?? []).map((x) => (
                    <li key={x.user_id ?? 0} className="flex items-center gap-3 py-1.5">
                      <span className="min-w-0 flex-1 truncate text-ink">{x.name}</span>
                      <span className="w-14 text-right tabular">{x.incidents}</span>
                      <span className="w-14 text-right tabular text-success">{x.positives}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          </div>

          <Panel title="Sınıf bazında" description="Öğrencinin şu anki sınıfına göre" className="mb-4">
            {isLoading ? <Skeleton className="h-32" /> : (
              <SimpleTable head={<tr><Th>Sınıf</Th><Th right>Olay</Th><Th right>Öğrenci</Th><Th right>Ceza puanı</Th><Th right>Yaptırım</Th><Th right>Olumlu</Th></tr>}>
                {(r?.by_class ?? []).map((c) => (
                  <tr key={c.id ?? 0}>
                    <td>{c.id ? <button type="button" className="font-medium text-ink hover:text-primary" onClick={() => setF({ class_group_id: String(c.id) })}>{c.name}</button> : <span className="text-ink-2">{c.name}</span>}</td>
                    <td className="text-right tabular">{c.incidents}</td>
                    <td className="text-right tabular">{c.students}</td>
                    <td className={cn('text-right tabular', c.penalty > 0 && 'text-danger')}>{c.penalty}</td>
                    <td className="text-right tabular">{c.sanctions}</td>
                    <td className="text-right tabular text-success">{c.positives}</td>
                  </tr>
                ))}
              </SimpleTable>
            )}
          </Panel>

          <Panel title="Tekrar eden öğrenciler" description="Aralıkta 2 ve üzeri disiplin olayı; net puana göre sıralı">
            {isLoading ? <Skeleton className="h-32" /> : !r?.repeaters.length ? <p className="text-[13px] text-ink-3">Tekrar eden öğrenci yok.</p> : (
              <SimpleTable head={<tr><Th>Öğrenci</Th><Th>Sınıf</Th><Th right>Olay</Th><Th right>Ceza</Th><Th right>Olumlu</Th><Th right>Net</Th><Th>Seviye</Th><Th right>Yaptırım</Th><Th right>Son olay</Th></tr>}>
                {r.repeaters.map((s) => (
                  <tr key={s.student_id}>
                    <td><Link to={`/ogrenciler/${s.student_id}`} className="font-medium text-ink hover:text-primary">{s.full_name}</Link><span className="ml-1.5 text-[12px] tabular text-ink-3">{s.student_no}</span></td>
                    <td className="text-ink-2">{s.class_names}</td>
                    <td className="text-right tabular">{s.incidents}</td>
                    <td className="text-right tabular text-danger">{s.penalty}</td>
                    <td className="text-right tabular text-success">{s.merit}</td>
                    <td className="text-right font-semibold tabular">{s.net}</td>
                    <td><Badge tone={LEVEL_TONE[s.level] ?? 'neutral'} dot>{s.level_label}</Badge></td>
                    <td className="text-right tabular">{s.sanctions}</td>
                    <td className="text-right tabular text-ink-2">{fmtDate(s.last_at)}</td>
                  </tr>
                ))}
              </SimpleTable>
            )}
          </Panel>
          <p className="mt-2 flex items-center gap-1.5 px-1 text-[12px] text-ink-3"><UserX className="size-3.5" />Asılsız kapatılan ve silinen olaylar sayılmaz. İptal edilen ve kurul bekleyen yaptırımlar yaptırım sayısına girmez.</p>
        </>
      )}
    </ReportFrame>
  )
}

export default reportPage('discipline', DisciplineReport)
