import { GraduationCap } from 'lucide-react'
import { date } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Panel } from '@/components/ui/layout'
import { PortalTitle } from '@/modules/portal/ui'
import { useTeacherQuery, type ExamLite } from '../api'

type Recent = {
  id: number; name: string; exam_date: string; type: string; participant_count: number; institution_avg: number | null
  my_classes: { group_id: number; name: string; participants: number; avg_net: number; max_net: number }[]
}
type Data = { upcoming: ExamLite[]; recent: Recent[] }

export default function TeacherExams() {
  const { data, isLoading } = useTeacherQuery<Data>(['exams'], '/exams')
  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Sınavlar" description="Yaklaşan deneme sınavları ve sınıflarınızın son sonuçları" />
      {isLoading || !data ? <Skeleton className="h-64" /> : (
        <>
          <Panel title="Yaklaşan sınavlar" description="Önümüzdeki 60 gün" flush>
            {data.upcoming.length === 0 ? <p className="px-4 pb-4 text-[14px] text-ink-3">Planlı sınav yok.</p> : (
              <ul>
                {data.upcoming.map((e) => (
                  <li key={e.id} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-line px-4 py-2.5">
                    <span className="min-w-0 text-[14.5px] font-medium">{e.name}{e.publisher ? <span className="font-normal text-ink-3"> · {e.publisher}</span> : null}</span>
                    <span className="flex items-center gap-2 text-[12.5px] text-ink-2 tabular"><Badge tone="info">{e.type}</Badge>{date(e.exam_date, 'day')}</span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          {data.recent.length === 0 ? (
            <EmptyState icon={<GraduationCap />} title="Yayımlanmış sonuç yok" />
          ) : (
            <div className="grid gap-3 md:grid-cols-2">
              {data.recent.map((e) => (
                <Panel key={e.id} title={e.name} description={`${date(e.exam_date)} · ${e.type} · ${e.participant_count} katılımcı`}>
                  {e.my_classes.length === 0 ? <p className="text-[14px] text-ink-3">Sınıflarınızdan katılan yok.</p> : (
                    <table className="w-full text-[14px]">
                      <thead>
                        <tr className="text-left text-[12.5px] text-ink-3">
                          <th className="pb-1 font-medium text-left">Sınıf</th>
                          <th className="pb-1 font-medium text-center">Katılan öğrenci</th>
                          <th className="pb-1 font-medium text-center">Ortalama net</th>
                          <th className="pb-1 font-medium text-center">En yüksek net</th>
                        </tr>
                      </thead>
                      <tbody>
                        {e.my_classes.map((c) => (
                          <tr key={c.group_id} className="border-t border-line">
                            <td className="py-1.5 font-medium text-left">{c.name}</td>
                            <td className="py-1.5 tabular text-center">{c.participants}</td>
                            <td className={cn('py-1.5 font-semibold tabular text-center', e.institution_avg !== null && (c.avg_net >= e.institution_avg ? 'text-success' : 'text-danger'))}>{c.avg_net.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                            <td className="py-1.5 tabular text-center">{c.max_net.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                  {e.institution_avg !== null && <p className="mt-2 text-[12.5px] text-ink-3">Kurum ortalaması <b className="tabular text-ink-2">{e.institution_avg.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</b> net</p>}
                </Panel>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  )
}
