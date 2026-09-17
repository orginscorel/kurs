import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ThumbsUp } from 'lucide-react'
import { api } from '@/lib/api'
import { PageHeader, Panel } from '@/components/ui/layout'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { Segmented } from '@/components/ui/form'
import { useSearchParams } from 'react-router-dom'
import type { AttentionRow } from './types'
import { LevelBadge, PointsMeter, useDisciplineOptions } from './ui'

/** Dönem net puanı eşiği aşan öğrenciler. */
export default function AttentionList() {
  const [params, setParams] = useSearchParams()
  const level = params.get('seviye') === 'uyari' ? 'warning' : 'watch'
  const { data: opt } = useDisciplineOptions()
  const { data, isLoading } = useQuery({
    queryKey: ['discipline', 'attention', level],
    queryFn: () => api.get<{ data: AttentionRow[] }>('/discipline/attention', { level }).then((r) => r.data),
  })
  return (
    <div className="animate-fade-in">
      <PageHeader title="Dikkat gerektirenler" description="Bu dönem disiplin puanı eşiği aşan öğrenciler (net puan = ceza − olumlu)."
        actions={<Segmented value={level} onChange={(v) => setParams(v === 'warning' ? { seviye: 'uyari' } : {}, { replace: true })}
          options={[{ value: 'watch', label: 'Dikkat ve üstü' }, { value: 'warning', label: 'Uyarı ve üstü' }]} />} />
      <Panel flush>
        {isLoading ? <div className="p-4"><Skeleton className="h-40" /></div> : !data?.length ? (
          <EmptyState icon={<ThumbsUp />} title="Eşiği aşan öğrenci yok" />
        ) : (
          <div className="overflow-x-auto scroll-thin">
            <table className="tbl w-full min-w-[620px] text-[13px]">
              <thead className="text-left text-[12px] text-ink-3"><tr><th className="text-left">Öğrenci</th><th className="text-center">Seviye</th><th className="w-[30%] text-center">Puan</th><th className="text-center">Ceza</th><th className="text-center">Olumlu</th><th className="text-center">Olay</th></tr></thead>
              <tbody>
                {data.map((a) => (
                  <tr key={a.student_id}>
                    <td className="text-left"><Link to={`/ogrenciler/${a.student_id}`} className="font-medium text-ink hover:text-primary">{a.full_name}</Link><span className="ml-1.5 text-[12px] tabular text-ink-3">{a.student_no}</span></td>
                    <td className="text-center"><LevelBadge level={a.level} label={a.level_label} /></td>
                    <td className="text-center"><div className="flex items-center justify-center gap-2"><PointsMeter net={a.net} settings={opt?.settings} className="flex-1" /><span className="w-8 text-right font-semibold tabular">{a.net}</span></div></td>
                    <td className="tabular text-danger text-center">{a.penalty}</td>
                    <td className="tabular text-success text-center">{a.merit}</td>
                    <td className="tabular text-center">{a.incidents}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </div>
  )
}
