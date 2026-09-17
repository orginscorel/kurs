import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { CalendarClock, Plus, Search, Users, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { date, dateTime, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useListState, useDebounced } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { EmptyState, Badge } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select, Switch } from '@/components/ui/form'
import type { MeetingOptions, MeetingRow } from './types'
import { MeetingFormDrawer } from './MeetingFormDrawer'

export default function MeetingList() {
  const can = useCan()
  const qc = useQueryClient()
  const list = useListState({ sort: '-met_at', filters: {} })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [params, setParams] = useSearchParams()
  const [drawer, setDrawer] = useState<{ id: number | null; studentId?: number; studentName?: string } | null>(params.get('yeni') === '1' ? { id: null } : null)

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const closeDrawer = () => {
    setDrawer(null)
    if (params.has('yeni')) setParams((p) => { p.delete('yeni'); return p })
  }

  const options = useQuery({ queryKey: ['guidance', 'meetings', 'options'], queryFn: () => api.get<MeetingOptions>('/guidance/meetings/options'), staleTime: 5 * 60_000 })

  const isStale = list.filters.stale === '1'
  const query = { q: list.q, counselor_id: list.filters.counselor_id, kind: list.filters.kind, upcoming: list.filters.upcoming, stale: list.filters.stale, sort: list.sort, page: list.page }

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['guidance', 'meetings', 'list', query],
    queryFn: () => api.get<Paginated<MeetingRow>>('/guidance/meetings', query),
    placeholderData: keepPreviousData,
  })

  const columns = useMemo<Column<MeetingRow>[]>(() => {
    if (isStale) {
      return [
        { key: 'student', header: 'Öğrenci', cell: (m) => <div><p className="font-medium text-ink">{m.student?.full_name}</p><p className="text-[12px] text-ink-3 tabular">Öğrenci no: {m.student?.student_no}</p></div> },
        { key: 'grade', header: 'Okul sınıfı', cell: (m) => <span className="text-ink-2">{m.student?.school_grade ?? '—'}</span> },
        { key: 'last', header: 'Son görüşme tarihi', cell: (m) => m.last_meeting_at ? <span className="text-ink-2">{date(m.last_meeting_at)} · {relative(m.last_meeting_at)}</span> : <span className="text-danger font-medium">Hiç görüşülmedi</span> },
        {
          key: 'action', header: '', align: 'right', cell: (m) => can('guidance.manage') && (
            <Button size="xs" onClick={(e) => { e.stopPropagation(); setDrawer({ id: null, studentId: m.student!.id, studentName: m.student!.full_name }) }}>Görüşme kaydet</Button>
          ),
        },
      ]
    }
    return [
      { key: 'student', header: 'Öğrenci', cell: (m) => <div><p className="font-medium text-ink">{m.student?.full_name}</p><p className="text-[12px] text-ink-3 tabular">Öğrenci no: {m.student?.student_no}</p></div> },
      { key: 'counselor', header: 'Rehber öğretmen', hideable: true, cell: (m) => <span className="text-ink-2">{m.counselor?.name ?? '—'}</span> },
      { key: 'kind', header: 'Görüşme türü', cell: (m) => <Badge tone="neutral">{m.kind_label}</Badge> },
      { key: 'met_at', header: 'Görüşme tarihi', sortKey: 'met_at', cell: (m) => <span className="text-ink-2 tabular">{dateTime(m.met_at)}</span> },
      { key: 'next', header: 'Sonraki görüşme tarihi', sortKey: 'next_meeting_on', hideable: true, cell: (m) => m.next_meeting_on ? <span className="text-ink-2">{date(m.next_meeting_on)}</span> : <span className="text-ink-3">—</span> },
    ]
  }, [isStale, can])

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Rehberlik Görüşmeleri"
        description="Öğrenci görüşme kayıtları ve takip"
        actions={can('guidance.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setDrawer({ id: null })}>Yeni görüşme</Button>}
      />

      <DataTable
        storageKey="guidance-meetings"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id ?? `stale-${r.student?.id}`}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => r.id && setDrawer({ id: r.id })}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Öğrenci ara" leading={<Search />}
              trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
              className="w-full sm:w-[240px]" />
            <Select value={list.filters.counselor_id ?? ''} onChange={(e) => list.update({ filters: { counselor_id: e.target.value } })} placeholder="Tüm rehber öğretmenler" aria-label="Rehber öğretmen"
              options={(options.data?.counselors ?? []).map((c) => ({ value: c.id, label: c.name }))} className="w-full sm:w-[220px]" />
            <Select value={list.filters.kind ?? ''} onChange={(e) => list.update({ filters: { kind: e.target.value } })} placeholder="Tüm görüşme türleri" aria-label="Görüşme türü"
              options={Object.entries(options.data?.kinds ?? {}).map(([value, label]) => ({ value, label }))} className="w-full sm:w-[210px]" />
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 sm:ml-auto">
              <Switch checked={list.filters.upcoming === '1'} onChange={(v) => list.update({ filters: { upcoming: v ? '1' : null, stale: null } })} label={<span className="inline-flex items-center gap-1.5"><CalendarClock className="size-3.5" /> Yaklaşan görüşmeler</span>} />
              <Switch checked={isStale} onChange={(v) => list.update({ filters: { stale: v ? '1' : null, upcoming: null } })} label={<span className="inline-flex items-center gap-1.5"><Users className="size-3.5" /> 30 günden uzun süredir görüşülmeyenler</span>} />
            </div>
          </div>
        }
        empty={<EmptyState icon={<Users />} title="Görüşme kaydı yok" description="Yeni bir rehberlik görüşmesi kaydederek başlayın." action={can('guidance.manage') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setDrawer({ id: null })}>Yeni görüşme</Button> : undefined} />}
      />

      <MeetingFormDrawer
        open={!!drawer}
        meetingId={drawer?.id}
        studentId={drawer?.studentId}
        studentName={drawer?.studentName}
        options={options.data}
        onClose={closeDrawer}
        onSaved={() => { closeDrawer(); qc.invalidateQueries({ queryKey: ['guidance'] }) }}
      />
    </div>
  )
}
