import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CheckCircle2, Download, FileText, HandCoins, Printer, RotateCcw, Search, Users, X } from 'lucide-react'
import { api, ApiError, idempotencyKey } from '@/lib/api'
import { date, money, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { Panel } from '@/components/ui/layout'
import { Alert, Avatar, Badge, EmptyState, Skeleton, Spinner } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Textarea } from '@/components/ui/form'
import { PhoneText } from '@/components/ui/contact'
import { AccountSelect, MetricRow, MoneyInput, useAccounts } from './components'
import { addDaysISO, fromCents, METHOD_ACCOUNT_KIND, METHODS, openPdf, toCents } from './shared'

type GuardianHit = { id: number; name: string; phone: string | null; children: string[] }
type Inst = { id: number; sequence: number; due_date: string; amount: string; remaining: string; program: string | null; days_overdue: number }
type Child = { id: number; full_name: string; student_no: string; remaining: string; overdue: string; credit: string; installments: Inst[] }
type Context = { guardian: { id: number; name: string }; students: Child[] }
type Created = { id: number; receipt_no: string; amount: string; student_id: number; student: string }

const nowLocal = () => {
  const d = new Date()
  return `${todayISO()}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

/** Veli toplu tahsilatı (kardeşler). Sayfa başlığı ve sekmeler CollectPage sarmalayıcısındadır. */
export default function GuardianCollect() {
  const can = useCan()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const guardianId = Number(params.get('veli') || 0) || null
  const [q, setQ] = useState('')
  const debounced = useDebounced(q.trim(), 250)
  const accounts = useAccounts()
  const key = useRef(idempotencyKey())

  const [selected, setSelected] = useState<Record<number, Set<number>>>({})
  const [amounts, setAmounts] = useState<Record<number, string>>({})
  const [touched, setTouched] = useState<Record<number, boolean>>({})
  const [method, setMethod] = useState('cash')
  const [accountId, setAccountId] = useState('')
  const [accountTouched, setAccountTouched] = useState(false)
  const [paidAt, setPaidAt] = useState(nowLocal())
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const [done, setDone] = useState<{ total: string; data: Created[] } | null>(null)

  const search = useQuery({
    queryKey: ['finance', 'guardian-search', debounced],
    queryFn: () => api.get<{ data: GuardianHit[] }>('/finance/guardians/search', { q: debounced }),
    enabled: debounced.length >= 2 && !guardianId,
    staleTime: 30_000,
  })
  const ctx = useQuery({
    queryKey: ['finance', 'guardian-context', guardianId],
    queryFn: () => api.get<{ data: Context }>(`/finance/guardians/${guardianId}/context`),
    enabled: !!guardianId,
  })
  const data = ctx.data?.data

  // Varsayılan seçim: gecikmiş + 7 gün içinde vadesi gelenler
  useEffect(() => {
    if (!data) return
    const horizon = addDaysISO(todayISO(), 7)
    const sel: Record<number, Set<number>> = {}
    data.students.forEach((s) => {
      sel[s.id] = new Set(s.installments.filter((i) => i.days_overdue > 0 || i.due_date <= horizon).map((i) => i.id))
    })
    setSelected(sel)
    setTouched({})
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data?.guardian.id, ctx.dataUpdatedAt])

  const sumSelected = (s: Child) => s.installments.filter((i) => selected[s.id]?.has(i.id)).reduce((t, i) => t + (toCents(i.remaining) ?? 0), 0)

  useEffect(() => {
    if (!data) return
    setAmounts((prev) => {
      const next = { ...prev }
      data.students.forEach((s) => {
        if (!touched[s.id]) {
          const c = sumSelected(s)
          next[s.id] = c > 0 ? fromCents(c).replace('.', ',') : ''
        }
      })
      return next
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected, data])

  useEffect(() => {
    if (accountTouched || !accounts.data) return
    const kind = METHOD_ACCOUNT_KIND[method]
    const m = accounts.data.data.find((a) => a.is_active && a.kind === kind) ?? accounts.data.data.find((a) => a.is_active)
    if (m) setAccountId(String(m.id))
  }, [method, accounts.data, accountTouched])

  const items = useMemo(
    () =>
      (data?.students ?? [])
        .map((s) => ({ student: s, cents: toCents(amounts[s.id] ?? '') }))
        .filter((x) => x.cents !== null && x.cents > 0),
    [data, amounts],
  )
  const invalid = (data?.students ?? []).some((s) => (amounts[s.id] ?? '').trim() !== '' && toCents(amounts[s.id]) === null)
  const totalCents = items.reduce((t, x) => t + (x.cents ?? 0), 0)
  const canSubmit = !!data && items.length > 0 && !invalid && !!accountId

  const pick = (id: number | null) => {
    setDone(null)
    key.current = idempotencyKey()
    setAmounts({})
    setTouched({})
    setQ('')
    setParams((p) => {
      if (id) p.set('veli', String(id))
      else p.delete('veli')
      return p
    }, { replace: true })
  }

  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const save = useMutation({
    mutationFn: () =>
      api.post<{ message: string; total: string; data: Created[] }>('/finance/payments/bulk', {
        guardian_id: guardianId,
        finance_account_id: Number(accountId),
        method,
        paid_at: paidAt ? paidAt.replace('T', ' ') + ':00' : null,
        reference: reference || null,
        note: note || null,
        items: items.map((x) => ({ student_id: x.student.id, amount: fromCents(x.cents!), installment_ids: [...(selected[x.student.id] ?? [])] })),
        idempotency_key: key.current,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      setErrors({})
      setDone({ total: res.total, data: res.data })
      qc.invalidateQueries({ queryKey: ['finance'] })
      qc.invalidateQueries({ queryKey: ['student'] })
      qc.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors ?? {})
        toast.error(e.firstError())
      } else toast.error('Tahsilat kaydedilemedi.')
    },
  })
  // Sunucu hatası items.N.* → öğrenci id'sine eşlenir (gönderilen sırayla)
  const itemError = (sid: number) => {
    const idx = items.findIndex((x) => x.student.id === sid)
    if (idx < 0) return null
    return errors[`items.${idx}.amount`]?.[0] ?? errors[`items.${idx}.student_id`]?.[0] ?? errors[`items.${idx}.installment_ids`]?.[0] ?? null
  }

  const reset = () => {
    key.current = idempotencyKey()
    setDone(null)
    setTouched({})
    setReference('')
    setNote('')
    setPaidAt(nowLocal())
    ctx.refetch()
  }

  if (!can('payments.create')) {
    return <EmptyState icon={<HandCoins />} title="Tahsilat yetkiniz yok" description="Bu ekranı kullanmak için tahsilat alma yetkisi gerekir." />
  }

  const toggle = (sid: number, iid: number) => {
    setSelected((prev) => {
      const set = new Set(prev[sid] ?? [])
      set.has(iid) ? set.delete(iid) : set.add(iid)
      return { ...prev, [sid]: set }
    })
    setTouched((t) => ({ ...t, [sid]: false }))
  }

  return (
    <div className="animate-fade-in">
      <p className="mb-4 text-[13px] text-ink-2">Bir velinin birden çok çocuğu için tek işlemde tahsilat; her çocuğa ayrı makbuz kesilir.</p>

      <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="Veli">
            {guardianId && data ? (
              <div className="flex items-center gap-3 rounded-[var(--radius-md)] bg-surface px-3 py-2 ring-1 ring-line">
                <Avatar name={data.guardian.name} size={34} />
                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium">{data.guardian.name}</p>
                  <p className="text-[12px] text-ink-3">{data.students.length} çocuğu kayıtlı</p>
                </div>
                <button type="button" onClick={() => pick(null)} className="grid size-7 place-items-center rounded-[6px] text-ink-3 hover:bg-surface-2 hover:text-ink" aria-label="Veliyi değiştir">
                  <X className="size-4" />
                </button>
              </div>
            ) : guardianId ? (
              <Skeleton className="h-12" />
            ) : (
              <div className="flex flex-col gap-2">
                <Input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Veli adı, telefon ya da çocuk adı" leading={<Search />}
                  trailing={search.isFetching ? <Spinner className="size-3.5" /> : undefined} />
                {debounced.length >= 2 && (
                  <ul className="flex flex-col divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
                    {(search.data?.data ?? []).length === 0 && !search.isFetching && <li className="px-3 py-3 text-[13px] text-ink-3">Eşleşen veli yok.</li>}
                    {(search.data?.data ?? []).map((g) => (
                      <li key={g.id}>
                        <button type="button" onClick={() => pick(g.id)} className="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-surface-2">
                          <Avatar name={g.name} size={30} />
                          <span className="min-w-0 flex-1">
                            <span className="block truncate text-[13.5px] font-medium">{g.name}</span>
                            {g.phone && <span className="block"><PhoneText value={g.phone} muted /></span>}
                            {g.children.length > 0 && <span className="block truncate text-[12px] text-ink-3">Çocukları: {g.children.join(', ')}</span>}
                          </span>
                          <Badge tone="info">{g.children.length} çocuk</Badge>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}
          </Panel>

          {!guardianId && <EmptyState icon={<Users />} title="Tahsilat için veli seçin" description="Veliyi seçince tüm çocuklarının açık taksitleri listelenir." />}
          {ctx.isLoading && guardianId && <Skeleton className="h-48" />}

          {data?.students.map((s) => {
            const sel = selected[s.id] ?? new Set<number>()
            const selCents = sumSelected(s)
            const cents = toCents(amounts[s.id] ?? '')
            return (
              <Panel
                key={s.id}
                title={<Link to={`/ogrenciler/${s.id}`} className="hover:underline">{s.full_name}</Link>}
                description={`Öğrenci no: ${s.student_no} · Kalan borç: ${money(s.remaining)}`}
                actions={
                  <div className="flex flex-wrap items-center gap-1.5">
                    {Number(s.overdue) > 0 && <Badge tone="danger">Vadesi geçmiş {money(s.overdue, { short: true })}</Badge>}
                    {Number(s.credit) > 0 && <Badge tone="success">Kullanılabilir avans {money(s.credit, { short: true })}</Badge>}
                  </div>
                }
                flush
              >
                {s.installments.length === 0 ? (
                  <p className="px-4 pb-4 text-[13px] text-ink-3">Açık taksit yok.</p>
                ) : (
                  <ul className="divide-y divide-line border-y border-line">
                    {s.installments.map((i) => (
                      <li key={i.id} onClick={() => toggle(s.id, i.id)} className={cn('flex cursor-pointer items-center gap-3 px-4 py-2 text-[13px]', sel.has(i.id) ? 'bg-primary-soft/40' : 'hover:bg-surface-2/60')}>
                        <span onClick={(e) => e.stopPropagation()}><Checkbox checked={sel.has(i.id)} onChange={() => toggle(s.id, i.id)} /></span>
                        <span className="min-w-0 flex-1">
                          <span className="font-medium">{i.sequence}. taksit</span>
                          <span className="block truncate text-[12px] text-ink-3">Vade: {date(i.due_date)}{i.program ? ` · ${i.program}` : ''}</span>
                        </span>
                        {i.days_overdue > 0 && <Badge tone="danger">{i.days_overdue} gün gecikti</Badge>}
                        <span className="tabular font-medium whitespace-nowrap">{money(i.remaining)}</span>
                      </li>
                    ))}
                  </ul>
                )}
                <div className="flex flex-wrap items-end gap-3 px-4 py-3">
                  <Field label="Bu öğrenci için tutar" hint="Boş bırakılan öğrenciden tahsilat alınmaz" error={itemError(s.id)} className="w-full sm:w-56">
                    <MoneyInput value={amounts[s.id] ?? ''} onChange={(v) => { setAmounts((a) => ({ ...a, [s.id]: v })); setTouched((t) => ({ ...t, [s.id]: true })) }} />
                  </Field>
                  <p className="pb-2 text-[12px] text-ink-3">
                    Seçili {sel.size} taksit · {money(selCents / 100)}
                    {cents !== null && cents > selCents && selCents >= 0 && <span className="text-info"> · fazlası sonraki taksitlere aktarılır</span>}
                  </p>
                </div>
              </Panel>
            )
          })}
        </div>

        <div className="lg:sticky lg:top-4">
          {done ? (
            <Panel>
              <div className="flex flex-col items-center py-2 text-center">
                <span className="grid size-12 place-items-center rounded-full bg-success-soft text-success"><CheckCircle2 className="size-6" /></span>
                <p className="mt-3 text-[15px] font-semibold">Tahsilat kaydedildi</p>
                <p className="mt-1 text-[26px] font-semibold tabular">{money(done.total)}</p>
              </div>
              <ul className="mt-3 flex flex-col divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
                {done.data.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-[13px]">
                    <span className="min-w-0 flex-1">
                      <span className="block truncate font-medium">{p.student}</span>
                      <span className="block text-[12px] tabular text-ink-3">Makbuz no: {p.receipt_no} · {money(p.amount)}</span>
                    </span>
                    <Button size="xs" variant="ghost" icon={<Printer className="size-3.5" />} onClick={() => openPdf(`/finance/payments/${p.id}/receipt`).catch((e) => toast.error(e.message))}>Yazdır</Button>
                    <Button size="xs" variant="ghost" icon={<Download className="size-3.5" />} onClick={() => api.download(`/finance/payments/${p.id}/receipt`, undefined, `makbuz-${p.receipt_no}.pdf`).catch((e) => toast.error(e.message))}>PDF</Button>
                  </li>
                ))}
              </ul>
              <div className="mt-3 grid grid-cols-1 gap-2">
                <Button icon={<FileText className="size-4" />} onClick={() => openPdf(`/finance/statements/guardians/${guardianId}/pdf`).catch((e) => toast.error(e.message))}>Veli cari ekstresi</Button>
                <Button variant="primary" icon={<RotateCcw className="size-4" />} onClick={reset}>Aynı veliden yeni tahsilat</Button>
                <Button variant="ghost" onClick={() => pick(null)}>Başka veli</Button>
              </div>
            </Panel>
          ) : (
            <Panel title="Ödeme bilgileri">
              <div className="flex flex-col gap-3.5">
                <div className="rounded-[var(--radius-md)] bg-surface-2/70 px-3 py-1.5 ring-1 ring-line">
                  {items.length === 0 ? (
                    <p className="py-1.5 text-[13px] text-ink-3">Tutar girilen öğrenci yok.</p>
                  ) : (
                    items.map((x) => <MetricRow key={x.student.id} label={x.student.full_name} value={money((x.cents ?? 0) / 100)} />)
                  )}
                  <MetricRow label="Genel toplam" value={money(totalCents / 100)} strong />
                </div>
                {errors.items?.[0] && <p className="text-xs text-danger">{errors.items[0]}</p>}
                <Field label="Ödeme yöntemi" required error={errors.method?.[0]}>
                  <div className="grid grid-cols-2 gap-1.5">
                    {Object.entries(METHODS).map(([value, label]) => (
                      <button key={value} type="button" onClick={() => setMethod(value)}
                        className={cn('h-9 rounded-[var(--radius-sm)] border text-[13px] font-medium transition-colors', method === value ? 'border-primary bg-primary-soft text-primary-ink' : 'border-line bg-surface text-ink-2 hover:border-line-strong')}>
                        {label}
                      </button>
                    ))}
                  </div>
                </Field>
                <Field label="Paranın gireceği kasa / banka" required hint="Ödeme yöntemine göre önerilir" error={errors.finance_account_id?.[0]}>
                  <AccountSelect accounts={accounts.data?.data} value={accountId} onChange={(v) => { setAccountId(v); setAccountTouched(true) }} />
                </Field>
                <Field label="Ödeme tarihi ve saati" optional hint="Boş bırakılırsa şu an" error={errors.paid_at?.[0]}>
                  <Input type="datetime-local" value={paidAt} max={nowLocal()} onChange={(e) => setPaidAt(e.target.value)} />
                </Field>
                <Field label="Referans no" optional hint="Dekont / POS provizyon no" error={errors.reference?.[0]}>
                  <Input value={reference} onChange={(e) => setReference(e.target.value)} maxLength={120} />
                </Field>
                <Field label="Not" optional error={errors.note?.[0]}>
                  <Textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} maxLength={400} />
                </Field>
                <Alert tone="info">Seçili taksitleri aşan tutar öğrencinin sonraki taksitlerine aktarılır. Her öğrenciye ayrı makbuz kesilir; biri kaydedilemezse hiçbiri kaydedilmez.</Alert>
                <Button variant="primary" size="lg" disabled={!canSubmit} loading={save.isPending} icon={<HandCoins className="size-4" />} onClick={() => save.mutate()}>
                  {totalCents > 0 ? `${money(totalCents / 100)} tahsil et` : 'Tahsil et'}
                </Button>
              </div>
            </Panel>
          )}
        </div>
      </div>
    </div>
  )
}
