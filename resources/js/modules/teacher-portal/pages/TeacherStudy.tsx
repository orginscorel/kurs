import { Timer } from 'lucide-react'
import { date, time } from '@/lib/format'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Panel } from '@/components/ui/layout'
import { MiniStat, PortalTitle } from '@/modules/portal/ui'
import { useTeacherQuery } from '../api'

type Row = { id: number; kind: string; starts_at: string; ends_at: string; topic: string | null; status: string; capacity: number; subject: string | null; classroom: string | null; student_count: number; students: { id: number; full_name: string; attendance: string | null }[] }
type Data = {
  upcoming: Row[]; past: Row[]
  availability: { weekday: number; starts_at: string; ends_at: string }[]
  leaves: { id: number; kind: string; starts_on: string; ends_on: string; status: string; reason: string | null }[]
  max_weekly_hours: number | null; target_weekly_hours: number | null
}

/** 1=Pazartesi … 7=Pazar (1 Ocak 2024 pazartesidir) */
const dayName = (w: number) => new Date(2024, 0, w).toLocaleDateString('tr-TR', { weekday: 'long' })
const STATUS: Record<string, string> = { requested: 'Onay bekliyor', approved: 'Onaylandı', completed: 'Tamamlandı', planned: 'Planlandı' }
const LEAVE: Record<string, string> = { pending: 'Onay bekliyor', approved: 'Onaylandı', rejected: 'Reddedildi' }

function StudyList({ rows }: { rows: Row[] }) {
  return (
    <ul>
      {rows.map((r) => (
        <li key={r.id} className="border-t border-line px-4 py-2.5">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="min-w-0 text-[14.5px] font-medium">{r.kind === 'private' ? 'Birebir ders' : 'Etüt'}{r.subject ? ` · ${r.subject}` : ''}{r.topic ? <span className="font-normal text-ink-3"> · {r.topic}</span> : null}</span>
            <Badge tone={r.status === 'requested' ? 'warning' : r.status === 'completed' ? 'success' : 'info'}>{STATUS[r.status] ?? r.status}</Badge>
          </div>
          <p className="text-[12.5px] text-ink-3 tabular">{date(r.starts_at, 'day')} · {time(r.starts_at)}–{time(r.ends_at)}{r.classroom ? ` · ${r.classroom}` : ''} · {r.student_count}/{r.capacity} öğrenci</p>
          {r.students.length > 0 && <p className="mt-0.5 break-words text-[12.5px] text-ink-2">{r.students.map((s) => s.full_name).join(', ')}</p>}
        </li>
      ))}
    </ul>
  )
}

export default function TeacherStudy() {
  const { data, isLoading } = useTeacherQuery<Data>(['study'], '/study')
  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Etüt ve müsaitlik" description="Etüt / birebir derslerin, müsait saatlerin ve izinlerin" />
      {isLoading || !data ? <Skeleton className="h-64" /> : (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <MiniStat label="Yaklaşan etüt" value={data.upcoming.length} />
            <MiniStat label="Son 30 gün" value={data.past.length} sub="tamamlanan / geçmiş" />
            <MiniStat label="Haftalık hedef" value={data.target_weekly_hours ?? '—'} sub="ders saati" />
            <MiniStat label="Haftalık üst sınır" value={data.max_weekly_hours ?? '—'} sub="ders saati" />
          </div>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
              <Panel title="Yaklaşan etütler" flush>
                {data.upcoming.length === 0 ? <EmptyState compact icon={<Timer />} title="Planlı etüt yok" /> : <StudyList rows={data.upcoming} />}
              </Panel>
              {data.past.length > 0 && <Panel title="Geçmiş etütler" flush><StudyList rows={data.past.slice(0, 15)} /></Panel>}
            </div>
            <div className="flex min-w-0 flex-col gap-4">
              <Panel title="Müsait olduğum saatler" description="Değişiklik için yönetime başvurun">
                {data.availability.length === 0 ? <p className="text-[14px] text-ink-3">Tanımlı müsaitlik yok.</p> : (
                  <ul className="flex flex-col gap-1 text-[14px]">
                    {data.availability.map((a, i) => (
                      <li key={i} className="flex justify-between gap-2"><span>{dayName(a.weekday)}</span><span className="tabular text-ink-2">{a.starts_at.slice(0, 5)}–{a.ends_at.slice(0, 5)}</span></li>
                    ))}
                  </ul>
                )}
              </Panel>
              <Panel title="İzinlerim">
                {data.leaves.length === 0 ? <p className="text-[14px] text-ink-3">Yakın tarihli izin yok.</p> : (
                  <ul className="flex flex-col gap-1.5 text-[14px]">
                    {data.leaves.map((l) => (
                      <li key={l.id} className="flex flex-wrap items-center justify-between gap-2">
                        <span className="tabular">{date(l.starts_on)} – {date(l.ends_on)}</span>
                        <Badge tone={l.status === 'approved' ? 'success' : l.status === 'rejected' ? 'danger' : 'warning'}>{LEAVE[l.status] ?? l.status}</Badge>
                      </li>
                    ))}
                  </ul>
                )}
              </Panel>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
