import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, Download, Filter, Lock, Pencil, Plus, Printer, Search, Tags, Trash2, TrendingDown, TrendingUp, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date, dateTime, money, num, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Drawer, Modal } from '@/components/ui/overlay'
import { AccountSelect, MetricRow, MoneyInput, useAccounts, VoidDialog } from './components'
import { fromCents, openPdf, toCents, type Category } from './shared'

type Entry = {
  id: number
  direction: 'income' | 'expense'
  amount: string
  entry_date: string
  description: string
  counterparty: string | null
  document_no: string | null
  category: { id: number; name: string } | null
  account: { id: number; name: string } | null
  created_by: string | null
  created_at: string
  voided_at: string | null
  void_reason: string | null
}
type ListResponse = Paginated<Entry> & { meta: { totals: { income: string; expense: string; net: string; count: number; voided_count: number } } }

export default function Entries() {
  const can = useCan()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: '-entry_date' })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [showFilters, setShowFilters] = useState(false)
  const [catOpen, setCatOpen] = useState(false)
  const createDir = params.get('yeni') as 'income' | 'expense' | null
  const detailId = Number(params.get('detay') || 0) || null
  const accounts = useAccounts()
  const categories = useQuery({ queryKey: ['finance', 'categories'], queryFn: () => api.get<{ data: Category[] }>('/finance/categories'), staleTime: 60_000 })

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'entries', list.query],
    queryFn: () => api.get<ListResponse>('/finance/entries', list.query),
    placeholderData: keepPreviousData,
  })

  const setParam = (key: string, value: string | null) =>
    setParams((p) => {
      if (value) p.set(key, value)
      else p.delete(key)
      return p
    }, { replace: true })

  const t = data?.meta.totals
  const activeFilterCount = ['category_id', 'account_id', 'from', 'to', 'status'].filter((k) => list.filters[k]).length

  const columns = useMemo<Column<Entry>[]>(
    () => [
      { key: 'date', header: 'İşlem tarihi', sortKey: 'entry_date', cell: (e) => <span className="tabular whitespace-nowrap">{date(e.entry_date)}</span> },
      {
        key: 'description',
        header: 'Açıklama',
        cell: (e) => (
          <div className="min-w-0">
            <p className={cn('truncate text-ink', e.voided_at && 'line-through text-ink-3')}>{e.description}</p>
            <p className="truncate text-[12px] text-ink-3">{[e.counterparty, e.document_no && `Belge ${e.document_no}`].filter(Boolean).join(' · ') || '—'}</p>
          </div>
        ),
      },
      {
        key: 'category',
        header: 'Kategori',
        cell: (e) => (
          <span className="inline-flex items-center gap-1.5 whitespace-nowrap text-ink-2">
            {e.direction === 'income' ? <TrendingUp className="size-3.5 text-success" /> : <TrendingDown className="size-3.5 text-danger" />}
            {e.category?.name}
          </span>
        ),
      },
      { key: 'counterparty', header: 'Karşı taraf', hideable: true, cell: (e) => (e.counterparty ? <span className="text-ink-2 truncate">{e.counterparty}</span> : <span className="text-ink-3">—</span>) },
      { key: 'document', header: 'Belge no', hideable: true, defaultHidden: true, cell: (e) => <span className="text-ink-3 tabular whitespace-nowrap">{e.document_no ?? '—'}</span> },
      { key: 'account', header: 'Kasa / banka', hideable: true, cell: (e) => <span className="text-ink-2 whitespace-nowrap">{e.account?.name}</span> },
      {
        key: 'user',
        header: 'Kaydeden',
        hideable: true,
        // İkinci satır kayıt zamanı (işlem tarihi ile kayıt anı farklı olabilir)
        cell: (e) => (
          <div className="min-w-0">
            <p className="truncate text-ink-2">{e.created_by ?? '—'}</p>
            <p className="text-[12px] text-ink-3 tabular whitespace-nowrap">{dateTime(e.created_at)}</p>
          </div>
        ),
      },
      { key: 'status', header: 'Durum', hideable: true, cell: (e) => (e.voided_at ? <Badge tone="danger">İptal edildi</Badge> : <Badge tone="success" dot>Geçerli</Badge>) },
      {
        key: 'amount',
        header: 'Tutar',
        sortKey: 'amount',
        align: 'right',
        cell: (e) => (
          <span className={cn('font-semibold tabular whitespace-nowrap', e.voided_at ? 'line-through text-ink-3 font-normal' : e.direction === 'income' ? 'text-success' : 'text-ink')}>
            {e.direction === 'income' ? '+' : '−'}{money(e.amount)}
          </span>
        ),
      },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Gelir ve gider"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Gelir ve gider' }]}
        description="Öğrenci tahsilatı dışındaki tüm gelir ve giderler. Kayıtlar değiştirilemez; düzeltme iptal ile yapılır."
        actions={
          <>
            {can('expenses.manage') && <Button icon={<Tags className="size-4" />} onClick={() => setCatOpen(true)}>Kategoriler</Button>}
            {can('reports.export') && <Button icon={<Download className="size-4" />} onClick={() => api.download('/finance/entries/export', list.query, 'gelir-gider.xlsx').catch((e) => toast.error(e.message))}>Excel</Button>}
            {can('expenses.manage') && (
              <>
                <Button icon={<TrendingUp className="size-4" />} onClick={() => setParam('yeni', 'income')}>Gelir</Button>
                <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParam('yeni', 'expense')}>Gider ekle</Button>
              </>
            )}
          </>
        }
      />

      <div className="mb-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <Box label="Gelir" value={t ? money(t.income) : null} tone="success" />
        <Box label="Gider" value={t ? money(t.expense) : null} />
        <Box label="Net" value={t ? money(t.net) : null} tone={t && Number(t.net) < 0 ? 'danger' : undefined} sub={t ? `${num(t.count)} kayıt${t.voided_count ? ` · ${t.voided_count} iptal hariç` : ''}` : undefined} />
      </div>

      <DataTable
        storageKey="finance-entries"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => setParam('detay', String(r.id))}
        toolbar={
          <div className="flex w-full flex-col gap-2.5">
            <div className="flex flex-wrap items-center gap-2">
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Açıklama, karşı taraf, belge no"
                leading={<Search />}
                trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
                className="w-full sm:w-[260px]"
              />
              <Segmented
                size="sm"
                value={list.filters.direction ?? 'all'}
                onChange={(v) => list.update({ filters: { direction: v === 'all' ? null : v, category_id: null } })}
                options={[{ value: 'all', label: 'Tümü' }, { value: 'income', label: 'Gelir' }, { value: 'expense', label: 'Gider' }]}
              />
              <Button size="sm" variant={activeFilterCount ? 'soft' : 'ghost'} icon={<Filter className="size-4" />} onClick={() => setShowFilters((v) => !v)}>
                Filtre{activeFilterCount ? ` (${activeFilterCount})` : ''}
              </Button>
              {activeFilterCount > 0 && <Button size="sm" variant="ghost" onClick={() => list.update({ filters: { category_id: null, account_id: null, from: null, to: null, status: null } })}>Temizle</Button>}
            </div>
            {showFilters && (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2 animate-fade-in">
                <Select value={list.filters.category_id ?? ''} onChange={(e) => list.update({ filters: { category_id: e.target.value } })} placeholder="Tüm kategoriler" options={(categories.data?.data ?? []).filter((c) => c.selectable && (!list.filters.direction || c.direction === list.filters.direction)).map((c) => ({ value: c.id, label: `${c.direction === 'income' ? 'Gelir' : 'Gider'} · ${c.name}` }))} />
                <Select value={list.filters.account_id ?? ''} onChange={(e) => list.update({ filters: { account_id: e.target.value } })} placeholder="Tüm hesaplar" options={(accounts.data?.data ?? []).map((a) => ({ value: a.id, label: a.name }))} />
                <Field label="İşlem tarihi (başlangıç)"><Input type="date" value={list.filters.from ?? ''} onChange={(e) => list.update({ filters: { from: e.target.value } })} /></Field>
                <Field label="İşlem tarihi (bitiş)"><Input type="date" value={list.filters.to ?? ''} onChange={(e) => list.update({ filters: { to: e.target.value } })} /></Field>
                <Select value={list.filters.status ?? ''} onChange={(e) => list.update({ filters: { status: e.target.value } })} placeholder="Geçerli + iptal" options={[{ value: 'active', label: 'Yalnız geçerli' }, { value: 'voided', label: 'Yalnız iptal' }]} />
              </div>
            )}
          </div>
        }
        empty={
          <EmptyState
            icon={<TrendingDown />}
            title={list.q || activeFilterCount ? 'Filtreye uyan kayıt yok' : 'Henüz gelir/gider kaydı yok'}
            action={can('expenses.manage') && !list.q ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParam('yeni', 'expense')}>Gider ekle</Button> : undefined}
          />
        }
      />

      <EntryForm direction={createDir} categories={categories.data?.data} onClose={() => setParam('yeni', null)} />
      <EntryDrawer id={detailId} onClose={() => setParam('detay', null)} />
      <CategoryModal open={catOpen} onClose={() => setCatOpen(false)} categories={categories.data?.data} loading={categories.isLoading} />
    </div>
  )
}

function Box({ label, value, tone, sub }: { label: string; value: string | null; tone?: 'success' | 'danger'; sub?: string }) {
  return (
    <div className="rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line">
      <p className="text-[12px] text-ink-3">{label}</p>
      {value === null ? <Skeleton className="mt-1 h-6 w-28" /> : <p className={cn('text-[20px] font-semibold tabular tracking-tight', tone === 'success' && 'text-success', tone === 'danger' && 'text-danger')}>{value}</p>}
      {sub && <p className="text-[12px] text-ink-3">{sub}</p>}
    </div>
  )
}

function EntryForm({ direction, categories, onClose }: { direction: 'income' | 'expense' | null; categories?: Category[]; onClose: () => void }) {
  const can = useCan()
  const qc = useQueryClient()
  const accounts = useAccounts()
  const open = (direction === 'income' || direction === 'expense') && can('expenses.manage')
  const [form, setForm] = useState({ direction: 'expense' as 'income' | 'expense', finance_category_id: '', finance_account_id: '', amount: '', entry_date: todayISO(), description: '', counterparty: '', document_no: '' })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))

  useEffect(() => {
    if (!open) return
    setErrors({})
    const cash = accounts.data?.data.find((a) => a.is_active && a.kind === (direction === 'expense' ? 'bank' : 'cash'))
    setForm({ direction: direction!, finance_category_id: '', finance_account_id: cash ? String(cash.id) : '', amount: '', entry_date: todayISO(), description: '', counterparty: '', document_no: '' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, direction])

  const cats = (categories ?? []).filter((c) => c.direction === form.direction && c.selectable)
  const cents = toCents(form.amount)
  const account = accounts.data?.data.find((a) => String(a.id) === form.finance_account_id)
  const cashShort = form.direction === 'expense' && account?.kind === 'cash' && cents !== null && cents > (toCents(account.balance) ?? 0)

  const save = useMutation({
    mutationFn: () => api.post<{ message: string }>('/finance/entries', { ...form, amount: fromCents(cents ?? 0), finance_category_id: Number(form.finance_category_id), finance_account_id: Number(form.finance_account_id) }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['finance'] })
      qc.invalidateQueries({ queryKey: ['dashboard'] })
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      }
    },
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={form.direction === 'income' ? 'Gelir kaydı' : 'Gider kaydı'}
      description="Kayıt, seçilen hesabın bakiyesine aynı anda işlenir."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} disabled={!cents || cents <= 0 || !form.finance_category_id || !form.finance_account_id || form.description.trim().length < 3 || cashShort} onClick={() => save.mutate()}>Kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Segmented value={form.direction} onChange={(v) => setForm((f) => ({ ...f, direction: v, finance_category_id: '' }))} options={[{ value: 'expense', label: 'Gider' }, { value: 'income', label: 'Gelir' }]} />
        <Field label="Tutar" required error={errors.amount?.[0]}>
          <MoneyInput size="lg" value={form.amount} onChange={(v) => set('amount', v)} autoFocus />
        </Field>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Kategori" required error={errors.finance_category_id?.[0]}>
            <Select value={form.finance_category_id} onChange={(e) => set('finance_category_id', e.target.value)} placeholder="Seçin" options={cats.map((c) => ({ value: c.id, label: c.name }))} />
          </Field>
          <Field label="İşlem tarihi" required error={errors.entry_date?.[0]}>
            <Input type="date" max={todayISO()} value={form.entry_date} onChange={(e) => set('entry_date', e.target.value)} />
          </Field>
        </div>
        <Field label={form.direction === 'income' ? 'Giren hesap' : 'Çıkan hesap'} required error={errors.finance_account_id?.[0] ?? (cashShort ? 'Kasada yeterli bakiye yok.' : null)}>
          <AccountSelect accounts={accounts.data?.data} value={form.finance_account_id} onChange={(v) => set('finance_account_id', v)} invalid={cashShort} />
        </Field>
        <Field label="Açıklama" required hint="En az 3 karakter" error={errors.description?.[0]}>
          <Textarea rows={2} value={form.description} onChange={(e) => set('description', e.target.value)} maxLength={500} placeholder={form.direction === 'expense' ? 'Örn. Eylül elektrik faturası' : 'Örn. Yaz okulu ücretleri'} />
        </Field>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Karşı taraf" optional error={errors.counterparty?.[0]}><Input value={form.counterparty} onChange={(e) => set('counterparty', e.target.value)} maxLength={160} placeholder="Firma / kişi" /></Field>
          <Field label="Belge no" optional error={errors.document_no?.[0]}><Input value={form.document_no} onChange={(e) => set('document_no', e.target.value)} maxLength={60} placeholder="Fatura / fiş no" /></Field>
        </div>
      </div>
    </Drawer>
  )
}

function EntryDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const can = useCan()
  const qc = useQueryClient()
  const [voidOpen, setVoidOpen] = useState(false)
  const { data, isLoading } = useQuery({
    queryKey: ['finance', 'entry', id],
    queryFn: () => api.get<{ data: Entry & { voided_by: string | null; stock_movement: { id: number; quantity: number; kind: string; product: string } | null } }>(`/finance/entries/${id}`),
    enabled: !!id,
  })
  const e = data?.data
  const voidMutation = useMutation({
    mutationFn: (reason: string) => api.post<{ message: string }>(`/finance/entries/${id}/void`, { reason }),
    onSuccess: (r) => {
      toast.success(r.message)
      setVoidOpen(false)
      qc.invalidateQueries({ queryKey: ['finance'] })
      qc.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: (err) => toast.error(err instanceof ApiError ? err.firstError() : 'İptal edilemedi.'),
  })

  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      title={e ? (e.direction === 'income' ? 'Gelir kaydı' : 'Gider kaydı') : 'Kayıt'}
      description={e ? date(e.entry_date) : undefined}
      footer={e ? (
        <div className="flex w-full flex-wrap items-center gap-2">
          {can('expenses.manage') && !e.voided_at && <Button variant="danger-soft" icon={<Ban className="size-4" />} onClick={() => setVoidOpen(true)}>İptal et</Button>}
          <Button className="ml-auto" icon={<Printer className="size-4" />} onClick={() => openPdf(`/finance/documents/vouchers/finance_entry/${e.id}.pdf`).catch((err) => toast.error(err.message))}>Dekont</Button>
        </div>
      ) : undefined}
    >
      {isLoading || !e ? (
        <Skeleton className="h-48" />
      ) : (
        <div className="flex flex-col gap-4">
          {e.voided_at && <Alert tone="danger" title="Bu kayıt iptal edildi">{dateTime(e.voided_at)} · {e.voided_by ?? 'Yetkili'} — {e.void_reason}</Alert>}
          <p className={cn('text-[28px] font-semibold tabular tracking-tight', e.voided_at && 'line-through text-ink-3', !e.voided_at && e.direction === 'income' && 'text-success')}>
            {e.direction === 'income' ? '+' : '−'}{money(e.amount)}
          </p>
          <div className="rounded-[var(--radius-md)] ring-1 ring-line px-3.5 py-1.5 divide-y divide-line">
            <MetricRow label="Açıklama" value={e.description} />
            <MetricRow label="Kategori" value={e.category?.name} />
            <MetricRow label="Kasa / banka" value={e.account?.name} />
            {e.counterparty && <MetricRow label="Karşı taraf" value={e.counterparty} />}
            {e.document_no && <MetricRow label="Belge no" value={e.document_no} />}
            <MetricRow label="Kaydeden" value={`${e.created_by ?? '—'} · Kayıt: ${dateTime(e.created_at)}`} />
            {e.stock_movement && <MetricRow label="Stok hareketi" value={`${e.stock_movement.product} × ${Math.abs(e.stock_movement.quantity)}`} />}
          </div>
          {e.stock_movement && !e.voided_at && <Alert tone="info">Bu gelir bir kitap/materyal teslimiyle oluştu. İptal stoğu değiştirmez; gerekirse envanterden iade alın.</Alert>}
        </div>
      )}
      <VoidDialog
        open={voidOpen}
        onClose={() => setVoidOpen(false)}
        loading={voidMutation.isPending}
        onConfirm={(reason) => voidMutation.mutate(reason)}
        title="Kaydı iptal et"
        description={e ? `${e.description} · ${money(e.amount)}` : undefined}
      >
        <Alert tone="warning" className="mb-3">Kayıt silinmez; hesaba ters kayıt atılır. Doğru tutarla yeniden kaydedebilirsiniz.</Alert>
      </VoidDialog>
    </Drawer>
  )
}

function CategoryModal({ open, onClose, categories, loading }: { open: boolean; onClose: () => void; categories?: Category[]; loading: boolean }) {
  const qc = useQueryClient()
  const [direction, setDirection] = useState<'expense' | 'income'>('expense')
  const [name, setName] = useState('')
  const [editing, setEditing] = useState<Category | null>(null)
  const [editName, setEditName] = useState('')
  const [deleting, setDeleting] = useState<Category | null>(null)
  const done = (msg: string) => {
    toast.success(msg)
    qc.invalidateQueries({ queryKey: ['finance', 'categories'] })
  }
  const onError = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')

  const create = useMutation({ mutationFn: () => api.post<{ message: string }>('/finance/categories', { direction, name }), onSuccess: (r) => { setName(''); done(r.message) }, onError })
  const rename = useMutation({ mutationFn: () => api.put<{ message: string }>(`/finance/categories/${editing!.id}`, { name: editName }), onSuccess: (r) => { setEditing(null); done(r.message) }, onError })
  const remove = useMutation({ mutationFn: () => api.delete<{ message: string }>(`/finance/categories/${deleting!.id}`), onSuccess: (r) => { setDeleting(null); done(r.message) }, onError })

  const rows = (categories ?? []).filter((c) => c.direction === direction)

  return (
    <Modal open={open} onClose={onClose} title="Gelir ve gider kategorileri" description="Sistem kategorileri silinemez; kullanılan kategori silinemez ama adı değiştirilebilir.">
      <Segmented value={direction} onChange={setDirection} options={[{ value: 'expense', label: 'Gider' }, { value: 'income', label: 'Gelir' }]} />
      <div className="mt-3 flex gap-2">
        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Yeni kategori adı" maxLength={120} onKeyDown={(e) => e.key === 'Enter' && name.trim().length >= 2 && create.mutate()} />
        <Button variant="primary" icon={<Plus className="size-4" />} disabled={name.trim().length < 2} loading={create.isPending} onClick={() => create.mutate()}>Ekle</Button>
      </div>
      <ul className="mt-3 divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
        {loading && <li className="p-3"><Skeleton className="h-24" /></li>}
        {rows.map((c) => (
          <li key={c.id} className="flex items-center gap-2 px-3 py-2">
            {editing?.id === c.id ? (
              <>
                <Input value={editName} onChange={(e) => setEditName(e.target.value)} autoFocus maxLength={120} />
                <Button size="sm" variant="primary" loading={rename.isPending} disabled={editName.trim().length < 2} onClick={() => rename.mutate()}>Kaydet</Button>
                <Button size="sm" variant="ghost" onClick={() => setEditing(null)}>Vazgeç</Button>
              </>
            ) : (
              <>
                <span className="min-w-0 flex-1 truncate text-[13.5px]">{c.name}</span>
                <span className="text-[12px] text-ink-3 tabular">{c.usage} kayıt</span>
                {c.is_system ? (
                  <Badge><Lock className="size-3" /> Sistem</Badge>
                ) : (
                  <>
                    <Button size="icon-sm" variant="ghost" aria-label="Yeniden adlandır" onClick={() => { setEditing(c); setEditName(c.name) }}><Pencil className="size-3.5" /></Button>
                    <Button size="icon-sm" variant="ghost" aria-label="Sil" disabled={c.usage > 0} title={c.usage > 0 ? 'Kullanılan kategori silinemez' : 'Sil'} onClick={() => setDeleting(c)}><Trash2 className="size-3.5" /></Button>
                  </>
                )}
              </>
            )}
          </li>
        ))}
      </ul>
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={() => remove.mutate()} loading={remove.isPending} danger title="Kategoriyi sil" description={deleting ? `"${deleting.name}" kategorisi silinecek.` : undefined} confirmLabel="Sil" />
    </Modal>
  )
}
