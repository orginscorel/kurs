import { useEffect, useMemo, useState } from 'react'
import { PhoneText } from '@/components/ui/contact'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  DndContext, pointerWithin, useDraggable, useDroppable,
  type DragEndEvent,
} from '@dnd-kit/core'
import { Download, Filter, KanbanSquare, Phone, Plus, Search, Table2, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useListState, useDebounced } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { EmptyState, Badge, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select, Segmented, Textarea } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { cn } from '@/lib/cn'
import type { BoardColumn, LeadOptions, LeadRow } from './types'
import { stageTone } from './types'
import { LeadDrawer } from './LeadDrawer'

type MoveDescriptor = { leadId: number; toStage: string; orderedIds: number[]; lostReason?: string }

export default function LeadBoard() {
  const can = useCan()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const list = useListState({ filters: {} })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [view, setView] = useState<'board' | 'list'>('board')
  const [showFilters, setShowFilters] = useState(false)
  const [params, setParams] = useSearchParams()
  const paramLead = params.get('aday')
  const [openLead, setOpenLead] = useState<number | 'new' | null>(paramLead === 'yeni' ? 'new' : paramLead ? Number(paramLead) : null)
  const [pendingLost, setPendingLost] = useState<MoveDescriptor | null>(null)
  const [lostReason, setLostReason] = useState('')

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const closeLead = () => {
    setOpenLead(null)
    if (params.has('aday')) setParams((p) => { p.delete('aday'); return p })
  }

  const options = useQuery({ queryKey: ['crm', 'leads', 'options'], queryFn: () => api.get<LeadOptions>('/crm/leads/options'), staleTime: 5 * 60_000 })

  const filters = { q: list.q, source: list.filters.source, owner_id: list.filters.owner_id, program_id: list.filters.program_id, due_today: list.filters.due_today }

  const board = useQuery({
    queryKey: ['crm', 'leads', 'board', filters],
    queryFn: () => api.get<{ data: BoardColumn[] }>('/crm/leads/board', filters),
    enabled: view === 'board',
    placeholderData: keepPreviousData,
  })

  const listQuery = useQuery({
    queryKey: ['crm', 'leads', 'list', { ...filters, sort: list.sort, page: list.page }],
    queryFn: () => api.get<{ data: LeadRow[]; meta: any }>('/crm/leads', { ...filters, sort: list.sort, page: list.page }),
    enabled: view === 'list',
    placeholderData: keepPreviousData,
  })

  const moveMutation = useMutation({
    mutationFn: (m: MoveDescriptor) => api.post(`/crm/leads/${m.leadId}/move`, { stage: m.toStage, ordered_ids: m.orderedIds, lost_reason: m.lostReason }),
    onError: (e) => {
      toast.error(e instanceof ApiError ? e.firstError() : 'Aşama güncellenemedi.')
      qc.invalidateQueries({ queryKey: ['crm', 'leads', 'board'] })
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['crm'] }),
  })

  const applyMove = (m: MoveDescriptor) => {
    qc.setQueryData<{ data: BoardColumn[] } | undefined>(['crm', 'leads', 'board', filters], (prev) => {
      if (!prev) return prev
      const cols = prev.data.map((c) => ({ ...c, leads: [...c.leads] }))
      let moved: LeadRow | undefined
      for (const c of cols) {
        const idx = c.leads.findIndex((l) => l.id === m.leadId)
        if (idx !== -1) {
          moved = { ...c.leads[idx]!, stage: m.toStage as LeadRow['stage'] }
          c.leads.splice(idx, 1)
          break
        }
      }
      if (!moved) return prev
      const target = cols.find((c) => c.stage === m.toStage)
      if (target) {
        const orderIndex = m.orderedIds.indexOf(m.leadId)
        const at = orderIndex === -1 ? target.leads.length : Math.min(orderIndex, target.leads.length)
        target.leads.splice(at, 0, moved)
      }
      return { data: cols }
    })
    moveMutation.mutate(m)
  }

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event
    if (!over) return
    const activeData = active.data.current as { leadId: number; stage: string } | undefined
    const overData = over.data.current as { type: 'lead' | 'column'; leadId?: number; stage: string } | undefined
    if (!activeData || !overData) return
    const fromStage = activeData.stage
    const toStage = overData.stage
    if (toStage === 'won') {
      toast.info('"Kayıt Oldu" aşamasına yalnızca "Kayda dönüştür" ile geçilebilir.')
      return
    }

    const cols = board.data?.data ?? []
    const toCol = cols.find((c) => c.stage === toStage)
    const currentIds = (toCol?.leads ?? []).map((l) => l.id).filter((id) => id !== activeData.leadId)
    let insertAt = currentIds.length
    if (overData.type === 'lead' && overData.leadId !== undefined) {
      const idx = currentIds.indexOf(overData.leadId)
      insertAt = idx === -1 ? currentIds.length : idx
    }
    currentIds.splice(insertAt, 0, activeData.leadId)

    if (toStage === 'lost' && fromStage !== 'lost') {
      setPendingLost({ leadId: activeData.leadId, toStage, orderedIds: currentIds })
      setLostReason('')
      return
    }
    applyMove({ leadId: activeData.leadId, toStage, orderedIds: currentIds })
  }

  const confirmLost = () => {
    if (!pendingLost) return
    if (!lostReason.trim()) {
      toast.error('Kaybedilme gerekçesi zorunludur.')
      return
    }
    applyMove({ ...pendingLost, lostReason })
    setPendingLost(null)
  }

  const activeFilterCount = ['source', 'owner_id', 'program_id', 'due_today'].filter((k) => list.filters[k]).length

  const columns = useMemo<Column<LeadRow>[]>(
    () => [
      {
        key: 'name', header: 'Öğrenci adayı', sortKey: 'full_name',
        cell: (l) => (
          <div className="min-w-[180px]">
            <p className="font-medium text-ink">{l.full_name}</p>
            <PhoneText value={l.phone} muted />
          </div>
        ),
      },
      { key: 'stage', header: 'Aşama', cell: (l) => <Badge tone={stageTone[l.stage]} dot>{l.stage_label}</Badge> },
      { key: 'source', header: 'Nereden duydu', hideable: true, cell: (l) => <span className="text-ink-2">{l.source_label}</span> },
      { key: 'program', header: 'İlgilendiği program', hideable: true, cell: (l) => <span className="text-ink-2">{l.program?.name ?? '—'}</span> },
      { key: 'owner', header: 'Takip eden personel', hideable: true, cell: (l) => <span className="text-ink-2">{l.owner?.name ?? '—'}</span> },
      {
        key: 'next_action', header: 'Sıradaki iş', hideable: true,
        cell: (l) => l.next_action ? (
          <div>
            <p className={cn(l.next_action_overdue ? 'text-danger font-medium' : 'text-ink-2')}>{l.next_action}</p>
            {l.next_action_at && <p className={cn('text-[12px]', l.next_action_overdue ? 'text-danger' : 'text-ink-3')}>{relative(l.next_action_at)}</p>}
          </div>
        ) : <span className="text-ink-3">—</span>,
      },
      { key: 'last_contact', header: 'Son görüşme', sortKey: 'last_contacted_at', hideable: true, cell: (l) => <span className="text-ink-2">{l.last_contacted_at ? relative(l.last_contacted_at) : '—'}</span> },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Kayıt ve CRM"
        description="Aday hattı ve dönüşüm takibi"
        actions={
          <>
            {view === 'list' && can('crm.view') && (
              <Button icon={<Download className="size-4" />} onClick={() => api.download('/crm/leads/export', filters, 'adaylar.xlsx').catch((e) => toast.error(e.message))}>
                Excel
              </Button>
            )}
            {can('crm.manage') && (
              <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setOpenLead('new')}>Yeni aday</Button>
            )}
          </>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ad ya da telefon ile ara" leading={<Search />}
          trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
          className="w-full sm:w-[280px]" />
        <Button size="sm" variant={activeFilterCount ? 'soft' : 'ghost'} icon={<Filter className="size-4" />} onClick={() => setShowFilters((v) => !v)}>
          Filtre{activeFilterCount ? ` (${activeFilterCount})` : ''}
        </Button>
        {activeFilterCount > 0 && (
          <Button size="sm" variant="ghost" onClick={() => list.update({ filters: { source: null, owner_id: null, program_id: null, due_today: null } })}>Temizle</Button>
        )}
        <Segmented size="sm" value={view} onChange={setView} className="ml-auto"
          options={[{ value: 'board', label: <span className="inline-flex items-center gap-1.5"><KanbanSquare className="size-3.5" /> Pano</span> },
            { value: 'list', label: <span className="inline-flex items-center gap-1.5"><Table2 className="size-3.5" /> Liste</span> }]} />
      </div>

      {showFilters && (
        <div className="mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 animate-fade-in">
          <Select value={list.filters.source ?? ''} onChange={(e) => list.update({ filters: { source: e.target.value } })} placeholder="Tüm kaynaklar"
            options={Object.entries(options.data?.sources ?? {}).map(([value, label]) => ({ value, label }))} />
          <Select value={list.filters.owner_id ?? ''} onChange={(e) => list.update({ filters: { owner_id: e.target.value } })} placeholder="Tüm sorumlular"
            options={(options.data?.owners ?? []).map((o) => ({ value: o.id, label: o.name }))} />
          <Select value={list.filters.program_id ?? ''} onChange={(e) => list.update({ filters: { program_id: e.target.value } })} placeholder="Tüm programlar"
            options={(options.data?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
          <label className="flex items-center gap-2 px-1">
            <input type="checkbox" checked={list.filters.due_today === '1'} onChange={(e) => list.update({ filters: { due_today: e.target.checked ? '1' : null } })} className="size-4 rounded border-line-strong" />
            <span className="text-[13px] text-ink-2">Bugün aranacaklar</span>
          </label>
        </div>
      )}

      {view === 'board' ? (
        board.isLoading ? (
          <div className="flex gap-3 overflow-x-auto pb-2">
            {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-96 w-72 shrink-0 rounded-[var(--radius-lg)]" />)}
          </div>
        ) : (
          <DndContext collisionDetection={pointerWithin} onDragEnd={handleDragEnd}>
            <div className="flex gap-3 overflow-x-auto pb-3 scroll-thin">
              {(board.data?.data ?? []).map((col) => (
                <BoardColumnView key={col.stage} column={col} onCardClick={(id) => setOpenLead(id)} canManage={can('crm.manage')} />
              ))}
            </div>
          </DndContext>
        )
      ) : (
        <DataTable
          storageKey="crm-leads"
          columns={columns}
          rows={listQuery.data?.data}
          rowKey={(r) => r.id}
          loading={listQuery.isLoading || listQuery.isFetching}
          meta={listQuery.data?.meta}
          sort={list.sort}
          onSort={(sort) => list.update({ sort })}
          onPage={(page) => list.update({ page })}
          onRowClick={(r) => setOpenLead(r.id)}
          empty={<EmptyState icon={<Phone />} title="Aday bulunamadı" description="Filtreleri değiştirerek tekrar deneyin." />}
        />
      )}

      <LeadDrawer
        leadId={openLead}
        options={options.data}
        onClose={closeLead}
        onConverted={(studentId) => { closeLead(); qc.invalidateQueries({ queryKey: ['crm'] }); navigate(`/ogrenciler/${studentId}`) }}
        onChanged={() => qc.invalidateQueries({ queryKey: ['crm'] })}
      />

      <Modal open={!!pendingLost} onClose={() => setPendingLost(null)} size="sm" title="Kaybedilme gerekçesi"
        description="Adayı 'Kaybedildi' olarak işaretlemek için gerekçe girin."
        footer={<>
          <Button variant="ghost" onClick={() => setPendingLost(null)}>Vazgeç</Button>
          <Button variant="danger" onClick={confirmLost}>Kaybedildi olarak işaretle</Button>
        </>}>
        <Textarea rows={3} value={lostReason} onChange={(e) => setLostReason(e.target.value)} placeholder="Örn. Başka kuruma kayıt oldu" autoFocus />
      </Modal>
    </div>
  )
}

function BoardColumnView({ column, onCardClick, canManage }: { column: BoardColumn; onCardClick: (id: number) => void; canManage: boolean }) {
  const droppable = useDroppable({ id: `col-${column.stage}`, data: { type: 'column', stage: column.stage }, disabled: !canManage || column.stage === 'won' })

  return (
    <div className="flex w-72 shrink-0 flex-col rounded-[var(--radius-lg)] bg-surface-2/50 ring-1 ring-line">
      <div className="flex items-center justify-between px-3 py-2.5 border-b border-line">
        <span className="text-[13px] font-semibold text-ink">{column.label}</span>
        <Badge tone={stageTone[column.stage]}>{column.leads.length}</Badge>
      </div>
      <div ref={droppable.setNodeRef} className={cn('flex-1 min-h-[80px] flex flex-col gap-2 p-2 overflow-y-auto scroll-thin max-h-[calc(100vh-340px)]', droppable.isOver && 'bg-primary-soft/40')}>
        {column.leads.length === 0 && <p className="px-2 py-6 text-center text-[12px] text-ink-3">Aday yok</p>}
        {column.leads.map((lead) => (
          <LeadCard key={lead.id} lead={lead} onClick={() => onCardClick(lead.id)} draggable={canManage && lead.stage !== 'won'} />
        ))}
      </div>
    </div>
  )
}

function LeadCard({ lead, onClick, draggable }: { lead: LeadRow; onClick: () => void; draggable: boolean }) {
  const drag = useDraggable({ id: `lead-${lead.id}`, data: { leadId: lead.id, stage: lead.stage }, disabled: !draggable })
  const drop = useDroppable({ id: `lead-${lead.id}`, data: { type: 'lead', leadId: lead.id, stage: lead.stage } })

  const style = drag.transform ? { transform: `translate3d(${drag.transform.x}px, ${drag.transform.y}px, 0)`, zIndex: 30 } : undefined

  return (
    <div
      ref={(node) => { drag.setNodeRef(node); drop.setNodeRef(node) }}
      style={style}
      {...drag.listeners}
      {...drag.attributes}
      onClick={onClick}
      className={cn(
        'cursor-pointer rounded-[var(--radius-md)] bg-surface ring-1 ring-line p-3 shadow-[var(--shadow-soft)] transition-shadow hover:ring-line-strong',
        drag.isDragging && 'opacity-50',
        drop.isOver && 'ring-2 ring-primary/40',
      )}
    >
      <p className="font-medium text-[13.5px] text-ink truncate">{lead.full_name}</p>
      <p className="mt-0.5 text-[12px] text-ink-3">{lead.program?.name ?? lead.source_label}</p>
      <div className="mt-2 flex items-center justify-between gap-2">
        <span className="text-[12px] text-ink-3 truncate">{lead.owner?.name ?? 'Atanmadı'}</span>
        {lead.next_action_at && (
          <span className={cn('text-[12px] font-medium tabular', lead.next_action_overdue ? 'text-danger' : 'text-ink-3')}>{relative(lead.next_action_at)}</span>
        )}
      </div>
    </div>
  )
}
