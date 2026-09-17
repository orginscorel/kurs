import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlarmClock, Ban, CheckCheck, CreditCard, Landmark, Link2, Scale, Unlink } from 'lucide-react'
import { api, ApiError, idempotencyKey, type Paginated } from '@/lib/api'
import { date, dateTime, money, num, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat, Tabs } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import { DateRange, MoneyInput, useAccounts } from './components'
import { fromCents, toCents } from './shared'

type PendingGroup = { date: string; account_id: number; count: number; gross: string; net: string; commission: string; overdue: boolean }
type Settlement = { id: number; deposit_date: string; pos: string | null; bank: string | null; gross: string; commission: string; net: string; expected_net: string; difference: string; count: number; reference: string | null; voided_at: string | null; void_reason: string | null }
type BankSummary = { id: number; name: string; balance: string; open_count: number; open_amount: string; total: number }
type Overview = {
  data: {
    pending: { groups: PendingGroup[]; total: { count: number; gross: string; net: string; overdue_count: number; overdue_net: string } }
    settlements: Settlement[]
    bank: BankSummary[]
  }
}
type CardRow = { id: number; payment_id: number; receipt_no: string; paid_at: string; student: string; amount: string; commission_rate: string; commission: string; net: string; expected_date: string; installments: number; method_label: string; account_id: number; settlement_id: number | null; overdue: boolean }
type BankRow = { id: number; amount: string; balance_after: string; description: string; occurred_at: string; source_type: string; matched: boolean; statement_date: string | null; statement_ref: string | null }

const errMsg = (e: unknown, fallback: string) => (e instanceof ApiError ? e.firstError() : fallback)
const cents = (v: string | null | undefined) => toCents(v) ?? 0

type TabKey = 'pos' | 'banka'

export default function Reconciliation() {
  const [params, setParams] = useSearchParams()
  const tab = (params.get('sekme') as TabKey) || 'pos'
  const overview = useQuery({ queryKey: ['finance', 'reconciliation', 'overview'], queryFn: () => api.get<Overview>('/finance/reconciliation') })
  const o = overview.data?.data
  const lastActive = o?.settlements.find((s) => !s.voided_at)
  const bankOpen = (o?.bank ?? []).reduce((s, b) => s + b.open_count, 0)

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Banka ve POS mutabakatı"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Mutabakat' }]}
        description="Kart tahsilatlarının bankaya yatışını eşleştirin, banka hareketlerini ekstreyle karşılaştırın"
      />
      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Bekleyen POS yatışı" icon={<CreditCard />} loading={overview.isLoading} value={money(o?.pending.total.net, { short: true })} sub={`${num(o?.pending.total.count)} kart işlemi · brüt ${money(o?.pending.total.gross, { short: true })}`} />
        <Stat
          label="Valörü geçmiş"
          icon={<AlarmClock />}
          loading={overview.isLoading}
          tone={(o?.pending.total.overdue_count ?? 0) > 0 ? 'danger' : undefined}
          value={money(o?.pending.total.overdue_net, { short: true })}
          sub={`${num(o?.pending.total.overdue_count)} işlem bankaya yatmış olmalıydı`}
        />
        <Stat label="Eşleşmeyen banka hareketi" icon={<Landmark />} loading={overview.isLoading} tone={bankOpen > 0 ? 'warning' : undefined} value={num(bankOpen)} sub="ekstrede işaretlenmemiş" />
        <Stat
          label="Son yatış farkı"
          icon={<Scale />}
          loading={overview.isLoading}
          tone={lastActive && cents(lastActive.difference) !== 0 ? 'warning' : undefined}
          value={lastActive ? money(lastActive.difference) : '—'}
          sub={lastActive ? `${date(lastActive.deposit_date)} · beklenen ${money(lastActive.expected_net, { short: true })}` : 'henüz eşleştirilmiş yatış yok'}
        />
      </div>

      <Tabs<TabKey>
        className="mb-4"
        value={tab}
        onChange={(t) => setParams((p) => { p.set('sekme', t); p.delete('page'); return p }, { replace: true })}
        tabs={[
          { value: 'pos', label: 'POS yatışları', count: o?.pending.total.count ?? null },
          { value: 'banka', label: 'Banka hareketleri', count: o ? bankOpen : null },
        ]}
      />

      {tab === 'pos' ? <PosTab settlements={o?.settlements} loading={overview.isLoading} /> : <BankTab banks={o?.bank} />}
    </div>
  )
}

/* ================================================================== POS */

function PosTab({ settlements, loading }: { settlements?: Settlement[]; loading: boolean }) {
  const can = useCan()
  const qc = useQueryClient()
  const accounts = useAccounts()
  const posAccounts = (accounts.data?.data ?? []).filter((a) => a.kind === 'pos' && a.is_active)
  const [accountId, setAccountId] = useState('')
  const [until, setUntil] = useState('')
  const [selected, setSelected] = useState<Set<string | number>>(new Set())
  const [settleOpen, setSettleOpen] = useState(false)
  const [voiding, setVoiding] = useState<Settlement | null>(null)

  const query = { account_id: accountId || undefined }
  const cards = useQuery({
    queryKey: ['finance', 'reconciliation', 'cards', query],
    queryFn: () => api.get<{ data: CardRow[] }>('/finance/reconciliation/cards', query),
    placeholderData: keepPreviousData,
  })
  const rows = useMemo(() => (cards.data?.data ?? []).filter((r) => !until || r.expected_date <= until), [cards.data, until])
  const chosen = (cards.data?.data ?? []).filter((r) => selected.has(r.id))
  const sum = chosen.reduce((s, r) => ({ gross: s.gross + cents(r.amount), net: s.net + cents(r.net) }), { gross: 0, net: 0 })
  const chosenAccounts = [...new Set(chosen.map((r) => r.account_id))]

  useEffect(() => setSelected(new Set()), [accountId])

  const columns = useMemo<Column<CardRow>[]>(
    () => [
      {
        key: 'receipt',
        header: 'Makbuz no / tarih',
        cell: (r) => (
          <div className="min-w-[120px]">
            <p className="font-medium tabular">{r.receipt_no}</p>
            <p className="text-[12px] text-ink-3 tabular">{dateTime(r.paid_at)}</p>
          </div>
        ),
      },
      { key: 'student', header: 'Öğrenci', cell: (r) => <span className="text-ink-2">{r.student}</span> },
      { key: 'method', header: 'Ödeme yöntemi', hideable: true, cell: (r) => <span className="text-ink-2 whitespace-nowrap">{r.method_label}{r.installments > 1 ? ` · ${r.installments} taksit` : ''}</span> },
      { key: 'gross', header: 'Çekilen tutar (brüt)', align: 'right', cell: (r) => <span className="tabular">{money(r.amount)}</span> },
      {
        key: 'commission',
        header: 'Komisyon',
        align: 'right',
        cell: (r) => (
          <span className="tabular text-ink-2 whitespace-nowrap">
            {money(r.commission)} <span className="text-[12px] text-ink-3">%{r.commission_rate.replace('.', ',')}</span>
          </span>
        ),
      },
      { key: 'net', header: 'Bankaya geçecek net', align: 'right', cell: (r) => <span className="font-semibold tabular">{money(r.net)}</span> },
      {
        key: 'expected',
        header: 'Beklenen yatış',
        cell: (r) => (
          <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
            <span className="tabular">{date(r.expected_date)}</span>
            {r.overdue && <Badge tone="danger">valör geçti</Badge>}
          </span>
        ),
      },
    ],
    [],
  )

  const today = todayISO()

  return (
    <div className="flex flex-col gap-4">
      <Alert tone="info">Komisyon, POS hesabından "POS komisyonu" gideri olarak yazılır; transfer ve gider fişleri otomatik kesilir.</Alert>

      <DataTable
        storageKey="finance-recon-cards"
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        loading={cards.isLoading || cards.isFetching}
        selectable={can('finance.reconcile')}
        selected={selected}
        onSelectedChange={setSelected}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Select className="w-full sm:w-[200px]" value={accountId} onChange={(e) => setAccountId(e.target.value)} placeholder="Tüm POS hesapları" options={posAccounts.map((a) => ({ value: a.id, label: a.name }))} />
            <div className="flex items-center gap-1.5">
              <span className="text-[12.5px] text-ink-3">Beklenen yatış tarihi (bu güne kadar)</span>
              <Input type="date" aria-label="Bu tarihe kadar" value={until} onChange={(e) => setUntil(e.target.value)} className="w-[150px]" />
            </div>
            {can('finance.reconcile') && (
              <Button size="sm" variant="info" icon={<CheckCheck className="size-3.5" />} onClick={() => setSelected(new Set(rows.filter((r) => r.expected_date <= (until || today)).map((r) => r.id)))}>
                {until ? 'Tarihe kadar olanları seç' : 'Valörü gelenleri seç'}
              </Button>
            )}
          </div>
        }
        bulkActions={
          <>
            <span className="text-[12.5px] text-ink-2 tabular">brüt {money(sum.gross / 100)} · beklenen net <strong>{money(sum.net / 100)}</strong></span>
            <Button size="sm" variant="primary" icon={<Link2 className="size-3.5" />} disabled={chosenAccounts.length !== 1} onClick={() => setSettleOpen(true)}>Yatışı eşleştir</Button>
          </>
        }
        empty={<EmptyState icon={<CreditCard />} title="Eşleşme bekleyen kart tahsilatı yok" description="Kredi kartı / POS tahsilatları bankaya yatınca burada eşleştirilir." />}
      />
      {chosen.length > 0 && chosenAccounts.length > 1 && <Alert tone="warning">Seçilen işlemler farklı POS hesaplarına ait; bir yatış tek POS hesabıyla eşleştirilir. POS hesabı filtresini kullanın.</Alert>}

      {chosen.length > 0 && (
        <div className="sticky bottom-3 z-20 flex flex-wrap items-center gap-3 rounded-[var(--radius-lg)] bg-surface px-4 py-3 shadow-[var(--shadow-pop)] ring-1 ring-line">
          <span className="text-[13px] font-medium">{chosen.length} işlem seçildi</span>
          <span className="text-[13px] text-ink-2 tabular">Brüt {money(sum.gross / 100)}</span>
          <span className="text-[13px] tabular">Beklenen net <strong>{money(sum.net / 100)}</strong></span>
          <Button className="ml-auto" variant="primary" icon={<Link2 className="size-4" />} disabled={chosenAccounts.length !== 1} onClick={() => setSettleOpen(true)}>Yatışı eşleştir</Button>
        </div>
      )}

      <Panel title="Son yatışlar" description="Eşleştirilmiş POS → banka yatışları" flush>
        {loading ? (
          <div className="px-4 pb-4"><Skeleton className="h-32" /></div>
        ) : !settlements?.length ? (
          <EmptyState compact icon={<Landmark />} title="Henüz eşleştirilmiş yatış yok" />
        ) : (
          <div className="overflow-x-auto scroll-thin border-t border-line">
            <table className="tbl w-full min-w-[760px] text-[13px]">
              <thead>
                <tr className="border-b border-line bg-surface-2/60 text-[12px] text-ink-3">
                  <th className="h-9 px-4 font-medium text-left">Yatış tarihi</th>
                  <th className="px-3 font-medium text-center">POS → banka</th>
                  <th className="px-3 font-medium text-center">İşlem sayısı</th>
                  <th className="px-3 font-medium text-center">Brüt tutar</th>
                  <th className="px-3 font-medium text-center">Komisyon</th>
                  <th className="px-3 font-medium text-center">Net tutar</th>
                  <th className="px-3 font-medium text-center">Beklenenden fark</th>
                  <th className="px-4 text-center" />
                </tr>
              </thead>
              <tbody>
                {settlements.map((s) => (
                  <tr key={s.id} className={cn('border-b border-line last:border-0', s.voided_at && 'opacity-60')}>
                    <td className="px-4 py-2.5 text-left">
                      <p className="tabular font-medium">{date(s.deposit_date)}</p>
                      {s.reference && <p className="text-[12px] text-ink-3">Referans: {s.reference}</p>}
                    </td>
                    <td className="px-3 py-2.5 text-ink-2 whitespace-nowrap text-center">{s.pos} → {s.bank}</td>
                    <td className="px-3 py-2.5 tabular text-center">{num(s.count)}</td>
                    <td className="px-3 py-2.5 tabular text-center">{money(s.gross)}</td>
                    <td className="px-3 py-2.5 tabular text-ink-2 text-center">{money(s.commission)}</td>
                    <td className="px-3 py-2.5 tabular font-semibold text-center">{money(s.net)}</td>
                    <td className="px-3 py-2.5 text-center">
                      {s.voided_at ? <Badge tone="danger">İptal edildi</Badge> : cents(s.difference) === 0 ? <Badge tone="success">Fark yok</Badge> : <Badge tone="warning">{money(s.difference)}</Badge>}
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      {can('finance.reconcile') && !s.voided_at && (
                        <Button size="xs" variant="danger-soft" icon={<Ban className="size-3.5" />} onClick={() => setVoiding(s)}>İptal</Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <SettleModal
        open={settleOpen}
        onClose={() => setSettleOpen(false)}
        posAccountId={chosenAccounts[0] ?? null}
        detailIds={chosen.map((r) => r.id)}
        gross={sum.gross}
        expectedNet={sum.net}
        onDone={() => {
          setSelected(new Set())
          setSettleOpen(false)
          qc.invalidateQueries({ queryKey: ['finance'] })
        }}
      />
      <VoidSettlement settlement={voiding} onClose={() => setVoiding(null)} />
    </div>
  )
}

function SettleModal({ open, onClose, posAccountId, detailIds, gross, expectedNet, onDone }: {
  open: boolean; onClose: () => void; posAccountId: number | null; detailIds: number[]; gross: number; expectedNet: number; onDone: () => void
}) {
  const accounts = useAccounts()
  const all = (accounts.data?.data ?? []).filter((a) => a.is_active)
  const [bankId, setBankId] = useState('')
  const [posId, setPosId] = useState('')
  const [depositDate, setDepositDate] = useState(todayISO())
  const [net, setNet] = useState('')
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const key = useRef(idempotencyKey())

  useEffect(() => {
    if (!open) return
    key.current = idempotencyKey()
    setPosId(posAccountId ? String(posAccountId) : '')
    setBankId((b) => b || String(all.find((a) => a.kind === 'bank')?.id ?? ''))
    setDepositDate(todayISO())
    setNet(fromCents(expectedNet).replace('.', ','))
    setReference('')
    setNote('')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const netCents = toCents(net)
  const commission = netCents === null ? null : gross - netCents
  const diff = netCents === null ? null : netCents - expectedNet
  const invalid = netCents === null || netCents <= 0 || netCents > gross || !posId || !bankId

  const save = useMutation({
    mutationFn: () =>
      api.post<{ message: string }>('/finance/reconciliation/pos-settlements', {
        pos_account_id: Number(posId),
        bank_account_id: Number(bankId),
        deposit_date: depositDate,
        actual_net: fromCents(netCents!),
        detail_ids: detailIds,
        reference: reference || null,
        note: note || null,
        idempotency_key: key.current,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      onDone()
    },
    onError: (e) => toast.error(errMsg(e, 'Yatış eşleştirilemedi.')),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="POS yatışını eşleştir"
      description={`${detailIds.length} kart işlemi · brüt ${money(gross / 100)} · beklenen net ${money(expectedNet / 100)}`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" disabled={invalid} loading={save.isPending} onClick={() => save.mutate()}>Eşleştir ve kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-3.5">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="POS hesabı" required>
            <Select value={posId} onChange={(e) => setPosId(e.target.value)} placeholder="Seçin" options={all.filter((a) => a.kind === 'pos').map((a) => ({ value: a.id, label: a.name }))} />
          </Field>
          <Field label="Yatışın geldiği banka" required>
            <Select value={bankId} onChange={(e) => setBankId(e.target.value)} placeholder="Seçin" options={all.filter((a) => a.kind === 'bank').map((a) => ({ value: a.id, label: a.name }))} />
          </Field>
          <Field label="Yatış tarihi" required>
            <Input type="date" value={depositDate} max={todayISO()} onChange={(e) => setDepositDate(e.target.value)} />
          </Field>
          <Field label="Bankaya yatan net tutar" required hint="Banka ekstresindeki tutar" error={netCents !== null && netCents > gross ? 'Net tutar brüt toplamı aşamaz.' : null}>
            <MoneyInput value={net} onChange={setNet} />
          </Field>
        </div>
        <div className="grid grid-cols-3 gap-2 rounded-[var(--radius-md)] ring-1 ring-line px-3 py-2 text-[13px]">
          <div><p className="text-[12px] text-ink-3">Brüt</p><p className="font-semibold tabular">{money(gross / 100)}</p></div>
          <div><p className="text-[12px] text-ink-3">Komisyon (brüt − net)</p><p className="font-semibold tabular">{commission === null ? '—' : money(commission / 100)}</p></div>
          <div>
            <p className="text-[12px] text-ink-3">Beklenenden fark</p>
            <p className={cn('font-semibold tabular', diff !== null && diff !== 0 && 'text-warning', diff === 0 && 'text-success')}>{diff === null ? '—' : money(diff / 100)}</p>
          </div>
        </div>
        {diff !== null && diff !== 0 && (
          <Alert tone="warning">Yatan tutar beklenen netten {money(Math.abs(diff) / 100)} {diff < 0 ? 'az' : 'fazla'}. Gerçek komisyon farkı gider olarak yazılır; banka ekstresini kontrol edin.</Alert>
        )}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Referans no" optional hint="Ekstre / dekont no">
            <Input value={reference} maxLength={120} onChange={(e) => setReference(e.target.value)} />
          </Field>
          <Field label="Not" optional>
            <Input value={note} maxLength={300} onChange={(e) => setNote(e.target.value)} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}

function ReasonConfirm({ open, onClose, title, description, loading, confirmLabel, onConfirm, children }: {
  open: boolean; onClose: () => void; title: string; description?: ReactNode; loading?: boolean; confirmLabel: string; onConfirm: (reason: string) => void; children?: ReactNode
}) {
  const [reason, setReason] = useState('')
  useEffect(() => {
    if (open) setReason('')
  }, [open])
  const short = reason.trim().length < 5
  return (
    <ConfirmDialog open={open} onClose={onClose} title={title} description={description} danger loading={loading} confirmLabel={confirmLabel} onConfirm={() => !short && onConfirm(reason.trim())}>
      {children}
      <Field label="Gerekçe" required hint="Denetim kaydına yazılır; en az 5 karakter." error={reason && short ? 'Gerekçe en az 5 karakter olmalı.' : null}>
        <Textarea autoFocus rows={3} value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} />
      </Field>
    </ConfirmDialog>
  )
}

function VoidSettlement({ settlement, onClose }: { settlement: Settlement | null; onClose: () => void }) {
  const qc = useQueryClient()
  const m = useMutation({
    mutationFn: (reason: string) => api.post<{ message: string }>(`/finance/reconciliation/pos-settlements/${settlement!.id}/void`, { reason }),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance'] })
      onClose()
    },
    onError: (e) => toast.error(errMsg(e, 'İptal edilemedi.')),
  })
  return (
    <ReasonConfirm
      open={!!settlement}
      onClose={onClose}
      title="Yatış eşleştirmesini iptal et"
      description={settlement ? `${date(settlement.deposit_date)} · ${settlement.pos} → ${settlement.bank} · net ${money(settlement.net)}` : undefined}
      confirmLabel="Eşleştirmeyi iptal et"
      loading={m.isPending}
      onConfirm={(r) => m.mutate(r)}
    >
      <Alert tone="warning" className="mb-3">Banka transferi ve komisyon gideri ters kayıtla geri alınır; kart işlemleri yeniden "bekliyor" olur.</Alert>
    </ReasonConfirm>
  )
}

/* ================================================================== Banka */

type BankResponse = Paginated<BankRow> & { meta: { account_id: number | null } }

function BankTab({ banks }: { banks?: BankSummary[] }) {
  const can = useCan()
  const qc = useQueryClient()
  const [accountId, setAccountId] = useState('')
  const [state, setState] = useState<'open' | 'matched' | 'all'>('open')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<Set<string | number>>(new Set())
  const [markOpen, setMarkOpen] = useState(false)
  const [statementDate, setStatementDate] = useState(todayISO())
  const [statementRef, setStatementRef] = useState('')

  useEffect(() => {
    if (!accountId && banks?.[0]) setAccountId(String(banks[0].id))
  }, [banks, accountId])
  useEffect(() => {
    setPage(1)
    setSelected(new Set())
  }, [accountId, state, from, to])

  const query = { account_id: accountId || undefined, state: state === 'all' ? undefined : state, from: from || undefined, to: to || undefined, page }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'reconciliation', 'bank', query],
    queryFn: () => api.get<BankResponse>('/finance/reconciliation/bank', query),
    placeholderData: keepPreviousData,
    enabled: !!accountId || !banks?.length,
  })

  const done = (res: { message: string }) => {
    toast.success(res.message)
    setSelected(new Set())
    setMarkOpen(false)
    qc.invalidateQueries({ queryKey: ['finance', 'reconciliation'] })
  }
  const mark = useMutation({
    mutationFn: () => api.post<{ message: string }>('/finance/reconciliation/bank/mark', { transaction_ids: [...selected], statement_date: statementDate, statement_ref: statementRef || null }),
    onSuccess: done,
    onError: (e) => toast.error(errMsg(e, 'İşaretlenemedi.')),
  })
  const unmark = useMutation({
    mutationFn: () => api.post<{ message: string }>('/finance/reconciliation/bank/unmark', { transaction_ids: [...selected] }),
    onSuccess: done,
    onError: (e) => toast.error(errMsg(e, 'İşaret kaldırılamadı.')),
  })

  const columns = useMemo<Column<BankRow>[]>(
    () => [
      { key: 'date', header: 'Hareket tarihi', cell: (r) => <span className="tabular whitespace-nowrap">{dateTime(r.occurred_at)}</span> },
      { key: 'description', header: 'Açıklama', maxWidth: 360, cell: (r) => <span className="text-ink-2 line-clamp-2">{r.description}</span> },
      {
        key: 'amount',
        header: 'Tutar',
        align: 'right',
        cell: (r) => <span className={cn('font-semibold tabular whitespace-nowrap', cents(r.amount) >= 0 ? 'text-success' : 'text-danger')}>{cents(r.amount) > 0 ? '+' : ''}{money(r.amount)}</span>,
      },
      { key: 'balance', header: 'Hareket sonrası bakiye', align: 'right', hideable: true, cell: (r) => <span className="tabular text-ink-2 whitespace-nowrap">{money(r.balance_after)}</span> },
      {
        key: 'state',
        header: 'Durum',
        cell: (r) =>
          r.matched ? (
            <Badge tone="success" dot>Eşleşti {r.statement_date ? date(r.statement_date) : ''}{r.statement_ref ? ` · ${r.statement_ref}` : ''}</Badge>
          ) : (
            <Badge tone="warning" dot>Bekliyor</Badge>
          ),
      },
    ],
    [],
  )

  const current = banks?.find((b) => String(b.id) === accountId)

  if (banks && banks.length === 0) {
    return (
      <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
        <EmptyState icon={<Landmark />} title="Banka hesabı hareketi yok" description="Banka türünde hesap açıldığında ve hareket oluştuğunda burada eşleştirebilirsiniz." />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-3">
      {current && (
        <p className="px-1 text-[12.5px] text-ink-3">
          {current.name}: bakiye <strong className="text-ink tabular">{money(current.balance)}</strong> · eşleşmeyen {num(current.open_count)} hareket ({money(current.open_amount)}) · toplam {num(current.total)}
        </p>
      )}
      <DataTable
        storageKey="finance-recon-bank"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={setPage}
        selectable={can('finance.reconcile')}
        selected={selected}
        onSelectedChange={setSelected}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Select className="w-full sm:w-[220px]" value={accountId} onChange={(e) => setAccountId(e.target.value)} options={(banks ?? []).map((b) => ({ value: b.id, label: b.name }))} />
            <Segmented size="sm" value={state} onChange={setState} options={[{ value: 'open', label: 'Bekleyen' }, { value: 'matched', label: 'Eşleşen' }, { value: 'all', label: 'Tümü' }]} />
            <DateRange label="Hareket tarihi" from={from} to={to} onChange={(k, v) => (k === 'from' ? setFrom(v ?? '') : setTo(v ?? ''))} />
          </div>
        }
        bulkActions={
          <>
            <Button size="sm" variant="success" icon={<CheckCheck className="size-3.5" />} onClick={() => { setStatementDate(todayISO()); setStatementRef(''); setMarkOpen(true) }}>Ekstrede görüldü</Button>
            <Button size="sm" variant="danger-soft" icon={<Unlink className="size-3.5" />} loading={unmark.isPending} onClick={() => unmark.mutate()}>Eşleşmeyi kaldır</Button>
          </>
        }
        empty={<EmptyState icon={<Landmark />} title={state === 'open' ? 'Eşleşme bekleyen hareket yok' : 'Hareket yok'} description="Filtreleri değiştirerek tekrar deneyin." />}
      />

      <Modal
        open={markOpen}
        onClose={() => setMarkOpen(false)}
        size="sm"
        title="Ekstrede görüldü olarak işaretle"
        description={`${selected.size} hareket`}
        footer={
          <>
            <Button variant="ghost" onClick={() => setMarkOpen(false)}>Vazgeç</Button>
            <Button variant="primary" disabled={!statementDate} loading={mark.isPending} onClick={() => mark.mutate()}>İşaretle</Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          <Field label="Ekstre tarihi" required>
            <Input type="date" value={statementDate} onChange={(e) => setStatementDate(e.target.value)} />
          </Field>
          <Field label="Ekstre referansı" optional>
            <Input value={statementRef} maxLength={120} onChange={(e) => setStatementRef(e.target.value)} />
          </Field>
          <p className="text-[12px] text-ink-3">Hesap hareketi değişmez; yalnız eşleşme işareti eklenir.</p>
        </div>
      </Modal>
    </div>
  )
}
