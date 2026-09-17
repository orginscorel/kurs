import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, FilePlus2, FileText, Layers, Printer, ReceiptText, Search, Settings2, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date, dateTime, money, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader, Stat, Tabs } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Input, Segmented, Select } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import { DateRange } from './components'
import { openPdf, sumCents } from './shared'
import { DOCUMENT_TYPES, INTEGRATOR_STATUS, INVOICE_KIND, INVOICE_STATUS, type InvoiceOptions, type InvoiceRow, type UnbilledRow } from './ledger'

type ListResponse = Paginated<InvoiceRow> & {
  meta: { totals: { count: number; drafts: number; issued_total: string; vat_total: string; return_total: string }; status_counts: Record<string, number> }
}
type UnbilledResponse = Paginated<UnbilledRow> & { meta: { totals: { count: number; amount: string; students: number } } }

/** Faturalar: liste + faturalanmamış tahsilatlardan toplu taslak. */
export default function Invoices() {
  const can = useCan()
  const list = useListState({ sort: '-issue_date' })
  const tab = list.filters.sekme === 'faturalanmamis' ? 'unbilled' : 'invoices'
  const setTab = (t: string) => list.update({ filters: { sekme: t === 'unbilled' ? 'faturalanmamis' : null, status: null, kind: null } })
  const options = useQuery({ queryKey: ['finance', 'invoice-options'], queryFn: () => api.get<{ data: InvoiceOptions }>('/finance/invoices/options'), staleTime: 60_000 })
  const unbilledCount = useQuery({ queryKey: ['finance', 'unbilled-count'], queryFn: () => api.get<UnbilledResponse>('/finance/invoices/unbilled', { per_page: 5 }) })
  const integrator = options.data?.data.integrator

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Faturalar"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Faturalar' }]}
        description="Fatura / e-Arşiv taslakları, kesilen faturalar ve faturalanmamış tahsilatlar"
        actions={
          <>
            {can('settings.manage') && <ButtonLink to="/finans/ayarlar" variant="ghost" icon={<Settings2 className="size-4" />}>Ayarlar</ButtonLink>}
            {can('reports.export') && tab === 'invoices' && (
              <Button icon={<Download className="size-4" />} onClick={() => api.download('/finance/invoices/export', list.query, 'faturalar.xlsx').catch((e) => toast.error(e.message))}>Excel</Button>
            )}
            {can('finance.invoice') && <ButtonLink to="/finans/faturalar/yeni" variant="primary" icon={<FilePlus2 className="size-4" />}>Yeni fatura</ButtonLink>}
          </>
        }
      />

      {integrator && !integrator.connected && (
        <Alert tone="warning" className="mb-4" title="e-Fatura / e-Arşiv entegratörü bağlı değil">
          Faturalar kurum içinde kesilir ve numaralandırılır; GİB'e iletilmez. Resmî e-Arşiv için faturayı entegratör/GİB portalında da düzenleyin.
          {integrator.key === 'simulation' ? ' Şu an simülasyon sürücüsü seçili (gerçek gönderim yok).' : ''}
        </Alert>
      )}

      <Tabs
        className="mb-4"
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'invoices', label: 'Faturalar' },
          { value: 'unbilled', label: 'Faturalanmamış tahsilatlar', count: unbilledCount.data?.meta.totals.count ?? null },
        ]}
      />
      {tab === 'invoices' ? <InvoiceList list={list} /> : <UnbilledList list={list} />}
    </div>
  )
}

function InvoiceList({ list }: { list: ReturnType<typeof useListState> }) {
  const can = useCan()
  const navigate = useNavigate()
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const [selected, setSelected] = useState<Set<string | number>>(new Set())
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'invoices', list.query],
    queryFn: () => api.get<ListResponse>('/finance/invoices', list.query),
    placeholderData: keepPreviousData,
  })
  const t = data?.meta.totals
  const c = data?.meta.status_counts

  const columns = useMemo<Column<InvoiceRow>[]>(
    () => [
      {
        key: 'no',
        header: 'Fatura no / tarih',
        sortKey: 'invoice_no',
        cell: (r) => (
          <div className="min-w-[150px]">
            <p className={cn('font-medium tabular', r.status === 'cancelled' && 'line-through text-ink-3')}>{r.invoice_no ?? `Taslak #${r.id}`}</p>
            <p className="text-[12px] text-ink-3 tabular">{date(r.issue_date)} · {DOCUMENT_TYPES[r.document_type] ?? r.document_type}</p>
          </div>
        ),
      },
      {
        key: 'buyer',
        header: 'Alıcı',
        cell: (r) => (
          <div className="min-w-[160px]">
            <p className="truncate font-medium text-ink">{r.buyer_name}</p>
            <p className="truncate text-[12px] text-ink-3">{r.student ? `Öğrenci: ${r.student.full_name}` : r.buyer_tax_id_masked ? `TCKN/VKN: ${r.buyer_tax_id_masked}` : '—'}</p>
          </div>
        ),
      },
      { key: 'kind', header: 'Fatura türü', cell: (r) => <Badge tone={INVOICE_KIND[r.kind]?.tone}>{INVOICE_KIND[r.kind]?.label}</Badge> },
      { key: 'status', header: 'Durum', cell: (r) => <Badge tone={INVOICE_STATUS[r.status]?.tone} dot>{INVOICE_STATUS[r.status]?.label}</Badge> },
      {
        key: 'gib',
        header: 'GİB gönderimi',
        hideable: true,
        cell: (r) => (r.status === 'draft' ? <span className="text-ink-3">—</span> : <Badge tone={INTEGRATOR_STATUS[r.integrator_status]?.tone ?? 'neutral'}>{INTEGRATOR_STATUS[r.integrator_status]?.label ?? r.integrator_status}</Badge>),
      },
      { key: 'net', header: 'Matrah (KDV hariç)', align: 'right', hideable: true, cell: (r) => <span className="tabular text-ink-2 whitespace-nowrap">{money(r.net_total)}</span> },
      { key: 'vat', header: 'KDV tutarı', align: 'right', hideable: true, cell: (r) => <span className="tabular text-ink-2 whitespace-nowrap">{money(r.vat_total)}</span> },
      {
        key: 'total',
        header: 'Ödenecek tutar',
        sortKey: 'total',
        align: 'right',
        cell: (r) => (
          <span className={cn('font-semibold tabular whitespace-nowrap', r.status === 'cancelled' && 'line-through text-ink-3 font-normal', r.kind === 'return' && r.status !== 'cancelled' && 'text-danger')}>
            {r.kind === 'return' ? '−' : ''}{money(r.payable_total)}
          </span>
        ),
      },
      {
        key: 'actions',
        header: '',
        cell: (r) => (
          <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
            <Button size="icon-sm" variant="ghost" aria-label="Yazdır" onClick={() => openPdf(`/finance/invoices/${r.id}/pdf`).catch((e) => toast.error(e.message))}>
              <Printer className="size-3.5" />
            </Button>
          </div>
        ),
      },
    ],
    [],
  )

  const active = list.filters.status ?? 'all'
  return (
    <>
      <div className="mb-3 grid grid-cols-2 lg:grid-cols-4 gap-3">
        <Stat label="Kesilen (filtre)" value={t ? money(t.issued_total, { short: true }) : '—'} loading={isLoading} sub="iadeler hariç satış faturaları" />
        <Stat label="Hesaplanan KDV" value={t ? money(t.vat_total, { short: true }) : '—'} loading={isLoading} sub="kesilen satış faturaları" />
        <Stat label="İade faturası" value={t ? money(t.return_total, { short: true }) : '—'} loading={isLoading} tone={t && Number(t.return_total) > 0 ? 'warning' : undefined} sub="kesilen iade faturaları" />
        <Stat label="Bekleyen taslak" value={num(c?.draft ?? 0)} loading={isLoading} tone={(c?.draft ?? 0) > 0 ? 'warning' : undefined} sub="kesilmeyi bekliyor"
          to={(c?.draft ?? 0) > 0 ? '/finans/faturalar?status=draft' : undefined} />
      </div>
      <DataTable
        storageKey="finance-invoices"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/finans/faturalar/${r.id}`)}
        selectable
        selected={selected}
        onSelectedChange={setSelected}
        bulkActions={
          <Button size="sm" variant="primary" icon={<Printer className="size-4" />} onClick={() => openPdf(`/finance/documents/invoices.pdf?ids=${[...selected].join(',')}`).catch((e) => toast.error(e.message))}>
            {selected.size} faturayı yazdır
          </Button>
        }
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Fatura no, alıcı ya da öğrenci"
              leading={<Search />}
              trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
              className="w-full sm:w-[280px]"
            />
            <Segmented
              size="sm"
              value={active}
              onChange={(v) => list.update({ filters: { status: v === 'all' ? null : v } })}
              options={[
                { value: 'all', label: `Tümü${c ? ` ${c.all}` : ''}` },
                { value: 'draft', label: `Taslak${c ? ` ${c.draft}` : ''}` },
                { value: 'issued', label: `Kesildi${c ? ` ${c.issued}` : ''}` },
                { value: 'cancelled', label: `İptal edilen${c ? ` ${c.cancelled}` : ''}` },
              ]}
            />
            <Select className="w-full sm:w-[150px]" value={list.filters.kind ?? ''} onChange={(e) => list.update({ filters: { kind: e.target.value } })} placeholder="Tüm türler"
              options={[{ value: 'sales', label: 'Satış' }, { value: 'return', label: 'İade' }]} />
            <DateRange label="Fatura tarihi" from={list.filters.from} to={list.filters.to} onChange={(k, v) => list.update({ filters: { [k]: v } })} />
          </div>
        }
        empty={
          <EmptyState
            icon={<FileText />}
            title={list.q || list.filters.status ? 'Filtreye uyan fatura yok' : 'Henüz fatura yok'}
            description="Faturalanmamış tahsilatlardan toplu taslak oluşturabilir ya da serbest fatura düzenleyebilirsiniz."
            action={can('finance.invoice') ? <ButtonLink to="/finans/faturalar/yeni" variant="primary" icon={<FilePlus2 className="size-4" />}>Yeni fatura</ButtonLink> : undefined}
          />
        }
      />
    </>
  )
}

function UnbilledList({ list }: { list: ReturnType<typeof useListState> }) {
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [selected, setSelected] = useState<Set<string | number>>(new Set())
  const [group, setGroup] = useState<'payment' | 'student'>('payment')
  const [confirm, setConfirm] = useState(false)
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])
  const query = { q: list.q, page: list.page, per_page: 50, from: list.filters.from, to: list.filters.to }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'unbilled', query],
    queryFn: () => api.get<UnbilledResponse>('/finance/invoices/unbilled', query),
    placeholderData: keepPreviousData,
  })
  const rows = data?.data ?? []
  const selectedRows = rows.filter((r) => selected.has(r.id))
  const selectedCents = sumCents(selectedRows.map((r) => r.available))

  const bulk = useMutation({
    mutationFn: () => api.post<{ message: string; ids: number[] }>('/finance/invoices/bulk-drafts', { payment_ids: [...selected], group }),
    onSuccess: (res) => {
      toast.success(res.message)
      setConfirm(false)
      setSelected(new Set())
      qc.invalidateQueries({ queryKey: ['finance'] })
      if (res.ids.length === 1) navigate(`/finans/faturalar/${res.ids[0]}`)
      else if (res.ids.length > 1) navigate('/finans/faturalar?status=draft')
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Taslaklar oluşturulamadı.'),
  })

  const columns = useMemo<Column<UnbilledRow>[]>(
    () => [
      {
        key: 'receipt',
        header: 'Makbuz no / tarih',
        cell: (r) => (
          <div className="min-w-[130px]">
            <p className="font-medium tabular">{r.receipt_no}</p>
            <p className="text-[12px] text-ink-3 tabular">{dateTime(r.paid_at)}</p>
          </div>
        ),
      },
      {
        key: 'student',
        header: 'Öğrenci',
        cell: (r) => (
          <div className="min-w-[150px]">
            <Link to={`/ogrenciler/${r.student_id}`} onClick={(e) => e.stopPropagation()} className="truncate font-medium text-ink hover:underline">{r.student}</Link>
            <p className="truncate text-[12px] text-ink-3">{r.program ? `Program: ${r.program}` : `Öğrenci no: ${r.student_no}`}</p>
          </div>
        ),
      },
      { key: 'method', header: 'Ödeme yöntemi', cell: (r) => <span className="text-ink-2 whitespace-nowrap">{r.method_label}</span> },
      { key: 'amount', header: 'Tahsilat tutarı', align: 'right', cell: (r) => <span className="tabular text-ink-2 whitespace-nowrap">{money(r.amount)}</span> },
      {
        key: 'available',
        header: 'Faturalanacak tutar',
        align: 'right',
        cell: (r) => <span className={cn('tabular font-semibold whitespace-nowrap', r.available !== r.amount && 'text-info')}>{money(r.available)}</span>,
      },
    ],
    [],
  )

  return (
    <>
      <div className="mb-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <Stat label="Faturalanmamış tahsilat" value={data ? money(data.meta.totals.amount, { short: true }) : '—'} loading={isLoading} tone={data && Number(data.meta.totals.amount) > 0 ? 'warning' : undefined} sub={data ? `${num(data.meta.totals.count)} makbuz · ${num(data.meta.totals.students)} öğrenci` : ''} />
        <Stat label="Seçili" value={money(selectedCents / 100)} sub={`${selected.size} makbuz`} />
        <div className="flex flex-col justify-center gap-2 rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line">
          <p className="text-[12.5px] font-medium text-ink-2">Taslak oluşturma biçimi</p>
          <Segmented size="sm" value={group} onChange={setGroup} options={[{ value: 'payment', label: 'Makbuz başına' }, { value: 'student', label: 'Öğrenci başına tek' }]} />
        </div>
      </div>
      <Alert tone="info" className="mb-3">
        Tahsilatlar fatura kesilene kadar muhasebede "alınan avans" (340) hesabında bekler. Taslaklar ödeme sorumlusu veli adına, varsayılan KDV oranıyla ve <b>KDV dahil</b> hazırlanır; kesmeden önce kontrol edin.
      </Alert>
      <DataTable
        storageKey="finance-unbilled"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        selectable={can('finance.invoice')}
        selected={selected}
        onSelectedChange={setSelected}
        bulkActions={
          can('finance.invoice') ? (
            <Button size="sm" variant="primary" icon={<Layers className="size-4" />} onClick={() => setConfirm(true)}>
              {selected.size} makbuzdan taslak oluştur
            </Button>
          ) : undefined
        }
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Öğrenci ya da makbuz no" leading={<Search />} className="w-full sm:w-[260px]" />
            <DateRange label="Tahsilat tarihi" from={list.filters.from} to={list.filters.to} onChange={(k, v) => list.update({ filters: { [k]: v } })} />
          </div>
        }
        empty={<EmptyState icon={<ReceiptText />} title="Faturalanmamış tahsilat yok" description="Tüm tahsilatlar faturaya bağlanmış." />}
      />
      <ConfirmDialog
        open={confirm}
        onClose={() => setConfirm(false)}
        onConfirm={() => bulk.mutate()}
        loading={bulk.isPending}
        title="Fatura taslakları oluşturulsun mu?"
        confirmLabel="Taslakları oluştur"
        description={`${selected.size} makbuz · ${money(selectedCents / 100)} · ${group === 'student' ? 'öğrenci başına tek fatura' : 'makbuz başına fatura'}`}
      >
        <p className="text-[13px] text-ink-2">Taslaklar numara almaz ve muhasebeye yansımaz; "Kes" dediğinizde numaralanır ve fiş oluşur.</p>
      </ConfirmDialog>
    </>
  )
}
