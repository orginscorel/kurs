import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { CalendarClock, Plus, Search, Settings2, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { date, money } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Input, Segmented, Select } from '@/components/ui/form'
import { useAcademicOptions } from './hooks'
import { ColorChip } from './ui'
import { studyStatusTone, type StudyDetailData, type StudyRow } from './types'
import { StudyFormDrawer } from './StudyFormDrawer'
import { StudyDetailDrawer } from './StudyDetailDrawer'

type ListResponse = Paginated<StudyRow> & { meta: { status_counts: Record<string, number>; statuses: Record<string, string> } }

export default function StudyPage() {
  const can = useCan()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: 'starts_at', filters: { status: 'open' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [detailId, setDetailId] = useState<number | null>(null)
  const [editing, setEditing] = useState<StudyDetailData | null>(null)
  const options = useAcademicOptions()
  const formOpen = params.get('yeni') === '1'

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['study', 'list', list.query],
    queryFn: () => api.get<ListResponse>('/study-sessions', list.query),
    placeholderData: keepPreviousData,
  })
  const counts = data?.meta.status_counts ?? {}
  const invalidate = () => qc.invalidateQueries({ queryKey: ['study'] })

  const columns = useMemo<Column<StudyRow>[]>(
    () => [
      {
        key: 'when', header: 'Zaman', sortKey: 'starts_at',
        cell: (s) => (
          <div className="min-w-0">
            <p className="font-medium tabular">{date(s.date)} · {s.start_time}</p>
            <p className="text-[12px] text-ink-3 tabular">{s.start_time}–{s.end_time} · {s.duration} dk</p>
          </div>
        ),
      },
      { key: 'kind', header: 'Tür', sortKey: 'kind', cell: (s) => <Badge tone={s.kind === 'private' ? 'accent' : 'info'}>{s.kind_label}</Badge> },
      { key: 'subject', header: 'Ders / konu', cell: (s) => <div className="flex items-center gap-2 min-w-0">{s.subject ? <ColorChip color={s.subject.color}>{s.subject.name}</ColorChip> : <span className="text-ink-3">—</span>}<span className="truncate text-ink-2">{s.topic ?? ''}</span></div> },
      { key: 'teacher', header: 'Öğretmen', cell: (s) => <span className="text-ink-2">{s.teacher?.name ?? '—'}</span> },
      { key: 'room', header: 'Derslik', hideable: true, cell: (s) => <span className="text-ink-2">{s.classroom?.name ?? '—'}</span> },
      { key: 'students', header: 'Öğrenci', sortKey: 'students_count', align: 'center', cell: (s) => <span className="tabular">{s.students_count}/{s.capacity}</span> },
      { key: 'fee', header: 'Ücret', align: 'right', hideable: true, cell: (s) => (s.fee ? <span className="tabular">{money(s.fee)}</span> : <span className="text-ink-3">—</span>) },
      { key: 'status', header: 'Durum', sortKey: 'status', cell: (s) => <Badge tone={studyStatusTone[s.status] ?? 'neutral'} dot>{s.status_label}</Badge> },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Etüt ve birebir ders"
        description={counts.requested ? `${counts.requested} talep onay bekliyor` : 'Talepler, planlama, katılım ve öğretmen uygunluğu'}
        actions={
          <>
            {can('study.manage') && <ButtonLink to="/etut/uygunluk" icon={<Settings2 className="size-4" />}>Öğretmen uygunluğu</ButtonLink>}
            {can('study.view') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Etüt planla</Button>}
          </>
        }
      />

      <DataTable
        storageKey="study"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => setDetailId(r.id)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Konu, öğretmen ya da öğrenci" leading={<Search />} trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined} className="w-full sm:w-[260px]" />
            <Segmented
              size="sm"
              value={list.filters.status ?? 'open'}
              onChange={(status) => list.update({ filters: { status } })}
              options={[
                { value: 'open', label: 'Açık' }, { value: 'requested', label: `Onay bekleyen ${counts.requested ?? ''}` }, { value: 'approved', label: 'Onaylı' },
                { value: 'completed', label: 'Tamamlanan' }, { value: 'all', label: 'Tümü' },
              ]}
              className="max-w-full"
            />
            <Select value={list.filters.kind ?? ''} onChange={(e) => list.update({ filters: { kind: e.target.value } })} placeholder="Tür" options={[{ value: 'study', label: 'Etüt' }, { value: 'private', label: 'Birebir' }]} className="w-[130px]" />
            <Select value={list.filters.teacher_id ?? ''} onChange={(e) => list.update({ filters: { teacher_id: e.target.value } })} placeholder="Tüm öğretmenler" options={(options.data?.teachers ?? []).map((t) => ({ value: t.id, label: t.name }))} className="w-[190px]" />
            <Input type="date" value={list.filters.from ?? ''} onChange={(e) => list.update({ filters: { from: e.target.value } })} className="w-[150px]" />
            <Input type="date" value={list.filters.to ?? ''} onChange={(e) => list.update({ filters: { to: e.target.value } })} className="w-[150px]" />
          </div>
        }
        empty={<EmptyState icon={<CalendarClock />} title="Kayıt yok" description="Filtreye uyan etüt ya da birebir ders bulunmuyor." action={can('study.view') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Etüt planla</Button> : undefined} />}
      />

      <StudyFormDrawer
        open={formOpen || !!editing}
        editing={editing}
        options={options.data}
        onClose={() => { setEditing(null); setParams((p) => { p.delete('yeni'); return p }) }}
        onSaved={invalidate}
      />
      <StudyDetailDrawer id={detailId} onClose={() => setDetailId(null)} onChanged={invalidate} options={options.data} onEdit={(s) => { setDetailId(null); setEditing(s) }} />
    </div>
  )
}
