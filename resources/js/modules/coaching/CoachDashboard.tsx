import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, BellRing, CalendarClock, ClipboardList, Users } from 'lucide-react'
import { api } from '@/lib/api'
import { date, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat } from '@/components/ui/layout'
import { Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { Select } from '@/components/ui/form'
import type { CoachingOptions, Dashboard } from './types'

export default function CoachDashboard() {
  const can = useCan()
  const isManager = can('coaching.manage')
  const [coachId, setCoachId] = useState('')

  const options = useQuery({ queryKey: ['coaching', 'options'], queryFn: () => api.get<CoachingOptions>('/coaching/assignments/options'), staleTime: 5 * 60_000, enabled: isManager })
  const { data, isLoading } = useQuery({
    queryKey: ['coaching', 'dashboard', coachId],
    queryFn: () => api.get<Dashboard>('/coaching/dashboard', coachId ? { coach_id: coachId } : {}),
  })

  const stats = data?.stats

  return (
    <div className="animate-fade-in">
      <PageHeader title="Koç Panom" description="Öğrencilerim, yaklaşan görüşmeler ve bu haftanın planları"
        actions={
          <>
            {can('messages.send') && <ButtonLink variant="secondary" icon={<BellRing className="size-4" />} to="/iletisim/bildirim-merkezi/gonder?event=coaching.session">Bildirim gönder</ButtonLink>}
            {isManager && (
              <Select value={coachId} onChange={(e) => setCoachId(e.target.value)} placeholder={can('coaching.manage') ? 'Tüm koçlar' : 'Kendi öğrencilerim'} aria-label="Koç seçin"
                options={(options.data?.coaches ?? []).map((c) => ({ value: c.id, label: c.name }))} className="w-[220px]" />
            )}
          </>
        }
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <Stat label="Öğrencim" value={stats?.students ?? 0} icon={<Users />} loading={isLoading} to="/kocluk/ogrenciler" />
        <Stat label="Geciken görüşme" value={stats?.overdue ?? 0} tone={stats?.overdue ? 'danger' : undefined} icon={<AlertTriangle />} loading={isLoading} />
        <Stat label="Bu hafta planı olan" value={stats?.plans_this_week ?? 0} icon={<ClipboardList />} loading={isLoading} to="/kocluk/planlar" />
        <Stat label="Planı olmayan" value={stats?.without_plan ?? 0} tone={stats?.without_plan ? 'warning' : undefined} icon={<CalendarClock />} loading={isLoading} />
      </div>

      <div className="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="lg:col-span-2">
          <Panel title="Öğrencilerim">
            {isLoading ? <Skeleton className="h-40" /> : (data?.students.length ?? 0) === 0 ? (
              <EmptyState compact icon={<Users />} title="Henüz öğrenciniz yok" description="Koçluk öğrencileri sayfasından öğrencilere koç atanır." />
            ) : (
              <div className="overflow-x-auto scroll-thin">
                <table className="w-full text-[13px]">
                  <thead>
                    <tr className="text-left text-ink-3 border-b border-line">
                      <th className="py-2 font-medium">Öğrenci</th>
                      <th className="py-2 font-medium">Son görüşme</th>
                      <th className="py-2 font-medium">Sonraki</th>
                      <th className="py-2 font-medium">Bu hafta planı</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data!.students.map((s) => (
                      <tr key={s.id} className="border-b border-line/60 hover:bg-surface-2">
                        <td className="py-2">
                          <Link to={`/kocluk/ogrenci/${s.id}`} className="font-medium text-ink hover:underline">{s.full_name}</Link>
                          <span className="ml-2 text-[12px] text-ink-3 tabular">{s.student_no}</span>
                        </td>
                        <td className="py-2 text-ink-2">{s.last_session_at ? relative(s.last_session_at) : <span className="text-ink-3">—</span>}</td>
                        <td className="py-2">{s.next_session_on ? <span className={s.overdue ? 'text-danger font-medium' : 'text-ink-2'}>{date(s.next_session_on)}</span> : <span className="text-ink-3">—</span>}</td>
                        <td className="py-2">
                          {s.has_plan ? (
                            <div className="flex items-center gap-2 min-w-[120px]"><ProgressBar value={s.plan_progress ?? 0} className="flex-1" /><span className="tabular text-[12px] text-ink-3">%{s.plan_progress ?? 0}</span></div>
                          ) : <Badge tone="warning">Yok</Badge>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
        </div>

        <Panel title="Yaklaşan görüşmeler">
          {isLoading ? <Skeleton className="h-40" /> : (data?.upcoming.length ?? 0) === 0 ? (
            <EmptyState compact icon={<CalendarClock />} title="Planlı görüşme yok" />
          ) : (
            <ol className="flex flex-col gap-2.5">
              {data!.upcoming.map((u) => (
                <li key={u.id} className="flex items-center justify-between gap-2">
                  <Link to={`/kocluk/ogrenci/${u.student?.id}`} className="text-[13px] text-ink hover:underline truncate">{u.student?.full_name ?? '—'}</Link>
                  <span className="text-[12.5px] text-ink-3 tabular shrink-0">{date(u.next_session_on)}</span>
                </li>
              ))}
            </ol>
          )}
        </Panel>
      </div>
    </div>
  )
}
