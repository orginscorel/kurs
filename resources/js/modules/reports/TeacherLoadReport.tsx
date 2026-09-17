import { Link } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { AlertTriangle, Clock, Presentation, Users } from 'lucide-react'
import { api } from '@/lib/api'
import { num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel, Stat } from '@/components/ui/layout'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { BarList, DateRange, ExportButton, presetRange, ReportFrame, reportPage, SimpleTable, Th, useUrlFilters } from './shared'
import type { TeacherLoadRow } from './types'

const h = (v: number | null) => (v === null ? '—' : num(v, v % 1 ? 1 : 0))

function TeacherLoadReport() {
  const can = useCan()
  const def = presetRange('month')
  const [f, setF] = useUrlFilters({ from: def.from, to: def.to })
  const query = { from: f.from, to: f.to }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['reports', 'teacher-load', query],
    queryFn: () => api.get<{ data: { from: string; to: string; rows: TeacherLoadRow[] } }>('/reports/teacher-load', query).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
  const rows = data?.rows ?? []
  const active = rows.filter((r) => r.is_active)
  const weekly = active.reduce((a, r) => a + r.weekly_hours, 0)
  const lessonHours = rows.reduce((a, r) => a + r.lesson_hours, 0)
  const missing = rows.reduce((a, r) => a + r.attendance_missing, 0)
  const over = active.filter((r) => r.max_weekly_hours !== null && r.weekly_hours > r.max_weekly_hours)
  const empty = !isLoading && rows.length === 0

  return (
    <ReportFrame
      reportKey="teachers"
      actions={<ExportButton path="/reports/teacher-load/export" query={query} name="ogretmen-ders-yuku.xlsx" disabled={empty} />}
      filters={<DateRange from={f.from} to={f.to} presets={['month', 'last_month', 'last30', 'year']} onChange={(r) => setF(r)} />}
    >
      <div className={cn('mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4', isFetching && !isLoading && 'opacity-70')}>
        <Stat label="Aktif öğretmen" icon={<Users />} loading={isLoading} value={num(active.length)} sub={over.length ? `${over.length} öğretmen üst sınırı aşıyor` : undefined} tone={over.length ? 'warning' : undefined} />
        <Stat label="Haftalık program" icon={<Clock />} loading={isLoading} value={`${h(Math.round(weekly * 10) / 10)} saat`} sub="güncel ders programına göre" />
        <Stat label="Aralıkta ders" icon={<Presentation />} loading={isLoading} value={`${h(Math.round(lessonHours * 10) / 10)} saat`} sub={`${num(rows.reduce((a, r) => a + r.sessions - r.cancelled, 0))} ders (iptaller hariç)`} />
        <Stat label="Yoklaması eksik ders" icon={<AlertTriangle />} loading={isLoading} value={num(missing)} tone={missing ? 'warning' : undefined} sub="bugüne kadar olanlar" />
      </div>

      {empty ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<Presentation />} title="Henüz öğretmen kaydı yok" description="Öğretmenler eklenip ders programı oluşturuldukça ders yükü burada görünür."
            action={can('teachers.manage') ? <ButtonLink to="/ogretmenler" variant="primary">Öğretmenlere git</ButtonLink> : undefined} />
        </div>
      ) : (
        <>
          {weekly > 0 && (
            <Panel title="Haftalık program saati" description="Aktif öğretmenler" className="mb-4">
              <BarList items={[...active].sort((a, b) => b.weekly_hours - a.weekly_hours).filter((r) => r.weekly_hours > 0).map((r) => ({ label: r.name, value: r.weekly_hours, hint: r.max_weekly_hours !== null ? `/ ${h(r.max_weekly_hours)}` : undefined }))}
                format={(n) => `${h(n)} sa`} />
            </Panel>
          )}
          <Panel title="Öğretmen bazında" description="Haftalık: güncel program · Diğer sütunlar: seçilen tarih aralığı">
            {isLoading ? <Skeleton className="h-40" /> : (
              <SimpleTable head={<tr><Th>Öğretmen</Th><Th right>Haftalık</Th><Th right>Üst sınır</Th><Th right>Sınıf</Th><Th right>Ders</Th><Th right>İptal</Th><Th right>Ders saati</Th><Th right>Eksik yoklama</Th><Th right>Etüt</Th><Th right>İzin</Th></tr>}>
                {rows.map((r) => {
                  const overLimit = r.max_weekly_hours !== null && r.weekly_hours > r.max_weekly_hours
                  return (
                    <tr key={r.id} className={cn(!r.is_active && 'text-ink-3')}>
                      <td>
                        <Link to={`/ogretmenler/${r.id}`} className="font-medium text-ink hover:text-primary">{r.name}</Link>
                        {r.specialty && <span className="ml-1.5 text-[12px] text-ink-3">{r.specialty}</span>}
                        {!r.is_active && <Badge className="ml-1.5">Pasif</Badge>}
                      </td>
                      <td className={cn('text-right tabular font-medium', overLimit && 'text-warning')}>{h(r.weekly_hours)}</td>
                      <td className="text-right tabular text-ink-2">{h(r.max_weekly_hours)}</td>
                      <td className="text-right tabular">{r.classes}</td>
                      <td className="text-right tabular">{r.sessions}</td>
                      <td className="text-right tabular text-ink-2">{r.cancelled}</td>
                      <td className="text-right tabular">{h(r.lesson_hours)}</td>
                      <td className={cn('text-right tabular', r.attendance_missing > 0 && 'text-warning')}>{r.attendance_missing}</td>
                      <td className="text-right tabular">{r.studies ? `${r.studies} · ${h(r.study_hours)} sa` : '—'}</td>
                      <td className="text-right tabular text-ink-2">{r.leave_days ? `${r.leave_days} gün` : '—'}</td>
                    </tr>
                  )
                })}
              </SimpleTable>
            )}
          </Panel>
        </>
      )}
    </ReportFrame>
  )
}

export default reportPage('teachers', TeacherLoadReport)
