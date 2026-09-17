import { useMemo, useState } from 'react'
import { NotebookPen, Plus } from 'lucide-react'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Segmented } from '@/components/ui/form'
import { MiniStat, PortalTitle } from '@/modules/portal/ui'
import { useTeacherCan, useTeacherQuery, type ObservationOptions, type ObservationRow } from '../api'
import { ObservationForm } from '../ObservationForm'
import { ObservationList } from '../ObservationList'

type Data = { data: ObservationRow[]; options: ObservationOptions }

export default function TeacherObservations() {
  const can = useTeacherCan()
  const { data, isLoading } = useTeacherQuery<Data>(['observations'], '/observations')
  const [adding, setAdding] = useState(false)
  const [kind, setKind] = useState<'all' | 'positive' | 'improve' | 'note'>('all')

  const rows = useMemo(() => (data?.data ?? []).filter((r) => kind === 'all' || r.kind === kind), [data, kind])
  const stats = useMemo(() => {
    const all = data?.data ?? []
    const week = all.filter((r) => Date.now() - new Date(r.created_at).getTime() < 7 * 864e5)
    return {
      week: week.length,
      positive: all.filter((r) => r.kind === 'positive').length,
      improve: all.filter((r) => r.kind === 'improve').length,
      students: new Set(all.map((r) => r.student?.id)).size,
    }
  }, [data])

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle
        title="Gözlemler"
        description="Öğrencileriniz için yazdığınız ders içi gözlemler ve davranış puanları"
        actions={can('observations') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>Gözlem ekle</Button>}
      />
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <MiniStat label="Bu hafta" value={stats.week} sub="yeni not" />
        <MiniStat label="Olumlu" value={stats.positive} tone="success" />
        <MiniStat label="Gelişmeli" value={stats.improve} tone={stats.improve ? 'warning' : undefined} />
        <MiniStat label="Öğrenci" value={stats.students} sub="not yazılan" />
      </div>
      <Segmented className="self-start" value={kind} onChange={setKind} options={[
        { value: 'all', label: 'Tümü' }, { value: 'positive', label: 'Olumlu' }, { value: 'improve', label: 'Gelişmeli' }, { value: 'note', label: 'Not' },
      ]} />
      {isLoading ? <Skeleton className="h-64" /> : rows.length === 0 ? (
        <EmptyState icon={<NotebookPen />} title="Henüz gözlem notu yok"
          description="Öğrencinin derse katılımı, ödev sorumluluğu ya da davranışı hakkında kısa notlar yazın; isterseniz veli ve öğrenci de görsün."
          action={can('observations') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>İlk notu yazın</Button> : undefined} />
      ) : (
        <div className="overflow-hidden rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <ObservationList rows={rows} showStudent />
        </div>
      )}
      {adding && <ObservationForm options={data?.options} onClose={() => setAdding(false)} />}
    </div>
  )
}
