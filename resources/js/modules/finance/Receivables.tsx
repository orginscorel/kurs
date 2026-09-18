import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlarmClock, Download, FileSignature, Filter, HandCoins, PhoneCall, Search, Users, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { date, money, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader, Tabs } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Switch } from '@/components/ui/form'
import { PersonText, PhoneText } from '@/components/ui/contact'
import { series } from '@/components/charts/ChartKit'
import { AGING_BUCKETS, INSTALLMENT_STATUS, type InstallmentRow } from './shared'
import { ShareBar } from './components'
import { NotePrintDialog } from './PromissoryNotes'

type InstallmentResponse = Paginated<InstallmentRow> & { meta: { totals: { count: number; amount: string; paid: string; remaining: string }; status_counts: Record<string, number> } }
type StudentRow = {
  student_id: number
  student: string
  student_no: string
  guardian: { id: number; name: string; phone: string | null } | null
  remaining: string
  overdue: string
  open_count: number
  overdue_count: number
  oldest_due: string
  days_overdue: number
  buckets: Record<string, string>
}
type Aging = { buckets: { key: string; label: string; amount: string; count: number }[]; total: string; overdue: string }
type Options = { programs: { id: number; name: string }[]; terms: { id: number; name: string }[] }

const bucketColors: Record<string, string> = {
  current: 'var(--line-strong)',
  d0_30: series[2]!,
  d31_60: 'var(--warning)',
  d61_90: series[1]!,
  d90_plus: 'var(--danger)',
}

export default function Receivables() {
  const can = useCan()
  const navigate = useNavigate()
  const list = useListState({ sort: 'due_date', filters: { view: 'installments' } })
  const view = list.filters.view === 'students' ? 'students' : 'installments'
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [showFilters, setShowFilters] = useState(false)
  const [selected, setSelected] = useState<Set<string | number>>(new Set())
  const [noteIds, setNoteIds] = useState<number[] | null>(null)

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])
  useEffect(() => setSelected(new Set()), [list.filters.status, list.q, view])

  const filterQuery = { q: list.q, program_id: list.filters.program_id, term_id: list.filters.term_id, due_from: list.filters.due_from, due_to: list.filters.due_to, bucket: list.filters.bucket }
  const options = useQuery({ queryKey: ['finance', 'enrollment-options'], queryFn: () => api.get<Options>('/finance/enrollment-options'), staleTime: 5 * 60_000 })
  const aging = useQuery({ queryKey: ['finance', 'aging', filterQuery], queryFn: () => api.get<{ data: Aging }>('/finance/receivables/aging', filterQuery), placeholderData: keepPreviousData })

  const installments = useQuery({
    queryKey: ['finance', 'installments', list.query],
    queryFn: () => api.get<InstallmentResponse>('/finance/installments', { ...list.query, view: undefined }),
    enabled: view === 'installments',
    placeholderData: keepPreviousData,
  })
  const students = useQuery({
    queryKey: ['finance', 'by-student', list.query],
    queryFn: () => api.get<Paginated<StudentRow>>('/finance/receivables/by-student', { ...filterQuery, sort: list.sort && list.sort !== 'due_date' ? list.sort : '-overdue', page: list.page, overdue_only: list.filters.overdue_only, per_page: 25 }),
    enabled: view === 'students',
    placeholderData: keepPreviousData,
  })

  const counts = installments.data?.meta.status_counts ?? {}
  const status = list.filters.status ?? 'open'
  const activeFilterCount = ['program_id', 'term_id', 'due_from', 'due_to', 'bucket'].filter((k) => list.filters[k]).length

  const selectedRows = (installments.data?.data ?? []).filter((r) => selected.has(r.id))
  const selectedStudents = [...new Set(selectedRows.map((r) => r.student_id))]
  const collectSelected = () => {
    if (selectedStudents.length !== 1) {
      toast.warning('Tahsilat tek öğrenci için alınır. Aynı öğrenciye ait taksitleri seçin.')
      return
    }
    navigate(`/finans/tahsilat?ogrenci=${selectedStudents[0]}&taksit=${selectedRows.map((r) => r.id).join(',')}`)
  }

  const instColumns = useMemo<Column<InstallmentRow>[]>(
    () => [
      {
        key: 'student',
        header: 'Öğrenci',
        sortKey: 'student',
        cell: (r) => (
          <div className="min-w-0">
            <Link to={`/ogrenciler/${r.student_id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-ink hover:underline">{r.student}</Link>
            <p className="truncate text-[12px] text-ink-3">{r.guardian ? `Veli: ${r.guardian}` : `Öğrenci no: ${r.student_no}`}</p>
          </div>
        ),
      },
      {
        key: 'enrollment', priority: 4,
        header: 'Kayıt no / program',
        hideable: true,
        cell: (r) => (
          <div className="min-w-0">
            <Link to={`/finans/kayitlar/${r.enrollment_id}`} onClick={(e) => e.stopPropagation()} className="tabular text-ink-2 hover:underline">{r.enrollment_no}</Link>
            <p className="truncate text-[12px] text-ink-3">{r.program}</p>
          </div>
        ),
      },
      { key: 'seq', priority: 4, header: 'Taksit sırası', cell: (r) => <span className="text-ink-2 tabular whitespace-nowrap">{r.sequence}. taksit</span> },
      {
        key: 'due',
        header: 'Vade tarihi',
        sortKey: 'due_date',
        cell: (r) => (
          <div className="whitespace-nowrap">
            <p className="tabular">{date(r.due_date)}</p>
            {r.days_overdue > 0 && <p className="text-[12px] text-danger tabular">{r.days_overdue} gün gecikti</p>}
          </div>
        ),
      },
      { key: 'status', header: 'Durum', cell: (r) => <Badge tone={INSTALLMENT_STATUS[r.effective_status]?.tone ?? 'neutral'} dot>{INSTALLMENT_STATUS[r.effective_status]?.label ?? r.effective_status}</Badge> },
      { key: 'amount', priority: 3, header: 'Taksit tutarı', sortKey: 'amount', align: 'right', hideable: true, cell: (r) => <span className="text-ink-2">{money(r.amount)}</span> },
      { key: 'paid', header: 'Ödenen', align: 'right', hideable: true, defaultHidden: true, cell: (r) => <span className="text-ink-2">{money(r.paid_amount)}</span> },
      { key: 'remaining', priority: 1, header: 'Kalan borç', sortKey: 'remaining', align: 'right', cell: (r) => <span className={cn('font-semibold', r.days_overdue > 0 && 'text-danger')}>{money(r.remaining)}</span> },
    ],
    [],
  )

  const studentColumns = useMemo<Column<StudentRow>[]>(
    () => [
      {
        key: 'student',
        header: 'Öğrenci',
        sortKey: 'student',
        cell: (r) => (
          <div className="min-w-0">
            <p className="font-medium text-ink">{r.student}</p>
            <p className="text-[12px] text-ink-3 tabular">Öğrenci no: {r.student_no} · {r.open_count} açık taksit</p>
          </div>
        ),
      },
      {
        key: 'guardian', priority: 3,
        header: 'Veli / ödeme sorumlusu',
        cell: (r) =>
          r.guardian ? (
            <div className="min-w-0 space-y-0.5">
              <PersonText className="text-ink-2">{r.guardian.name}</PersonText>
              <div><PhoneText value={r.guardian.phone} muted whatsapp /></div>
            </div>
          ) : (
            <span className="text-ink-3">—</span>
          ),
      },
      {
        key: 'oldest', priority: 3,
        header: 'En eski vade',
        sortKey: 'oldest_due',
        cell: (r) => (
          <div className="whitespace-nowrap">
            <p className="tabular">{date(r.oldest_due)}</p>
            {r.days_overdue > 0 && <p className="text-[12px] text-danger">{r.days_overdue} gün gecikti</p>}
          </div>
        ),
      },
      ...AGING_BUCKETS.filter((b) => b.key !== 'current').map((b) => ({
        key: b.key,
        header: `Gecikme ${b.label}`,
        align: 'right' as const,
        hideable: true,
        priority: 4 as const,
        cell: (r: StudentRow) => (Number(r.buckets[b.key]) > 0 ? <span className="text-ink-2">{money(r.buckets[b.key], { short: true })}</span> : <span className="text-ink-3">—</span>),
      })),
      { key: 'overdue', priority: 2, header: 'Vadesi geçmiş', sortKey: 'overdue', align: 'right', cell: (r) => <span className={cn('font-semibold', Number(r.overdue) > 0 ? 'text-danger' : 'text-ink-3')}>{money(r.overdue, { short: true })}</span> },
      { key: 'remaining', priority: 1, header: 'Toplam kalan borç', sortKey: 'remaining', align: 'right', cell: (r) => <span className="font-semibold">{money(r.remaining, { short: true })}</span> },
      {
        key: 'action',
        header: '',
        align: 'right',
        cell: (r) =>
          can('payments.create') ? (
            <Button size="xs" variant="soft" onClick={(e) => { e.stopPropagation(); navigate(`/finans/tahsilat?ogrenci=${r.student_id}`) }}>Tahsil et</Button>
          ) : null,
      },
    ],
    [can, navigate],
  )

  const agingData = aging.data?.data
  const toolbar = (
    <div className="flex w-full flex-col gap-2.5">
      <div className="flex flex-wrap items-center gap-2">
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Öğrenci, veli, öğrenci ya da kayıt no"
          leading={<Search />}
          trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
          className="w-full sm:w-[280px]"
        />
        {noteIds && <NotePrintDialog open onClose={() => setNoteIds(null)} selector={{ installment_ids: noteIds }} title="Seçili taksitlerin senetleri" />}
      {view === 'installments' ? (
          <Segmented
            size="sm"
            value={status}
            onChange={(v) => list.update({ filters: { status: v === 'open' ? null : v } })}
            className="max-w-full"
            options={[
              { value: 'open', label: `Açık ${counts.open ?? ''}` },
              { value: 'overdue', label: `Gecikti ${counts.overdue ?? ''}` },
              { value: 'pending', label: `Ödeme bekliyor ${counts.pending ?? ''}` },
              { value: 'partial', label: `Kısmen ödendi ${counts.partial ?? ''}` },
              { value: 'paid', label: `Ödendi ${counts.paid ?? ''}` },
              { value: 'all', label: 'Tümü' },
            ]}
          />
        ) : (
          <Switch checked={list.filters.overdue_only === '1'} onChange={(v) => list.update({ filters: { overdue_only: v ? '1' : null } })} label="Yalnız gecikmesi olanlar" />
        )}
        <Button size="sm" variant={activeFilterCount ? 'soft' : 'ghost'} icon={<Filter className="size-4" />} onClick={() => setShowFilters((v) => !v)}>
          Filtre{activeFilterCount ? ` (${activeFilterCount})` : ''}
        </Button>
        {activeFilterCount > 0 && <Button size="sm" variant="ghost" onClick={() => list.update({ filters: { program_id: null, term_id: null, due_from: null, due_to: null, bucket: null } })}>Temizle</Button>}
      </div>
      {showFilters && (
        <div className="grid grid-cols-1 items-end sm:grid-cols-2 lg:grid-cols-5 gap-2 animate-fade-in">
          <Select value={list.filters.program_id ?? ''} onChange={(e) => list.update({ filters: { program_id: e.target.value } })} placeholder="Tüm programlar" options={(options.data?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
          <Select value={list.filters.term_id ?? ''} onChange={(e) => list.update({ filters: { term_id: e.target.value } })} placeholder="Tüm dönemler" options={(options.data?.terms ?? []).map((t) => ({ value: t.id, label: t.name }))} />
          <Select value={list.filters.bucket ?? ''} onChange={(e) => list.update({ filters: { bucket: e.target.value } })} placeholder="Tüm gecikme aralıkları" options={AGING_BUCKETS.map((b) => ({ value: b.key, label: b.label }))} />
          <Field label="Vade tarihi (başlangıç)"><Input type="date" value={list.filters.due_from ?? ''} onChange={(e) => list.update({ filters: { due_from: e.target.value } })} /></Field>
          <Field label="Vade tarihi (bitiş)"><Input type="date" value={list.filters.due_to ?? ''} onChange={(e) => list.update({ filters: { due_to: e.target.value } })} /></Field>
        </div>
      )}
    </div>
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Taksitler ve alacaklar"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Taksit ve alacaklar' }]}
        description="Vade durumu, yaşlandırma ve öğrenci/veli bazlı açık bakiye"
        actions={
          <>
            <ButtonLink to="/finans/takip" icon={<PhoneCall className="size-4" />}>Gecikme takibi</ButtonLink>
            <ButtonLink to="/finans/senetler" icon={<FileSignature className="size-4" />}>Senet basımı</ButtonLink>
            {can('reports.export') && view === 'installments' && (
              <Button icon={<Download className="size-4" />} onClick={() => api.download('/finance/installments/export', { ...list.query, view: undefined }, 'taksitler.xlsx').catch((e) => toast.error(e.message))}>Excel</Button>
            )}
          </>
        }
      />

      {/* Yaşlandırma */}
      <section className="mb-4 rounded-[var(--radius-lg)] bg-surface ring-1 ring-line p-4">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="text-[12.5px] text-ink-3">Toplam açık alacak</p>
            {agingData ? <p className="text-[24px] font-semibold tabular tracking-tight">{money(agingData.total)}</p> : <Skeleton className="h-7 w-40 mt-1" />}
          </div>
          <div className="text-right">
            <p className="text-[12.5px] text-ink-3">Vadesi geçmiş</p>
            {agingData ? <p className="text-[18px] font-semibold tabular text-danger">{money(agingData.overdue)}</p> : <Skeleton className="h-6 w-28 mt-1" />}
          </div>
        </div>
        <div className="mt-3">
          <ShareBar items={(agingData?.buckets ?? []).map((b) => ({ key: b.key, label: b.label, value: Math.round(Number(b.amount) * 100), color: bucketColors[b.key]! }))} />
        </div>
        <div className="mt-3 grid grid-cols-2 sm:grid-cols-5 gap-2">
          {(agingData?.buckets ?? AGING_BUCKETS.map((b) => ({ ...b, amount: '0', count: 0 }))).map((b) => {
            const active = list.filters.bucket === b.key
            return (
              <button
                key={b.key}
                type="button"
                onClick={() => list.update({ filters: { bucket: active ? null : b.key } })}
                className={cn('rounded-[var(--radius-md)] px-3 py-2 text-left ring-1 transition-colors', active ? 'ring-primary bg-primary-soft/50' : 'ring-line hover:bg-surface-2')}
              >
                <span className="flex items-center gap-1.5 text-[12px] text-ink-2">
                  <span className="size-2 rounded-[3px]" style={{ background: bucketColors[b.key] }} />
                  {b.key === 'current' ? b.label : `${b.label} gecikmiş`}
                </span>
                {aging.isLoading ? <Skeleton className="mt-1 h-5 w-20" /> : <span className="block text-[15px] font-semibold tabular">{money(b.amount, { short: true })}</span>}
                <span className="block text-[12px] text-ink-3">{num(b.count)} taksit</span>
              </button>
            )
          })}
        </div>
      </section>

      <Tabs
        className="mb-3"
        value={view}
        onChange={(v) => list.update({ filters: { view: v === 'installments' ? null : v, status: null, overdue_only: null }, sort: v === 'students' ? '-overdue' : 'due_date' })}
        tabs={[
          { value: 'installments', label: 'Taksitler' },
          { value: 'students', label: 'Öğrenci / veli bazlı' },
        ]}
      />

      {view === 'installments' ? (
        <>
          {installments.data && (
            <p className="mb-2 text-[12.5px] text-ink-3 tabular">
              {num(installments.data.meta.totals.count)} taksit · Toplam tutar: {money(installments.data.meta.totals.amount, { short: true })} · Ödenen: {money(installments.data.meta.totals.paid, { short: true })} · Kalan: <span className="font-medium text-ink">{money(installments.data.meta.totals.remaining, { short: true })}</span>
            </p>
          )}
          <DataTable
            storageKey="finance-installments"
            columns={instColumns}
            rows={installments.data?.data}
            rowKey={(r) => r.id}
            loading={installments.isLoading || installments.isFetching}
            meta={installments.data?.meta}
            sort={list.sort}
            onSort={(sort) => list.update({ sort })}
            onPage={(page) => list.update({ page })}
            onRowClick={(r) => navigate(`/finans/kayitlar/${r.enrollment_id}`)}
            selectable={can('payments.create') || can('installments.manage') || can('enrollments.create')}
            selected={selected}
            onSelectedChange={(s) => setSelected(new Set([...s].filter((id) => (installments.data?.data ?? []).some((r) => r.id === id && Number(r.remaining) > 0))))}
            bulkActions={
              <>
                {selectedStudents.length > 1 && <span className="text-[12px] text-warning">{selectedStudents.length} farklı öğrenci</span>}
                {(can('installments.manage') || can('enrollments.create') || can('finance.invoice')) && (
                  <Button size="sm" icon={<FileSignature className="size-3.5" />} onClick={() => setNoteIds([...selected].map(Number))}>Senet yazdır</Button>
                )}
                {can('payments.create') && <Button size="sm" variant="primary" icon={<HandCoins className="size-3.5" />} onClick={collectSelected}>
                  Tahsilat al · {money(selectedRows.reduce((s, r) => s + Math.round(Number(r.remaining) * 100), 0) / 100, { short: true })}
                </Button>}
              </>
            }
            toolbar={toolbar}
            empty={<EmptyState icon={<AlarmClock />} title={status === 'overdue' ? 'Gecikmiş taksit yok' : 'Filtreye uyan taksit yok'} description={list.q || activeFilterCount ? 'Filtreleri değiştirerek tekrar deneyin.' : 'Taksitler öğrenci kaydı (ödeme planı) oluşturulduğunda burada listelenir.'} action={!list.q && !activeFilterCount && can('enrollments.create') ? <ButtonLink to="/finans/kayitlar/yeni">Yeni kayıt</ButtonLink> : undefined} />}
          />
        </>
      ) : (
        <DataTable
          storageKey="finance-by-student"
          columns={studentColumns}
          rows={students.data?.data}
          rowKey={(r) => r.student_id}
          loading={students.isLoading || students.isFetching}
          meta={students.data?.meta}
          sort={list.sort && list.sort !== 'due_date' ? list.sort : '-overdue'}
          onSort={(sort) => list.update({ sort })}
          onPage={(page) => list.update({ page })}
          onRowClick={(r) => list.update({ filters: { view: null, status: 'open' }, q: r.student, sort: 'due_date' })}
          toolbar={toolbar}
          empty={<EmptyState icon={<Users />} title="Açık bakiyesi olan öğrenci yok" description={list.q || activeFilterCount ? 'Filtreleri değiştirerek tekrar deneyin.' : undefined} />}
        />
      )}
    </div>
  )
}
