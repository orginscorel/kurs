import { Link } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { AlertTriangle, CalendarX2, ClipboardCheck, Percent, UserX } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date as fmtDate, num, percent } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel, Stat } from '@/components/ui/layout'
import { Alert, EmptyState, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { Select } from '@/components/ui/form'
import { axisProps, ChartTooltip, gridProps, Legend, series } from '@/components/charts/ChartKit'
import { DateRange, ExportButton, presetRange, ReportFrame, reportPage, SimpleTable, Th, useUrlFilters } from './shared'
import type { AttendanceData, ReportOptions } from './types'

const shortDay = (iso: string) => {
  const [, m, d] = iso.split('-')
  return `${d}.${m}`
}

function AttendanceReport() {
  const can = useCan()
  const def = presetRange('last30')
  const [f, setF] = useUrlFilters({ from: def.from, to: def.to, class_group_id: '' })
  const options = useQuery({ queryKey: ['reports', 'options'], queryFn: () => api.get<ReportOptions>('/reports/options'), staleTime: 5 * 60_000 })
  const query = { from: f.from, to: f.to, class_group_id: f.class_group_id }
  const { data, isLoading, isFetching, isError, error } = useQuery({
    queryKey: ['reports', 'attendance', query],
    queryFn: () => api.get<{ data: AttendanceData }>('/reports/attendance', query).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
  const t = data?.totals
  const empty = !isLoading && !t?.records
  const chart = (data?.by_day ?? []).map((d) => ({ label: shortDay(d.date), date: d.date, absent: d.absent, late: d.late, leave: d.excused + d.medical }))

  return (
    <ReportFrame
      reportKey="attendance"
      actions={
        <>
          <ExportButton path="/reports/attendance/export" query={query} name="devamsizlik-raporu.xlsx" disabled={empty} />
          <ExportButton path="/reports/attendance/pdf" query={query} name="devamsizlik-raporu.pdf" kind="pdf" disabled={empty} />
        </>
      }
      filters={
        <>
          <DateRange from={f.from} to={f.to} presets={['last30', 'month', 'last_month', 'year']} onChange={(r) => setF(r)} />
          <Select className="w-full sm:ml-auto sm:w-[170px]" value={f.class_group_id} onChange={(e) => setF({ class_group_id: e.target.value })} placeholder="Tüm sınıflar"
            options={(options.data?.class_groups ?? []).map((c) => ({ value: c.id, label: c.is_active ? c.name : `${c.name} (pasif)` }))} />
        </>
      }
    >
      {isError && <Alert tone="danger" className="mb-4">{error instanceof ApiError ? error.firstError() : 'Rapor yüklenemedi.'}</Alert>}

      <div className={cn('mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4', isFetching && !isLoading && 'opacity-70')}>
        <Stat label="Katılım oranı" icon={<Percent />} loading={isLoading} value={percent(t?.rate, 1)} sub="Gelen ve geç gelen / tüm yoklama kayıtları" />
        <Stat label="Gelmedi" icon={<UserX />} loading={isLoading} value={num(t?.absent)} tone={t?.absent ? 'danger' : undefined} sub={`${num(t?.students)} öğrencinin ${num(t?.records)} yoklama kaydı`} />
        <Stat label="Geç / izinli / raporlu" icon={<ClipboardCheck />} loading={isLoading} value={`${num(t?.late)} / ${num(t?.excused)} / ${num(t?.medical)}`} />
        <Stat label="Yoklaması eksik ders" icon={<AlertTriangle />} loading={isLoading} value={num(t?.missing_sessions)} tone={t?.missing_sessions ? 'warning' : undefined}
          sub={`${num(t?.sessions)} ders · ${num(t?.cancelled_sessions)} iptal`} />
      </div>

      {empty ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<CalendarX2 />} title="Bu aralıkta yoklama kaydı yok"
            description={t?.sessions ? `Bu aralıkta ${num(t.sessions)} ders var ama henüz yoklama alınmamış.` : 'Ders programı oluşturulup yoklama alındıkça bu rapor dolacak.'}
            action={can('attendance.take') ? <ButtonLink to="/yoklama" variant="primary">Yoklama al</ButtonLink> : undefined} />
        </div>
      ) : (
        <>
          <Panel title="Günlük devamsızlık" description="Gelmeyen, geç kalan ve izinli/raporlu öğrenci kaydı" className="mb-4">
            {isLoading ? <Skeleton className="h-[240px]" /> : (
              <>
                <Legend className="mb-2" items={[{ label: 'Gelmedi', color: series[0]! }, { label: 'Geç', color: series[3]! }, { label: 'İzinli / raporlu', color: series[1]! }]} />
                <div className="h-[230px]">
                  <ResponsiveContainer>
                    <BarChart data={chart} margin={{ top: 4, right: 0, left: 0, bottom: 0 }} barCategoryGap="22%">
                      <CartesianGrid {...gridProps} />
                      <XAxis dataKey="label" {...axisProps} dy={6} minTickGap={12} />
                      <YAxis {...axisProps} width={32} allowDecimals={false} />
                      <Tooltip cursor={{ fill: 'var(--surface-2)' }} content={<ChartTooltip formatLabel={(_l) => _l} formatValue={(v) => num(v)} />} />
                      <Bar dataKey="absent" name="Gelmedi" stackId="a" fill={series[0]} maxBarSize={22} />
                      <Bar dataKey="late" name="Geç" stackId="a" fill={series[3]} maxBarSize={22} />
                      <Bar dataKey="leave" name="İzinli / raporlu" stackId="a" fill={series[1]} radius={[3, 3, 0, 0]} maxBarSize={22} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </>
            )}
          </Panel>

          {(data?.by_class.length ?? 0) > 0 && (
            <Panel title="Sınıflara göre devam" className="mb-4">
              <SimpleTable head={<tr><Th>Sınıf</Th><Th right>Öğrenci sayısı</Th><Th right>Yoklama kaydı</Th><Th right>Gelmedi</Th><Th right>Geç</Th><Th right>İzinli</Th><Th right>Raporlu</Th><Th right>Katılım oranı</Th></tr>}>
                {data!.by_class.map((c) => (
                  <tr key={c.id}>
                    <td><button type="button" className="font-medium text-ink hover:text-primary" onClick={() => setF({ class_group_id: String(c.id) })}>{c.name}</button></td>
                    <td className="text-right tabular">{c.students}</td>
                    <td className="text-right tabular text-ink-2">{num(c.records)}</td>
                    <td className={cn('text-right tabular', c.absent > 0 && 'text-danger')}>{num(c.absent)}</td>
                    <td className="text-right tabular">{num(c.late)}</td>
                    <td className="text-right tabular">{num(c.excused)}</td>
                    <td className="text-right tabular">{num(c.medical)}</td>
                    <td className="text-right tabular font-medium">{percent(c.rate, 1)}</td>
                  </tr>
                ))}
              </SimpleTable>
            </Panel>
          )}

          <Panel title="Öğrencilere göre devam" description={`En çok gelmeyen üstte${(data?.students.length ?? 0) >= 300 ? ' · ilk 300 öğrenci (tamamı Excel dosyasında)' : ''}`}>
            {isLoading ? <Skeleton className="h-40" /> : (
              <SimpleTable head={<tr><Th>Öğrenci</Th><Th>Sınıf</Th><Th right>Yoklama kaydı</Th><Th right>Gelmedi</Th><Th right>Geç</Th><Th right>İzinli</Th><Th right>Raporlu</Th><Th right>Katılım oranı</Th></tr>}>
                {data!.students.map((s) => (
                  <tr key={s.student_id}>
                    <td><Link to={`/ogrenciler/${s.student_id}`} className="font-medium text-ink hover:text-primary">{s.full_name}</Link><span className="ml-1.5 text-[12px] tabular text-ink-3">No: {s.student_no}</span></td>
                    <td className="text-ink-2">{s.class_names ?? '—'}</td>
                    <td className="text-right tabular text-ink-2">{s.records}</td>
                    <td className={cn('text-right tabular', s.absent > 0 && 'text-danger')}>{s.absent}</td>
                    <td className="text-right tabular">{s.late}</td>
                    <td className="text-right tabular">{s.excused}</td>
                    <td className="text-right tabular">{s.medical}</td>
                    <td className="text-right tabular font-medium">{percent(s.rate, 1)}</td>
                  </tr>
                ))}
              </SimpleTable>
            )}
          </Panel>
          <p className="mt-2 px-1 text-[12px] text-ink-3">{fmtDate(f.from)} – {fmtDate(f.to)} · İzinli ve raporlu kayıtlar katılım oranında "gelmedi" sayılır.</p>
        </>
      )}
    </ReportFrame>
  )
}

export default reportPage('attendance', AttendanceReport)
