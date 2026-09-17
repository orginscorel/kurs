import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, CheckCircle2, Download, FileText, ReceiptText, RotateCcw, Search, Undo2, X } from 'lucide-react'
import { api, ApiError, idempotencyKey, type Paginated } from '@/lib/api'
import { dateTime, money, num, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import { AccountSelect, DateRange, MetricRow, MoneyInput, useAccounts, VoidDialog } from './components'
import { fromCents, METHODS, openPdf, toCents } from './shared'
import type { RefundRow } from './ledger'

type ListResponse = Paginated<RefundRow> & { meta: { totals: { count: number; amount: string } } }
type Context = {
  payment_id: number
  receipt_no: string
  amount: string
  refundable: string
  credit: string
  invoiced: string
  voided: boolean
  method: string
  finance_account_id: number
  payer_name: string | null
  previous: RefundRow[]
}

export default function Refunds() {
  const can = useCan()
  const qc = useQueryClient()
  const list = useListState({})
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [voiding, setVoiding] = useState<RefundRow | null>(null)

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'refunds', list.query],
    queryFn: () => api.get<ListResponse>('/finance/refunds', list.query),
    placeholderData: keepPreviousData,
  })

  const voidMutation = useMutation({
    mutationFn: (reason: string) => api.post<{ message: string }>(`/finance/refunds/${voiding!.id}/void`, { reason }),
    onSuccess: (res) => {
      toast.success(res.message)
      setVoiding(null)
      qc.invalidateQueries({ queryKey: ['finance'] })
      qc.invalidateQueries({ queryKey: ['student'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İade iptal edilemedi.'),
  })

  const columns = useMemo<Column<RefundRow>[]>(
    () => [
      {
        key: 'refund',
        header: 'İade no / tarih',
        cell: (r) => (
          <div className="min-w-[130px]">
            <p className={cn('font-medium tabular', r.voided_at && 'line-through text-ink-3')}>{r.refund_no}</p>
            <p className="text-[12px] text-ink-3 tabular">{dateTime(r.refunded_at)}</p>
          </div>
        ),
      },
      {
        key: 'student',
        header: 'Öğrenci',
        cell: (r) =>
          r.student ? (
            <div className="min-w-[140px]">
              <Link to={`/ogrenciler/${r.student.id}`} onClick={(e) => e.stopPropagation()} className="block truncate font-medium hover:underline">{r.student.full_name}</Link>
              <p className="truncate text-[12px] text-ink-3">{r.payee_name ? `İadeyi alan: ${r.payee_name}` : `Öğrenci no: ${r.student.student_no}`}</p>
            </div>
          ) : '—',
      },
      {
        key: 'payment',
        header: 'İade edilen makbuz',
        cell: (r) =>
          r.payment ? (
            <Link to={`/finans/tahsilatlar?detay=${r.payment.id}`} className="tabular text-ink-2 hover:underline whitespace-nowrap">
              {r.payment.receipt_no}
            </Link>
          ) : '—',
      },
      { key: 'method', header: 'Ödeme yöntemi / hesap', hideable: true, cell: (r) => <span className="text-ink-2 whitespace-nowrap">{r.method_label}{r.account ? ` · ${r.account}` : ''}</span> },
      {
        key: 'split',
        header: 'İadenin kaynağı',
        hideable: true,
        cell: (r) => (
          <div className="text-[12px] tabular text-ink-2 whitespace-nowrap">
            {Number(r.from_credit) > 0 && <p>Avans: {money(r.from_credit)}</p>}
            {Number(r.from_installments) > 0 && <p>Taksit: {money(r.from_installments)}</p>}
            {Number(r.invoiced_portion) > 0 && <p className="text-warning">Faturalı: {money(r.invoiced_portion)}</p>}
          </div>
        ),
      },
      { key: 'reason', header: 'İade gerekçesi', maxWidth: 220, cell: (r) => <p className="truncate text-ink-2" title={r.reason}>{r.reason}</p> },
      { key: 'status', header: 'Durum', cell: (r) => (r.voided_at ? <Badge tone="danger">İptal edildi</Badge> : <Badge tone="success" dot>Geçerli</Badge>) },
      {
        key: 'amount',
        header: 'İade tutarı',
        align: 'right',
        cell: (r) => <span className={cn('font-semibold tabular whitespace-nowrap', r.voided_at && 'line-through text-ink-3 font-normal')}>{money(r.amount)}</span>,
      },
      {
        key: 'actions',
        header: '',
        cell: (r) => (
          <div className="flex items-center justify-end gap-1">
            <Button size="xs" variant="ghost" icon={<FileText className="size-3.5" />} onClick={() => openPdf(`/finance/refunds/${r.id}/pdf`).catch((e) => toast.error(e.message))}>PDF</Button>
            {can('finance.refund') && !r.voided_at && (
              <Button size="xs" variant="danger-soft" icon={<Ban className="size-3.5" />} onClick={() => setVoiding(r)}>İptal</Button>
            )}
          </div>
        ),
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [can],
  )

  const t = data?.meta.totals

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="İadeler"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'İadeler' }]}
        description={t ? `${num(t.count)} iade · geçerli toplam ${money(t.amount)}` : 'Tahsilat iadeleri ve iade makbuzları'}
        actions={<ButtonLink to="/finans/tahsilatlar" icon={<ReceiptText className="size-4" />}>Tahsilatlar</ButtonLink>}
      />
      <Alert tone="info" className="mb-3" action={<ButtonLink size="sm" to="/finans/tahsilatlar">Tahsilatlara git</ButtonLink>}>
        Yeni iade, Tahsilatlar ekranında makbuz ayrıntısından yapılır.
      </Alert>

      <DataTable
        storageKey="finance-refunds"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        toolbar={
          <>
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="İade no ya da öğrenci"
              leading={<Search />}
              trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
              className="w-full sm:w-[240px]"
            />
            <Segmented
              size="sm"
              value={list.filters.status ?? 'all'}
              onChange={(v) => list.update({ filters: { status: v === 'all' ? null : v } })}
              options={[{ value: 'all', label: 'Tümü' }, { value: 'active', label: 'Geçerli' }, { value: 'voided', label: 'İptal edilen' }]}
            />
            <DateRange label="İade tarihi" from={list.filters.from} to={list.filters.to} onChange={(k, v) => list.update({ filters: { [k]: v } })} />
          </>
        }
        empty={<EmptyState icon={<Undo2 />} title="İade yok" description="Yapılan iadeler burada listelenir." />}
      />

      <VoidDialog
        open={!!voiding}
        onClose={() => setVoiding(null)}
        loading={voidMutation.isPending}
        onConfirm={(reason) => voidMutation.mutate(reason)}
        title="İadeyi iptal et"
        description={voiding ? `${voiding.refund_no} · ${money(voiding.amount)} · ${voiding.student?.full_name ?? ''}` : undefined}
      >
        <Alert tone="warning" className="mb-3">
          Tutar hesaba geri alınır, taksitlerden geri alınan kısım yeniden ödenmiş sayılır. Muhasebe fişi ters kayıtla kapanır.
        </Alert>
      </VoidDialog>
    </div>
  )
}

const nowLocal = () => {
  const d = new Date()
  return `${todayISO()}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

/** Makbuzdan iade penceresi. */
export function RefundDialog({ paymentId, open, onClose, onDone }: { paymentId: number | null; open: boolean; onClose: () => void; onDone?: (refund: RefundRow) => void }) {
  const qc = useQueryClient()
  const accounts = useAccounts()
  const key = useRef(idempotencyKey())
  const [amount, setAmount] = useState('')
  const [accountId, setAccountId] = useState('')
  const [method, setMethod] = useState('cash')
  const [when, setWhen] = useState(nowLocal())
  const [payee, setPayee] = useState('')
  const [reference, setReference] = useState('')
  const [reason, setReason] = useState('')
  const [confirm, setConfirm] = useState(false)
  const [done, setDone] = useState<RefundRow | null>(null)

  const ctx = useQuery({
    queryKey: ['finance', 'refund-context', paymentId],
    queryFn: () => api.get<{ data: Context }>(`/finance/payments/${paymentId}/refund-context`),
    enabled: open && !!paymentId,
  })
  const c = ctx.data?.data

  useEffect(() => {
    if (!open) return
    key.current = idempotencyKey()
    setDone(null)
    setConfirm(false)
    setReason('')
    setReference('')
    setWhen(nowLocal())
  }, [open, paymentId])

  useEffect(() => {
    if (!c || !open) return
    setAmount(c.refundable.replace('.', ','))
    setAccountId(String(c.finance_account_id))
    setMethod(c.method)
    setPayee(c.payer_name ?? '')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [c?.payment_id, open])

  const cents = toCents(amount)
  const refundableCents = toCents(c?.refundable) ?? 0
  const creditCents = toCents(c?.credit) ?? 0
  const exceeds = cents !== null && cents > refundableCents
  const fromCredit = cents ? Math.min(cents, creditCents) : 0
  const fromInst = cents ? Math.max(0, cents - fromCredit) : 0
  const valid = !!c && !c.voided && cents !== null && cents > 0 && !exceeds && !!accountId && reason.trim().length >= 5

  const save = useMutation({
    mutationFn: () =>
      api.post<{ message: string; data: RefundRow }>(`/finance/payments/${paymentId}/refunds`, {
        amount: fromCents(cents!),
        finance_account_id: Number(accountId),
        method,
        refunded_at: when ? when.replace('T', ' ') + ':00' : null,
        reason: reason.trim(),
        payee_name: payee || null,
        reference: reference || null,
        idempotency_key: key.current,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      setConfirm(false)
      setDone(res.data)
      qc.invalidateQueries({ queryKey: ['finance'] })
      qc.invalidateQueries({ queryKey: ['student'] })
      onDone?.(res.data)
    },
    onError: (e) => {
      setConfirm(false)
      toast.error(e instanceof ApiError ? e.firstError() : 'İade kaydedilemedi.')
    },
  })

  return (
    <>
      <Modal
        open={open && !confirm}
        onClose={onClose}
        title={done ? 'İade kaydedildi' : `İade — ${c?.receipt_no ?? ''}`}
        description={!done && c ? `Tahsilat ${money(c.amount)} · iade edilebilir ${money(c.refundable)}` : undefined}
        footer={
          done ? (
            <>
              <Button icon={<Download className="size-4" />} onClick={() => api.download(`/finance/refunds/${done.id}/pdf`, undefined, `iade-${done.refund_no}.pdf`).catch((e) => toast.error(e.message))}>PDF indir</Button>
              <Button variant="primary" icon={<FileText className="size-4" />} onClick={() => openPdf(`/finance/refunds/${done.id}/pdf`).catch((e) => toast.error(e.message))}>İade makbuzu PDF</Button>
            </>
          ) : (
            <>
              <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
              <Button variant="danger" disabled={!valid} icon={<RotateCcw className="size-4" />} onClick={() => setConfirm(true)}>
                {cents ? `${money(cents / 100)} iade et` : 'İade et'}
              </Button>
            </>
          )
        }
      >
        {ctx.isLoading || !c ? (
          <Skeleton className="h-48" />
        ) : done ? (
          <div className="flex flex-col items-center py-2 text-center">
            <span className="grid size-12 place-items-center rounded-full bg-success-soft text-success"><CheckCircle2 className="size-6" /></span>
            <p className="mt-3 text-[26px] font-semibold tabular">{money(done.amount)}</p>
            <p className="text-[12.5px] text-ink-3 tabular">İade no {done.refund_no} · {done.method_label}{done.account ? ` · ${done.account}` : ''}</p>
          </div>
        ) : c.voided ? (
          <Alert tone="danger">Bu tahsilat iptal edilmiş; iade yapılamaz.</Alert>
        ) : refundableCents === 0 ? (
          <Alert tone="info">Bu tahsilatın tamamı iade edilmiş.</Alert>
        ) : (
          <div className="flex flex-col gap-3.5">
            {Number(c.invoiced) > 0 && <Alert tone="warning">Bu tahsilat faturalanmış ({money(c.invoiced)}); gerekiyorsa iade faturası da düzenleyin.</Alert>}
            <Field label="İade tutarı" required error={exceeds ? `En çok ${money(c.refundable)} iade edilebilir.` : null}>
              <MoneyInput size="lg" value={amount} onChange={setAmount} invalid={exceeds} />
            </Field>
            {cents !== null && cents > 0 && !exceeds && (
              <div className="rounded-[var(--radius-md)] bg-surface-2/70 px-3 py-1.5 ring-1 ring-line">
                {fromCredit > 0 && <MetricRow label="Avanstan (fazla ödeme)" value={money(fromCredit / 100)} />}
                {fromInst > 0 && <MetricRow label="Taksitlerden geri alınır" value={money(fromInst / 100)} tone="warning" />}
                {fromInst > 0 && <p className="pb-1 text-[12px] text-ink-3">Taksitlerden geri alınan tutar kadar öğrencinin borcu yeniden açılır (en geç vadeli taksitten başlayarak).</p>}
              </div>
            )}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label="Paranın çıktığı kasa / banka" required hint="Kasada bakiye yetmezse iade yapılamaz">
                <AccountSelect accounts={accounts.data?.data} value={accountId} onChange={setAccountId} />
              </Field>
              <Field label="Ödeme yöntemi" required>
                <Select value={method} onChange={(e) => setMethod(e.target.value)} options={Object.entries(METHODS).map(([value, label]) => ({ value, label }))} />
              </Field>
              <Field label="İade tarihi ve saati" optional hint="Boş bırakılırsa şu an">
                <Input type="datetime-local" value={when} max={nowLocal()} onChange={(e) => setWhen(e.target.value)} />
              </Field>
              <Field label="İadeyi alan kişi" optional>
                <Input value={payee} onChange={(e) => setPayee(e.target.value)} maxLength={160} />
              </Field>
            </div>
            <Field label="Referans no" optional hint="Dekont / işlem no">
              <Input value={reference} onChange={(e) => setReference(e.target.value)} maxLength={120} />
            </Field>
            <Field label="İade gerekçesi" required hint="En az 5 karakter" error={reason && reason.trim().length < 5 ? 'Gerekçe en az 5 karakter olmalı.' : null}>
              <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} placeholder="Örn. Kayıt iptali, fazla ödeme" />
            </Field>
            {c.previous.length > 0 && (
              <p className="text-[12px] text-ink-3">
                Önceki iadeler: {c.previous.map((p) => `${p.refund_no} ${money(p.amount)}${p.voided_at ? ' (iptal)' : ''}`).join(' · ')}
              </p>
            )}
          </div>
        )}
      </Modal>
      <ConfirmDialog
        open={open && confirm}
        onClose={() => setConfirm(false)}
        onConfirm={() => save.mutate()}
        loading={save.isPending}
        danger
        title="İadeyi onaylayın"
        confirmLabel="İadeyi kaydet"
        description={cents ? `${money(cents / 100)} ${c?.receipt_no ?? ''} makbuzundan iade edilecek ve hesaptan düşülecek.` : undefined}
      >
        <Alert tone="warning">İade kaydı değiştirilemez; hata olursa iptal edilir.</Alert>
      </ConfirmDialog>
    </>
  )
}
