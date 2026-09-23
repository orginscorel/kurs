import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { ClipboardList, Pencil, Plus } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { date } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState, ProgressBar } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import type { CoachingOptions, PlanRow } from './types'
import { PlanFormDrawer } from './PlanFormDrawer'

export default function CoachingPlans() {
  const can = useCan()
  const navigate = useNavigate()
  const list = useListState({ sort: '-week_start', filters: {} })
  const [edit, setEdit] = useState<PlanRow | null>(null)

  const options = useQuery({ queryKey: ['coaching', 'options'], queryFn: () => api.get<CoachingOptions>('/coaching/assignments/options'), staleTime: 5 * 60_000 })

  const query = { coach_id: list.filters.coach_id, week_start: list.filters.week_start, sort: list.sort, page: list.page }
  const { data, isLoading, isFetching, refetch } = useQuery({
    queryKey: ['coaching', 'plans', 'list', query],
    queryFn: () => api.get<Paginated<PlanRow>>('/coaching/plans', query),
    placeholderData: keepPreviousData,
  })

  const columns: Column<PlanRow>[] = [
    { key: 'student', header: 'Öğrenci', cell: (p) => <div className="min-w-0"><Link to={`/kocluk/ogrenci/${p.student?.id}`} className="block truncate font-medium text-ink hover:underline" title={p.student?.full_name} onClick={(e) => e.stopPropagation()}>{p.student?.full_name}</Link><p className="text-[12px] text-ink-3 tabular">No: {p.student?.student_no}</p></div> },
    { key: 'week', header: 'Hafta', sortKey: 'week_start', cell: (p) => <Badge tone="neutral">{date(p.week_start)}</Badge> },
    { key: 'coach', header: 'Koç', hideable: true, cell: (p) => <span className="text-ink-2">{p.coach?.name ?? '—'}</span> },
    { key: 'items', header: 'Kalem', hideable: true, cell: (p) => <span className="text-ink-2 tabular">{p.items_done}/{p.items_total}</span> },
    { key: 'progress', header: 'İlerleme', width: 160, cell: (p) => <div className="flex items-center gap-2"><ProgressBar value={p.progress} tone={p.progress >= 100 ? 'success' : 'primary'} className="flex-1" /><span className="tabular text-[12px] text-ink-3 w-9 text-right">%{p.progress}</span></div> },
    { key: 'action', header: '', align: 'right', cell: (p) => can('coaching.manage') && <Button size="xs" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={(e) => { e.stopPropagation(); setEdit(p) }}>Düzenle</Button> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Çalışma Planları" description="Haftalık koçluk çalışma planları ve ilerleme"
        actions={can('coaching.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => navigate('/kocluk/ogrenciler')}>Yeni plan (öğrenci seç)</Button>}
      />

      <DataTable
        storageKey="coaching-plans"
        columns={columns}
        rows={data?.data}
        rowKey={(p) => p.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(p) => navigate(`/kocluk/ogrenci/${p.student?.id}`)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input type="date" value={list.filters.week_start ?? ''} onChange={(e) => list.update({ filters: { week_start: e.target.value } })} aria-label="Hafta" className="w-full sm:w-[180px]" />
            <Select value={list.filters.coach_id ?? ''} onChange={(e) => list.update({ filters: { coach_id: e.target.value } })} placeholder="Tüm koçlar" aria-label="Koç"
              options={(options.data?.coaches ?? []).map((c) => ({ value: c.id, label: c.name }))} className="w-full sm:w-[220px]" />
          </div>
        }
        empty={<EmptyState icon={<ClipboardList />} title="Plan bulunamadı" description="Bir öğrencinin koçluk sayfasından haftalık plan oluşturun." />}
      />

      {edit && (
        <PlanFormDrawer open onClose={() => setEdit(null)} studentId={edit.student!.id} studentName={edit.student?.full_name} plan={edit}
          onSaved={() => { setEdit(null); refetch() }} />
      )}
    </div>
  )
}
