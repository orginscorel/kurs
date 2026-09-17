import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeftRight, Ban, Calculator, CreditCard, Landmark, MoreHorizontal, Pencil, Plus, Power, Printer, Trash2, Wallet } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date, money, time, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Tabs } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Menu, Modal } from '@/components/ui/overlay'
import { AccountSelect, MetricRow, MoneyInput, useAccounts, VoidDialog } from './components'
import { ACCOUNT_KINDS, fromCents, METHODS, SOURCE_LABELS, toCents, type AccountRow } from './shared'

type Transfer = {
  id: number; kind: 'transfer' | 'opening' | 'adjustment'; kind_label: string
  from: { id: number; name: string } | null; to: { id: number; name: string } | null
  amount: string; transfer_date: string; description: string | null; created_by: string | null; voided_at: string | null; void_reason: string | null
}
type DayEnd = {
  date: string
  accounts: { id: number; name: string; kind: string; kind_label: string; opening: string; inflow: string; outflow: string; closing: string; current_balance: string }[]
  totals: { opening: string; inflow: string; outflow: string; closing: string }
  payments_by_method: { method: string; label: string; count: number; amount: string }[]
  voided_today: number
  movements: { id: number; amount: string; description: string; occurred_at: string; source_type: string; account: string; kind: string; user: string | null }[]
}

const kindIcon = (kind: string) => (kind === 'bank' ? <Landmark /> : kind === 'pos' ? <CreditCard /> : <Wallet />)

export default function Accounts() {
  const can = useCan()
  const [params, setParams] = useSearchParams()
  const tab = (params.get('sekme') as 'hesaplar' | 'transferler' | 'gun-sonu') || 'hesaplar'
  const [includeInactive, setIncludeInactive] = useState(false)
  const accounts = useQuery({
    queryKey: ['finance', 'accounts', { includeInactive }],
    queryFn: () => api.get<{ data: AccountRow[]; totals: { balance: string; by_kind: { kind: string; label: string; balance: string }[] } }>('/finance/accounts', { include_inactive: includeInactive ? 1 : undefined }),
  })
  const [formFor, setFormFor] = useState<AccountRow | 'new' | null>(null)
  const [transferOpen, setTransferOpen] = useState(false)
  const [adjustFor, setAdjustFor] = useState<AccountRow | null>(null)
  const [deleteFor, setDeleteFor] = useState<AccountRow | null>(null)
  const qc = useQueryClient()

  const toggle = useMutation({
    mutationFn: (a: AccountRow) => api.put<{ message: string }>(`/finance/accounts/${a.id}`, { name: a.name, bank_name: a.bank_name, iban: a.iban, is_active: !a.is_active }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.'),
  })
  const remove = useMutation({
    mutationFn: (a: AccountRow) => api.delete<{ message: string }>(`/finance/accounts/${a.id}`),
    onSuccess: (r) => { toast.success(r.message); setDeleteFor(null); qc.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
  })

  const setTab = (v: string) => setParams((p) => { if (v === 'hesaplar') p.delete('sekme'); else p.set('sekme', v); return p }, { replace: true })

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Kasa, banka ve POS"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Kasa ve banka' }]}
        description="Hesap bakiyeleri hareket defterinden türetilir; her değişiklik bir belgeye bağlıdır."
        actions={
          can('accounts.manage') && (
            <>
              <Button icon={<ArrowLeftRight className="size-4" />} onClick={() => setTransferOpen(true)}>Transfer</Button>
              <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setFormFor('new')}>Yeni hesap</Button>
            </>
          )
        }
      />

      <Tabs
        className="mb-4"
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'hesaplar', label: 'Hesaplar' },
          { value: 'transferler', label: 'Transfer ve düzeltmeler' },
          { value: 'gun-sonu', label: 'Gün sonu' },
        ]}
      />

      {tab === 'hesaplar' && (
        <>
          <div className="mb-4 grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div className="rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line">
              <p className="text-[12px] text-ink-3">Toplam bakiye</p>
              {accounts.data ? <p className="text-[20px] font-semibold tabular">{money(accounts.data.totals.balance)}</p> : <Skeleton className="mt-1 h-6 w-28" />}
            </div>
            {(accounts.data?.totals.by_kind ?? []).map((k) => (
              <div key={k.kind} className="rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line">
                <p className="text-[12px] text-ink-3">{k.label}</p>
                <p className={cn('text-[20px] font-semibold tabular', Number(k.balance) < 0 && 'text-danger')}>{money(k.balance)}</p>
              </div>
            ))}
          </div>
          <div className="mb-3 flex justify-end">
            <Switch checked={includeInactive} onChange={setIncludeInactive} label="Pasif hesapları göster" />
          </div>
          {accounts.isLoading ? (
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-40" />)}</div>
          ) : !accounts.data?.data.length ? (
            <EmptyState icon={<Landmark />} title="Hesap yok" description="Kasa, banka ya da POS hesabı ekleyin." action={can('accounts.manage') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setFormFor('new')}>Yeni hesap</Button> : undefined} />
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
              {accounts.data.data.map((a) => (
                <div key={a.id} className={cn('rounded-[var(--radius-lg)] bg-surface ring-1 ring-line p-4 flex flex-col gap-3', !a.is_active && 'opacity-60')}>
                  <div className="flex items-start gap-3">
                    <span className="grid size-9 place-items-center rounded-[var(--radius-sm)] bg-surface-2 text-ink-2 ring-1 ring-line [&_svg]:size-4">{kindIcon(a.kind)}</span>
                    <div className="min-w-0 flex-1">
                      <Link to={`/finans/hesaplar/${a.id}`} className="block truncate font-medium hover:underline">{a.name}</Link>
                      <p className="truncate text-[12px] text-ink-3">{a.kind_label}{a.bank_name ? ` · ${a.bank_name}` : ''}{!a.is_active ? ' · pasif' : ''}</p>
                    </div>
                    {can('accounts.manage') && (
                      <Menu
                        trigger={<Button size="icon-sm" variant="ghost" aria-label="İşlemler"><MoreHorizontal className="size-4" /></Button>}
                        items={[
                          { label: 'Düzenle', icon: <Pencil />, onClick: () => setFormFor(a) },
                          { label: 'Sayım farkı kaydet', icon: <Calculator />, onClick: () => setAdjustFor(a), hidden: !a.is_active },
                          { label: a.is_active ? 'Pasife al' : 'Aktifleştir', icon: <Power />, onClick: () => toggle.mutate(a) },
                          'divider',
                          { label: 'Sil', icon: <Trash2 />, danger: true, onClick: () => setDeleteFor(a), disabled: a.transaction_count > 0 },
                        ]}
                      />
                    )}
                  </div>
                  <p className={cn('text-[24px] font-semibold tabular tracking-tight', Number(a.balance) < 0 && 'text-danger')}>{money(a.balance)}</p>
                  <div className="flex items-center justify-between text-[12.5px]">
                    <span className="text-ink-3">Bugün</span>
                    <span className="tabular"><span className="text-success">+{money(a.today_inflow, { short: true })}</span> <span className="text-ink-3">/</span> <span className="text-danger">−{money(a.today_outflow, { short: true })}</span></span>
                  </div>
                  {a.iban && <p className="truncate text-[12px] text-ink-3 tabular">{a.iban}</p>}
                  <Link to={`/finans/hesaplar/${a.id}`} className="text-[12.5px] text-primary hover:underline">Hesap hareketleri ({a.transaction_count})</Link>
                </div>
              ))}
            </div>
          )}
        </>
      )}

      {tab === 'transferler' && <Transfers />}
      {tab === 'gun-sonu' && <DayEndView />}

      <AccountForm target={formFor} onClose={() => setFormFor(null)} />
      <TransferModal open={transferOpen} onClose={() => setTransferOpen(false)} />
      <AdjustModal account={adjustFor} onClose={() => setAdjustFor(null)} />
      <ConfirmDialog open={!!deleteFor} onClose={() => setDeleteFor(null)} onConfirm={() => deleteFor && remove.mutate(deleteFor)} loading={remove.isPending} danger title="Hesabı sil" description={deleteFor ? `"${deleteFor.name}" silinecek. Hareketi olan hesaplar silinemez.` : undefined} confirmLabel="Sil" />
    </div>
  )
}

function AccountForm({ target, onClose }: { target: AccountRow | 'new' | null; onClose: () => void }) {
  const qc = useQueryClient()
  const isNew = target === 'new'
  const [form, setForm] = useState({ kind: 'bank', name: '', bank_name: '', iban: '', opening_balance: '' })
  useEffect(() => {
    if (!target) return
    if (target === 'new') setForm({ kind: 'bank', name: '', bank_name: '', iban: '', opening_balance: '' })
    else setForm({ kind: target.kind, name: target.name, bank_name: target.bank_name ?? '', iban: target.iban ?? '', opening_balance: '' })
  }, [target])
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))
  const openingC = toCents(form.opening_balance)

  const save = useMutation({
    mutationFn: () =>
      isNew
        ? api.post<{ message: string }>('/finance/accounts', { ...form, opening_balance: form.opening_balance ? fromCents(openingC ?? 0) : null })
        : api.put<{ message: string }>(`/finance/accounts/${(target as AccountRow).id}`, { name: form.name, bank_name: form.bank_name || null, iban: form.iban || null }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  return (
    <Modal
      open={!!target}
      onClose={onClose}
      title={isNew ? 'Yeni hesap' : 'Hesabı düzenle'}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={form.name.trim().length < 2 || (form.opening_balance !== '' && openingC === null)} onClick={() => save.mutate()}>Kaydet</Button></>}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Hesap türü" required hint={!isNew ? 'Hesap açıldıktan sonra türü değiştirilemez' : undefined}>
          <Select value={form.kind} disabled={!isNew} onChange={(e) => set('kind', e.target.value)} options={Object.entries(ACCOUNT_KINDS).map(([value, label]) => ({ value, label }))} />
        </Field>
        <Field label="Hesap adı" required hint="En az 2 karakter"><Input value={form.name} onChange={(e) => set('name', e.target.value)} maxLength={120} placeholder="Örn. Ziraat TL hesabı" /></Field>
        {form.kind !== 'cash' && <Field label="Banka adı" optional><Input value={form.bank_name} onChange={(e) => set('bank_name', e.target.value)} maxLength={120} /></Field>}
        {form.kind === 'bank' && <Field label="IBAN" optional><Input value={form.iban} onChange={(e) => set('iban', e.target.value)} maxLength={40} placeholder="TR.." /></Field>}
        {isNew && (
          <Field label="Açılış bakiyesi" optional hint="Açılış hareketi olarak deftere yazılır" className="sm:col-span-2">
            <MoneyInput value={form.opening_balance} onChange={(v) => set('opening_balance', v)} allowNegative={form.kind !== 'cash'} />
          </Field>
        )}
      </div>
    </Modal>
  )
}

function TransferModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient()
  const accounts = useAccounts()
  const [form, setForm] = useState({ from_account_id: '', to_account_id: '', amount: '', transfer_date: todayISO(), description: '' })
  useEffect(() => { if (open) setForm({ from_account_id: '', to_account_id: '', amount: '', transfer_date: todayISO(), description: '' }) }, [open])
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))
  const cents = toCents(form.amount)
  const from = accounts.data?.data.find((a) => String(a.id) === form.from_account_id)
  const short = from?.kind === 'cash' && cents !== null && cents > (toCents(from.balance) ?? 0)

  const save = useMutation({
    mutationFn: () => api.post<{ message: string }>('/finance/transfers', { ...form, amount: fromCents(cents ?? 0), from_account_id: Number(form.from_account_id), to_account_id: Number(form.to_account_id) }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Transfer yapılamadı.'),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Hesaplar arası transfer"
      description="Kaynaktan düşülür, hedefe eklenir; iki hareket tek işlemde yazılır."
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={!cents || cents <= 0 || !form.from_account_id || !form.to_account_id || form.from_account_id === form.to_account_id || short} onClick={() => save.mutate()}>Transfer et</Button></>}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Paranın çıkacağı hesap" required error={short ? 'Kasada yeterli bakiye yok.' : null}><AccountSelect accounts={accounts.data?.data} value={form.from_account_id} onChange={(v) => set('from_account_id', v)} invalid={short} /></Field>
        <Field label="Paranın gireceği hesap" required error={form.from_account_id && form.from_account_id === form.to_account_id ? 'Farklı bir hesap seçin.' : null}><AccountSelect accounts={accounts.data?.data} value={form.to_account_id} onChange={(v) => set('to_account_id', v)} /></Field>
        <Field label="Tutar" required><MoneyInput value={form.amount} onChange={(v) => set('amount', v)} /></Field>
        <Field label="Transfer tarihi" required><Input type="date" max={todayISO()} value={form.transfer_date} onChange={(e) => set('transfer_date', e.target.value)} /></Field>
        <Field label="Açıklama" optional className="sm:col-span-2"><Input value={form.description} onChange={(e) => set('description', e.target.value)} maxLength={300} placeholder="Örn. Gün sonu kasadan bankaya yatırılan" /></Field>
      </div>
    </Modal>
  )
}

function AdjustModal({ account, onClose }: { account: AccountRow | null; onClose: () => void }) {
  const qc = useQueryClient()
  const [counted, setCounted] = useState('')
  const [reason, setReason] = useState('')
  useEffect(() => { if (account) { setCounted(''); setReason('') } }, [account])
  const countedC = toCents(counted)
  const diff = countedC !== null && account ? countedC - (toCents(account.balance) ?? 0) : null

  const save = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/finance/accounts/${account!.id}/adjust`, { counted_balance: fromCents(countedC ?? 0), reason }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  return (
    <Modal
      open={!!account}
      onClose={onClose}
      size="sm"
      title="Sayım farkı"
      description={account ? `${account.name} · sistem bakiyesi ${money(account.balance)}` : undefined}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={diff === null || diff === 0 || reason.trim().length < 5} onClick={() => save.mutate()}>Farkı kaydet</Button></>}
    >
      <div className="flex flex-col gap-3">
        <Field label="Sayılan / ekstre bakiyesi" required><MoneyInput value={counted} onChange={setCounted} allowNegative={account?.kind !== 'cash'} autoFocus /></Field>
        {diff !== null && diff !== 0 && <Alert tone={diff < 0 ? 'danger' : 'info'}>Fark: {diff > 0 ? '+' : '−'}{money(Math.abs(diff) / 100)} · düzeltme hareketi olarak yazılır.</Alert>}
        <Field label="Gerekçe" required hint="En az 5 karakter"><Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={250} placeholder="Örn. Gün sonu sayımında eksik çıktı" /></Field>
      </div>
    </Modal>
  )
}

function Transfers() {
  const can = useCan()
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [kind, setKind] = useState('')
  const [voidFor, setVoidFor] = useState<Transfer | null>(null)
  const { data, isLoading } = useQuery({
    queryKey: ['finance', 'transfers', page, kind],
    queryFn: () => api.get<Paginated<Transfer>>('/finance/transfers', { page, kind }),
    placeholderData: keepPreviousData,
  })
  const voidMutation = useMutation({
    mutationFn: (reason: string) => api.post<{ message: string }>(`/finance/transfers/${voidFor!.id}/void`, { reason }),
    onSuccess: (r) => { toast.success(r.message); setVoidFor(null); qc.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İptal edilemedi.'),
  })

  return (
    <Panel
      title="Transfer, açılış ve sayım farkları"
      actions={<Select value={kind} onChange={(e) => { setKind(e.target.value); setPage(1) }} placeholder="Tüm türler" options={[{ value: 'transfer', label: 'Transfer' }, { value: 'opening', label: 'Açılış bakiyesi' }, { value: 'adjustment', label: 'Sayım farkı' }]} className="w-44" />}
      flush
    >
      {isLoading ? (
        <div className="px-4 pb-4"><Skeleton className="h-40" /></div>
      ) : !data?.data.length ? (
        <EmptyState compact icon={<ArrowLeftRight />} title="Kayıt yok" />
      ) : (
        <div className="overflow-x-auto scroll-thin">
          <table className="tbl w-full text-[13px]">
            <thead>
              <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
                <th className="h-9 px-4 font-medium text-left">İşlem tarihi</th>
                <th className="px-3 font-medium text-center">İşlem türü</th>
                <th className="px-3 font-medium text-center">Kasa / banka</th>
                <th className="fill px-3 font-medium hidden md:table-cell text-center">Açıklama</th>
                <th className="px-3 font-medium text-center">Tutar</th>
                <th className="px-4 text-center" />
              </tr>
            </thead>
            <tbody>
              {data.data.map((t) => (
                <tr key={t.id} className={cn('border-b border-line last:border-0', t.voided_at && 'opacity-60')}>
                  <td className="px-4 py-2.5 tabular whitespace-nowrap text-left">{date(t.transfer_date)}</td>
                  <td className="px-3 py-2.5 text-center">{t.kind_label}{t.voided_at && <Badge tone="danger" className="ml-1.5">İptal</Badge>}</td>
                  <td className="px-3 py-2.5 whitespace-nowrap text-center">{t.from ? `${t.from.name} → ${t.to?.name}` : t.to?.name}</td>
                  <td className="fill px-3 py-2.5 text-ink-3 hidden md:table-cell text-center">{t.voided_at ? `İptal: ${t.void_reason}` : t.description ?? '—'}<span className="block text-[12px]">{t.created_by}</span></td>
                  <td className={cn('px-3 py-2.5 tabular font-medium text-center', t.voided_at && 'line-through')}>{money(t.amount)}</td>
                  <td className="px-4 py-2.5 text-right">
                    {can('accounts.manage') && !t.voided_at && <Button size="xs" variant="ghost" icon={<Ban className="size-3.5" />} onClick={() => setVoidFor(t)}>İptal</Button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {data.meta.last_page > 1 && (
            <div className="flex items-center justify-end gap-2 border-t border-line px-4 py-2 text-[12.5px] text-ink-3">
              <Button size="xs" variant="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>Önceki</Button>
              <span className="tabular">{page} / {data.meta.last_page}</span>
              <Button size="xs" variant="ghost" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Sonraki</Button>
            </div>
          )}
        </div>
      )}
      <VoidDialog open={!!voidFor} onClose={() => setVoidFor(null)} loading={voidMutation.isPending} onConfirm={(r) => voidMutation.mutate(r)} title="Kaydı iptal et" description={voidFor ? `${voidFor.kind_label} · ${money(voidFor.amount)}` : undefined}>
        <Alert tone="warning" className="mb-3">Bakiyeler ters kayıtla düzeltilir; kasa eksiye düşecekse iptal yapılamaz.</Alert>
      </VoidDialog>
    </Panel>
  )
}

function DayEndView() {
  const [day, setDay] = useState(todayISO())
  const { data, isLoading } = useQuery({ queryKey: ['finance', 'day-end', day], queryFn: () => api.get<{ data: DayEnd }>('/finance/day-end', { date: day }), placeholderData: keepPreviousData })
  const d = data?.data

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2 print:hidden">
        <Input type="date" max={todayISO()} value={day} onChange={(e) => setDay(e.target.value)} className="w-44" />
        <Button size="sm" variant="ghost" onClick={() => setDay(todayISO())}>Bugün</Button>
        <Button size="sm" icon={<Printer className="size-4" />} className="ml-auto" onClick={() => window.print()}>Yazdır</Button>
      </div>
      {isLoading || !d ? (
        <Skeleton className="h-64" />
      ) : (
        <>
          <Panel title={`Gün sonu · ${date(d.date, 'long')}`} description={d.voided_today ? `${d.voided_today} tahsilat bu gün iptal edildi` : undefined} flush>
            <div className="overflow-x-auto scroll-thin">
              <table className="tbl w-full text-[13px]">
                <thead>
                  <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
                    <th className="h-9 px-4 font-medium text-left">Kasa / banka</th>
                    <th className="px-3 font-medium text-center">Önceki günden devir</th>
                    <th className="px-3 font-medium text-center">Giren</th>
                    <th className="px-3 font-medium text-center">Çıkan</th>
                    <th className="px-4 font-medium text-center">Gün sonu bakiyesi</th>
                  </tr>
                </thead>
                <tbody>
                  {d.accounts.map((a) => (
                    <tr key={a.id} className="border-b border-line">
                      <td className="px-4 py-2.5 text-left"><Link to={`/finans/hesaplar/${a.id}?from=${d.date}&to=${d.date}`} className="font-medium hover:underline">{a.name}</Link><span className="block text-[12px] text-ink-3">{a.kind_label}</span></td>
                      <td className="px-3 py-2.5 tabular text-ink-2 text-center">{money(a.opening)}</td>
                      <td className="px-3 py-2.5 tabular text-success text-center">+{money(a.inflow)}</td>
                      <td className="px-3 py-2.5 tabular text-danger text-center">−{money(a.outflow)}</td>
                      <td className="px-4 py-2.5 tabular font-semibold text-center">{money(a.closing)}</td>
                    </tr>
                  ))}
                  <tr className="bg-surface-2/40 font-semibold">
                    <td className="px-4 py-2.5 text-left">Toplam</td>
                    <td className="px-3 py-2.5 tabular text-center">{money(d.totals.opening)}</td>
                    <td className="px-3 py-2.5 tabular text-success text-center">+{money(d.totals.inflow)}</td>
                    <td className="px-3 py-2.5 tabular text-danger text-center">−{money(d.totals.outflow)}</td>
                    <td className="px-4 py-2.5 tabular text-center">{money(d.totals.closing)}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </Panel>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <Panel title="Tahsilatlar (yönteme göre)">
              {d.payments_by_method.length === 0 ? (
                <p className="text-[13px] text-ink-3">Bu gün tahsilat yok.</p>
              ) : (
                <div className="divide-y divide-line">
                  {d.payments_by_method.map((m) => <MetricRow key={m.method} label={`${METHODS[m.method] ?? m.label} · ${m.count}`} value={money(m.amount)} />)}
                  <MetricRow label="Toplam" value={money(d.payments_by_method.reduce((s, m) => s + (toCents(m.amount) ?? 0), 0) / 100)} strong />
                </div>
              )}
            </Panel>
            <Panel title="Hareketler" className="lg:col-span-2" flush>
              {d.movements.length === 0 ? (
                <EmptyState compact icon={<Wallet />} title="Bu gün hareket yok" />
              ) : (
                <ul className="max-h-[420px] overflow-y-auto scroll-thin divide-y divide-line border-t border-line">
                  {d.movements.map((m) => (
                    <li key={m.id} className="flex items-center gap-3 px-4 py-2 text-[13px]">
                      <span className="w-11 text-ink-3 tabular">{time(m.occurred_at)}</span>
                      <span className="min-w-0 flex-1">
                        <span className="block truncate">{m.description}</span>
                        <span className="block text-[12px] text-ink-3">{m.account} · {SOURCE_LABELS[m.source_type] ?? m.source_type}{m.user ? ` · ${m.user}` : ''}</span>
                      </span>
                      <span className={cn('tabular font-medium', Number(m.amount) < 0 ? 'text-danger' : 'text-success')}>{Number(m.amount) < 0 ? '−' : '+'}{money(Math.abs(Number(m.amount)))}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          </div>
        </>
      )}
    </div>
  )
}
