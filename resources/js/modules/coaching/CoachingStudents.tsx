import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { Plus, Search, UserPlus, Users, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { date, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select, Switch } from '@/components/ui/form'
import type { AssignmentRow, CoachingOptions } from './types'
import { AssignCoachModal } from './AssignCoachModal'
import { SessionFormDrawer } from './SessionFormDrawer'

export default function CoachingStudents() {
  const can = useCan()
  const navigate = useNavigate()
  const list = useListState({ filters: {} })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [params, setParams] = useSearchParams()
  const [assign, setAssign] = useState<{ id: number; name: string; coachId: number | null } | null>(null)
  const [session, setSession] = useState<{ studentId?: number; studentName?: string } | null>(params.get('yeni') === 'gorusme' ? {} : null)

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const options = useQuery({ queryKey: ['coaching', 'options'], queryFn: () => api.get<CoachingOptions>('/coaching/assignments/options'), staleTime: 5 * 60_000 })

  const query = { q: list.q, coach_id: list.filters.coach_id, unassigned: list.filters.unassigned, page: list.page }
  const { data, isLoading, isFetching, refetch } = useQuery({
    queryKey: ['coaching', 'assignments', 'list', query],
    queryFn: () => api.get<Paginated<AssignmentRow>>('/coaching/assignments', query),
    placeholderData: keepPreviousData,
  })

  const closeSession = () => {
    setSession(null)
    if (params.has('yeni')) setParams((p) => { p.delete('yeni'); return p })
  }

  const columns = useMemo<Column<AssignmentRow>[]>(() => [
    { key: 'student', header: 'Öğrenci', cell: (r) => <div><p className="font-medium text-ink">{r.full_name}</p><p className="text-[12px] text-ink-3 tabular">Öğrenci no: {r.student_no}</p></div> },
    { key: 'grade', header: 'Sınıf / alan', hideable: true, cell: (r) => <span className="text-ink-2">{[r.school_grade, r.field].filter(Boolean).join(' · ') || '—'}</span> },
    { key: 'coach', header: 'Koç', cell: (r) => r.coach ? <Badge tone="primary">{r.coach.name}</Badge> : <span className="text-ink-3">Atanmadı</span> },
    { key: 'last', header: 'Son görüşme', hideable: true, cell: (r) => r.last_session_at ? <span className="text-ink-2">{date(r.last_session_at)} · {relative(r.last_session_at)}</span> : <span className="text-ink-3">—</span> },
    {
      key: 'action', header: '', align: 'right', cell: (r) => can('coaching.manage') && (
        <div className="flex justify-end gap-1.5" onClick={(e) => e.stopPropagation()}>
          <Button size="xs" variant="ghost" onClick={() => setSession({ studentId: r.id, studentName: r.full_name })}>Görüşme</Button>
          <Button size="xs" onClick={() => setAssign({ id: r.id, name: r.full_name, coachId: r.coach?.id ?? null })}>{r.coach ? 'Koç değiştir' : 'Koç ata'}</Button>
        </div>
      ),
    },
  ], [can])

  return (
    <div className="animate-fade-in">
      <PageHeader title="Koçluk Öğrencileri" description="Öğrencilere koç atayın ve koçluk görüşmesi kaydedin"
        actions={can('coaching.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setSession({})}>Yeni görüşme</Button>}
      />

      <DataTable
        storageKey="coaching-students"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/kocluk/ogrenci/${r.id}`)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Öğrenci ara" leading={<Search />}
              trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
              className="w-full sm:w-[240px]" />
            <Select value={list.filters.coach_id ?? ''} onChange={(e) => list.update({ filters: { coach_id: e.target.value } })} placeholder="Tüm koçlar" aria-label="Koç"
              options={(options.data?.coaches ?? []).map((c) => ({ value: c.id, label: c.name }))} className="w-full sm:w-[220px]" />
            <div className="sm:ml-auto">
              <Switch checked={list.filters.unassigned === '1'} onChange={(v) => list.update({ filters: { unassigned: v ? '1' : null } })}
                label={<span className="inline-flex items-center gap-1.5"><UserPlus className="size-3.5" /> Koçu olmayanlar</span>} />
            </div>
          </div>
        }
        empty={<EmptyState icon={<Users />} title="Öğrenci bulunamadı" description="Filtreleri değiştirin ya da öğrenci kaydı ekleyin." />}
      />

      {assign && (
        <AssignCoachModal open onClose={() => setAssign(null)} studentId={assign.id} studentName={assign.name} currentCoachId={assign.coachId}
          onSaved={() => { setAssign(null); refetch() }} />
      )}
      <SessionFormDrawer open={!!session} onClose={closeSession} studentId={session?.studentId} studentName={session?.studentName} options={options.data}
        onSaved={() => { closeSession(); refetch() }} />
    </div>
  )
}
