import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { FileSignature, Printer, Search } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, money, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { PageHeader, Panel, Stat } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Segmented, Select, Switch } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { INSTALLMENT_STATUS, sumCents } from './shared'

type NoteRow = {
  installment_id: number
  student_id: number
  student: string | null
  enrollment_no: string | null
  program: string | null
  sequence: number
  due_date: string
  amount: string
  remaining: string
  status: string
  debtor: string | null
  debtor_kind: string | null
  debtor_tax_id: string | null
  missing: string[]
  note: { id: number; note_no: string; print_count: number; last_printed_at: string | null; stale: boolean } | null
}
type Preview = {
  rows: NoteRow[]
  missing: { enrollment_no: string | null; student: string | null; student_id: number; debtor: string | null; missing: string[]; count: number }[]
  total: string
  printed: number
  truncated: boolean
  max: number
}
export type NoteSelector = { installment_ids?: number[]; enrollment_ids?: number[]; student_id?: number; term_id?: string; program_id?: string; class_group_id?: string; due_from?: string; due_to?: string; include_paid?: boolean; q?: string }
type Options = { terms: { id: number; name: string; is_current: boolean }[]; programs: { id: number; name: string }[]; class_groups: { id: number; name: string }[] }

const canPrintPerms = ['installments.manage', 'enrollments.create', 'finance.invoice']

/** Seçilen taksitleri senet olarak hazırlar ve yazdırır (sayfa düzeni + suret seçenekli). */
async function printNotes(installmentIds: number[], perPage: string, copy: boolean) {
  // Pencere tıklama anında açılır (açılır pencere engeline takılmasın), PDF hazır olunca içine yüklenir
  const win = window.open('', '_blank')
  try {
    const prepared = await api.post<{ data: { ids: number[]; count: number } }>('/finance/promissory-notes/prepare', { installment_ids: installmentIds })
    if (!prepared.data.count) throw new Error('Basılacak senet yok (iptal edilmiş taksitler basılmaz).')
    const path = `/finance/promissory-notes/pdf?ids=${prepared.data.ids.join(',')}&per_page=${perPage}&copy=${copy ? 1 : 0}`
    if (!win) {
      await api.download(path, undefined, 'senetler.pdf')
      return prepared.data.count
    }
    const res = await fetch(`/api/v1${path}&inline=1`, { credentials: 'same-origin', headers: { Accept: 'application/pdf' } })
    if (!res.ok) throw new Error('Senet PDF\'i oluşturulamadı.')
    const url = URL.createObjectURL(await res.blob())
    win.location.href = url
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
    return prepared.data.count
  } catch (e) {
    win?.close()
    throw e
  }
}

function PrintOptions({ perPage, setPerPage, copy, setCopy }: { perPage: string; setPerPage: (v: string) => void; copy: boolean; setCopy: (v: boolean) => void }) {
  return (
    <div className="flex flex-wrap items-center gap-3">
      <Segmented size="sm" value={perPage} onChange={setPerPage} options={[{ value: '3', label: 'A4 · 3 senet' }, { value: '2', label: 'A4 · 2 senet' }, { value: '1', label: 'A5 · tek senet' }]} />
      <Switch checked={copy} onChange={setCopy} label="Tekrar basımda SURETİDİR yaz" />
    </div>
  )
}

function MissingAlert({ missing }: { missing: Preview['missing'] }) {
  if (!missing.length) return null
  return (
    <Alert tone="warning" title={`${missing.length} kayıtta borçlu bilgisi eksik`}>
      <p>Senet yine basılır; eksik alanlar boş satır olarak kalır ve elle doldurulabilir.</p>
      <ul className="mt-1 max-h-32 overflow-y-auto scroll-thin text-[12.5px]">
        {missing.slice(0, 30).map((m) => (
          <li key={`${m.enrollment_no}-${m.student_id}`}>
            <Link to={`/ogrenciler/${m.student_id}`} className="font-medium hover:underline">{m.student}</Link> · {m.enrollment_no} · borçlu {m.debtor ?? '—'}: <span className="text-danger">{m.missing.join(', ')}</span> eksik
          </li>
        ))}
        {missing.length > 30 && <li>… ve {missing.length - 30} kayıt daha</li>}
      </ul>
    </Alert>
  )
}

/** Kayıt/taksit ekranlarından hızlı senet basımı. */
export function NotePrintDialog({ open, onClose, selector, title = 'Senet yazdır' }: { open: boolean; onClose: () => void; selector: NoteSelector; title?: string }) {
  const [perPage, setPerPage] = useState('3')
  const [copy, setCopy] = useState(true)
  const preview = useQuery({
    queryKey: ['finance', 'notes-preview', selector],
    queryFn: () => api.post<{ data: Preview }>('/finance/promissory-notes/preview', { ...selector, include_paid: selector.include_paid ?? !!selector.installment_ids }),
    enabled: open,
  })
  const p = preview.data?.data
  const print = useMutation({
    mutationFn: () => printNotes((p?.rows ?? []).map((r) => r.installment_id), perPage, copy),
    onSuccess: (n) => {
      toast.success(`${n} senet hazırlandı.`)
      onClose()
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : e instanceof Error ? e.message : 'Senet basılamadı.'),
  })
  return (
    <Modal open={open} onClose={onClose} title={title} size="lg"
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" icon={<Printer className="size-4" />} disabled={!p?.rows.length} loading={print.isPending} onClick={() => print.mutate()}>{p ? `${p.rows.length} senet yazdır` : 'Yazdır'}</Button></>}>
      {preview.isLoading || !p ? (
        <Skeleton className="h-32" />
      ) : p.rows.length === 0 ? (
        <EmptyState compact icon={<FileSignature />} title="Basılacak taksit yok" description="Açık (ödenmemiş) taksit bulunmuyor." />
      ) : (
        <div className="flex flex-col gap-3">
          <p className="text-[13px] text-ink-2">{num(p.rows.length)} taksit · toplam {money(p.total)} · daha önce basılan {num(p.printed)}</p>
          <PrintOptions perPage={perPage} setPerPage={setPerPage} copy={copy} setCopy={setCopy} />
          <MissingAlert missing={p.missing} />
          <ul className="max-h-56 divide-y divide-line overflow-y-auto scroll-thin rounded-[var(--radius-md)] ring-1 ring-line text-[13px]">
            {p.rows.map((r) => (
              <li key={r.installment_id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                <span className="tabular">{r.sequence}. taksit · {date(r.due_date)}</span>
                <Badge tone={INSTALLMENT_STATUS[r.status]?.tone ?? 'neutral'}>{INSTALLMENT_STATUS[r.status]?.label ?? r.status}</Badge>
                {r.note && <Badge tone={r.note.stale ? 'warning' : 'info'}>{r.note.note_no}{r.note.print_count ? ` · ${r.note.print_count}× basıldı` : ''}{r.note.stale ? ' · yenilenecek' : ''}</Badge>}
                <span className="ml-auto tabular font-medium">{money(r.amount)}</span>
              </li>
            ))}
          </ul>
          <p className="text-[12px] text-ink-3">Senet metni Türk Ticaret Kanunu'nun bono unsurlarına göre hazırlanmıştır; kurum hukukçusu/muhasebecisiyle teyit edin. Metin ayarları: Finans ve fatura ayarları.</p>
        </div>
      )}
    </Modal>
  )
}

/** /finans/senetler — filtreyle toplu senet basımı. */
export default function PromissoryNotes() {
  const can = useCan()
  const canPrint = canPrintPerms.some((x) => can(x))
  const [f, setF] = useState<NoteSelector>({ include_paid: false })
  const [search, setSearch] = useState('')
  const q = useDebounced(search.trim(), 350)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [perPage, setPerPage] = useState('3')
  const [copy, setCopy] = useState(true)
  const options = useQuery({ queryKey: ['finance', 'enrollment-options'], queryFn: () => api.get<Options>('/finance/enrollment-options'), staleTime: 300_000 })
  const hasFilter = !!(f.term_id || f.program_id || f.class_group_id || f.due_from || f.due_to || q)
  const selector = useMemo(() => ({ ...f, q: q || undefined }), [f, q])
  const preview = useQuery({
    queryKey: ['finance', 'notes-preview', 'page', selector],
    queryFn: () => api.post<{ data: Preview }>('/finance/promissory-notes/preview', selector),
    enabled: hasFilter,
  })
  const p = preview.data?.data
  useEffect(() => setSelected(new Set((p?.rows ?? []).map((r) => r.installment_id))), [p])
  const rows = p?.rows ?? []
  const selectedRows = rows.filter((r) => selected.has(r.installment_id))
  const print = useMutation({
    mutationFn: () => printNotes([...selected], perPage, copy),
    onSuccess: (n) => {
      toast.success(`${n} senet hazırlandı; basım kaydedildi.`)
      preview.refetch()
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : e instanceof Error ? e.message : 'Senet basılamadı.'),
  })
  const set = (k: keyof NoteSelector, v: string | boolean) => setF((x) => ({ ...x, [k]: v === '' ? undefined : v }))
  const toggle = (id: number) => setSelected((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Senet basımı"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Senetler' }]}
        description="Her taksit için bir senet (bono). Basılan senetler numaralanır ve kayda alınır; tekrar basımda SURETİDİR ibaresi eklenebilir."
      />
      <Panel title="Filtre" className="mb-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <Field label="Dönem" optional><Select value={f.term_id ?? ''} onChange={(e) => set('term_id', e.target.value)} placeholder="Tüm dönemler" options={(options.data?.terms ?? []).map((t) => ({ value: t.id, label: t.name }))} /></Field>
          <Field label="Program" optional><Select value={f.program_id ?? ''} onChange={(e) => set('program_id', e.target.value)} placeholder="Tüm programlar" options={(options.data?.programs ?? []).map((t) => ({ value: t.id, label: t.name }))} /></Field>
          <Field label="Sınıf" optional><Select value={f.class_group_id ?? ''} onChange={(e) => set('class_group_id', e.target.value)} placeholder="Tüm sınıflar" options={(options.data?.class_groups ?? []).map((t) => ({ value: t.id, label: t.name }))} /></Field>
          <Field label="Öğrenci / kayıt no" optional><Input value={search} onChange={(e) => setSearch(e.target.value)} leading={<Search />} placeholder="Ad, öğrenci no, kayıt no" /></Field>
          <Field label="Vade tarihi (başlangıç)" optional><Input type="date" value={f.due_from ?? ''} onChange={(e) => set('due_from', e.target.value)} /></Field>
          <Field label="Vade tarihi (bitiş)" optional><Input type="date" value={f.due_to ?? ''} onChange={(e) => set('due_to', e.target.value)} /></Field>
          <div className="flex items-end pb-2 sm:col-span-2"><Switch checked={!!f.include_paid} onChange={(v) => set('include_paid', v)} label="Ödenmiş taksitleri de dahil et (ÖDENDİ ibaresiyle)" /></div>
        </div>
      </Panel>

      {!hasFilter ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<FileSignature />} title="Senet basmak için filtre seçin" description="Dönem, program, sınıf, vade aralığı ya da öğrenci seçin. Tek bir kaydın senetleri Kayıt ve Planlar ekranından da basılabilir." />
        </div>
      ) : preview.isLoading || !p ? (
        <Skeleton className="h-64" />
      ) : (
        <div className="flex flex-col gap-3">
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <Stat label="Taksit" value={num(rows.length)} sub={p.truncated ? `ilk ${p.max} gösteriliyor` : 'filtreye uyan'} tone={p.truncated ? 'warning' : undefined} />
            <Stat label="Seçili" value={num(selected.size)} sub={money(sumCents(selectedRows.map((r) => r.amount)) / 100)} />
            <Stat label="Daha önce basılan" value={num(p.printed)} sub="tekrar basımda suret" />
            <Stat label="Bilgisi eksik kayıt" value={num(p.missing.length)} tone={p.missing.length ? 'warning' : undefined} sub="TCKN / adres" />
          </div>
          <MissingAlert missing={p.missing} />
          <Panel
            flush
            title="Taksitler"
            actions={canPrint ? (
              <div className="flex flex-wrap items-center justify-end gap-2">
                <PrintOptions perPage={perPage} setPerPage={setPerPage} copy={copy} setCopy={setCopy} />
                <Button variant="primary" size="sm" icon={<Printer className="size-4" />} disabled={!selected.size} loading={print.isPending} onClick={() => print.mutate()}>{selected.size} senet yazdır</Button>
              </div>
            ) : undefined}
          >
            {rows.length === 0 ? (
              <EmptyState compact icon={<FileSignature />} title="Filtreye uyan açık taksit yok" />
            ) : (
              <div className="overflow-x-auto scroll-thin">
                <table className="tbl w-full min-w-[720px] text-[13px]">
                  <thead>
                    <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
                      <th className="w-10 pl-4 text-left"><Checkbox checked={selected.size === rows.length} indeterminate={selected.size > 0 && selected.size < rows.length} onChange={(v) => setSelected(new Set(v ? rows.map((r) => r.installment_id) : []))} /></th>
                      <th className="h-9 px-2 font-medium text-center">Öğrenci / kayıt no</th>
                      <th className="px-3 font-medium text-center">Taksit / vade</th>
                      <th className="px-3 font-medium text-center">Borçlu (senedi imzalayan)</th>
                      <th className="px-3 font-medium text-center">Senet no</th>
                      <th className="px-4 font-medium text-center">Senet tutarı</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((r) => (
                      <tr key={r.installment_id} onClick={() => toggle(r.installment_id)} className={cn('cursor-pointer border-b border-line last:border-0', selected.has(r.installment_id) ? 'bg-primary-soft/30' : 'hover:bg-surface-2/60')}>
                        <td className="pl-4 text-left" onClick={(e) => e.stopPropagation()}><Checkbox checked={selected.has(r.installment_id)} onChange={() => toggle(r.installment_id)} /></td>
                        <td className="px-2 py-2 text-center">
                          <p className="font-medium">{r.student}</p>
                          <p className="text-[12px] text-ink-3">Kayıt no: {r.enrollment_no}{r.program ? ` · ${r.program}` : ''}</p>
                        </td>
                        <td className="px-3 py-2 whitespace-nowrap text-center">
                          <p className="tabular">{r.sequence}. taksit · Vade: {date(r.due_date)}</p>
                          <Badge tone={INSTALLMENT_STATUS[r.status]?.tone ?? 'neutral'}>{INSTALLMENT_STATUS[r.status]?.label ?? r.status}</Badge>
                        </td>
                        <td className="px-3 py-2 text-center">
                          <p>{r.debtor ?? '—'}</p>
                          {r.missing.length > 0 ? <p className="text-[12px] text-warning">Eksik: {r.missing.join(', ')}</p> : <p className="text-[12px] text-ink-3 tabular">TCKN: {r.debtor_tax_id}</p>}
                        </td>
                        <td className="px-3 py-2 text-center">
                          {r.note ? (
                            <Badge tone={r.note.stale ? 'warning' : r.note.print_count ? 'info' : 'neutral'}>{r.note.note_no}{r.note.print_count ? ` · ${r.note.print_count} kez basıldı` : ''}{r.note.stale ? ' · yenilenecek' : ''}</Badge>
                          ) : <span className="text-ink-3">Basılmadı</span>}
                        </td>
                        <td className="px-4 py-2 tabular font-medium whitespace-nowrap text-center">{money(r.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
          <p className="px-1 text-[12px] text-ink-3">Senet metni TTK bono unsurlarına (bono ibaresi, kayıtsız şartsız ödeme vaadi, vade, ödeme yeri, lehtar, düzenleme yeri ve tarihi, imza) göre hazırlanmıştır; kurum hukukçusu/muhasebecisiyle teyit edin.</p>
        </div>
      )}
    </div>
  )
}
