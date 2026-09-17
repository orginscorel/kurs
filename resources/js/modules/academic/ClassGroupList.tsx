import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { LayoutGrid, Plus, Search, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState, ProgressBar } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Segmented, Select } from '@/components/ui/form'
import { useAcademicOptions } from './hooks'
import { ColorChip } from './ui'
import type { ClassGroupRow } from './types'
import { ClassGroupFormDrawer } from './ClassGroupFormDrawer'

export default function ClassGroupList() {
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: 'name', filters: { status: 'active' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const options = useAcademicOptions()
  const formOpen = params.get('yeni') === '1'

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['class-groups', 'list', list.query],
    queryFn: () => api.get<Paginated<ClassGroupRow>>('/class-groups', list.query),
    placeholderData: keepPreviousData,
  })

  const columns = useMemo<Column<ClassGroupRow>[]>(
    () => [
      { key: 'name', header: 'Sınıf', sortKey: 'name', cell: (g) => <div className="min-w-[160px]"><p className="font-medium">{g.name}</p>{g.term && <p className="text-[12.5px] text-ink-3">Dönem: {g.term}</p>}</div> },
      { key: 'program', header: 'Program', sortKey: 'program', cell: (g) => (g.program ? <ColorChip>{g.program}</ColorChip> : '—') },
      {
        key: 'fill', header: 'Doluluk (öğrenci / kontenjan)', mobileLabel: 'Doluluk', sortKey: 'students_count', width: 170,
        cell: (g) => (
          <div>
            <div className="flex justify-between text-[12px] tabular"><span>{g.students_count}/{g.capacity}</span><span className="text-ink-3">%{g.fill_rate}</span></div>
            <ProgressBar value={g.fill_rate} tone={g.fill_rate >= 100 ? 'danger' : g.fill_rate >= 85 ? 'warning' : 'primary'} className="mt-1" />
          </div>
        ),
      },
      {
        key: 'lessons',
        header: 'Haftalık ders sayısı',
        sortKey: 'lessons_count',
        align: 'right',
        // İkinci satır bugünkü ders sayısı
        cell: (g) => (
          <div>
            <p className="tabular">{g.lessons_count}</p>
            {g.today_lessons !== null && g.today_lessons !== undefined && <p className="text-[12px] text-ink-3 tabular whitespace-nowrap">Bugün: {g.today_lessons}</p>}
          </div>
        ),
      },
      {
        key: 'attendance',
        header: 'Devam oranı (30 gün)',
        align: 'right',
        hideable: true,
        // Son 30 gün: %90 üstü sade, %75-89 uyarı, altı tehlike
        cell: (g) =>
          g.attendance_30 === null || g.attendance_30 === undefined ? (
            <span className="text-ink-3">—</span>
          ) : (
            <p className={`tabular font-medium ${g.attendance_30 < 75 ? 'text-danger' : g.attendance_30 < 90 ? 'text-warning' : 'text-ink'}`}>%{g.attendance_30}</p>
          ),
      },
      { key: 'homeroom', header: 'Ana derslik', hideable: true, cell: (g) => <span className="text-ink-2 whitespace-nowrap">{g.homeroom ?? '—'}</span> },
      { key: 'advisor', header: 'Danışman öğretmen', hideable: true, cell: (g) => <span className="text-ink-2 whitespace-nowrap">{g.advisor ?? '—'}</span> },
      { key: 'active', header: 'Durum', cell: (g) => <Badge tone={g.is_active ? 'success' : 'neutral'} dot>{g.is_active ? 'Aktif' : 'Pasif'}</Badge> },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Sınıflar"
        description={data ? `${data.meta.total} sınıf` : 'Dönem sınıfları, kontenjan ve haftalık ders yükü'}
        actions={can('academic.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Yeni sınıf</Button>}
      />
      <DataTable
        storageKey="class-groups"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/siniflar/${r.id}`)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Sınıf adı" leading={<Search />} trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined} className="w-full sm:w-[220px]" />
            <Segmented size="sm" value={list.filters.status ?? 'active'} onChange={(status) => list.update({ filters: { status } })} options={[{ value: 'active', label: 'Aktif sınıflar' }, { value: 'all', label: 'Tümü' }]} />
            <Select value={list.filters.program_id ?? ''} onChange={(e) => list.update({ filters: { program_id: e.target.value } })} placeholder="Tüm programlar" aria-label="Program" options={(options.data?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} className="w-full sm:w-[190px]" />
            <Select value={list.filters.academic_term_id ?? ''} onChange={(e) => list.update({ filters: { academic_term_id: e.target.value } })} placeholder="Tüm dönemler" aria-label="Dönem" options={(options.data?.terms ?? []).map((t) => ({ value: t.id, label: t.name }))} className="w-full sm:w-[160px]" />
          </div>
        }
        empty={<EmptyState icon={<LayoutGrid />} title="Sınıf yok" description="Bu filtreye uyan sınıf bulunmuyor." action={can('academic.manage') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Yeni sınıf</Button> : undefined} />}
      />
      <ClassGroupFormDrawer
        open={formOpen}
        options={options.data}
        onClose={() => setParams((p) => { p.delete('yeni'); return p })}
        onSaved={(id) => {
          qc.invalidateQueries({ queryKey: ['class-groups'] })
          qc.invalidateQueries({ queryKey: ['academic', 'options'] })
          navigate(`/siniflar/${id}`)
        }}
      />
    </div>
  )
}
