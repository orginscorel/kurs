import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle, BookOpen, Calculator, MoreHorizontal, PackagePlus, Pencil, Plus, Search, Trash2, Undo2, UserCheck, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, money, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader, Tabs } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Menu, Modal } from '@/components/ui/overlay'
import { AccountSelect, MoneyInput, StudentPicker, useAccounts, type PickedStudent } from './components'
import { fromCents, toCents } from './shared'

type Product = { id: number; name: string; publisher: string | null; barcode: string | null; subject_id: number | null; subject: string | null; purchase_price: string; sale_price: string; stock: number; min_stock: number; is_active: boolean; low_stock: boolean; delivered_total: number; stock_value: string }
type ProductsResp = Paginated<Product> & { meta: { low_stock_count: number; subjects: { id: number; name: string }[] } }
type Movement = { id: number; kind: string; kind_label: string; quantity: number; product: { id: number; name: string } | null; student: { id: number; full_name: string; student_no: string } | null; unit_price: string | null; finance_entry_id: number | null; note: string | null; created_by: string | null; created_at: string }
type Action = { type: 'stock-in' | 'deliver' | 'return' | 'adjust'; product: Product }

export default function Inventory() {
  const can = useCan()
  const qc = useQueryClient()
  const list = useListState({ sort: 'name' })
  const tab = list.filters.sekme === 'hareketler' ? 'hareketler' : 'urunler'
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [edit, setEdit] = useState<Product | 'new' | null>(null)
  const [action, setAction] = useState<Action | null>(null)
  const [deleting, setDeleting] = useState<Product | null>(null)
  const manage = can('inventory.manage')

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const products = useQuery({
    queryKey: ['finance', 'products', list.query],
    queryFn: () => api.get<ProductsResp>('/finance/products', { ...list.query, sekme: undefined }),
    enabled: tab === 'urunler',
    placeholderData: keepPreviousData,
  })
  const remove = useMutation({
    mutationFn: (p: Product) => api.delete<{ message: string }>(`/finance/products/${p.id}`),
    onSuccess: (r) => { toast.success(r.message); setDeleting(null); qc.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.'),
  })

  const columns = useMemo<Column<Product>[]>(
    () => [
      {
        key: 'name',
        header: 'Ürün',
        sortKey: 'name',
        cell: (p) => (
          <div className="min-w-0">
            <p className="font-medium text-ink">{p.name}</p>
            <p className="text-[12px] text-ink-3">{[p.publisher, p.subject, p.barcode].filter(Boolean).join(' · ') || '—'}</p>
          </div>
        ),
      },
      {
        key: 'stock',
        header: 'Stoktaki adet',
        sortKey: 'stock',
        align: 'right',
        cell: (p) => (
          <div className="whitespace-nowrap">
            <span className={cn('font-semibold', p.low_stock && 'text-danger', p.stock <= 0 && 'text-danger')}>{num(p.stock)}</span>
            {p.low_stock && <Badge tone="danger" className="ml-2"><AlertTriangle className="size-3" /> Kritik</Badge>}
            <p className="text-[12px] text-ink-3">min {p.min_stock}</p>
          </div>
        ),
      },
      { key: 'delivered', header: 'Teslim edilen', align: 'right', hideable: true, cell: (p) => <span className="text-ink-2">{num(p.delivered_total)}</span> },
      { key: 'purchase', header: 'Alış fiyatı', align: 'right', hideable: true, defaultHidden: true, cell: (p) => <span className="text-ink-2">{money(p.purchase_price)}</span> },
      { key: 'sale', header: 'Satış fiyatı', sortKey: 'sale_price', align: 'right', cell: (p) => <span>{money(p.sale_price)}</span> },
      { key: 'value', header: 'Stok değeri', align: 'right', hideable: true, cell: (p) => <span className="text-ink-2">{money(p.stock_value, { short: true })}</span> },
      {
        key: 'actions',
        header: '',
        align: 'right',
        cell: (p) =>
          manage ? (
            <div className="flex items-center justify-end gap-1" onClick={(e) => e.stopPropagation()}>
              <Button size="xs" variant="soft" icon={<UserCheck className="size-3.5" />} disabled={p.stock <= 0} onClick={() => setAction({ type: 'deliver', product: p })}>Teslim</Button>
              <Menu
                trigger={<Button size="icon-sm" variant="ghost" aria-label="İşlemler"><MoreHorizontal className="size-4" /></Button>}
                items={[
                  { label: 'Stok girişi', icon: <PackagePlus />, onClick: () => setAction({ type: 'stock-in', product: p }) },
                  { label: 'Öğrenciden iade', icon: <Undo2 />, onClick: () => setAction({ type: 'return', product: p }) },
                  { label: 'Sayım düzeltmesi', icon: <Calculator />, onClick: () => setAction({ type: 'adjust', product: p }) },
                  { label: 'Düzenle', icon: <Pencil />, onClick: () => setEdit(p) },
                  'divider',
                  { label: p.delivered_total || p.stock ? 'Pasife al' : 'Sil', icon: <Trash2 />, danger: true, onClick: () => setDeleting(p) },
                ]}
              />
            </div>
          ) : null,
      },
    ],
    [manage],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Kitap ve materyal"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Envanter' }]}
        description="Stok girişi, öğrenciye teslim ve düşük stok takibi. Stok hareketlerden türetilir."
        actions={manage && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEdit('new')}>Yeni ürün</Button>}
      />

      <Tabs className="mb-3" value={tab} onChange={(v) => list.update({ filters: { sekme: v === 'urunler' ? null : v } })} tabs={[{ value: 'urunler', label: 'Ürünler' }, { value: 'hareketler', label: 'Stok hareketleri' }]} />

      {tab === 'urunler' ? (
        <>
          {!!products.data?.meta.low_stock_count && list.filters.low_stock !== '1' && (
            <Alert tone="warning" className="mb-3" action={<Button size="sm" onClick={() => list.update({ filters: { low_stock: '1' } })}>Göster</Button>}>
              {products.data.meta.low_stock_count} ürün minimum stok seviyesinde ya da altında.
            </Alert>
          )}
          <DataTable
            storageKey="finance-products"
            columns={columns}
            rows={products.data?.data}
            rowKey={(r) => r.id}
            loading={products.isLoading || products.isFetching}
            meta={products.data?.meta}
            sort={list.sort}
            onSort={(sort) => list.update({ sort })}
            onPage={(page) => list.update({ page })}
            onRowClick={(r) => list.update({ filters: { sekme: 'hareketler', product_id: String(r.id) } })}
            toolbar={
              <div className="flex flex-wrap items-center gap-2">
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Ürün, yayınevi ya da barkod"
                  leading={<Search />}
                  trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
                  className="w-full sm:w-[260px]"
                />
                <Switch checked={list.filters.low_stock === '1'} onChange={(v) => list.update({ filters: { low_stock: v ? '1' : null } })} label="Yalnız kritik stok" />
                <Switch checked={list.filters.include_inactive === '1'} onChange={(v) => list.update({ filters: { include_inactive: v ? '1' : null } })} label="Pasifler" />
              </div>
            }
            empty={<EmptyState icon={<BookOpen />} title={list.q || list.filters.low_stock ? 'Eşleşen ürün yok' : 'Envanterde ürün yok'} action={manage && !list.q ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEdit('new')}>Yeni ürün</Button> : undefined} />}
          />
        </>
      ) : (
        <Movements productId={list.filters.product_id} onClearProduct={() => list.update({ filters: { product_id: null } })} />
      )}

      <ProductForm target={edit} subjects={products.data?.meta.subjects} onClose={() => setEdit(null)} />
      <ActionModal action={action} onClose={() => setAction(null)} />
      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting)}
        loading={remove.isPending}
        danger
        title="Ürünü kaldır"
        description={deleting ? `"${deleting.name}" hareket geçmişi varsa pasife alınır, yoksa silinir.` : undefined}
        confirmLabel="Onayla"
      />
    </div>
  )
}

function Movements({ productId, onClearProduct }: { productId?: string; onClearProduct: () => void }) {
  const [page, setPage] = useState(1)
  const [kind, setKind] = useState('')
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'stock-movements', productId, kind, page],
    queryFn: () => api.get<Paginated<Movement>>('/finance/stock-movements', { product_id: productId, kind, page }),
    placeholderData: keepPreviousData,
  })
  const columns: Column<Movement>[] = [
    { key: 'at', header: 'Hareket tarihi', cell: (m) => <span className="tabular whitespace-nowrap">{dateTime(m.created_at)}</span> },
    { key: 'product', header: 'Ürün', cell: (m) => <span className="font-medium">{m.product?.name}</span> },
    { key: 'kind', header: 'Hareket türü', cell: (m) => <Badge tone={m.kind === 'purchase' || m.kind === 'return' ? 'success' : m.kind === 'delivery' ? 'primary' : 'neutral'}>{m.kind_label}</Badge> },
    { key: 'student', header: 'Öğrenci', cell: (m) => (m.student ? <Link to={`/ogrenciler/${m.student.id}`} className="hover:underline">{m.student.full_name}</Link> : <span className="text-ink-3">—</span>) },
    { key: 'note', header: 'Not', hideable: true, cell: (m) => <span className="text-ink-3">{m.note ?? '—'}{m.finance_entry_id ? ' · gelir/gider kaydı var' : ''}</span> },
    { key: 'user', header: 'Kaydeden', hideable: true, defaultHidden: true, cell: (m) => <span className="text-ink-3">{m.created_by ?? '—'}</span> },
    { key: 'qty', header: 'Adet (+giren / −çıkan)', align: 'right', cell: (m) => <span className={cn('font-semibold', m.quantity < 0 ? 'text-danger' : 'text-success')}>{m.quantity > 0 ? '+' : ''}{m.quantity}</span> },
  ]
  return (
    <DataTable
      storageKey="finance-stock-movements"
      columns={columns}
      rows={data?.data}
      rowKey={(r) => r.id}
      loading={isLoading || isFetching}
      meta={data?.meta}
      onPage={setPage}
      toolbar={
        <div className="flex flex-wrap items-center gap-2">
          <Select className="w-48" value={kind} onChange={(e) => { setKind(e.target.value); setPage(1) }} placeholder="Tüm hareketler" options={[{ value: 'purchase', label: 'Stok girişi' }, { value: 'delivery', label: 'Öğrenciye teslim' }, { value: 'return', label: 'İade' }, { value: 'adjustment', label: 'Sayım düzeltmesi' }]} />
          {productId && <Button size="sm" variant="soft" icon={<X className="size-3.5" />} onClick={onClearProduct}>{data?.data[0]?.product?.name ?? 'Ürün'} filtresini kaldır</Button>}
        </div>
      }
      empty={<EmptyState icon={<BookOpen />} title="Hareket yok" />}
    />
  )
}

function ProductForm({ target, subjects, onClose }: { target: Product | 'new' | null; subjects?: { id: number; name: string }[]; onClose: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ name: '', publisher: '', barcode: '', subject_id: '', purchase_price: '', sale_price: '', min_stock: '0', is_active: true })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  useEffect(() => {
    if (!target) return
    setErrors({})
    if (target === 'new') setForm({ name: '', publisher: '', barcode: '', subject_id: '', purchase_price: '', sale_price: '', min_stock: '0', is_active: true })
    else setForm({ name: target.name, publisher: target.publisher ?? '', barcode: target.barcode ?? '', subject_id: String(target.subject_id ?? ''), purchase_price: target.purchase_price.replace('.', ','), sale_price: target.sale_price.replace('.', ','), min_stock: String(target.min_stock), is_active: target.is_active })
  }, [target])
  const set = (k: keyof typeof form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))
  const save = useMutation({
    mutationFn: () => {
      const body = { ...form, subject_id: form.subject_id || null, purchase_price: fromCents(toCents(form.purchase_price) ?? 0), sale_price: fromCents(toCents(form.sale_price) ?? 0), min_stock: Number(form.min_stock || 0), barcode: form.barcode || null, publisher: form.publisher || null }
      return target === 'new' ? api.post<{ message: string }>('/finance/products', body) : api.put<{ message: string }>(`/finance/products/${(target as Product).id}`, body)
    },
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })
  return (
    <Modal
      open={!!target}
      onClose={onClose}
      title={target === 'new' ? 'Yeni ürün' : 'Ürünü düzenle'}
      description={target === 'new' ? 'Stok miktarı ürün kartından değil, stok girişi ile eklenir.' : undefined}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={form.name.trim().length < 2} onClick={() => save.mutate()}>Kaydet</Button></>}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Ürün adı" required className="sm:col-span-2" error={errors.name?.[0]}><Input value={form.name} onChange={(e) => set('name', e.target.value)} maxLength={200} /></Field>
        <Field label="Yayınevi" optional><Input value={form.publisher} onChange={(e) => set('publisher', e.target.value)} maxLength={120} /></Field>
        <Field label="Barkod" optional error={errors.barcode?.[0]}><Input value={form.barcode} onChange={(e) => set('barcode', e.target.value)} maxLength={40} inputMode="numeric" /></Field>
        <Field label="Ders" optional><Select value={form.subject_id} onChange={(e) => set('subject_id', e.target.value)} placeholder="Genel" options={(subjects ?? []).map((s) => ({ value: s.id, label: s.name }))} /></Field>
        <Field label="Kritik stok adedi" optional hint="Altına düşünce uyarı verilir"><Input type="number" min={0} value={form.min_stock} onChange={(e) => set('min_stock', e.target.value)} /></Field>
        <Field label="Alış fiyatı" optional><MoneyInput value={form.purchase_price} onChange={(v) => set('purchase_price', v)} /></Field>
        <Field label="Satış fiyatı" optional><MoneyInput value={form.sale_price} onChange={(v) => set('sale_price', v)} /></Field>
        <div className="sm:col-span-2"><Switch checked={form.is_active} onChange={(v) => set('is_active', v)} label="Aktif" /></div>
      </div>
    </Modal>
  )
}

function ActionModal({ action, onClose }: { action: Action | null; onClose: () => void }) {
  const can = useCan()
  const qc = useQueryClient()
  const accounts = useAccounts()
  const [qty, setQty] = useState('1')
  const [student, setStudent] = useState<PickedStudent | null>(null)
  const [note, setNote] = useState('')
  const [unit, setUnit] = useState('')
  const [charge, setCharge] = useState(false)
  const [accountId, setAccountId] = useState('')
  const [counted, setCounted] = useState('')
  const p = action?.product

  useEffect(() => {
    if (!action) return
    setQty('1'); setStudent(null); setNote(''); setCharge(false); setCounted(String(action.product.stock))
    setUnit((action.type === 'stock-in' ? action.product.purchase_price : action.product.sale_price).replace('.', ','))
    const cash = accounts.data?.data.find((a) => a.is_active && a.kind === 'cash')
    setAccountId(cash ? String(cash.id) : '')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [action])

  const qtyN = Math.max(0, Math.floor(Number(qty) || 0))
  const unitC = toCents(unit)
  const totalC = unitC !== null ? unitC * qtyN : null

  const save = useMutation({
    mutationFn: () => {
      if (!action || !p) throw new Error()
      const base = `/finance/products/${p.id}`
      switch (action.type) {
        case 'stock-in':
          return api.post<{ message: string }>(`${base}/stock-in`, { quantity: qtyN, unit_price: unitC !== null ? fromCents(unitC) : null, note: note || null, expense_account_id: charge ? Number(accountId) : null })
        case 'deliver':
          return api.post<{ message: string }>(`${base}/deliver`, { student_id: student?.id, quantity: qtyN, note: note || null, charge, unit_price: charge && unitC !== null ? fromCents(unitC) : null, income_account_id: charge ? Number(accountId) : null })
        case 'return':
          return api.post<{ message: string }>(`${base}/return`, { student_id: student?.id, quantity: qtyN, note: note || null })
        default:
          return api.post<{ message: string }>(`${base}/adjust`, { counted: Number(counted), note })
      }
    },
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  if (!action || !p) return null
  const titles = { 'stock-in': 'Stok girişi', deliver: 'Öğrenciye teslim', return: 'Öğrenciden iade', adjust: 'Sayım düzeltmesi' }
  const needsStudent = action.type === 'deliver' || action.type === 'return'
  const invalid =
    (action.type !== 'adjust' && qtyN < 1) ||
    (needsStudent && !student) ||
    (action.type === 'deliver' && qtyN > p.stock) ||
    (charge && (!unitC || unitC <= 0 || !accountId)) ||
    (action.type === 'adjust' && (counted === '' || Number(counted) === p.stock || note.trim().length < 3))

  return (
    <Modal
      open
      onClose={onClose}
      title={titles[action.type]}
      description={`${p.name} · mevcut stok ${p.stock}`}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={invalid} onClick={() => save.mutate()}>Kaydet</Button></>}
    >
      <div className="flex flex-col gap-3">
        {needsStudent && <Field label="Öğrenci" required><StudentPicker value={student} onChange={setStudent} autoFocus /></Field>}
        {action.type === 'adjust' ? (
          <Field label="Sayılan adet" required hint={counted !== '' ? `Fark: ${Number(counted) - p.stock > 0 ? '+' : ''}${Number(counted) - p.stock}` : undefined}>
            <Input type="number" min={0} value={counted} onChange={(e) => setCounted(e.target.value)} autoFocus />
          </Field>
        ) : (
          <Field label="Adet" required error={action.type === 'deliver' && qtyN > p.stock ? `Stokta ${p.stock} adet var.` : null}>
            <Input type="number" min={1} value={qty} onChange={(e) => setQty(e.target.value)} />
          </Field>
        )}

        {action.type === 'stock-in' && (
          <>
            <Field label="Birim alış fiyatı" optional><MoneyInput value={unit} onChange={setUnit} /></Field>
            {can('expenses.manage') && <Switch checked={charge} onChange={setCharge} label="Alımı gider olarak kaydet" />}
          </>
        )}
        {action.type === 'deliver' && can('expenses.manage') && (
          <>
            <Switch checked={charge} onChange={setCharge} label="Satış ücreti al (gelir kaydı)" />
            {charge && <Field label="Birim satış fiyatı" required><MoneyInput value={unit} onChange={setUnit} /></Field>}
          </>
        )}
        {charge && (
          <>
            <Field label={action.type === 'stock-in' ? 'Ödemenin çıktığı hesap' : 'Tutarın girdiği hesap'} required>
              <AccountSelect accounts={accounts.data?.data} value={accountId} onChange={setAccountId} />
            </Field>
            {totalC !== null && totalC > 0 && <Alert tone="info">{action.type === 'stock-in' ? 'Gider' : 'Gelir'} kaydı: {money(totalC / 100)}</Alert>}
          </>
        )}
        <Field label={action.type === 'adjust' ? 'Açıklama' : 'Not'} required={action.type === 'adjust'} optional={action.type !== 'adjust'}>
          <Textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} maxLength={300} />
        </Field>
      </div>
    </Modal>
  )
}
