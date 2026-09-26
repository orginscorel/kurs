import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { GraduationCap, Search, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { date } from '@/lib/format'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Input, Select } from '@/components/ui/form'

type Row = { id: number; full_name: string; student_no: string; school_grade: string | null; field: string | null; package: string | null; start: string | null; end: string | null }
type Options = { class_groups: { id: number; name: string }[] }

/** Deneme öğrencileri: denemeye girecek öğrenciler kayıt oldukları "deneme sistemi dahil" paketten gelir. */
export default function ExamStudents() {
  const navigate = useNavigate()
  const list = useListState({ filters: {} })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  useEffect(() => { if (debounced !== list.q) list.update({ q: debounced }) /* eslint-disable-next-line */ }, [debounced])

  const options = useQuery({ queryKey: ['exams', 'options'], queryFn: () => api.get<Options>('/exams/options'), staleTime: 5 * 60_000 })
  const query = { q: list.q, class_group_id: list.filters.class_group_id, page: list.page }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['exams', 'deneme-students', query],
    queryFn: () => api.get<Paginated<Row>>('/exams/deneme-students', query),
    placeholderData: keepPreviousData,
  })

  const columns: Column<Row>[] = [
    { key: 'student', header: 'Öğrenci', cell: (r) => <div className="min-w-0"><p className="truncate font-medium text-ink">{r.full_name}</p><p className="text-[12px] text-ink-3 tabular">Öğrenci no: {r.student_no}</p></div> },
    { key: 'grade', header: 'Sınıf / alan', hideable: true, cell: (r) => <span className="text-ink-2">{[r.school_grade, r.field].filter(Boolean).join(' · ') || '—'}</span> },
    { key: 'package', header: 'Deneme paketi', cell: (r) => r.package ? <Badge tone="success">{r.package}</Badge> : <span className="text-ink-3">—</span> },
    { key: 'period', header: 'Süre', hideable: true, cell: (r) => <span className="text-ink-2 tabular">{r.start ? date(r.start) : '—'} – {r.end ? date(r.end) : 'sürüyor'}</span> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Deneme Öğrencileri"
        breadcrumbs={[{ label: 'Denemeler', to: '/sinavlar' }, { label: 'Deneme öğrencileri' }]}
        description="Denemeye girecek öğrenciler kayıt oldukları pakete göre listelenir (deneme sistemi dahil paket ya da ekstra deneme paketi)."
      />
      <DataTable
        storageKey="exam-students"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/ogrenciler/${r.id}`)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Öğrenci ara" leading={<Search />}
              trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
              className="w-full sm:w-[240px]" />
            <Select value={list.filters.class_group_id ?? ''} onChange={(e) => list.update({ filters: { class_group_id: e.target.value } })} placeholder="Tüm sınıflar" aria-label="Sınıf"
              options={(options.data?.class_groups ?? []).map((c) => ({ value: c.id, label: c.name }))} className="w-full sm:w-[220px]" />
          </div>
        }
        empty={<EmptyState icon={<GraduationCap />} title="Deneme öğrencisi yok" description="Deneme sistemi dahil paketi olan öğrenci bulunamadı. Pakete 'Deneme sistemi dahil' işaretleyin." />}
      />
    </div>
  )
}
