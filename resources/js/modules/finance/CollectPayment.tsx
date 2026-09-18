import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CheckCircle2, CreditCard, Download, HandCoins, Printer, RotateCcw, Wallet } from 'lucide-react'
import { api, ApiError, idempotencyKey } from '@/lib/api'
import { date, dateTime, money, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel } from '@/components/ui/layout'
import { Alert, Avatar, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Checkbox, Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { AccountSelect, MetricRow, MoneyInput, StudentPicker, useAccounts, type PickedStudent } from './components'
import { allocate, fromCents, INSTALLMENT_STATUS, METHOD_ACCOUNT_KIND, METHODS, openPdf, toCents, type PaymentRow } from './shared'

type Context = {
  student: { id: number; full_name: string; student_no: string; status: string; status_label: string; photo_url: string | null }
  guardians: { id: number; name: string; phone: string | null; relationship: string | null; is_primary: boolean; is_financially_responsible: boolean }[]
  summary: { total: string; paid: string; remaining: string; overdue: string; open_count: number; credit?: string }
  installments: { id: number; enrollment_id: number; enrollment_no: string; program: string | null; term: string | null; sequence: number; due_date: string; amount: string; paid_amount: string; remaining: string; status: string; days_overdue: number }[]
  recent_payments: { id: number; receipt_no: string; amount: string; method: string; paid_at: string; account: string | null; voided_at: string | null }[]
}

const nowLocal = () => {
  const d = new Date()
  return `${todayISO()}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

/** Tek öğrenci tahsilatı. Sayfa başlığı ve sekmeler CollectPage sarmalayıcısındadır. */
export default function CollectPayment() {
  const can = useCan()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const studentId = Number(params.get('ogrenci') || 0) || null
  const preselect = useMemo(() => (params.get('taksit') ?? '').split(',').map(Number).filter(Boolean), [params])

  const [student, setStudent] = useState<PickedStudent | null>(null)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [amount, setAmount] = useState('')
  const [amountTouched, setAmountTouched] = useState(false)
  const [method, setMethod] = useState('cash')
  const [accountId, setAccountId] = useState('')
  const [accountTouched, setAccountTouched] = useState(false)
  const [paidAt, setPaidAt] = useState(nowLocal())
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const [payerId, setPayerId] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [overpayment, setOverpayment] = useState<'next' | 'credit'>('next')
  const [commissionRate, setCommissionRate] = useState('')
  const [cardInstallments, setCardInstallments] = useState('1')
  const [done, setDone] = useState<PaymentRow | null>(null)
  const key = useRef(idempotencyKey())

  const accounts = useAccounts()
  const ctx = useQuery({
    queryKey: ['finance', 'student-context', studentId],
    queryFn: () => api.get<Context>(`/finance/students/${studentId}/context`),
    enabled: !!studentId,
  })
  const data = ctx.data

  // URL'deki öğrenci bağlamdan doldurulur (öğrenci profilinden gelindiğinde)
  useEffect(() => {
    if (data && (!student || student.id !== data.student.id)) setStudent({ id: data.student.id, full_name: data.student.full_name, student_no: data.student.student_no, photo_url: data.student.photo_url })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data])

  // Öğrenci değişince seçim: URL'de taksit varsa onlar; yoksa yalnız gecikmiş ve 7 gün içinde vadesi gelenler.
  // Hiçbiri yoksa en yakın tek taksit. Tüm açık bakiyeyi varsayılan seçmek yanlışlıkla fazla tahsilata yol açıyordu.
  useEffect(() => {
    if (!data) return
    const ids = data.installments.map((i) => i.id)
    const pre = preselect.filter((id) => ids.includes(id))
    const horizon = new Date()
    horizon.setDate(horizon.getDate() + 7)
    const dueSoon = data.installments.filter((i) => i.days_overdue > 0 || new Date(i.due_date) <= horizon).map((i) => i.id)
    setSelected(new Set(pre.length ? pre : dueSoon.length ? dueSoon : ids.slice(0, 1)))
    setAmountTouched(false)
    const payer = data.guardians.find((g) => g.is_financially_responsible) ?? data.guardians[0]
    setPayerId(payer ? String(payer.id) : '')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data?.student.id])

  const selectedRows = useMemo(() => (data?.installments ?? []).filter((i) => selected.has(i.id)), [data, selected])
  const selectedCents = selectedRows.reduce((s, r) => s + (toCents(r.remaining) ?? 0), 0)
  const overdueSelectedCents = selectedRows.filter((r) => r.days_overdue > 0).reduce((s, r) => s + (toCents(r.remaining) ?? 0), 0)

  // Tutar elle değiştirilmediyse seçili taksitlerin toplamı
  useEffect(() => {
    if (!amountTouched) setAmount(selectedCents > 0 ? fromCents(selectedCents).replace('.', ',') : '')
  }, [selectedCents, amountTouched])

  // Yönteme göre hesap önerisi
  useEffect(() => {
    if (accountTouched || !accounts.data) return
    const kind = METHOD_ACCOUNT_KIND[method]
    const match = accounts.data.data.find((a) => a.is_active && a.kind === kind) ?? accounts.data.data.find((a) => a.is_active)
    if (match) setAccountId(String(match.id))
  }, [method, accounts.data, accountTouched])

  const amountCents = toCents(amount)
  const preview = useMemo(() => allocate(selectedRows, amountCents ?? 0), [selectedRows, amountCents])
  const excess = amountCents !== null && amountCents > selectedCents ? amountCents - selectedCents : 0
  const remainingCents = toCents(data?.summary.remaining) ?? 0
  const exceedsAll = amountCents !== null && amountCents > remainingCents
  // Fazla tutar: sonraki taksitlere aktarılır; tüm borcu da aşıyorsa kalan avans olarak tutulur
  const effectiveOverpayment = exceedsAll ? 'credit' : overpayment
  const exceeds = false
  const isCard = method === 'credit_card' || method === 'pos'
  const payer = data?.guardians.find((g) => String(g.id) === payerId)
  const canSubmit = !!data && amountCents !== null && amountCents > 0 && !exceeds && selected.size > 0 && !!accountId

  const pickStudent = (s: PickedStudent | null) => {
    setStudent(s)
    setDone(null)
    setErrors({})
    setParams((p) => {
      if (s) p.set('ogrenci', String(s.id))
      else p.delete('ogrenci')
      p.delete('taksit')
      return p
    }, { replace: true })
  }

  const save = useMutation({
    mutationFn: () =>
      api.post<{ message: string; duplicate: boolean; data: PaymentRow }>('/finance/payments', {
        student_id: data!.student.id,
        finance_account_id: Number(accountId),
        method,
        amount: fromCents(amountCents!),
        paid_at: paidAt ? paidAt.replace('T', ' ') + ':00' : null,
        installment_ids: [...selected],
        guardian_id: payer ? payer.id : null,
        payer_name: payer ? payer.name : null,
        reference: reference || null,
        note: note || null,
        idempotency_key: key.current,
        overpayment: excess > 0 ? effectiveOverpayment : 'reject',
        commission_rate: isCard && commissionRate ? commissionRate : null,
        card_installments: isCard ? Number(cardInstallments) : null,
      }),
    onSuccess: (res) => {
      setDone(res.data)
      setErrors({})
      toast.success(res.duplicate ? 'Bu tahsilat zaten kaydedilmişti.' : `${res.data.receipt_no} numaralı tahsilat kaydedildi.`)
      qc.invalidateQueries({ queryKey: ['finance'] })
      qc.invalidateQueries({ queryKey: ['student'] })
      qc.invalidateQueries({ queryKey: ['students'] })
      qc.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      } else toast.error('Tahsilat kaydedilemedi.')
    },
  })

  const reset = () => {
    key.current = idempotencyKey()
    setDone(null)
    setAmountTouched(false)
    setReference('')
    setNote('')
    setPaidAt(nowLocal())
    ctx.refetch()
  }

  const toggle = (id: number) =>
    setSelected((prev) => {
      const next = new Set(prev)
      next.has(id) ? next.delete(id) : next.add(id)
      return next
    })

  if (!can('payments.create')) {
    return <EmptyState icon={<HandCoins />} title="Tahsilat yetkiniz yok" description="Bu ekranı kullanmak için tahsilat alma yetkisi gerekir." />
  }

  return (
    <div className="animate-fade-in">
      <p className="mb-4 text-[13px] text-ink-2">Öğrenciyi seçin, açık taksitleri işaretleyin; tutar en eski vadeden başlayarak dağıtılır.</p>

      <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_380px] gap-4 items-start">
        <div className="flex flex-col gap-4 min-w-0">
          <Panel title="Öğrenci">
            <StudentPicker value={student} onChange={pickStudent} autoFocus={!studentId} />
            {ctx.isLoading && studentId && <Skeleton className="mt-3 h-16" />}
            {data && (
              <div className="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                <SummaryBox label="Ödeme planı toplamı" value={money(data.summary.total, { short: true })} />
                <SummaryBox label="Ödenen tutar" value={money(data.summary.paid, { short: true })} />
                <SummaryBox label="Kalan borç" value={money(data.summary.remaining, { short: true })} strong />
                <SummaryBox label="Vadesi geçmiş" value={money(data.summary.overdue, { short: true })} tone={Number(data.summary.overdue) > 0 ? 'danger' : undefined} />
              </div>
            )}
            {data && Number(data.summary.credit ?? 0) > 0 && (
              <Alert tone="success" className="mt-3" title={`${money(data.summary.credit)} kullanılabilir avans`}>
                Önceki fazla ödemeden kalan tutar. Tahsilatlar ekranında makbuz ayrıntısından açık taksitlere mahsup edebilirsiniz.
              </Alert>
            )}
          </Panel>

          {data && (
            <Panel
              title="Açık taksitler"
              description={data.installments.length ? `${selected.size} / ${data.installments.length} seçili · ${money(selectedCents / 100)}` : undefined}
              actions={
                data.installments.length > 0 && (
                  <>
                    <Button size="xs" variant="ghost" onClick={() => setSelected(new Set(data.installments.filter((i) => i.days_overdue > 0).map((i) => i.id)))}>Gecikmişler</Button>
                    <Button size="xs" variant="ghost" onClick={() => setSelected(new Set(data.installments.map((i) => i.id)))}>Tümü</Button>
                  </>
                )
              }
              flush
            >
              {errors.installment_ids?.[0] && <p className="px-4 pb-2 text-xs text-danger">{errors.installment_ids[0]}</p>}
              {data.installments.length === 0 ? (
                <EmptyState compact icon={<CheckCircle2 />} title="Açık taksit yok" description="Bu öğrencinin ödenmemiş taksiti bulunmuyor." />
              ) : (
                <div className="overflow-x-auto scroll-thin">
                  <table className="tbl w-full text-[13px]">
                    <thead>
                      <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
                        <th className="w-10 pl-4 text-left" />
                        <th className="fill h-9 px-2 font-medium text-center">Taksit / program</th>
                        <th className="px-3 font-medium text-center">Vade tarihi</th>
                        <th className="px-3 font-medium hidden sm:table-cell text-center">Taksit tutarı</th>
                        <th className="px-3 font-medium text-center">Kalan borç</th>
                        <th className="px-4 font-medium text-center">Bu ödemeden düşülecek</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.installments.map((i) => {
                        const portion = preview.get(i.id) ?? 0
                        const full = portion > 0 && portion >= (toCents(i.remaining) ?? 0)
                        return (
                          <tr key={i.id} onClick={() => toggle(i.id)} className={cn('cursor-pointer border-b border-line last:border-0', selected.has(i.id) ? 'bg-primary-soft/40' : 'hover:bg-surface-2/60')}>
                            <td className="pl-4 text-left" onClick={(e) => e.stopPropagation()}>
                              <Checkbox checked={selected.has(i.id)} onChange={() => toggle(i.id)} />
                            </td>
                            <td className="fill px-2 py-2.5 text-center">
                              <p className="font-medium">{i.sequence}. taksit</p>
                              <p className="text-[12px] text-ink-3 truncate max-w-[180px]">{i.program ?? i.enrollment_no}</p>
                            </td>
                            <td className="px-3 py-2.5 whitespace-nowrap text-center">
                              <p className="tabular">{date(i.due_date)}</p>
                              {i.days_overdue > 0 ? <Badge tone="danger">{i.days_overdue} gün gecikti</Badge> : i.status === 'partial' ? <Badge tone={INSTALLMENT_STATUS.partial!.tone}>Kısmi</Badge> : null}
                            </td>
                            <td className="px-3 py-2.5 tabular text-ink-2 hidden sm:table-cell text-center">{money(i.amount)}</td>
                            <td className="px-3 py-2.5 tabular font-medium text-center">{money(i.remaining)}</td>
                            <td className="px-4 py-2.5 tabular text-center">
                              {portion > 0 ? <span className={cn('font-medium', full ? 'text-success' : 'text-info')}>{money(portion / 100)}</span> : <span className="text-ink-3">—</span>}
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </Panel>
          )}

          {data && data.recent_payments.length > 0 && (
            <Panel title="Son tahsilatlar" flush>
              <ul className="divide-y divide-line">
                {data.recent_payments.map((p) => (
                  <li key={p.id} className={cn('flex items-center gap-3 px-4 py-2.5 text-[13px]', p.voided_at && 'opacity-60')}>
                    <span className="min-w-0 flex-1">
                      <span className="font-medium tabular">{p.receipt_no}</span>
                      <span className="block text-[12px] text-ink-3">{dateTime(p.paid_at)} · {METHODS[p.method]}{p.account ? ` · ${p.account}` : ''}</span>
                    </span>
                    {p.voided_at ? <Badge tone="danger">İptal edildi</Badge> : <span className="tabular font-medium">{money(p.amount)}</span>}
                  </li>
                ))}
              </ul>
            </Panel>
          )}

          {!studentId && (
            <EmptyState icon={<Wallet />} title="Tahsilat için öğrenci seçin" description="Ad, öğrenci numarası ya da telefonla arayın. Öğrenci profilinden de bu ekrana gelebilirsiniz." />
          )}
        </div>

        {/* Ödeme formu / başarı */}
        <div className="lg:sticky lg:top-4">
          {done ? (
            <Panel>
              <div className="flex flex-col items-center text-center py-2">
                <span className="grid size-12 place-items-center rounded-full bg-success-soft text-success"><CheckCircle2 className="size-6" /></span>
                <p className="mt-3 text-[15px] font-semibold">Tahsilat kaydedildi</p>
                <p className="text-[13px] text-ink-2">{done.student?.full_name}</p>
                <p className="mt-3 text-[28px] font-semibold tabular tracking-tight">{money(done.amount)}</p>
                <p className="text-[12.5px] text-ink-3 tabular">Makbuz no: {done.receipt_no} · {done.method_label} · {done.account?.name}</p>
              </div>
              <div className="mt-4 grid grid-cols-2 gap-2">
                <Button icon={<Printer className="size-4" />} onClick={() => openPdf(`/finance/payments/${done.id}/receipt`).catch((e) => toast.error(e.message))}>Yazdır</Button>
                <Button icon={<Download className="size-4" />} onClick={() => api.download(`/finance/payments/${done.id}/receipt`, undefined, `makbuz-${done.receipt_no}.pdf`).catch((e) => toast.error(e.message))}>PDF indir</Button>
              </div>
              <div className="mt-2 grid grid-cols-1 gap-2">
                <Button variant="primary" icon={<RotateCcw className="size-4" />} onClick={reset}>Aynı öğrenciden yeni tahsilat</Button>
                <Button variant="ghost" onClick={() => { reset(); pickStudent(null) }}>Başka öğrenci</Button>
                <ButtonLink variant="ghost" to={`/ogrenciler/${done.student?.id}`}>Öğrenci profiline git</ButtonLink>
              </div>
            </Panel>
          ) : (
            <Panel title="Ödeme bilgileri">
              <div className="flex flex-col gap-3.5">
                <Field label="Tutar" required error={errors.amount?.[0] ?? null}>
                  <MoneyInput
                    size="lg"
                    value={amount}
                    onChange={(v) => {
                      setAmount(v)
                      setAmountTouched(true)
                    }}
                    invalid={exceeds}
                  />
                </Field>
                {excess > 0 && data && (
                  <div className="-mt-1 flex flex-col gap-1.5 rounded-[var(--radius-md)] bg-info-soft px-3 py-2 ring-1 ring-info/25">
                    <p className="text-[12.5px] text-ink-2">
                      Seçili taksitlerden <b className="tabular">{money(excess / 100)}</b> fazla.
                      {exceedsAll ? ` Öğrencinin tüm borcu kapanır, ${money((amountCents! - remainingCents) / 100)} avans olarak kalır.` : ' Fazlası:'}
                    </p>
                    {!exceedsAll && (
                      <Segmented size="sm" value={overpayment} onChange={setOverpayment}
                        options={[{ value: 'next', label: 'Sonraki taksitlere aktar' }, { value: 'credit', label: 'Önce taksitlere, artarsa avans' }]} />
                    )}
                  </div>
                )}
                {data && selectedCents > 0 && (
                  <div className="flex flex-wrap gap-1.5 -mt-1">
                    <Button size="xs" variant="soft" onClick={() => { setAmount(fromCents(selectedCents).replace('.', ',')); setAmountTouched(true) }}>Seçililerin tamamı</Button>
                    {overdueSelectedCents > 0 && overdueSelectedCents !== selectedCents && (
                      <Button size="xs" variant="soft" onClick={() => { setAmount(fromCents(overdueSelectedCents).replace('.', ',')); setAmountTouched(true) }}>Gecikmişler</Button>
                    )}
                  </div>
                )}

                <Field label="Ödeme yöntemi" required error={errors.method?.[0]}>
                  <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-2 gap-1.5">
                    {Object.entries(METHODS).map(([value, label]) => (
                      <button
                        key={value}
                        type="button"
                        onClick={() => setMethod(value)}
                        className={cn('h-9 rounded-[var(--radius-sm)] border text-[13px] font-medium transition-colors', method === value ? 'border-primary bg-primary-soft text-primary-ink' : 'border-line bg-surface text-ink-2 hover:border-line-strong')}
                      >
                        {label}
                      </button>
                    ))}
                  </div>
                </Field>

                <Field label="Paranın gireceği kasa / banka" required hint="Ödeme yöntemine göre önerilir" error={errors.finance_account_id?.[0]}>
                  <AccountSelect
                    accounts={accounts.data?.data}
                    value={accountId}
                    onChange={(v) => {
                      setAccountId(v)
                      setAccountTouched(true)
                    }}
                  />
                </Field>

                {isCard && (
                  <div className="grid grid-cols-2 gap-3 rounded-[var(--radius-md)] bg-surface-2/60 p-2.5 ring-1 ring-line">
                    <Field label={<span className="inline-flex items-center gap-1"><CreditCard className="size-3.5" /> Komisyon oranı (%)</span>} optional hint="Boşsa ayardaki oran" error={errors.commission_rate?.[0]}>
                      <Input inputMode="decimal" value={commissionRate} placeholder="ayar" onChange={(e) => setCommissionRate(e.target.value.replace(/[^\d.,]/g, ''))} className="text-right tabular" />
                    </Field>
                    <Field label="Kart taksidi" error={errors.card_installments?.[0]}>
                      <Select value={cardInstallments} onChange={(e) => setCardInstallments(e.target.value)} options={[1, 2, 3, 4, 5, 6, 9, 12].map((n) => ({ value: String(n), label: n === 1 ? 'Tek çekim' : `${n} taksit` }))} />
                    </Field>
                  </div>
                )}

                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-3.5">
                  <Field label="Ödeme tarihi ve saati" optional hint="Boş bırakılırsa şu an" error={errors.paid_at?.[0]}>
                    <Input type="datetime-local" value={paidAt} max={nowLocal()} onChange={(e) => setPaidAt(e.target.value)} />
                  </Field>
                  <Field label="Ödeyen" optional error={errors.guardian_id?.[0]}>
                    <Select value={payerId} onChange={(e) => setPayerId(e.target.value)} placeholder="Öğrencinin kendisi" options={(data?.guardians ?? []).map((g) => ({ value: g.id, label: `${g.name}${g.is_financially_responsible ? ' · ödeme sorumlusu' : ''}` }))} />
                  </Field>
                </div>
                <Field label="Referans no" optional hint="Dekont, POS provizyon ya da çek no" error={errors.reference?.[0]}>
                  <Input value={reference} onChange={(e) => setReference(e.target.value)} maxLength={120} />
                </Field>
                <Field label="Not" optional error={errors.note?.[0]}>
                  <Textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2} maxLength={500} />
                </Field>

                {data && amountCents !== null && amountCents > 0 && (
                  <div className="rounded-[var(--radius-md)] bg-surface-2/70 px-3 py-2 ring-1 ring-line">
                    <MetricRow label="Ödemenin dağıtılacağı taksit" value={excess > 0 ? `${preview.size} seçili + sonrakiler` : `${preview.size} adet`} />
                    <MetricRow label="Tahsilat sonrası kalan" value={money(Math.max(0, remainingCents - amountCents) / 100)} strong />
                    {exceedsAll && <MetricRow label="Avans olarak kalacak" value={money((amountCents - remainingCents) / 100)} tone="success" />}
                  </div>
                )}

                <Button variant="primary" size="lg" disabled={!canSubmit} loading={save.isPending} icon={<HandCoins className="size-4" />} onClick={() => save.mutate()}>
                  {amountCents ? `${money(amountCents / 100)} tahsil et` : 'Tahsil et'}
                </Button>
                {data && <p className="text-center text-[12px] text-ink-3">Kayıt sonrası numaralı makbuz oluşturulur. Tahsilat değiştirilemez; hata olursa iptal edilir.</p>}
              </div>
            </Panel>
          )}
          {data && !done && (
            <div className="mt-3 flex items-center gap-3 rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line">
              <Avatar name={data.student.full_name} src={data.student.photo_url} size={32} />
              <div className="min-w-0 flex-1">
                <Link to={`/ogrenciler/${data.student.id}`} className="block truncate text-[13px] font-medium hover:underline">{data.student.full_name}</Link>
                <p className="text-[12px] text-ink-3">{data.student.status_label} · Öğrenci no: {data.student.student_no}</p>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

function SummaryBox({ label, value, tone, strong }: { label: string; value: string; tone?: 'danger'; strong?: boolean }) {
  return (
    <div className="rounded-[var(--radius-md)] bg-surface-2/60 px-3 py-2 ring-1 ring-line">
      <p className="text-[12px] text-ink-3">{label}</p>
      <p className={cn('text-[15px] tabular', strong ? 'font-semibold' : 'font-medium', tone === 'danger' && 'text-danger')}>{value}</p>
    </div>
  )
}
