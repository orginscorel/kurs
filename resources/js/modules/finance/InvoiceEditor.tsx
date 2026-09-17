import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, CheckCircle2, Download, FileText, Link2, Plus, Printer, RotateCcw, Save, Send, Trash2, Undo2 } from 'lucide-react'
import { api, ApiError, idempotencyKey } from '@/lib/api'
import { date, dateTime, money, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import { MetricRow, MoneyInput, StudentPicker, VoidDialog, type PickedStudent } from './components'
import { openPdf, toCents } from './shared'
import {
  BUYER_TYPES, DOCUMENT_TYPES, INTEGRATOR_STATUS, INVOICE_KIND, INVOICE_STATUS, JOURNAL_EVENTS,
  type InvoiceDetail, type InvoiceLine, type InvoiceOptions,
} from './ledger'

type Form = {
  document_type: string
  issue_date: string
  buyer_type: string
  buyer_id: number | null
  buyer_name: string
  buyer_tax_id: string
  buyer_tax_office: string
  buyer_address: string
  buyer_email: string
  buyer_phone: string
  student: PickedStudent | null
  enrollment_id: number | null
  prices_include_vat: boolean
  notes: string
  lines: InvoiceLine[]
  payments: { payment_id: number; amount: string; receipt_no?: string }[]
}

type Totals = { gross_total: string; discount_total: string; net_total: string; vat_total: string; withholding_total: string; grand_total: string; payable_total: string; vat_breakdown: { rate: string; net: string; vat: string }[] }
type StudentContext = { guardians: { id: number; name: string; phone: string | null; is_financially_responsible: boolean; is_primary: boolean }[] }

const blankLine = (o?: InvoiceOptions): InvoiceLine => ({
  description: '', quantity: '1', unit: o?.default_unit ?? 'ADET', unit_price: '', discount_rate: '', discount_amount: '', vat_rate: o?.default_vat_rate ?? '10', withholding_tenths: 0,
})

const toInput = (v: string | null | undefined) => (v === null || v === undefined || v === '' ? '' : String(v).replace('.', ','))

/** /finans/faturalar/yeni ve /finans/faturalar/:id — taslakta düzenleme formu, kesilmişte salt okunur görünüm. */
export default function InvoiceEditor() {
  const { id } = useParams()
  const isNew = !id || id === 'yeni'
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const enrollmentParam = Number(params.get('kayit') || 0) || null
  const fromEnrollment = useRef(false)

  // Kayıttan taslak: sunucuda oluşturup ayrıntıya yönlen
  const createFromEnrollment = useMutation({
    mutationFn: () => api.post<{ id: number; message: string }>(`/finance/invoices/from-enrollment/${enrollmentParam}`),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance'] })
      navigate(`/finans/faturalar/${res.id}`, { replace: true })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Taslak oluşturulamadı.'),
  })
  useEffect(() => {
    if (isNew && enrollmentParam && !fromEnrollment.current) {
      fromEnrollment.current = true
      createFromEnrollment.mutate()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isNew, enrollmentParam])

  const detail = useQuery({
    queryKey: ['finance', 'invoice', id],
    queryFn: () => api.get<{ data: InvoiceDetail }>(`/finance/invoices/${id}`),
    enabled: !isNew,
  })
  const options = useQuery({ queryKey: ['finance', 'invoice-options'], queryFn: () => api.get<{ data: InvoiceOptions }>('/finance/invoices/options'), staleTime: 60_000 })

  if (isNew && enrollmentParam) return <Skeleton className="h-96" />
  if (!isNew && detail.isLoading) return <Skeleton className="h-96" />
  if (!isNew && !detail.data) return <EmptyState icon={<FileText />} title="Fatura bulunamadı" action={<ButtonLink to="/finans/faturalar">Faturalara dön</ButtonLink>} />
  if (!options.data) return <Skeleton className="h-96" />

  const inv = detail.data?.data
  if (inv && inv.status !== 'draft') return <InvoiceView inv={inv} />
  return <DraftForm key={inv?.id ?? 'new'} inv={inv} options={options.data.data} />
}

// ====================================================================== taslak formu

function DraftForm({ inv, options }: { inv?: InvoiceDetail; options: InvoiceOptions }) {
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const key = useRef(idempotencyKey())
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [issueOpen, setIssueOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [form, setForm] = useState<Form>(() =>
    inv
      ? {
          document_type: inv.document_type, issue_date: inv.issue_date, buyer_type: inv.buyer_type, buyer_id: inv.buyer_id, buyer_name: inv.buyer_name,
          buyer_tax_id: inv.buyer_tax_id ?? '', buyer_tax_office: inv.buyer_tax_office ?? '', buyer_address: inv.buyer_address ?? '',
          buyer_email: inv.buyer_email ?? '', buyer_phone: inv.buyer_phone ?? '',
          student: inv.student ? { id: inv.student.id, full_name: inv.student.full_name, student_no: inv.student.student_no } : null,
          enrollment_id: inv.enrollment_id, prices_include_vat: inv.prices_include_vat, notes: inv.notes ?? '',
          lines: inv.lines.map((l) => ({ ...l, unit_price: toInput(l.unit_price), discount_rate: Number(l.discount_rate) ? toInput(l.discount_rate) : '', discount_amount: Number(l.discount_rate) ? '' : Number(l.discount_amount) ? toInput(l.discount_amount) : '', quantity: toInput(String(Number(l.quantity))), vat_rate: String(Number(l.vat_rate)) })),
          payments: inv.payments.map((p) => ({ payment_id: p.id, amount: p.linked, receipt_no: p.receipt_no })),
        }
      : {
          document_type: options.default_document_type, issue_date: todayISO(), buyer_type: 'guardian', buyer_id: null, buyer_name: '', buyer_tax_id: '',
          buyer_tax_office: '', buyer_address: '', buyer_email: '', buyer_phone: '', student: null, enrollment_id: null,
          prices_include_vat: options.prices_include_vat, notes: '', lines: [blankLine(options)], payments: [],
        },
  )
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }))
  const setLine = (i: number, patch: Partial<InvoiceLine>) => setForm((f) => ({ ...f, lines: f.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)) }))

  // ?ogrenci= ile gelindiyse öğrenciyi seç
  const studentParam = Number(params.get('ogrenci') || 0) || null
  const ctx = useQuery({
    queryKey: ['finance', 'student-context', form.student?.id ?? studentParam],
    queryFn: () => api.get<StudentContext & { student: PickedStudent }>(`/finance/students/${form.student?.id ?? studentParam}/context`),
    enabled: !!(form.student?.id ?? studentParam),
  })
  // Öğrenci seçilince alıcı, ödeme sorumlusu veliden otomatik doldurulur (elle yazılmış alıcı korunur)
  const autoBuyer = useRef(!inv)
  useEffect(() => {
    const data = ctx.data
    if (inv || !data) return
    const s = data.student
    setForm((f) => {
      const next = { ...f, student: f.student ?? { id: s.id, full_name: s.full_name, student_no: s.student_no } }
      if (autoBuyer.current || !f.buyer_name) {
        const g = data.guardians.find((x) => x.is_financially_responsible) ?? data.guardians[0]
        Object.assign(next, g ? { buyer_type: 'guardian', buyer_id: g.id, buyer_name: g.name } : { buyer_type: 'student', buyer_id: s.id, buyer_name: s.full_name })
      }
      return next
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ctx.data])
  const pickStudent = (s: PickedStudent | null) => setForm((f) => ({ ...f, student: s }))

  // Canlı hesap (sunucu kuralıyla)
  const payload = {
    prices_include_vat: form.prices_include_vat,
    lines: form.lines.map((l) => ({ quantity: l.quantity || '0', unit_price: l.unit_price || '0', discount_rate: l.discount_rate || null, discount_amount: l.discount_amount || null, vat_rate: l.vat_rate, withholding_tenths: l.withholding_tenths })),
  }
  const debounced = useDebounced(JSON.stringify(payload), 350)
  const preview = useQuery({
    queryKey: ['finance', 'invoice-preview', debounced],
    queryFn: () => api.post<{ data: { lines: InvoiceLine[]; totals: Totals } }>('/finance/invoices/preview', JSON.parse(debounced)),
    enabled: form.lines.every((l) => l.vat_rate !== ''),
    retry: false,
    placeholderData: (p) => p,
  })
  const totals = preview.data?.data.totals
  const linkedCents = form.payments.reduce((s, p) => s + (toCents(p.amount) ?? 0), 0)

  const body = () => ({
    document_type: form.document_type, issue_date: form.issue_date, buyer_type: form.buyer_type, buyer_id: form.buyer_id,
    buyer_name: form.buyer_name, buyer_tax_id: form.buyer_tax_id || null, buyer_tax_office: form.buyer_tax_office || null,
    buyer_address: form.buyer_address || null, buyer_email: form.buyer_email || null, buyer_phone: form.buyer_phone || null,
    student_id: form.student?.id ?? null, enrollment_id: form.enrollment_id, prices_include_vat: form.prices_include_vat, notes: form.notes || null,
    lines: form.lines.map((l) => ({ description: l.description, quantity: l.quantity, unit: l.unit, unit_price: l.unit_price, discount_rate: l.discount_rate || null, discount_amount: l.discount_amount || null, vat_rate: l.vat_rate, withholding_tenths: l.withholding_tenths })),
    payments: form.payments.map((p) => ({ payment_id: p.payment_id, amount: p.amount })),
    idempotency_key: key.current,
  })

  const onError = (e: unknown) => {
    if (e instanceof ApiError) {
      setErrors(e.errors)
      toast.error(e.firstError())
    } else toast.error('Kaydedilemedi.')
  }
  // Yeni taslak ilk kayıttan sonra hep PUT ile güncellenir (aynı idempotency anahtarıyla eski taslağın dönmesi önlenir)
  const createdId = useRef<number | null>(inv?.id ?? null)
  const persist = async () => {
    if (createdId.current) return api.put<{ id: number; message: string }>(`/finance/invoices/${createdId.current}`, body())
    const res = await api.post<{ id: number; message: string }>('/finance/invoices', body())
    createdId.current = res.id
    return res
  }
  const save = useMutation({
    mutationFn: persist,
    onSuccess: (res) => {
      setErrors({})
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance'] })
      if (!inv) navigate(`/finans/faturalar/${res.id}`, { replace: true })
    },
    onError,
  })
  const issue = useMutation({
    mutationFn: async () => {
      const saved = await persist()
      return api.post<{ message: string; id: number }>(`/finance/invoices/${saved.id}/issue`)
    },
    onSuccess: (res) => {
      toast.success(res.message)
      setIssueOpen(false)
      qc.invalidateQueries({ queryKey: ['finance'] })
      navigate(`/finans/faturalar/${res.id}`, { replace: true })
    },
    onError: (e) => {
      setIssueOpen(false)
      onError(e)
      qc.invalidateQueries({ queryKey: ['finance'] })
      if (!inv && createdId.current) navigate(`/finans/faturalar/${createdId.current}`, { replace: true })
    },
  })
  const remove = useMutation({
    mutationFn: () => api.delete<{ message: string }>(`/finance/invoices/${inv!.id}`),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance'] })
      navigate('/finans/faturalar?status=draft', { replace: true })
    },
    onError,
  })

  const canEdit = can('finance.invoice')
  const err = (k: string) => errors[k]?.[0] ?? null
  const valid = form.buyer_name.trim().length >= 2 && form.lines.every((l) => l.description.trim().length >= 2 && toCents(l.unit_price) !== null) && !!totals && Number(totals.payable_total) > 0
  const isReturn = inv?.kind === 'return'

  return (
    <div className="animate-fade-in">
      <PageHeader
        title={inv ? `${isReturn ? 'İade faturası' : 'Fatura'} taslağı #${inv.id}` : 'Yeni fatura'}
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Faturalar', to: '/finans/faturalar' }, { label: inv ? `Taslak #${inv.id}` : 'Yeni' }]}
        description="Taslak numara almaz ve muhasebeye yansımaz. Kesilen fatura değiştirilemez; düzeltme iptal ya da iade faturasıyla yapılır."
        actions={
          <>
            {inv && <Button icon={<Printer className="size-4" />} onClick={() => openPdf(`/finance/invoices/${inv.id}/pdf`).catch((e) => toast.error(e.message))}>Önizle</Button>}
            {inv && canEdit && <Button variant="danger-soft" icon={<Trash2 className="size-4" />} onClick={() => setDeleteOpen(true)}>Sil</Button>}
          </>
        }
      />
      {!canEdit && <Alert tone="warning" className="mb-4">Fatura düzenleme yetkiniz yok; yalnız görüntüleyebilirsiniz.</Alert>}
      {inv?.related && <Alert tone="info" className="mb-4">Bu taslak <Link className="font-medium underline" to={`/finans/faturalar/${inv.related.id}`}>{inv.related.invoice_no}</Link> numaralı faturanın iadesidir. İade edilecek miktar/tutarları düzenleyin.</Alert>}

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_340px] gap-4 items-start">
        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="Alıcı">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <Field label="Öğrenci" optional hint="Muavin ve ekstre için öğrenciye bağlanır">
                <StudentPicker value={form.student} onChange={pickStudent} placeholder="Öğrenci ara" />
              </Field>
              <Field label="Alıcı türü" required error={err('buyer_type')}>
                <Select value={form.buyer_type} onChange={(e) => set('buyer_type', e.target.value)} options={Object.entries(BUYER_TYPES).map(([value, label]) => ({ value, label }))} />
              </Field>
              {form.student && ctx.data && ctx.data.guardians.length > 0 && (
                <div className="md:col-span-2 -mt-1 flex flex-wrap items-center gap-1.5">
                  <span className="text-[12px] text-ink-3">Alıcı olarak seç:</span>
                  {ctx.data.guardians.map((g) => (
                    <Button key={g.id} size="xs" variant={form.buyer_id === g.id && form.buyer_type === 'guardian' ? 'soft' : 'ghost'} onClick={() => setForm((f) => ({ ...f, buyer_type: 'guardian', buyer_id: g.id, buyer_name: g.name }))}>
                      {g.name}{g.is_financially_responsible ? ' · ödeme sorumlusu' : ''}
                    </Button>
                  ))}
                  <Button size="xs" variant={form.buyer_type === 'student' ? 'soft' : 'ghost'} onClick={() => setForm((f) => ({ ...f, buyer_type: 'student', buyer_id: f.student!.id, buyer_name: f.student!.full_name }))}>Öğrencinin kendisi</Button>
                </div>
              )}
              <Field label="Alıcı adı soyadı / unvanı" required error={err('buyer_name')}>
                <Input value={form.buyer_name} onChange={(e) => { autoBuyer.current = false; set('buyer_name', e.target.value) }} maxLength={200} />
              </Field>
              <Field label="TCKN / VKN" optional hint="11 hane TCKN ya da 10 hane VKN" error={err('buyer_tax_id')}>
                <Input inputMode="numeric" value={form.buyer_tax_id} onChange={(e) => set('buyer_tax_id', e.target.value.replace(/\D/g, '').slice(0, 11))} />
              </Field>
              <Field label="Vergi dairesi" optional error={err('buyer_tax_office')}><Input value={form.buyer_tax_office} onChange={(e) => set('buyer_tax_office', e.target.value)} maxLength={120} /></Field>
              <Field label="Telefon" optional error={err('buyer_phone')}><Input inputMode="tel" placeholder="05xx xxx xx xx" value={form.buyer_phone} onChange={(e) => set('buyer_phone', e.target.value)} maxLength={30} /></Field>
              <Field label="Adres" optional className="md:col-span-2" error={err('buyer_address')}><Input value={form.buyer_address} onChange={(e) => set('buyer_address', e.target.value)} maxLength={400} /></Field>
              <Field label="E-posta" optional error={err('buyer_email')}><Input type="email" value={form.buyer_email} onChange={(e) => set('buyer_email', e.target.value)} /></Field>
            </div>
          </Panel>

          <Panel
            title="Kalemler"
            description={form.prices_include_vat ? 'Birim fiyatlar KDV DAHİL girilir' : 'Birim fiyatlar KDV HARİÇ girilir'}
            actions={<Switch checked={form.prices_include_vat} onChange={(v) => set('prices_include_vat', v)} label="KDV dahil" />}
          >
            <div className="flex flex-col gap-3">
              {form.lines.map((l, i) => {
                const calc = preview.data?.data.lines[i]
                return (
                  <div key={i} className="rounded-[var(--radius-md)] bg-surface-2/50 p-3 ring-1 ring-line">
                    <div className="grid grid-cols-2 sm:grid-cols-6 gap-2 items-end">
                      <Field label={`${i + 1}. kalem açıklaması`} required className="col-span-2 sm:col-span-6" error={err(`lines.${i}.description`)}>
                        <Input value={l.description} onChange={(e) => setLine(i, { description: e.target.value })} placeholder="Örn. YKS hazırlık eğitim hizmeti (Eylül)" maxLength={300} />
                      </Field>
                      <Field label="Miktar" required error={err(`lines.${i}.quantity`)}><Input inputMode="decimal" value={l.quantity} onChange={(e) => setLine(i, { quantity: e.target.value.replace(/[^\d.,]/g, '') })} className="text-right tabular" /></Field>
                      <Field label="Birim">
                        <Select value={l.unit} onChange={(e) => setLine(i, { unit: e.target.value })} options={[...new Set([...options.units, l.unit])].map((u) => ({ value: u, label: u }))} />
                      </Field>
                      <Field label="Birim fiyat" required className="col-span-2" error={err(`lines.${i}.unit_price`)}><MoneyInput value={l.unit_price} onChange={(v) => setLine(i, { unit_price: v })} /></Field>
                      <Field label="KDV oranı" required error={err(`lines.${i}.vat_rate`)}>
                        <Select value={l.vat_rate} onChange={(e) => setLine(i, { vat_rate: e.target.value })} options={[...new Set([...options.vat_rates, l.vat_rate])].map((r) => ({ value: r, label: `%${r}` }))} />
                      </Field>
                      <Field label="İskonto (%)" optional error={err(`lines.${i}.discount_rate`)}><Input inputMode="decimal" value={l.discount_rate} placeholder="0" onChange={(e) => setLine(i, { discount_rate: e.target.value.replace(/[^\d.,]/g, ''), discount_amount: '' })} className="text-right tabular" /></Field>
                      <Field label="ya da iskonto tutarı (₺)" optional className="col-span-2" error={err(`lines.${i}.discount_amount`)}><MoneyInput value={l.discount_amount} placeholder="0,00" onChange={(v) => setLine(i, { discount_amount: v, discount_rate: '' })} /></Field>
                      {options.withholding_enabled && (
                        <Field label="Tevkifat" className="col-span-2">
                          <Select value={String(l.withholding_tenths)} onChange={(e) => setLine(i, { withholding_tenths: Number(e.target.value) })}
                            options={[{ value: '0', label: 'Yok' }, ...[2, 3, 4, 5, 7, 9, 10].map((n) => ({ value: String(n), label: `${n}/10` }))]} />
                        </Field>
                      )}
                      <div className="col-span-2 sm:col-span-6 flex flex-wrap items-center justify-between gap-2 pt-1 text-[12.5px]">
                        <span className="text-ink-3 tabular">
                          {calc ? <>Matrah {money(calc.net_amount)} · KDV {money(calc.vat_amount)}{Number(calc.withholding_amount) > 0 ? ` · tevkifat ${money(calc.withholding_amount)}` : ''}</> : ' '}
                        </span>
                        <span className="flex items-center gap-2">
                          <span className="font-semibold tabular text-ink">{calc ? money(calc.total_amount) : '—'}</span>
                          {form.lines.length > 1 && (
                            <Button size="icon-sm" variant="ghost" aria-label="Kalemi sil" onClick={() => setForm((f) => ({ ...f, lines: f.lines.filter((_, j) => j !== i) }))}><Trash2 className="size-3.5" /></Button>
                          )}
                        </span>
                      </div>
                    </div>
                  </div>
                )
              })}
              <Button variant="ghost" icon={<Plus className="size-4" />} className="self-start" onClick={() => setForm((f) => ({ ...f, lines: [...f.lines, blankLine(options)] }))}>Kalem ekle</Button>
            </div>
          </Panel>

          {form.payments.length > 0 && (
            <Panel title="Bu faturaya mahsup edilecek tahsilatlar" description={`Toplam ${money(linkedCents / 100)}`} flush>
              <ul className="divide-y divide-line">
                {form.payments.map((p) => (
                  <li key={p.payment_id} className="flex items-center gap-3 px-4 py-2.5 text-[13px]">
                    <span className="flex-1 font-medium tabular">{p.receipt_no ?? `#${p.payment_id}`}</span>
                    <span className="tabular">{money(p.amount)}</span>
                    <Button size="icon-sm" variant="ghost" aria-label="Çıkar" onClick={() => set('payments', form.payments.filter((x) => x.payment_id !== p.payment_id))}><Trash2 className="size-3.5" /></Button>
                  </li>
                ))}
              </ul>
            </Panel>
          )}

          <Panel title="Fatura notu (isteğe bağlı)">
            <Textarea value={form.notes} onChange={(e) => set('notes', e.target.value)} rows={2} maxLength={1000} placeholder="Faturada görünecek açıklama" />
          </Panel>
        </div>

        <div className="xl:sticky xl:top-4 flex flex-col gap-3">
          <Panel title="Belge">
            <div className="flex flex-col gap-3">
              <Field label="Belge türü" optional hint="Boşsa ayardaki varsayılan"><Select value={form.document_type} onChange={(e) => set('document_type', e.target.value)} options={Object.entries(DOCUMENT_TYPES).map(([value, label]) => ({ value, label }))} /></Field>
              <Field label="Düzenleme tarihi" required hint="Son kesilen faturadan önce olamaz" error={err('issue_date')}><Input type="date" value={form.issue_date} max={todayISO()} onChange={(e) => set('issue_date', e.target.value)} /></Field>
            </div>
          </Panel>
          <Panel title="Toplam">
            {!totals ? (
              <Skeleton className="h-32" />
            ) : (
              <div className={cn('divide-y divide-line', preview.isFetching && 'opacity-60')}>
                <MetricRow label="Mal / hizmet toplamı" value={money(totals.gross_total)} />
                {Number(totals.discount_total) > 0 && <MetricRow label="İskonto" value={`− ${money(totals.discount_total)}`} />}
                <MetricRow label="KDV matrahı" value={money(totals.net_total)} />
                {totals.vat_breakdown.map((b) => <MetricRow key={b.rate} label={`KDV %${Number(b.rate)}`} value={money(b.vat)} />)}
                {Number(totals.withholding_total) > 0 && <MetricRow label="Tevkifat" value={`− ${money(totals.withholding_total)}`} />}
                <MetricRow label="Ödenecek" value={<span className="text-[17px]">{money(totals.payable_total)}</span>} strong />
                {linkedCents > 0 && <MetricRow label="Mahsup edilen tahsilat" value={money(linkedCents / 100)} tone={linkedCents > (toCents(totals.payable_total) ?? 0) ? 'danger' : 'success'} />}
              </div>
            )}
            {preview.isError && <p className="mt-2 text-[12px] text-danger">{preview.error instanceof ApiError ? preview.error.firstError() : 'Hesaplanamadı.'}</p>}
          </Panel>
          {canEdit && (
            <div className="flex flex-col gap-2">
              <Button variant="primary" size="lg" icon={<CheckCircle2 className="size-4" />} disabled={!valid} loading={issue.isPending} onClick={() => setIssueOpen(true)}>Kaydet ve kes</Button>
              <Button icon={<Save className="size-4" />} disabled={!form.buyer_name.trim()} loading={save.isPending} onClick={() => save.mutate()}>Taslak olarak kaydet</Button>
            </div>
          )}
          <p className="px-1 text-[12px] text-ink-3">KDV oranları ayarlardan gelir ve kurum muhasebecisiyle teyit edilmelidir. Entegratör: {options.integrator.label}.</p>
        </div>
      </div>

      <ConfirmDialog
        open={issueOpen}
        onClose={() => setIssueOpen(false)}
        onConfirm={() => issue.mutate()}
        loading={issue.isPending}
        title={isReturn ? 'İade faturası kesilsin mi?' : 'Fatura kesilsin mi?'}
        confirmLabel="Kes"
        description={totals ? `${form.buyer_name} · ${money(totals.payable_total)} · ${date(form.issue_date)}` : undefined}
      >
        <Alert tone="warning">
          Kesilen fatura numaralanır ({isReturn ? options.return_prefix : options.invoice_prefix}…), muhasebe fişi oluşur ve <b>artık değiştirilemez</b>. Hata olursa iptal ya da iade faturası gerekir.
        </Alert>
      </ConfirmDialog>
      <ConfirmDialog open={deleteOpen} onClose={() => setDeleteOpen(false)} onConfirm={() => remove.mutate()} loading={remove.isPending} danger title="Taslak silinsin mi?" confirmLabel="Sil"
        description="Taslak ve tahsilat bağlantıları silinir; tahsilatlar yeniden faturalanabilir hâle gelir." />
    </div>
  )
}

// ====================================================================== kesilmiş / iptal fatura görünümü

function InvoiceView({ inv }: { inv: InvoiceDetail }) {
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [cancelOpen, setCancelOpen] = useState(false)
  const [linkOpen, setLinkOpen] = useState(false)
  const returnKey = useRef(idempotencyKey())
  const refresh = () => qc.invalidateQueries({ queryKey: ['finance'] })
  const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')

  const cancel = useMutation({
    mutationFn: (reason: string) => api.post<{ message: string }>(`/finance/invoices/${inv.id}/cancel`, { reason }),
    onSuccess: (r) => { toast.success(r.message); setCancelOpen(false); refresh() },
    onError: fail,
  })
  const makeReturn = useMutation({
    mutationFn: () => api.post<{ message: string; id: number }>(`/finance/invoices/${inv.id}/return`, { idempotency_key: returnKey.current }),
    onSuccess: (r) => { toast.success(r.message); refresh(); navigate(`/finans/faturalar/${r.id}`) },
    onError: fail,
  })
  const send = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/finance/invoices/${inv.id}/send`),
    onSuccess: (r) => { toast.info(r.message); refresh() },
    onError: fail,
  })

  const cancelled = inv.status === 'cancelled'
  const canManage = can('finance.invoice')
  const openCents = toCents(inv.open_amount) ?? 0

  return (
    <div className="animate-fade-in">
      <PageHeader
        title={
          <span className="inline-flex flex-wrap items-center gap-2">
            <span className="tabular">{inv.invoice_no}</span>
            <Badge tone={INVOICE_STATUS[inv.status]?.tone} dot>{INVOICE_STATUS[inv.status]?.label}</Badge>
            <Badge tone={INVOICE_KIND[inv.kind]?.tone}>{INVOICE_KIND[inv.kind]?.label}</Badge>
          </span>
        }
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Faturalar', to: '/finans/faturalar' }, { label: inv.invoice_no ?? '' }]}
        description={`${DOCUMENT_TYPES[inv.document_type] ?? ''} · ${date(inv.issue_date)} · ${inv.buyer_name}`}
        actions={
          <>
            <Button icon={<Printer className="size-4" />} onClick={() => openPdf(`/finance/invoices/${inv.id}/pdf`).catch((e) => toast.error(e.message))}>Yazdır</Button>
            <Button icon={<Download className="size-4" />} onClick={() => api.download(`/finance/invoices/${inv.id}/pdf`, undefined, `fatura-${inv.invoice_no}.pdf`).catch((e) => toast.error(e.message))}>PDF</Button>
            {canManage && !cancelled && inv.kind === 'sales' && (
              <Button variant="warning" icon={<Undo2 className="size-4" />} loading={makeReturn.isPending} onClick={() => makeReturn.mutate()}>İade faturası</Button>
            )}
            {canManage && !cancelled && <Button variant="danger-soft" icon={<Ban className="size-4" />} onClick={() => setCancelOpen(true)}>İptal et</Button>}
          </>
        }
      />
      {cancelled && (
        <Alert tone="danger" className="mb-4" title="Bu fatura iptal edildi">
          {dateTime(inv.cancelled_at)} · {inv.cancelled_by ?? 'Yetkili'} — {inv.cancel_reason}. Muhasebe fişi ters kayıtla kapatıldı; bağlı tahsilatlar yeniden faturalanabilir.
        </Alert>
      )}

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_340px] gap-4 items-start">
        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="Kalemler" flush>
            <div className="overflow-x-auto scroll-thin">
              <table className="tbl w-full min-w-[620px] text-[13px]">
                <thead>
                  <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
                    <th className="h-9 px-4 font-medium text-left">Açıklama</th>
                    <th className="px-3 font-medium text-center">Miktar</th>
                    <th className="px-3 font-medium text-center">Birim fiyat</th>
                    <th className="px-3 font-medium text-center">İskonto</th>
                    <th className="px-3 font-medium text-center">KDV</th>
                    <th className="px-4 font-medium text-center">Tutar</th>
                  </tr>
                </thead>
                <tbody>
                  {inv.lines.map((l) => (
                    <tr key={l.id} className="border-b border-line last:border-0">
                      <td className="px-4 py-2.5 text-left">{l.description}</td>
                      <td className="px-3 py-2.5 tabular whitespace-nowrap text-center">{Number(l.quantity).toLocaleString('tr-TR')} {l.unit}</td>
                      <td className="px-3 py-2.5 tabular whitespace-nowrap text-center">{money(l.unit_price)}</td>
                      <td className="px-3 py-2.5 tabular whitespace-nowrap text-center">{Number(l.discount_amount) ? money(l.discount_amount) : '—'}</td>
                      <td className="px-3 py-2.5 tabular whitespace-nowrap text-center">%{Number(l.vat_rate)} · {money(l.vat_amount)}</td>
                      <td className="px-4 py-2.5 tabular font-medium whitespace-nowrap text-center">{money(l.total_amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <Panel title="Alıcı">
              <div className="divide-y divide-line">
                <MetricRow label="Ad / unvan" value={inv.buyer_name} strong />
                <MetricRow label="Tür" value={BUYER_TYPES[inv.buyer_type] ?? inv.buyer_type} />
                <MetricRow label="TCKN / VKN" value={inv.buyer_tax_id ?? inv.buyer_tax_id_masked ?? '—'} />
                {inv.buyer_tax_office && <MetricRow label="Vergi dairesi" value={inv.buyer_tax_office} />}
                {inv.buyer_address && <MetricRow label="Adres" value={inv.buyer_address} />}
                {inv.student && <MetricRow label="Öğrenci" value={<Link to={`/ogrenciler/${inv.student.id}`} className="hover:underline">{inv.student.full_name}</Link>} />}
                {inv.related && <MetricRow label="İlgili fatura" value={<Link to={`/finans/faturalar/${inv.related.id}`} className="hover:underline tabular">{inv.related.invoice_no}</Link>} />}
              </div>
            </Panel>
            <Panel title="Kayıt izi">
              <div className="divide-y divide-line">
                <MetricRow label="Kesen" value={`${inv.issued_by ?? '—'} · ${dateTime(inv.issued_at)}`} />
                <MetricRow label="Hazırlayan" value={inv.created_by ?? '—'} />
                <MetricRow label="GİB / entegratör" value={<Badge tone={INTEGRATOR_STATUS[inv.integrator_status]?.tone ?? 'neutral'}>{INTEGRATOR_STATUS[inv.integrator_status]?.label ?? inv.integrator_status}</Badge>} />
                {inv.ettn && <MetricRow label="ETTN" value={<span className="text-[12px] tabular">{inv.ettn}</span>} />}
                {inv.journal.map((j) => (
                  <MetricRow key={j.id} label={`Fiş ${j.entry_no}`} value={<span className="inline-flex items-center gap-1.5"><Badge tone={JOURNAL_EVENTS[j.source_event]?.tone}>{JOURNAL_EVENTS[j.source_event]?.label ?? j.source_event}</Badge>{can('finance.accounting') && <Link to={`/finans/muhasebe?fis=${j.id}`} className="text-ink-3 hover:text-ink">aç</Link>}</span>} />
                ))}
              </div>
              {canManage && !cancelled && inv.integrator_status === 'not_sent' && (
                <Button size="sm" variant="ghost" className="mt-2" icon={<Send className="size-3.5" />} loading={send.isPending} onClick={() => send.mutate()}>Entegratöre gönder</Button>
              )}
            </Panel>
          </div>

          {inv.returns.length > 0 && (
            <Panel title="İade faturaları" flush>
              <ul className="divide-y divide-line">
                {inv.returns.map((r) => (
                  <li key={r.id} className="flex items-center gap-3 px-4 py-2.5 text-[13px]">
                    <Link to={`/finans/faturalar/${r.id}`} className="flex-1 font-medium tabular hover:underline">{r.invoice_no ?? `Taslak #${r.id}`}</Link>
                    <Badge tone={INVOICE_STATUS[r.status]?.tone}>{INVOICE_STATUS[r.status]?.label}</Badge>
                    <span className="tabular text-danger">−{money(r.payable_total)}</span>
                  </li>
                ))}
              </ul>
            </Panel>
          )}
        </div>

        <div className="xl:sticky xl:top-4 flex flex-col gap-3">
          <Panel title="Toplam">
            <div className="divide-y divide-line">
              <MetricRow label="Mal / hizmet" value={money(inv.gross_total)} />
              {Number(inv.discount_total) > 0 && <MetricRow label="İskonto" value={`− ${money(inv.discount_total)}`} />}
              <MetricRow label="Matrah" value={money(inv.net_total)} />
              {inv.vat_breakdown.map((b) => <MetricRow key={b.rate} label={`KDV %${Number(b.rate)}`} value={money(b.vat)} />)}
              {Number(inv.withholding_total) > 0 && <MetricRow label="Tevkifat" value={`− ${money(inv.withholding_total)}`} />}
              <MetricRow label="Ödenecek" value={<span className="text-[18px]">{money(inv.payable_total)}</span>} strong />
            </div>
            <p className="mt-2 text-[12px] text-ink-3">Yalnız: {inv.amount_words}</p>
          </Panel>
          {inv.kind === 'sales' && (
            <Panel
              title="Tahsilat durumu"
              description={openCents > 0 ? `${money(inv.open_amount)} açık` : 'Tamamı tahsilatla eşleşti'}
              actions={canManage && !cancelled && openCents > 0 ? <Button size="xs" variant="soft" icon={<Link2 className="size-3.5" />} onClick={() => setLinkOpen(true)}>Tahsilat bağla</Button> : undefined}
            >
              {inv.payments.length === 0 ? (
                <p className="text-[13px] text-ink-3">Bağlı tahsilat yok; tutar "Alıcılar" (120) hesabında alacak olarak durur.</p>
              ) : (
                <ul className="flex flex-col gap-1.5">
                  {inv.payments.map((p) => (
                    <li key={p.id} className="flex items-center justify-between gap-2 text-[13px]">
                      <Link to={`/finans/tahsilatlar?detay=${p.id}`} className="tabular hover:underline">{p.receipt_no}</Link>
                      <span className="tabular">{money(p.linked)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          )}
          {inv.notes && <Panel title="Not"><p className="text-[13px] text-ink-2 whitespace-pre-line">{inv.notes}</p></Panel>}
        </div>
      </div>

      <VoidDialog open={cancelOpen} onClose={() => setCancelOpen(false)} onConfirm={(r) => cancel.mutate(r)} loading={cancel.isPending}
        title="Faturayı iptal et" description={`${inv.invoice_no} · ${money(inv.payable_total)} · ${inv.buyer_name}`}>
        <Alert tone="warning" className="mb-3">Fatura silinmez; muhasebe fişi ters kayıtla kapanır. e-Arşiv düzenlendiyse GİB tarafında da iptal etmeyi unutmayın.</Alert>
      </VoidDialog>
      {linkOpen && <LinkPaymentModal inv={inv} onClose={() => setLinkOpen(false)} />}
    </div>
  )
}

function LinkPaymentModal({ inv, onClose }: { inv: InvoiceDetail; onClose: () => void }) {
  const qc = useQueryClient()
  const [paymentId, setPaymentId] = useState('')
  const [amount, setAmount] = useState('')
  const q = useQuery({
    queryKey: ['finance', 'unbilled', 'link', inv.student?.id],
    queryFn: () => api.get<{ data: { id: number; receipt_no: string; paid_at: string; available: string; student: string }[] }>('/finance/invoices/unbilled', { student_id: inv.student?.id, per_page: 50 }),
    enabled: !!inv.student,
  })
  const selected = useMemo(() => q.data?.data.find((p) => String(p.id) === paymentId), [q.data, paymentId])
  useEffect(() => {
    if (selected) {
      const cents = Math.min(toCents(selected.available) ?? 0, toCents(inv.open_amount) ?? 0)
      setAmount((cents / 100).toFixed(2).replace('.', ','))
    }
  }, [selected, inv.open_amount])
  const link = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/finance/invoices/${inv.id}/link-payment`, { payment_id: Number(paymentId), amount }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Bağlanamadı.'),
  })
  return (
    <Modal open onClose={onClose} title="Tahsilat bağla" description={`Fatura açığı ${money(inv.open_amount)}`}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" disabled={!paymentId || !amount} loading={link.isPending} onClick={() => link.mutate()}>Bağla</Button></>}>
      {!inv.student ? (
        <Alert tone="info">Faturada öğrenci seçili değil; tahsilat bağlamak için öğrenciye bağlı bir fatura gerekir.</Alert>
      ) : q.isLoading ? (
        <Skeleton className="h-20" />
      ) : !q.data?.data.length ? (
        <EmptyState compact icon={<RotateCcw />} title="Faturalanmamış tahsilat yok" description={`${inv.student.full_name} için bağlanabilecek tahsilat bulunamadı.`} />
      ) : (
        <div className="flex flex-col gap-3">
          <Field label="Bağlanacak tahsilat" required>
            <Select value={paymentId} onChange={(e) => setPaymentId(e.target.value)} placeholder="Seçin" options={q.data.data.map((p) => ({ value: p.id, label: `${p.receipt_no} · ${date(p.paid_at)} · ${money(p.available)}` }))} />
          </Field>
          <Field label="Bağlanacak tutar" optional hint="Boşsa tahsilatın faturalanmamış tutarının tamamı"><MoneyInput value={amount} onChange={setAmount} /></Field>
          <p className="text-[12px] text-ink-3">Muhasebede "alınan avans" (340) → "alıcılar" (120) mahsup fişi oluşur.</p>
        </div>
      )}
    </Modal>
  )
}
