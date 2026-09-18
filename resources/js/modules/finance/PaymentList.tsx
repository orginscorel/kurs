import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, Download, FilePlus2, FileText, Filter, HandCoins, Printer, ReceiptText, Search, Undo2, WalletCards, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, date, money, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Segmented, Select } from '@/components/ui/form'
import { PersonText } from '@/components/ui/contact'
import { Drawer } from '@/components/ui/overlay'
import { MetricRow, useAccounts, VoidDialog } from './components'
import { INSTALLMENT_STATUS, METHODS, openPdf, type PaymentRow } from './shared'
import { RefundDialog } from './Refunds'
import { INVOICE_STATUS } from './ledger'
import { useNavigate } from 'react-router-dom'

type ListResponse = Paginated<PaymentRow> & { meta: { totals: { count: number; active_count: number; active_amount: string; voided_count: number; voided_amount: string } } }
type Detail = PaymentRow & {
  note: string | null
  amount_words: string
  enrollment: { id: number; enrollment_no: string; program: string | null } | null
  guardian: { id: number; name: string } | null
  voided_by: string | null
  created_at: string
  allocations: { installment_id: number; sequence: number; due_date: string; installment_amount: string; installment_status: string; amount: string }[]
  credit: string
  refundable: string
  refunds: { id: number; refund_no: string; amount: string; refunded_at: string; voided_at: string | null }[]
  invoices: { id: number; invoice_no: string | null; status: string; amount: string }[]
  card: { commission_rate: string; commission_amount: string; net_amount: string; expected_deposit_date: string; card_installments: number; pos_settlement_id: number | null } | null
}

export default function PaymentList() {
  const can = useCan()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: '-paid_at' })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [showFilters, setShowFilters] = useState(!!(list.filters.from || list.filters.to || list.filters.method || list.filters.account_id))
  const detailId = Number(params.get('detay') || 0) || null
  const accounts = useAccounts()
  const [selected, setSelected] = useState<Set<string | number>>(new Set())

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'payments', list.query],
    queryFn: () => api.get<ListResponse>('/finance/payments', list.query),
    placeholderData: keepPreviousData,
  })

  // ?makbuz= ile tek sonuç geldiyse detayı aç (global aramadan gelindiğinde)
  useEffect(() => {
    if (list.filters.makbuz && data?.data.length === 1 && !detailId) openDetail(data.data[0]!.id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [list.filters.makbuz, data])

  const openDetail = (id: number | null) =>
    setParams((p) => {
      if (id) p.set('detay', String(id))
      else p.delete('detay')
      return p
    }, { replace: true })

  const t = data?.meta.totals
  const activeFilterCount = ['from', 'to', 'method', 'account_id'].filter((k) => list.filters[k]).length

  const columns = useMemo<Column<PaymentRow>[]>(
    () => [
      {
        key: 'receipt',
        header: 'Makbuz no / tarih',
        sortKey: 'receipt_no',
        cell: (p) => (
          <div className="min-w-0">
            <p className={cn('font-medium tabular', p.voided_at && 'line-through text-ink-3')}>{p.receipt_no}</p>
            <p className="text-[12px] text-ink-3 tabular">{dateTime(p.paid_at)}</p>
          </div>
        ),
      },
      {
        key: 'student',
        header: 'Öğrenci',
        cell: (p) => (
          <div className="min-w-0">
            <p className="truncate font-medium text-ink">{p.student?.full_name ?? '—'}</p>
            <p className="truncate text-[12px] text-ink-3">{p.payer_name ? `Ödeyen: ${p.payer_name}` : `Öğrenci no: ${p.student?.student_no ?? '—'}`}</p>
          </div>
        ),
      },
      {
        key: 'amount', priority: 1,
        header: 'Tahsilat tutarı',
        sortKey: 'amount',
        align: 'right',
        cell: (p) => <span className={cn('font-semibold tabular whitespace-nowrap', p.voided_at && 'line-through text-ink-3 font-normal')}>{money(p.amount)}</span>,
      },
      {
        key: 'program', priority: 4,
        header: 'Program / kayıt no',
        hideable: true,
        cell: (p) =>
          p.program || p.enrollment_no ? (
            <div className="min-w-0">
              <p className="truncate text-ink-2">{p.program ?? '—'}</p>
              {p.enrollment_no && <p className="text-[12px] text-ink-3 tabular">{p.enrollment_no}</p>}
            </div>
          ) : (
            <span className="text-ink-3">—</span>
          ),
      },
      {
        key: 'installments', priority: 4,
        header: 'Kapattığı taksit',
        hideable: true,
        // Tahsilatın kapattığı taksitlerin sıra numaraları
        cell: (p) =>
          p.installment_sequences && p.installment_sequences.length ? (
            <span className="text-ink-2 tabular whitespace-nowrap">{p.installment_sequences.join(', ')}. taksit</span>
          ) : (
            <span className="text-ink-3">—</span>
          ),
      },
      { key: 'method', priority: 3, header: 'Ödeme yöntemi', cell: (p) => <span className="text-ink-2 whitespace-nowrap">{p.method_label}</span> },
      { key: 'account', header: 'Kasa / banka', hideable: true, defaultHidden: true, cell: (p) => <span className="text-ink-2 whitespace-nowrap">{p.account?.name ?? '—'}</span> },
      { key: 'receiver', header: 'Tahsil eden', hideable: true, defaultHidden: true, cell: (p) => <span className="text-ink-2 whitespace-nowrap">{p.received_by ?? '—'}</span> },
      { key: 'reference', header: 'Referans no', hideable: true, defaultHidden: true, cell: (p) => <span className="text-ink-3 tabular">{p.reference ?? '—'}</span> },
      { key: 'status', header: 'Durum', cell: (p) => (p.voided_at ? <Badge tone="danger">İptal edildi</Badge> : <Badge tone="success" dot>Geçerli</Badge>) },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Tahsilatlar"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Tahsilatlar' }]}
        description="Tüm öğrenci tahsilatları, makbuzlar ve iptaller"
        actions={
          <>
            {can('reports.export') && (
              <Button icon={<Download className="size-4" />} onClick={() => api.download('/finance/payments/export', list.query, 'tahsilatlar.xlsx').catch((e) => toast.error(e.message))}>
                Excel
              </Button>
            )}
            {can('payments.create') && (
              <ButtonLink to="/finans/tahsilat" variant="primary" icon={<HandCoins className="size-4" />}>
                Tahsilat al
              </ButtonLink>
            )}
          </>
        }
      />

      <div className="mb-3 grid grid-cols-2 lg:grid-cols-4 gap-3">
        <TotalBox label="Geçerli tahsilat" value={t ? money(t.active_amount) : null} sub={t ? `${num(t.active_count)} makbuz` : ''} />
        <TotalBox label="Ortalama tahsilat" value={t ? money(t.active_count ? Math.round((Number(t.active_amount) * 100) / t.active_count) / 100 : 0) : null} sub="geçerli makbuzlar" />
        <TotalBox label="İptal edilen" value={t ? money(t.voided_amount) : null} sub={t ? `${num(t.voided_count)} makbuz · toplama dahil değil` : ''} tone={t && t.voided_count > 0 ? 'danger' : undefined} />
        <TotalBox label="Listelenen makbuz" value={t ? num(t.count) : null} sub="filtreye uyan (iptaller dahil)" />
      </div>

      {list.filters.makbuz && (
        <Alert tone="info" className="mb-3" action={<Button size="xs" variant="ghost" icon={<X className="size-3.5" />} onClick={() => list.update({ filters: { makbuz: null } })}>Kaldır</Button>}>
          <span className="tabular">{list.filters.makbuz}</span> numaralı makbuz gösteriliyor.
        </Alert>
      )}

      <DataTable
        storageKey="finance-payments-v2"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => openDetail(r.id)}
        selectable
        selected={selected}
        onSelectedChange={setSelected}
        bulkActions={
          <Button size="sm" variant="primary" icon={<Printer className="size-4" />} onClick={() => openPdf(`/finance/documents/receipts.pdf?ids=${[...selected].join(',')}`).catch((e) => toast.error(e.message))}>
            {selected.size} makbuzu yazdır
          </Button>
        }
        toolbar={
          <div className="flex w-full flex-col gap-2.5">
            <div className="flex flex-wrap items-center gap-2">
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Öğrenci, ödeyen, makbuz ya da referans no"
                leading={<Search />}
                trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
                className="w-full sm:w-[300px]"
              />
              <Segmented
                size="sm"
                value={list.filters.status ?? 'all'}
                onChange={(status) => list.update({ filters: { status: status === 'all' ? null : status } })}
                options={[{ value: 'all', label: 'Tümü' }, { value: 'active', label: 'Geçerli' }, { value: 'voided', label: 'İptal edilen' }]}
              />
              <Button size="sm" variant={activeFilterCount ? 'soft' : 'ghost'} icon={<Filter className="size-4" />} onClick={() => setShowFilters((v) => !v)}>
                Filtre{activeFilterCount ? ` (${activeFilterCount})` : ''}
              </Button>
              {activeFilterCount > 0 && (
                <Button size="sm" variant="ghost" onClick={() => list.update({ filters: { from: null, to: null, method: null, account_id: null } })}>Temizle</Button>
              )}
            </div>
            {showFilters && (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 animate-fade-in">
                <Field label="Tahsilat tarihi (başlangıç)"><Input type="date" value={list.filters.from ?? ''} onChange={(e) => list.update({ filters: { from: e.target.value } })} /></Field>
                <Field label="Tahsilat tarihi (bitiş)"><Input type="date" value={list.filters.to ?? ''} onChange={(e) => list.update({ filters: { to: e.target.value } })} /></Field>
                <Field label="Ödeme yöntemi"><Select value={list.filters.method ?? ''} onChange={(e) => list.update({ filters: { method: e.target.value } })} placeholder="Tüm yöntemler" options={Object.entries(METHODS).map(([value, label]) => ({ value, label }))} /></Field>
                <Field label="Kasa / banka"><Select value={list.filters.account_id ?? ''} onChange={(e) => list.update({ filters: { account_id: e.target.value } })} placeholder="Tüm hesaplar" options={(accounts.data?.data ?? []).map((a) => ({ value: a.id, label: a.name }))} /></Field>
              </div>
            )}
          </div>
        }
        empty={
          <EmptyState
            icon={<ReceiptText />}
            title={list.q || activeFilterCount || list.filters.status ? 'Filtreye uyan tahsilat yok' : 'Henüz tahsilat yok'}
            description={list.q || activeFilterCount ? 'Filtreleri değiştirerek tekrar deneyin.' : 'İlk tahsilatı alarak başlayın.'}
            action={can('payments.create') && !list.q ? <ButtonLink to="/finans/tahsilat" variant="primary" icon={<HandCoins className="size-4" />}>Tahsilat al</ButtonLink> : undefined}
          />
        }
      />

      <PaymentDrawer id={detailId} onClose={() => openDetail(null)} />
    </div>
  )
}

function TotalBox({ label, value, sub, tone }: { label: string; value: string | null; sub: string; tone?: 'danger' }) {
  return (
    <div className="rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line">
      <p className="text-[12px] text-ink-3">{label}</p>
      {value === null ? <Skeleton className="mt-1 h-6 w-28" /> : <p className={cn('mt-0.5 text-[18px] font-semibold tabular tracking-tight', tone === 'danger' && 'text-danger')}>{value}</p>}
      <p className="text-[12px] text-ink-3 truncate">{sub}</p>
    </div>
  )
}

export function PaymentDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const can = useCan()
  const qc = useQueryClient()
  const [voidOpen, setVoidOpen] = useState(false)
  const [refundOpen, setRefundOpen] = useState(false)
  const navigate = useNavigate()
  const { data, isLoading } = useQuery({
    queryKey: ['finance', 'payment', id],
    queryFn: () => api.get<{ data: Detail }>(`/finance/payments/${id}`),
    enabled: !!id,
  })
  const p = data?.data

  const voidMutation = useMutation({
    mutationFn: (reason: string) => api.post<{ message: string }>(`/finance/payments/${id}/void`, { reason }),
    onSuccess: (res) => {
      toast.success(res.message)
      setVoidOpen(false)
      qc.invalidateQueries({ queryKey: ['finance'] })
      qc.invalidateQueries({ queryKey: ['student'] })
      qc.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İptal edilemedi.'),
  })

  const draftInvoice = useMutation({
    mutationFn: () => api.post<{ message: string; ids: number[] }>('/finance/invoices/bulk-drafts', { payment_ids: [id] }),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance'] })
      if (res.ids[0]) navigate(`/finans/faturalar/${res.ids[0]}`)
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Taslak oluşturulamadı.'),
  })
  const applyCredit = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/finance/payments/${id}/apply-credit`),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Mahsup yapılamadı.'),
  })
  const activeInvoice = p?.invoices.some((i) => i.status !== 'cancelled')
  const hasActiveRefund = p?.refunds.some((r) => !r.voided_at)

  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      title={p ? `Makbuz ${p.receipt_no}` : 'Tahsilat'}
      description={p ? dateTime(p.paid_at) : undefined}
      width={520}
      footer={
        p && (
          <div className="flex w-full flex-wrap items-center gap-2">
            {can('finance.refund') && !p.voided_at && Number(p.refundable) > 0 && (
              <Button variant="warning" icon={<Undo2 className="size-4" />} onClick={() => setRefundOpen(true)}>İade</Button>
            )}
            {can('finance.invoice') && !p.voided_at && !activeInvoice && (
              <Button variant="info" icon={<FilePlus2 className="size-4" />} loading={draftInvoice.isPending} onClick={() => draftInvoice.mutate()}>Fatura taslağı</Button>
            )}
            {can('payments.void') && !p.voided_at && !hasActiveRefund && !p.invoices.some((i) => i.status === 'issued') && (
              <Button variant="danger-soft" icon={<Ban className="size-4" />} onClick={() => setVoidOpen(true)}>
                İptal et
              </Button>
            )}
            <div className="ml-auto flex gap-2">
              <Button icon={<ReceiptText className="size-4" />} onClick={() => openPdf(`/finance/documents/vouchers/payment/${p.id}.pdf`).catch((e) => toast.error(e.message))}>Dekont</Button>
              <Button icon={<Printer className="size-4" />} onClick={() => openPdf(`/finance/payments/${p.id}/receipt`).catch((e) => toast.error(e.message))}>Yazdır</Button>
              <Button variant="primary" icon={<FileText className="size-4" />} onClick={() => api.download(`/finance/payments/${p.id}/receipt`, undefined, `makbuz-${p.receipt_no}.pdf`).catch((e) => toast.error(e.message))}>Makbuz PDF</Button>
            </div>
          </div>
        )
      }
    >
      {isLoading || !p ? (
        <div className="space-y-3">
          <Skeleton className="h-24" />
          <Skeleton className="h-40" />
        </div>
      ) : (
        <div className="flex flex-col gap-5">
          {p.voided_at && (
            <Alert tone="danger" title="Bu tahsilat iptal edildi">
              {dateTime(p.voided_at)} · İptal eden: {p.voided_by ?? 'Yetkili'} · Gerekçe: {p.void_reason}
            </Alert>
          )}
          <div>
            <p className={cn('text-[30px] font-semibold tabular tracking-tight', p.voided_at && 'line-through text-ink-3')}>{money(p.amount)}</p>
            <p className="text-[12.5px] text-ink-3">{p.amount_words}</p>
          </div>
          <div className="rounded-[var(--radius-md)] ring-1 ring-line px-3.5 py-1.5 divide-y divide-line">
            <MetricRow label="Öğrenci" value={p.student ? <Link to={`/ogrenciler/${p.student.id}`} className="font-medium text-ink hover:underline">{p.student.full_name}</Link> : '—'} />
            <MetricRow label="Ödeyen" value={p.payer_name || p.guardian?.name ? <PersonText>{p.payer_name ?? p.guardian?.name}</PersonText> : '—'} />
            {p.enrollment && <MetricRow label="Kayıt no / program" value={<Link to={`/finans/kayitlar/${p.enrollment.id}`} className="hover:underline">{p.enrollment.enrollment_no}{p.enrollment.program ? ` · ${p.enrollment.program}` : ''}</Link>} />}
            <MetricRow label="Ödeme yöntemi" value={METHODS[p.method] ?? p.method} />
            <MetricRow label="Kasa / banka" value={p.account?.name ?? '—'} />
            {p.reference && <MetricRow label="Referans no" value={p.reference} />}
            <MetricRow label="Tahsil eden" value={p.received_by ?? '—'} />
            {p.note && <MetricRow label="Not" value={p.note} />}
          </div>

          {Number(p.credit) > 0 && !p.voided_at && (
            <Alert tone="success" title={`${money(p.credit)} dağıtılmamış avans`}
              action={can('payments.create') ? <Button size="xs" variant="success" icon={<WalletCards className="size-3.5" />} loading={applyCredit.isPending} onClick={() => applyCredit.mutate()}>Taksitlere mahsup et</Button> : undefined}>
              Fazla ödemeden kalan tutar; öğrencinin açık taksitlerine uygulanabilir ya da iade edilebilir.
            </Alert>
          )}
          {(p.invoices.length > 0 || p.refunds.length > 0 || p.card) && (
            <div className="rounded-[var(--radius-md)] ring-1 ring-line px-3.5 py-1.5 divide-y divide-line">
              {p.invoices.map((i) => (
                <MetricRow key={`i${i.id}`} label="Fatura" value={<Link to={`/finans/faturalar/${i.id}`} className="inline-flex items-center gap-1.5 hover:underline"><span className="tabular">{i.invoice_no ?? `Taslak #${i.id}`}</span><Badge tone={INVOICE_STATUS[i.status]?.tone}>{INVOICE_STATUS[i.status]?.label}</Badge><span className="tabular">{money(i.amount)}</span></Link>} />
              ))}
              {p.refunds.map((r) => (
                <MetricRow key={`r${r.id}`} label="İade" value={<span className={cn('inline-flex items-center gap-1.5', r.voided_at && 'line-through text-ink-3')}>
                  <button type="button" className="tabular hover:underline" onClick={() => openPdf(`/finance/refunds/${r.id}/pdf`).catch((e) => toast.error(e.message))}>{r.refund_no}</button>
                  <span className="tabular text-danger">−{money(r.amount)}</span></span>} />
              ))}
              {p.card && (
                <MetricRow label="Kart / POS" value={`Komisyon (%${Number(p.card.commission_rate)}): ${money(p.card.commission_amount)} · Net: ${money(p.card.net_amount)} · ${p.card.pos_settlement_id ? 'Bankaya yattı' : `Beklenen yatış: ${date(p.card.expected_deposit_date)}`}`} />
              )}
            </div>
          )}

          <div>
            <p className="mb-2 text-[12.5px] font-medium text-ink-2">Taksit dağılımı</p>
            {p.allocations.length === 0 ? (
              <p className="text-[13px] text-ink-3">Dağıtım yok.</p>
            ) : (
              <div className="overflow-x-auto rounded-[var(--radius-md)] ring-1 ring-line">
                <table className="tbl w-full text-[13px]">
                  <thead>
                    <tr className="border-b border-line bg-surface-2/60 text-[12px] text-ink-3">
                      <th className="h-8 px-3 font-medium text-left">Taksit</th>
                      <th className="px-3 font-medium text-center">Vade tarihi</th>
                      <th className="px-3 font-medium text-center">Bu ödemeden düşülen</th>
                    </tr>
                  </thead>
                  <tbody>
                    {p.allocations.map((a) => (
                      <tr key={a.installment_id} className="border-b border-line last:border-0">
                        <td className="px-3 py-2 text-left">
                          {a.sequence}. taksit <Badge tone={INSTALLMENT_STATUS[a.installment_status]?.tone ?? 'neutral'} className="ml-1">{INSTALLMENT_STATUS[a.installment_status]?.label ?? a.installment_status}</Badge>
                          <span className="block text-[12px] text-ink-3 tabular">Taksit tutarı: {money(a.installment_amount)}</span>
                        </td>
                        <td className="px-3 py-2 tabular text-center">{date(a.due_date)}</td>
                        <td className="px-3 py-2 tabular font-medium text-center">{money(a.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}

      <RefundDialog paymentId={p?.id ?? null} open={refundOpen} onClose={() => setRefundOpen(false)} />
      <VoidDialog
        open={voidOpen}
        onClose={() => setVoidOpen(false)}
        loading={voidMutation.isPending}
        onConfirm={(reason) => voidMutation.mutate(reason)}
        title="Tahsilatı iptal et"
        description={p ? `${p.receipt_no} · ${money(p.amount)} · ${p.student?.full_name ?? ''}` : undefined}
      >
        <Alert tone="warning" className="mb-3">
          Tahsilat silinmez. Taksit ödemeleri geri alınır ve hesaba ters kayıt atılır. Bu işlem geri alınamaz.
        </Alert>
      </VoidDialog>
    </Drawer>
  )
}
