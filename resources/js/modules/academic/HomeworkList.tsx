import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { ClipboardList, Plus, Search, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { EmptyState, ProgressBar } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Segmented, Select } from '@/components/ui/form'
import { useAcademicOptions } from './hooks'
import { ColorChip } from './ui'
import type { HomeworkRow } from './types'
import { HomeworkFormDrawer } from './HomeworkFormDrawer'

type ListResponse = Paginated<HomeworkRow> & { meta: { status_counts: { open: number; closed: number } } }

export default function HomeworkList() {
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: '-due_at', filters: { status: 'open' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const options = useAcademicOptions()
  const formOpen = params.get('yeni') === '1'

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['homework', 'list', list.query],
    queryFn: () => api.get<ListResponse>('/homework', list.query),
    placeholderData: keepPreviousData,
  })
  const counts = data?.meta.status_counts

  const columns = useMemo<Column<HomeworkRow>[]>(
    () => [
      {
        key: 'title', header: 'Ödev', sortKey: 'title',
        cell: (h) => (
          <div className="min-w-0">
            <p className="font-medium text-ink truncate">{h.title}</p>
            <p className="text-[12.5px] text-ink-3 truncate">{h.class_group ? `Sınıf: ${h.class_group}` : 'Seçili öğrencilere'}{h.topic ? ` · Konu: ${h.topic}` : ''}</p>
          </div>
        ),
      },
      { key: 'subject', priority: 3, header: 'Ders', cell: (h) => (h.subject ? <ColorChip color={h.subject.color}>{h.subject.name}</ColorChip> : '—') },
      { key: 'teacher', priority: 4, header: 'Veren öğretmen', hideable: true, cell: (h) => <span className="text-ink-2">{h.teacher ?? '—'}</span> },
      {
        key: 'due', header: 'Son teslim', sortKey: 'due_at',
        cell: (h) => (
          <div className="tabular">
            <p className={h.is_open ? '' : 'text-ink-3'}>{dateTime(h.due_at)}</p>
            <p className={`text-[12px] ${h.is_open ? 'text-ink-3' : 'text-danger'}`}>{h.is_open ? relative(h.due_at) : 'Süresi doldu'}</p>
          </div>
        ),
      },
      {
        key: 'progress', header: 'Teslim eden', sortKey: 'done_count', width: 170,
        cell: (h) => (
          <div>
            <div className="flex justify-between text-[12px] tabular"><span>{h.done_count}/{h.total_count} öğrenci</span><span className="text-ink-3">%{h.completion}</span></div>
            <ProgressBar value={h.completion} tone={h.completion >= 80 ? 'success' : h.completion >= 40 ? 'warning' : 'danger'} className="mt-1" />
          </div>
        ),
      },
      { key: 'missed', priority: 3, header: 'Yapmayan', align: 'center', hideable: true, cell: (h) => (h.missed_count ? <span className="text-danger tabular">{h.missed_count}</span> : <span className="text-ink-3">—</span>) },
      { key: 'graded', priority: 4, header: 'Puan verilen', align: 'center', hideable: true, cell: (h) => <span className="tabular">{h.graded_count}</span> },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Ödevler"
        description={counts ? `${counts.open} açık · ${counts.closed} süresi dolmuş ödev` : 'Ödev verme, teslim takibi ve değerlendirme'}
        actions={can('homework.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Ödev ver</Button>}
      />

      <DataTable
        storageKey="homework"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/odevler/${r.id}`)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ödev başlığı" leading={<Search />} trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined} className="w-full sm:w-[240px]" />
            <Segmented size="sm" value={list.filters.status ?? 'open'} onChange={(status) => list.update({ filters: { status } })} options={[{ value: 'open', label: `Açık ${counts?.open ?? ''}` }, { value: 'closed', label: `Süresi dolan ${counts?.closed ?? ''}` }, { value: 'all', label: 'Tümü' }]} />
            <Select value={list.filters.class_group_id ?? ''} onChange={(e) => list.update({ filters: { class_group_id: e.target.value } })} placeholder="Tüm sınıflar" aria-label="Sınıf" options={(options.data?.class_groups ?? []).map((g) => ({ value: g.id, label: g.name }))} className="w-full sm:w-[170px]" />
            <Select value={list.filters.subject_id ?? ''} onChange={(e) => list.update({ filters: { subject_id: e.target.value } })} placeholder="Tüm dersler" aria-label="Ders" options={(options.data?.subjects ?? []).map((s) => ({ value: s.id, label: s.name }))} className="w-full sm:w-[170px]" />
            <Select value={list.filters.teacher_id ?? ''} onChange={(e) => list.update({ filters: { teacher_id: e.target.value } })} placeholder="Tüm öğretmenler" aria-label="Öğretmen" options={(options.data?.teachers ?? []).map((t) => ({ value: t.id, label: t.name }))} className="w-full sm:w-[190px]" />
          </div>
        }
        empty={<EmptyState icon={<ClipboardList />} title="Ödev yok" description="Filtreye uyan ödev bulunmuyor." action={can('homework.manage') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Ödev ver</Button> : undefined} />}
      />

      <HomeworkFormDrawer
        open={formOpen}
        options={options.data}
        onClose={() => setParams((p) => { p.delete('yeni'); return p })}
        onSaved={(id) => {
          qc.invalidateQueries({ queryKey: ['homework'] })
          navigate(`/odevler/${id}`)
        }}
      />
    </div>
  )
}
