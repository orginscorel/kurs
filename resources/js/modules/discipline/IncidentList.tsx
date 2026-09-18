import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Gavel, Plus, Search, ThumbsUp } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import type { IncidentRow } from './types'
import { INCIDENT_TONE, KindBadge, SeverityBadge, StatusBadge, useDisciplineOptions } from './ui'
import { IncidentFormDrawer } from './IncidentFormDrawer'

const STATUS_TABS: { value: string; label: string }[] = [
  { value: '', label: 'Tümü' },
  { value: 'open,review', label: 'Açık' },
  { value: 'decided', label: 'Karara bağlandı' },
  { value: 'appealed', label: 'İtirazda' },
  { value: 'closed', label: 'Kapandı' },
]

export default function IncidentList() {
  const can = useCan()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: '-occurred_at' })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const { data: opt } = useDisciplineOptions()
  const [form, setForm] = useState<null | 'negative' | 'positive'>(params.get('yeni') === '1' ? 'negative' : params.get('yeni') === 'olumlu' ? 'positive' : null)

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const close = () => {
    setForm(null)
    if (params.has('yeni')) setParams((p) => { p.delete('yeni'); return p }, { replace: true })
  }

  const fl = list.filters
  const query = { q: list.q, status: fl.status, kind: fl.kind, severity: fl.severity, class_group_id: fl.class_group_id, behavior_id: fl.behavior_id, from: fl.from, to: fl.to, sort: list.sort, page: list.page }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['discipline', 'incidents', query],
    queryFn: () => api.get<Paginated<IncidentRow>>('/discipline/incidents', query),
    placeholderData: keepPreviousData,
  })
  const counts = (data?.meta.status_counts ?? {}) as Record<string, number>

  const columns = useMemo<Column<IncidentRow>[]>(() => [
    {
      key: 'occurred_at', header: 'Olay tarihi / no', sortKey: 'occurred_at',
      cell: (r) => (
        <div>
          <p className="tabular text-ink">{dateTime(r.occurred_at)}</p>
          <p className="text-[12px] tabular text-ink-3">Olay no: {r.incident_no}</p>
        </div>
      ),
    },
    {
      key: 'students', header: 'Öğrenci ve davranış', maxWidth: 420,
      cell: (r) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ink">
            {r.students.map((s) => s.full_name).join(', ') || '—'}
            {r.others_count > 0 && <span className="ml-1.5 text-[12px] font-normal text-ink-3">+{r.others_count} mağdur / tanık</span>}
          </p>
          <p className="truncate text-[12.5px] text-ink-2">{(() => { const b = [...new Set(r.students.map((s) => s.behavior).filter(Boolean))].join(', '); return b ? `Davranış: ${b}` : r.title ? `Başlık: ${r.title}` : '—' })()}</p>
        </div>
      ),
    },
    { key: 'severity', header: 'Ciddiyet', sortKey: 'severity', cell: (r) => (r.kind === 'positive' ? <KindBadge kind="positive" /> : <SeverityBadge severity={r.severity} label={r.severity_label} />) },
    { key: 'status', header: 'Durum', sortKey: 'status', cell: (r) => <StatusBadge status={r.status} label={r.outcome === 'unfounded' ? 'Asılsız' : r.status_label} map={INCIDENT_TONE} /> },
    { key: 'sanctions', priority: 4, header: 'Verilen yaptırım', align: 'right', hideable: true, cell: (r) => (r.sanctions_count ? <Badge tone="danger">{r.sanctions_count} yaptırım</Badge> : <span className="text-ink-3">—</span>) },
    { key: 'location', priority: 4, header: 'Olay yeri', hideable: true, cell: (r) => <span className="text-ink-2">{r.location ?? '—'}</span> },
    { key: 'class_group', priority: 3, header: 'Sınıf / ders', hideable: true, cell: (r) => <span className="text-ink-2">{[r.class_group, r.subject].filter(Boolean).join(' · ') || '—'}</span> },
    {
      key: 'reporter', priority: 4, header: 'Kaydeden personel', hideable: true,
      cell: (r) => <span className="text-ink-2">{r.reporter ?? '—'}{r.source === 'teacher_portal' && <Badge tone="info" className="ml-1.5">Öğretmen portalından</Badge>}</span>,
    },
    { key: 'notified', header: 'Veli bilgilendirmesi', hideable: true, defaultHidden: true, cell: (r) => (r.guardian_notified_at ? <Badge tone="success">Veli bilgilendirildi</Badge> : <span className="text-ink-3">—</span>) },
  ], [])

  return (
    <div className="animate-fade-in">
      <PageHeader title="Disiplin olayları" description="Tutanaklar, olumlu davranış kayıtları ve süreç durumu."
        actions={can('discipline.create') && (
          <>
            <Button variant="success" icon={<ThumbsUp className="size-4" />} onClick={() => setForm('positive')}>Olumlu davranış</Button>
            <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setForm('negative')}>Olay kaydet</Button>
          </>
        )} />

      <div className="mb-3 flex max-w-full flex-wrap gap-1">
        {STATUS_TABS.map((t) => {
          const active = (fl.status ?? '') === t.value
          const n = t.value ? t.value.split(',').reduce((a, k) => a + (counts[k] ?? 0), 0) : null
          return (
            <button key={t.value} type="button" onClick={() => list.update({ filters: { status: t.value || null } })}
              className={cn('inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full px-3 text-[13px] font-medium ring-1 transition-colors',
                active ? 'bg-primary text-white ring-primary' : 'bg-surface text-ink-2 ring-line hover:text-ink')}>
              {t.label}
              {n !== null && <span className={cn('rounded-full px-1.5 text-[12px] tabular', active ? 'bg-white/20' : 'bg-surface-2')}>{n}</span>}
            </button>
          )
        })}
      </div>

      <DataTable
        storageKey="discipline-incidents"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/disiplin/olaylar/${r.id}`)}
        toolbar={
          <>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Öğrenci, numara ya da olay no" leading={<Search />} className="w-full sm:w-64" aria-label="Ara" />
            <Select aria-label="Tür" className="w-full sm:w-36" value={fl.kind ?? ''} onChange={(e) => list.update({ filters: { kind: e.target.value || null } })} placeholder="Tüm kayıtlar"
              options={[{ value: 'negative', label: 'Disiplin olayı' }, { value: 'positive', label: 'Olumlu davranış' }]} />
            <Select aria-label="Ciddiyet" className="w-full sm:w-32" value={fl.severity ?? ''} onChange={(e) => list.update({ filters: { severity: e.target.value || null } })} placeholder="Tüm ciddiyetler"
              options={Object.entries(opt?.severities ?? {}).map(([value, label]) => ({ value, label }))} />
            <Select aria-label="Sınıf" className="w-full sm:w-32" value={fl.class_group_id ?? ''} onChange={(e) => list.update({ filters: { class_group_id: e.target.value || null } })} placeholder="Tüm sınıflar"
              options={(opt?.class_groups ?? []).map((c) => ({ value: c.id, label: c.name }))} />
            <Select aria-label="Davranış" className="w-full sm:w-48" value={fl.behavior_id ?? ''} onChange={(e) => list.update({ filters: { behavior_id: e.target.value || null } })} placeholder="Tüm davranışlar"
              options={(opt?.behaviors ?? []).map((b) => ({ value: b.id, label: b.name }))} />
          </>
        }
        empty={
          <EmptyState compact icon={<Gavel />} title={list.q || Object.values(fl).some(Boolean) ? 'Filtreye uyan kayıt yok' : 'Henüz disiplin kaydı yok'}
            description="Olay ya da olumlu davranış kaydettiğinizde burada listelenir."
            action={can('discipline.create') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setForm('negative')}>Olay kaydet</Button> : undefined} />
        }
      />

      <IncidentFormDrawer open={form !== null} defaultKind={form ?? 'negative'} onClose={close} />
    </div>
  )
}
