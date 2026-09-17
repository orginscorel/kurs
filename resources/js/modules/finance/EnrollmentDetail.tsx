import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  AlertTriangle, CalendarPlus, Combine, Download, FileCheck2, FilePen, FileText, HandCoins, History, Lock, Pencil, Percent, Plus, Printer, RefreshCw, Scissors, Trash2, Undo2,
} from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, dateTime, money } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { DescriptionList, PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Checkbox, Field, Input, Select, Textarea } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { PersonText } from '@/components/ui/contact'
import { MetricRow, MoneyInput } from './components'
import { NotePrintDialog, type NoteSelector } from './PromissoryNotes'
import { FileSignature } from 'lucide-react'
import { addMonthsISO, fromCents, INSTALLMENT_STATUS, METHODS, openPdf, splitCents, toCents } from './shared'
import { enrollmentStatusTone } from './EnrollmentList'

type Inst = {
  id: number; sequence: number; due_date: string; amount: string; paid_amount: string; remaining: string; status: string; days_overdue: number; locked: boolean
  payments: { payment_id: number; receipt_no: string; amount: string; paid_at: string; voided: boolean }[]
}
type Detail = {
  id: number; enrollment_no: string; status: string; status_label: string
  student: { id: number; full_name: string; student_no: string } | null
  program: string | null; term: string | null; package: string | null; class_group: string | null
  financial_guardian: { id: number; name: string } | null
  guardians: { id: number; name: string; is_financially_responsible: boolean }[]
  enrolled_on: string
  list_price: string; discount_amount: string; discount_reason: string | null; scholarship_amount: string; scholarship_reason: string | null; net_price: string
  plan_total: string; paid: string; remaining: string; plan_matches: boolean
  installments: Inst[]
  payments: { id: number; receipt_no: string; amount: string; method: string; method_label: string; paid_at: string; account: string | null; voided_at: string | null }[]
  contract: { id: number; contract_no: string; signed_at: string | null; signed_by_name: string | null; updated_at: string | null } | null
  history: { id: number; action: string; description: string; created_at: string }[]
}

type EditRow = { key: string; id: number | null; due_date: string; amount: string; paid: number; checked: boolean }

let rowSeq = 0
const newKey = () => `n${++rowSeq}`

export default function EnrollmentDetail() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const { data, isLoading, error } = useQuery({ queryKey: ['finance', 'enrollment', id], queryFn: () => api.get<{ data: Detail }>(`/finance/enrollments/${id}`) })
  const e = data?.data
  const [editing, setEditing] = useState(false)
  const [priceOpen, setPriceOpen] = useState(false)
  const [signOpen, setSignOpen] = useState(false)
  const [notes, setNotes] = useState<NoteSelector | null>(null)
  const navigate = useNavigate()
  const invoiceDraft = useMutation({
    mutationFn: () => api.post<{ id: number; message: string }>(`/finance/invoices/from-enrollment/${id}`),
    onSuccess: (r) => { toast.success(r.message); refresh(); navigate(`/finans/faturalar/${r.id}`) },
    onError: (err) => toast.error(err instanceof ApiError ? err.firstError() : 'Fatura taslağı oluşturulamadı.'),
  })

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['finance'] })
    qc.invalidateQueries({ queryKey: ['student'] })
  }

  const prepare = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/finance/enrollments/${id}/contract`),
    onSuccess: (r) => { toast.success(r.message); refresh() },
    onError: (err) => toast.error(err instanceof ApiError ? err.firstError() : 'Sözleşme hazırlanamadı.'),
  })

  if (error) return <EmptyState title="Kayıt bulunamadı" description={error instanceof ApiError && error.status !== 404 ? error.message : 'Kayıt silinmiş ya da adres hatalı olabilir.'} action={<ButtonLink to="/finans/kayitlar">Kayıtlara dön</ButtonLink>} />
  if (isLoading || !e) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-16" />
        <Skeleton className="h-64" />
      </div>
    )
  }

  const paidPct = Number(e.net_price) > 0 ? Math.min(100, (Number(e.paid) / Number(e.net_price)) * 100) : 100
  const canManage = can('installments.manage')
  const canContract = can('enrollments.create') || canManage

  return (
    <div className="animate-fade-in">
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-2">
            {e.student?.full_name}
            <Badge tone={enrollmentStatusTone[e.status] ?? 'neutral'} dot>{e.status_label}</Badge>
          </span>
        }
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Kayıtlar', to: '/finans/kayitlar' }, { label: e.enrollment_no }]}
        description={`${e.enrollment_no} · ${e.program ?? ''} · ${e.term ?? ''}`}
        actions={
          <>
            {e.student && <ButtonLink to={`/ogrenciler/${e.student.id}`}>Öğrenci profili</ButtonLink>}
            {(canManage || can('enrollments.create') || can('finance.invoice')) && e.installments.some((i) => i.status !== 'cancelled') && (
              <Button icon={<FileSignature className="size-4" />} onClick={() => setNotes({ enrollment_ids: [e.id] })}>Senetleri yazdır</Button>
            )}
            {can('finance.invoice') && (
              <Button variant="info" icon={<FileText className="size-4" />} loading={invoiceDraft.isPending} onClick={() => invoiceDraft.mutate()}>Fatura taslağı</Button>
            )}
            {can('payments.create') && Number(e.remaining) > 0 && e.student && (
              <ButtonLink variant="primary" to={`/finans/tahsilat?ogrenci=${e.student.id}&taksit=${e.installments.filter((i) => !i.locked).map((i) => i.id).join(',')}`} icon={<HandCoins className="size-4" />}>
                Tahsilat al
              </ButtonLink>
            )}
          </>
        }
      />

      {notes && <NotePrintDialog open onClose={() => setNotes(null)} selector={notes} title={notes.installment_ids ? 'Taksit senedi' : `${e.enrollment_no} senetleri`} />}

      {!e.plan_matches && (
        <Alert tone="warning" className="mb-4" title="Plan toplamı net bedelle eşleşmiyor">
          Plan toplamı {money(e.plan_total)}, net bedel {money(e.net_price)}. {canManage ? 'Ödeme planını düzenleyerek farkı giderin.' : 'Yetkili bir kullanıcının planı düzeltmesi gerekir.'}
        </Alert>
      )}

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-4 items-start">
        <div className="xl:col-span-2 flex flex-col gap-4 min-w-0">
          {editing ? (
            <PlanEditor detail={e} onCancel={() => setEditing(false)} onSaved={() => { setEditing(false); refresh() }} />
          ) : (
            <Panel
              title="Ödeme planı"
              description={`${e.installments.filter((i) => i.status !== 'cancelled').length} taksit · Ödenen: ${money(e.paid, { short: true })} · Kalan: ${money(e.remaining, { short: true })}`}
              actions={canManage && e.installments.some((i) => !i.locked) || (canManage && Number(e.remaining) > 0) ? <Button size="sm" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(true)}>Planı düzenle</Button> : undefined}
              flush
            >
              <InstallmentTable onNote={(canManage || can('enrollments.create') || can('finance.invoice')) ? (iid) => setNotes({ installment_ids: [iid] }) : undefined} rows={e.installments} />
            </Panel>
          )}

          <Panel title="Tahsilatlar" flush>
            {e.payments.length === 0 ? (
              <EmptyState compact icon={<HandCoins />} title="Bu kayda tahsilat yok" description="İlk taksit tahsil edildiğinde makbuz burada listelenir." action={can('payments.create') && Number(e.remaining) > 0 && e.student ? <ButtonLink variant="success" size="sm" icon={<HandCoins className="size-4" />} to={`/finans/tahsilat?ogrenci=${e.student.id}&taksit=${e.installments.filter((i) => !i.locked).map((i) => i.id).join(',')}`}>Tahsilat al</ButtonLink> : undefined} />
            ) : (
              <ul className="divide-y divide-line border-t border-line">
                {e.payments.map((p) => (
                  <li key={p.id} className={cn('flex items-center gap-3 px-4 py-2.5 text-[13px]', p.voided_at && 'opacity-60')}>
                    <div className="min-w-0 flex-1">
                      <Link to={`/finans/tahsilatlar?makbuz=${encodeURIComponent(p.receipt_no)}`} className="font-medium tabular hover:underline">{p.receipt_no}</Link>
                      <p className="text-[12px] text-ink-3">{dateTime(p.paid_at)} · {METHODS[p.method] ?? p.method_label}{p.account ? ` · ${p.account}` : ''}</p>
                    </div>
                    {p.voided_at ? <Badge tone="danger">İptal edildi</Badge> : <span className="font-medium tabular">{money(p.amount)}</span>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          {e.history.length > 0 && (
            <Panel title={<span className="inline-flex items-center gap-1.5"><History className="size-4 text-ink-3" /> Değişiklik geçmişi</span>}>
              <ol className="flex flex-col gap-2.5">
                {e.history.map((h) => (
                  <li key={h.id} className="text-[13px]">
                    <p className="text-ink">{h.description}</p>
                    <p className="text-[12px] text-ink-3">{dateTime(h.created_at)}</p>
                  </li>
                ))}
              </ol>
            </Panel>
          )}
        </div>

        <div className="flex flex-col gap-4">
          <Panel title="Ücret" actions={canManage ? <Button size="xs" variant="ghost" icon={<Percent className="size-3.5" />} onClick={() => setPriceOpen(true)}>İndirim / burs</Button> : undefined}>
            <div className="divide-y divide-line">
              <MetricRow label="Liste fiyatı" value={money(e.list_price)} />
              <MetricRow label={<span>İndirim{e.discount_reason ? <span className="block text-[12px] text-ink-3">Gerekçe: {e.discount_reason}</span> : null}</span>} value={Number(e.discount_amount) > 0 ? `−${money(e.discount_amount)}` : '—'} />
              <MetricRow label={<span>Burs{e.scholarship_reason ? <span className="block text-[12px] text-ink-3">Gerekçe: {e.scholarship_reason}</span> : null}</span>} value={Number(e.scholarship_amount) > 0 ? `−${money(e.scholarship_amount)}` : '—'} />
              <MetricRow label="Ödenecek net tutar" value={money(e.net_price)} strong />
              <MetricRow label="Ödenen tutar" value={money(e.paid)} tone="success" />
              <MetricRow label="Kalan borç" value={money(e.remaining)} strong />
            </div>
            <div className="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-surface-3">
              <div className="h-full rounded-full bg-success" style={{ width: `${paidPct}%` }} />
            </div>
          </Panel>

          <Panel title="Kayıt bilgileri">
            <DescriptionList
              columns={1}
              items={[
                { label: 'Öğrenci', value: e.student ? <Link to={`/ogrenciler/${e.student.id}`} className="hover:underline">{e.student.full_name} · Öğrenci no: {e.student.student_no}</Link> : '—' },
                { label: 'Program / dönem', value: `${e.program ?? '—'} · ${e.term ?? '—'}` },
                { label: 'Eğitim paketi', value: e.package ?? '—' },
                { label: 'Sınıf', value: e.class_group ?? 'Atanmadı' },
                { label: 'Ödeme sorumlusu veli', value: e.financial_guardian ? <PersonText>{e.financial_guardian.name}</PersonText> : '—' },
                { label: 'Kayıt tarihi', value: date(e.enrolled_on) },
              ]}
            />
          </Panel>

          <Panel title="Kayıt sözleşmesi">
            {e.contract ? (
              <>
                <div className="flex items-center gap-2">
                  <span className="font-medium tabular">{e.contract.contract_no}</span>
                  {e.contract.signed_at ? <Badge tone="success"><FileCheck2 className="size-3" /> İmzalı</Badge> : <Badge tone="warning">Taslak</Badge>}
                </div>
                <p className="mt-1 text-[12.5px] text-ink-3">
                  {e.contract.signed_at ? `İmza: ${dateTime(e.contract.signed_at)} · İmzalayan: ${e.contract.signed_by_name} · metin donduruldu` : 'İmzalanana kadar kayıt bilgileri değişirse metni yenileyebilirsiniz.'}
                </p>
                <div className="mt-3 flex flex-wrap gap-2">
                  <Button size="sm" icon={<Printer className="size-3.5" />} onClick={() => openPdf(`/finance/enrollments/${e.id}/contract.pdf`).catch((err) => toast.error(err.message))}>Yazdır</Button>
                  <Button size="sm" icon={<Download className="size-3.5" />} onClick={() => api.download(`/finance/enrollments/${e.id}/contract.pdf`, undefined, `sozlesme-${e.contract!.contract_no}.pdf`).catch((err) => toast.error(err.message))}>PDF</Button>
                  {!e.contract.signed_at && canContract && (
                    <>
                      <Button size="sm" variant="ghost" icon={<RefreshCw className="size-3.5" />} loading={prepare.isPending} onClick={() => prepare.mutate()}>Metni yenile</Button>
                      <Button size="sm" variant="primary" icon={<FilePen className="size-3.5" />} onClick={() => setSignOpen(true)}>İmzalandı</Button>
                    </>
                  )}
                </div>
              </>
            ) : canContract ? (
              <div>
                <p className="text-[13px] text-ink-3">Bu kayıt için sözleşme hazırlanmamış.</p>
                <Button size="sm" className="mt-3" icon={<FileText className="size-3.5" />} loading={prepare.isPending} onClick={() => prepare.mutate()}>Sözleşme hazırla</Button>
              </div>
            ) : (
              <p className="text-[13px] text-ink-3">Sözleşme yok.</p>
            )}
          </Panel>
        </div>
      </div>

      <PriceModal open={priceOpen} onClose={() => setPriceOpen(false)} detail={e} onSaved={refresh} />
      <SignModal open={signOpen} onClose={() => setSignOpen(false)} detail={e} onSaved={refresh} />
    </div>
  )
}

function InstallmentTable({ rows, onNote }: { rows: Inst[]; onNote?: (installmentId: number) => void }) {
  if (rows.length === 0) return <EmptyState compact icon={<CalendarPlus />} title="Ödeme planı yok" />
  return (
    <div className="overflow-x-auto scroll-thin">
      <table className="tbl w-full text-[13px]">
        <thead>
          <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
            <th className="h-9 px-4 font-medium text-left">Sıra</th>
            <th className="fill px-3 font-medium text-center">Vade tarihi</th>
            <th className="px-3 font-medium text-center">Taksit tutarı</th>
            <th className="px-3 font-medium text-center">Ödenen</th>
            <th className="px-3 font-medium text-center">Kalan</th>
            <th className="px-3 font-medium hidden md:table-cell text-center">Makbuz no</th>
            <th className="px-4 font-medium text-center">Durum</th>
            {onNote && <th className="w-10 pr-3 text-center" />}
          </tr>
        </thead>
        <tbody>
          {rows.map((i) => {
            const eff = i.status === 'cancelled' || i.status === 'paid' ? i.status : i.days_overdue > 0 ? 'overdue' : Number(i.paid_amount) > 0 ? 'partial' : 'pending'
            return (
              <tr key={i.id} className={cn('border-b border-line last:border-0', eff === 'overdue' && 'bg-danger-soft/30', i.status === 'cancelled' && 'opacity-50')}>
                <td className="px-4 py-2.5 text-ink-3 tabular text-left">{i.sequence}</td>
                <td className="fill px-3 py-2.5 tabular whitespace-nowrap text-center">
                  {date(i.due_date)}
                  {i.days_overdue > 0 && <span className="block text-[12px] text-danger">{i.days_overdue} gün gecikti</span>}
                </td>
                <td className={cn('px-3 py-2.5 tabular text-center', i.status === 'cancelled' && 'line-through')}>{money(i.amount)}</td>
                <td className="px-3 py-2.5 tabular text-ink-2 text-center">{money(i.paid_amount)}</td>
                <td className="px-3 py-2.5 tabular font-medium text-center">{money(i.remaining)}</td>
                <td className="px-3 py-2.5 hidden md:table-cell text-center">
                  <div className="flex flex-wrap gap-1">
                    {i.payments.map((p) => (
                      <Link key={`${p.payment_id}-${i.id}`} to={`/finans/tahsilatlar?makbuz=${encodeURIComponent(p.receipt_no)}`} className={cn('text-[12px] tabular text-ink-3 hover:text-ink', p.voided && 'line-through')}>
                        {p.receipt_no.replace(/^[A-Z]+-\d{4}-/, '#')}
                      </Link>
                    ))}
                  </div>
                </td>
                <td className="px-4 py-2.5 text-center">
                  <Badge tone={INSTALLMENT_STATUS[eff]?.tone ?? 'neutral'}>{i.locked && <Lock className="size-3" />}{INSTALLMENT_STATUS[eff]?.label ?? eff}</Badge>
                </td>
                {onNote && (
                  <td className="pr-3 text-right">
                    {i.status !== 'cancelled' && (
                      <Button size="icon-sm" variant="ghost" aria-label="Senet yazdır" title="Senet yazdır" onClick={() => onNote(i.id)}><FileSignature className="size-3.5" /></Button>
                    )}
                  </td>
                )}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

/** Ödenmemiş taksitleri yeniden yapılandırma: tutar/vade düzenleme, bölme, birleştirme, ekleme, silme. Toplam sunucuda da doğrulanır. */
function PlanEditor({ detail, onCancel, onSaved }: { detail: Detail; onCancel: () => void; onSaved: () => void }) {
  const locked = detail.installments.filter((i) => i.locked)
  const lockedC = locked.filter((i) => i.status !== 'cancelled').reduce((s, i) => s + (toCents(i.amount) ?? 0), 0)
  const targetC = (toCents(detail.net_price) ?? 0) - lockedC
  const initial = () =>
    detail.installments.filter((i) => !i.locked).map<EditRow>((i) => ({ key: `i${i.id}`, id: i.id, due_date: i.due_date, amount: i.amount.replace('.', ','), paid: toCents(i.paid_amount) ?? 0, checked: false }))
  const [rows, setRows] = useState<EditRow[]>(initial)
  const [note, setNote] = useState('')
  const [splitFor, setSplitFor] = useState<EditRow | null>(null)
  const [splitCount, setSplitCount] = useState('2')

  useEffect(() => setRows(initial()), [detail]) // eslint-disable-line react-hooks/exhaustive-deps

  const totalC = rows.reduce((s, r) => s + (toCents(r.amount) ?? 0), 0)
  const diffC = targetC - totalC
  const invalid = rows.some((r) => (toCents(r.amount) ?? -1) <= 0 || (toCents(r.amount) ?? 0) < r.paid || !/^\d{4}-\d{2}-\d{2}$/.test(r.due_date))
  const checked = rows.filter((r) => r.checked)

  const update = (key: string, patch: Partial<EditRow>) => setRows((rs) => rs.map((r) => (r.key === key ? { ...r, ...patch } : r)))
  const sortByDate = (rs: EditRow[]) => [...rs].sort((a, b) => (a.due_date < b.due_date ? -1 : a.due_date > b.due_date ? 1 : 0))

  const merge = () => {
    if (checked.length < 2) return
    const withPaid = checked.filter((r) => r.paid > 0)
    if (withPaid.length > 1) {
      toast.error('Kısmen ödenmiş iki taksit birleştirilemez.')
      return
    }
    const keep = withPaid[0] ?? [...checked].sort((a, b) => (a.due_date < b.due_date ? -1 : 1))[0]!
    const sum = checked.reduce((s, r) => s + (toCents(r.amount) ?? 0), 0)
    const earliest = checked.map((r) => r.due_date).sort()[0]!
    setRows((rs) => sortByDate(rs.filter((r) => !r.checked || r.key === keep.key).map((r) => (r.key === keep.key ? { ...r, amount: fromCents(sum).replace('.', ','), due_date: withPaid[0] ? r.due_date : earliest, checked: false } : r))))
  }

  const split = () => {
    if (!splitFor) return
    const n = Math.max(2, Math.min(24, Number(splitCount) || 2))
    const cents = toCents(splitFor.amount) ?? 0
    const unpaid = cents - splitFor.paid
    if (unpaid <= 0) return
    // Kısmi taksitte ödenen kısım ilk parçada kalır; kalan tutar bölünür.
    const parts = splitCents(unpaid, n)
    const created: EditRow[] = parts.slice(1).map((c, i) => ({ key: newKey(), id: null, due_date: addMonthsISO(splitFor.due_date, i + 1), amount: fromCents(c).replace('.', ','), paid: 0, checked: false }))
    setRows((rs) => sortByDate([...rs.map((r) => (r.key === splitFor.key ? { ...r, amount: fromCents(parts[0]! + splitFor.paid).replace('.', ',') } : r)), ...created]))
    setSplitFor(null)
  }

  const addRow = () => {
    const last = rows.map((r) => r.due_date).sort().pop() ?? detail.installments.map((i) => i.due_date).sort().pop() ?? new Date().toISOString().slice(0, 10)
    setRows((rs) => [...rs, { key: newKey(), id: null, due_date: addMonthsISO(last, 1), amount: diffC > 0 ? fromCents(diffC).replace('.', ',') : '', paid: 0, checked: false }])
  }

  const distributeEqual = () => {
    const paidTotal = rows.reduce((s, r) => s + r.paid, 0)
    const parts = splitCents(targetC - paidTotal, rows.length)
    setRows((rs) => rs.map((r, i) => ({ ...r, amount: fromCents(parts[i]! + r.paid).replace('.', ',') })))
  }

  const save = useMutation({
    mutationFn: () =>
      api.put<{ message: string }>(`/finance/enrollments/${detail.id}/plan`, {
        rows: rows.map((r) => ({ id: r.id, due_date: r.due_date, amount: fromCents(toCents(r.amount) ?? 0) })),
        note: note || null,
      }),
    onSuccess: (r) => {
      toast.success(r.message)
      onSaved()
    },
    onError: (err) => toast.error(err instanceof ApiError ? err.firstError() : 'Plan kaydedilemedi.'),
  })

  return (
    <Panel
      title="Ödeme planını düzenle"
      description="Ödenmiş taksitler kilitlidir. Düzenlenen taksitlerin toplamı kalan net bedele kuruşu kuruşuna eşit olmalı."
      actions={<Button size="sm" variant="ghost" icon={<Undo2 className="size-3.5" />} onClick={() => setRows(initial())}>Sıfırla</Button>}
      flush
    >
      <div className="px-4 pb-3 flex flex-wrap items-center gap-2">
        <Button size="sm" icon={<Plus className="size-3.5" />} onClick={addRow}>Taksit ekle</Button>
        <Button size="sm" icon={<Combine className="size-3.5" />} disabled={checked.length < 2} onClick={merge}>Seçilenleri birleştir{checked.length > 1 ? ` (${checked.length})` : ''}</Button>
        <Button size="sm" variant="ghost" disabled={rows.length === 0} onClick={distributeEqual}>Eşit dağıt</Button>
      </div>

      <div className="overflow-x-auto scroll-thin border-t border-line">
        <table className="tbl w-full text-[13px]">
          <thead>
            <tr className="border-b border-line bg-surface-2/60 text-[12px] text-ink-3">
              <th className="w-10 pl-4 text-left" />
              <th className="fill h-9 px-2 font-medium text-center">Vade tarihi</th>
              <th className="px-2 font-medium text-center">Taksit tutarı</th>
              <th className="px-2 font-medium text-center">Ödenen</th>
              <th className="px-4 text-center" />
            </tr>
          </thead>
          <tbody>
            {locked.map((i) => (
              <tr key={i.id} className="border-b border-line bg-surface-2/40 text-ink-3">
                <td className="pl-4 text-left"><Lock className="size-3.5" /></td>
                <td className="fill px-2 py-2 tabular text-center">{date(i.due_date)}</td>
                <td className={cn('px-2 py-2 tabular text-center', i.status === 'cancelled' && 'line-through')}>{money(i.amount)}</td>
                <td className="px-2 py-2 tabular text-center">{money(i.paid_amount)}</td>
                <td className="px-4 py-2 text-[12px] text-center">{INSTALLMENT_STATUS[i.status]?.label}</td>
              </tr>
            ))}
            {rows.map((r) => {
              const c = toCents(r.amount)
              const bad = c === null || c <= 0 || c < r.paid
              return (
                <tr key={r.key} className={cn('border-b border-line last:border-0', r.checked && 'bg-primary-soft/40')}>
                  <td className="pl-4 text-left"><Checkbox checked={r.checked} onChange={(v) => update(r.key, { checked: v })} /></td>
                  <td className="fill px-2 py-1.5 text-center">
                    <Input type="date" value={r.due_date} onChange={(ev) => update(r.key, { due_date: ev.target.value })} className="w-[150px]" />
                    {r.id === null && <span className="ml-1 text-[12px] text-primary">yeni taksit</span>}
                  </td>
                  <td className="px-2 py-1.5 text-center">
                    <div className="flex justify-end">
                      <MoneyInput value={r.amount} onChange={(v) => update(r.key, { amount: v })} invalid={bad} className="w-[150px]" />
                    </div>
                    {c !== null && c < r.paid && <p className="text-right text-[12px] text-danger">Ödenen kısmın altına inemez</p>}
                  </td>
                  <td className="px-2 py-1.5 tabular text-ink-3 text-center">{r.paid > 0 ? money(r.paid / 100) : '—'}</td>
                  <td className="px-4 py-1.5 whitespace-nowrap text-right">
                    <Button size="icon-sm" variant="ghost" aria-label="Böl" title="Böl" onClick={() => { setSplitFor(r); setSplitCount('2') }}><Scissors className="size-3.5" /></Button>
                    <Button size="icon-sm" variant="ghost" aria-label="Kaldır" title={r.paid > 0 ? 'Kısmen ödenmiş taksit kaldırılamaz' : 'Kaldır'} disabled={r.paid > 0} onClick={() => setRows((rs) => rs.filter((x) => x.key !== r.key))}><Trash2 className="size-3.5" /></Button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="px-4 py-3 border-t border-line flex flex-col gap-3">
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
          <Summary label="Düzenlenen toplam" value={money(totalC / 100)} />
          <Summary label="Olması gereken" value={money(targetC / 100)} hint={`Net tutar ${money(detail.net_price, { short: true })} − ödenmiş taksitler ${money(lockedC / 100, { short: true })}`} />
          <Summary label="Fark" value={diffC === 0 ? 'Eşit' : `${diffC > 0 ? 'Eksik' : 'Fazla'} ${money(Math.abs(diffC) / 100)}`} tone={diffC === 0 ? 'success' : 'danger'} />
        </div>
        {diffC !== 0 && (
          <Alert tone="warning"><span className="inline-flex items-center gap-1.5"><AlertTriangle className="size-4" /> Toplam eşitlenmeden kaydedilemez.</span></Alert>
        )}
        <Field label="Değişiklik notu" optional hint="Denetim kaydına yazılır">
          <Textarea rows={2} value={note} onChange={(ev) => setNote(ev.target.value)} placeholder="Örn. Veli talebiyle son iki taksit birleştirildi" maxLength={300} />
        </Field>
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="ghost" onClick={onCancel}>Vazgeç</Button>
          <Button variant="primary" disabled={diffC !== 0 || invalid} loading={save.isPending} onClick={() => save.mutate()}>Planı kaydet</Button>
        </div>
      </div>

      <Modal
        open={!!splitFor}
        onClose={() => setSplitFor(null)}
        size="sm"
        title="Taksiti böl"
        description={splitFor ? `${date(splitFor.due_date)} · ${money((toCents(splitFor.amount) ?? 0) / 100)}${splitFor.paid ? ` (ödenen ${money(splitFor.paid / 100)} ilk parçada kalır)` : ''}` : undefined}
        footer={
          <>
            <Button variant="ghost" onClick={() => setSplitFor(null)}>Vazgeç</Button>
            <Button variant="primary" onClick={split}>Böl</Button>
          </>
        }
      >
        <Field label="Parça sayısı" required hint="Yeni parçalar birer ay arayla vadelenir; kuruş farkı son parçaya yazılır.">
          <Input type="number" min={2} max={24} value={splitCount} onChange={(ev) => setSplitCount(ev.target.value)} />
        </Field>
      </Modal>
    </Panel>
  )
}

function Summary({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: 'success' | 'danger' }) {
  return (
    <div className="rounded-[var(--radius-md)] bg-surface-2/60 px-3 py-2 ring-1 ring-line">
      <p className="text-[12px] text-ink-3">{label}</p>
      <p className={cn('text-[15px] font-semibold tabular', tone === 'success' && 'text-success', tone === 'danger' && 'text-danger')}>{value}</p>
      {hint && <p className="text-[12px] text-ink-3 truncate" title={hint}>{hint}</p>}
    </div>
  )
}

function PriceModal({ open, onClose, detail, onSaved }: { open: boolean; onClose: () => void; detail: Detail; onSaved: () => void }) {
  const [discount, setDiscount] = useState('')
  const [discountReason, setDiscountReason] = useState('')
  const [scholarship, setScholarship] = useState('')
  const [scholarshipReason, setScholarshipReason] = useState('')

  useEffect(() => {
    if (!open) return
    setDiscount(detail.discount_amount.replace('.', ','))
    setDiscountReason(detail.discount_reason ?? '')
    setScholarship(detail.scholarship_amount.replace('.', ','))
    setScholarshipReason(detail.scholarship_reason ?? '')
  }, [open, detail])

  const listC = toCents(detail.list_price) ?? 0
  const newNetC = listC - (toCents(discount) ?? 0) - (toCents(scholarship) ?? 0)
  const deltaC = newNetC - (toCents(detail.net_price) ?? 0)
  const remainingC = toCents(detail.remaining) ?? 0

  const save = useMutation({
    mutationFn: () =>
      api.post<{ message: string }>(`/finance/enrollments/${detail.id}/price`, {
        discount_amount: fromCents(toCents(discount) ?? 0),
        discount_reason: discountReason || null,
        scholarship_amount: fromCents(toCents(scholarship) ?? 0),
        scholarship_reason: scholarshipReason || null,
      }),
    onSuccess: (r) => {
      toast.success(r.message)
      onSaved()
      onClose()
    },
    onError: (err) => toast.error(err instanceof ApiError ? err.firstError() : 'Kaydedilemedi.'),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="İndirim ve burs"
      description="Net bedel farkı, ödenmemiş taksitlere kalan tutarlarıyla orantılı dağıtılır. Ödenmiş kısım değişmez."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" disabled={deltaC === 0 || newNetC < 0 || -deltaC > remainingC} loading={save.isPending} onClick={() => save.mutate()}>Uygula</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="İndirim tutarı" optional><MoneyInput value={discount} onChange={setDiscount} /></Field>
        <Field label="İndirim gerekçesi" optional><Input value={discountReason} onChange={(ev) => setDiscountReason(ev.target.value)} maxLength={200} /></Field>
        <Field label="Burs tutarı" optional><MoneyInput value={scholarship} onChange={setScholarship} /></Field>
        <Field label="Burs gerekçesi" optional><Input value={scholarshipReason} onChange={(ev) => setScholarshipReason(ev.target.value)} maxLength={200} /></Field>
      </div>
      <div className="mt-3 rounded-[var(--radius-md)] ring-1 ring-line px-3 py-1 divide-y divide-line">
        <MetricRow label="Mevcut net tutar" value={money(detail.net_price)} />
        <MetricRow label="Yeni net tutar" value={money(Math.max(0, newNetC) / 100)} strong />
        <MetricRow label="Kalan taksitlere etkisi" value={deltaC === 0 ? '—' : `${deltaC > 0 ? '+' : '−'}${money(Math.abs(deltaC) / 100)}`} tone={deltaC < 0 ? 'success' : deltaC > 0 ? 'warning' : undefined} />
      </div>
      {-deltaC > remainingC && <Alert tone="danger" className="mt-3">İndirim ödenmemiş bakiyeden ({money(remainingC / 100)}) büyük olamaz.</Alert>}
    </Modal>
  )
}

function SignModal({ open, onClose, detail, onSaved }: { open: boolean; onClose: () => void; detail: Detail; onSaved: () => void }) {
  const [name, setName] = useState('')
  const [guardianId, setGuardianId] = useState('')
  const options = useMemo(() => detail.guardians.map((g) => ({ value: g.id, label: g.name })), [detail.guardians])

  useEffect(() => {
    if (!open) return
    const g = detail.financial_guardian ?? detail.guardians.find((x) => x.is_financially_responsible) ?? detail.guardians[0]
    setGuardianId(g ? String(g.id) : '')
    setName(g?.name ?? '')
  }, [open, detail])

  const sign = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/finance/enrollments/${detail.id}/contract/sign`, { signed_by_name: name }),
    onSuccess: (r) => {
      toast.success(r.message)
      onSaved()
      onClose()
    },
    onError: (err) => toast.error(err instanceof ApiError ? err.firstError() : 'Kaydedilemedi.'),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="sm"
      title="Sözleşme imzalandı"
      description="Metin güncel kayıt bilgileriyle son kez oluşturulur ve dondurulur. Sonradan değiştirilemez."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" disabled={name.trim().length < 3} loading={sign.isPending} onClick={() => sign.mutate()}>İmzalandı olarak kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {options.length > 0 && (
          <Field label="İmzalayan veli" optional hint="Listede yoksa “Diğer” seçip adı yazın">
            <Select value={guardianId} onChange={(ev) => { setGuardianId(ev.target.value); setName(detail.guardians.find((g) => String(g.id) === ev.target.value)?.name ?? '') }} placeholder="Diğer" options={options} />
          </Field>
        )}
        <Field label="İmzalayanın adı soyadı" required hint="En az 3 karakter">
          <Input value={name} onChange={(ev) => setName(ev.target.value)} maxLength={160} />
        </Field>
      </div>
    </Modal>
  )
}
