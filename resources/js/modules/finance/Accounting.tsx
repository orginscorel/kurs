import { useMemo, useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, BookOpenCheck, CheckCircle2, ExternalLink, Lock, LockOpen, Pencil, Plus, RotateCcw, Scale, Search, Trash2 } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date, dateTime, money, num, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { PageHeader, Panel, Tabs } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Drawer, Modal } from '@/components/ui/overlay'
import { DateRange, ExportButton, presetRange, SimpleTable, Th } from '@/modules/reports/shared'
import { MetricRow, MoneyInput } from './components'
import { fromCents, toCents } from './shared'
import { JOURNAL_EVENTS, LEDGER_TYPES, sourceLink, type JournalRow } from './ledger'

type TabKey = 'yevmiye' | 'defter' | 'mizan' | 'plan' | 'donem'

type ChartAccount = { id: number; code: string; name: string; type: string; normal_side: string; is_active: boolean; is_system: boolean; balance: string; line_count: number }
type MappingRow = { source_type: string; source_key: string; label: string; group: string; code: string; custom: boolean; inactive: boolean }
type ChartResponse = { data: { accounts: ChartAccount[]; mappings: { accounts: MappingRow[]; categories: MappingRow[]; roles: MappingRow[] }; types: Record<string, string> } }

/** Gerekçe zorunlu onay penceresi (en az 5 karakter). */
function ReasonDialog({ open, onClose, onConfirm, title, description, loading, confirmLabel, children }: {
  open: boolean; onClose: () => void; onConfirm: (reason: string) => void; title: string; description?: ReactNode; loading?: boolean; confirmLabel: string; children?: ReactNode
}) {
  const [reason, setReason] = useState('')
  const [wasOpen, setWasOpen] = useState(open)
  if (open !== wasOpen) {
    setWasOpen(open)
    if (open) setReason('')
  }
  const short = reason.trim().length < 5
  return (
    <ConfirmDialog open={open} onClose={onClose} title={title} description={description} danger loading={loading} confirmLabel={confirmLabel} onConfirm={() => !short && onConfirm(reason.trim())}>
      {children}
      <Field label="Gerekçe" required hint="Denetim kaydına yazılır; en az 5 karakter." error={reason && short ? 'Gerekçe en az 5 karakter olmalı.' : null}>
        <Textarea autoFocus rows={3} value={reason} maxLength={250} onChange={(e) => setReason(e.target.value)} />
      </Field>
    </ConfirmDialog>
  )
}

const errMsg = (e: unknown, fallback: string) => (e instanceof ApiError ? e.firstError() : fallback)

function useChart() {
  return useQuery({ queryKey: ['finance', 'accounting', 'chart'], queryFn: () => api.get<ChartResponse>('/finance/accounting/chart'), staleTime: 30_000 })
}

/** Tarih aralığı URL'de (?from=&to=) — sekmeler arasında korunur. */
function useRange() {
  const [params, setParams] = useSearchParams()
  const def = presetRange('year')
  const from = params.get('from') ?? def.from
  const to = params.get('to') ?? def.to
  const set = (r: { from: string; to: string }) =>
    setParams((p) => {
      p.set('from', r.from)
      p.set('to', r.to)
      p.delete('page')
      return p
    }, { replace: true })
  return { from, to, set }
}

export default function Accounting() {
  const can = useCan()
  const [params, setParams] = useSearchParams()
  const tab = (params.get('sekme') as TabKey) || 'yevmiye'
  const setTab = (t: TabKey, extra?: Record<string, string>) =>
    setParams((p) => {
      p.set('sekme', t)
      p.delete('page')
      Object.entries(extra ?? {}).forEach(([k, v]) => p.set(k, v))
      return p
    }, { replace: true })

  if (!can('finance.accounting')) {
    return (
      <div className="animate-fade-in">
        <PageHeader title="Muhasebe" breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Muhasebe' }]} />
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<Lock />} title="Muhasebe yetkiniz yok" description="Yevmiye, mizan ve hesap planı için muhasebe yetkisi gerekir." />
        </div>
      </div>
    )
  }

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Muhasebe"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Muhasebe' }]}
        description="Tahsilat, iade, gelir-gider, transfer ve faturalardan otomatik kesilen yevmiye fişleri; defter, mizan ve dönem kilidi"
      />
      <Tabs<TabKey>
        className="mb-4"
        value={tab}
        onChange={(t) => setTab(t)}
        tabs={[
          { value: 'yevmiye', label: 'Yevmiye' },
          { value: 'defter', label: 'Büyük defter / muavin' },
          { value: 'mizan', label: 'Mizan' },
          { value: 'plan', label: 'Hesap planı ve eşleme' },
          { value: 'donem', label: 'Dönem kilidi' },
        ]}
      />
      {tab === 'yevmiye' && <JournalTab />}
      {tab === 'defter' && <LedgerTab />}
      {tab === 'mizan' && <TrialBalanceTab onOpenCode={(code) => setTab('defter', { code })} />}
      {tab === 'plan' && <ChartTab />}
      {tab === 'donem' && <PeriodsTab />}
    </div>
  )
}

/* ================================================================== Yevmiye */

type JournalResponse = Paginated<JournalRow> & { meta: { totals: { count: number; amount: string } } }

function JournalTab() {
  const can = useCan()
  const [params, setParams] = useSearchParams()
  const range = useRange()
  const [search, setSearch] = useState(params.get('q') ?? '')
  const q = useDebounced(search.trim(), 300)
  const event = params.get('event') ?? ''
  const page = Number(params.get('page') ?? 1)
  // ?fis=ID ile gelindiyse (fatura/tahsilat ekranından) fiş çekmecesi doğrudan açılır
  const [detail, setDetail] = useState<JournalRow | null>(() => {
    const id = Number(params.get('fis') || 0)
    return id ? ({ id, entry_no: '', entry_date: '', event: '', event_label: '' } as unknown as JournalRow) : null
  })
  const [manualOpen, setManualOpen] = useState(false)
  const query = { from: range.from, to: range.to, event: event || undefined, q: q || undefined, page }

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'accounting', 'journal', query],
    queryFn: () => api.get<JournalResponse>('/finance/accounting/journal', query),
    placeholderData: keepPreviousData,
  })

  const setParam = (k: string, v: string | null) =>
    setParams((p) => {
      if (v) p.set(k, v)
      else p.delete(k)
      if (k !== 'page') p.delete('page')
      return p
    }, { replace: true })

  const columns = useMemo<Column<JournalRow>[]>(
    () => [
      {
        key: 'no',
        header: 'Fiş no / tarih',
        cell: (r) => (
          <div className="min-w-[120px]">
            <p className={cn('font-medium tabular', r.reversed && 'text-ink-3')}>{r.entry_no}</p>
            <p className="text-[12px] text-ink-3 tabular">{date(r.entry_date)}</p>
          </div>
        ),
      },
      {
        key: 'event',
        header: 'İşlem',
        cell: (r) => (
          <div className="flex flex-col items-start gap-1">
            <Badge tone={JOURNAL_EVENTS[r.event]?.tone ?? 'neutral'}>{JOURNAL_EVENTS[r.event]?.label ?? r.event_label}</Badge>
            {r.reversed && <span className="text-[12px] text-ink-3">ters kaydı var</span>}
          </div>
        ),
      },
      { key: 'description', header: 'Açıklama', maxWidth: 300, cell: (r) => <span className="text-ink-2 line-clamp-2">{r.description}</span> },
      {
        key: 'lines',
        header: 'Hesap satırları',
        hideable: true,
        maxWidth: 340,
        cell: (r) => (
          <ul className="text-[12px] leading-5">
            {r.lines.slice(0, 4).map((l, i) => (
              <li key={i} className="flex gap-2 whitespace-nowrap">
                <span className={cn('w-8 shrink-0 tabular font-medium', Number(l.debit) > 0 ? 'text-ink' : 'pl-3 text-ink-2')}>{l.code}</span>
                <span className="truncate text-ink-3">{l.name}</span>
                <span className="ml-auto tabular text-ink-2">{money(Number(l.debit) > 0 ? l.debit : l.credit)}</span>
              </li>
            ))}
            {r.lines.length > 4 && <li className="text-ink-3">+{r.lines.length - 4} satır</li>}
          </ul>
        ),
      },
      { key: 'total', header: 'Tutar', align: 'right', cell: (r) => <span className="font-semibold tabular whitespace-nowrap">{money(r.total)}</span> },
    ],
    [],
  )

  const t = data?.meta.totals

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center gap-2 rounded-[var(--radius-lg)] bg-surface p-3 ring-1 ring-line">
        <DateRange from={range.from} to={range.to} presets={['month', 'last_month', 'year']} onChange={range.set} />
        <div className="ml-auto flex flex-wrap gap-2">
          <ExportButton path="/finance/accounting/journal/export" query={{ from: range.from, to: range.to, event: event || undefined, q: q || undefined }} name="yevmiye.xlsx" label="Fiş listesi (Excel)" />
          {can('finance.accounting') && (
            <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setManualOpen(true)}>Elle fiş</Button>
          )}
        </div>
      </div>

      <DataTable
        storageKey="finance-journal"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(p) => setParam('page', p === 1 ? null : String(p))}
        onRowClick={setDetail}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => { setSearch(e.target.value); setParam('q', e.target.value || null) }} placeholder="Fiş no ya da açıklama" leading={<Search />} className="w-full sm:w-[260px]" />
            <Select className="w-full sm:w-[200px]" value={event} onChange={(e) => setParam('event', e.target.value || null)} placeholder="Tüm işlemler"
              options={Object.entries(JOURNAL_EVENTS).map(([value, v]) => ({ value, label: v.label }))} />
            {t && <span className="ml-auto text-[12.5px] text-ink-3 tabular">{num(t.count)} fiş · {money(t.amount)}</span>}
          </div>
        }
        empty={<EmptyState icon={<BookOpenCheck />} title="Bu aralıkta fiş yok" description="Tahsilat, gider, transfer ya da fatura kaydedildikçe fişler otomatik oluşur." />}
      />

      <JournalDrawer row={detail} onClose={() => setDetail(null)} />
      <ManualEntryModal open={manualOpen} onClose={() => setManualOpen(false)} />
    </>
  )
}

type EntryDetail = JournalRow & { reversal: { id: number; entry_no: string; entry_date: string } | null; reverses: { id: number; entry_no: string; entry_date: string } | null; created_by: string | null }

function JournalDrawer({ row, onClose }: { row: JournalRow | null; onClose: () => void }) {
  const can = useCan()
  const qc = useQueryClient()
  const [reverseOpen, setReverseOpen] = useState(false)
  const { data, isLoading } = useQuery({
    queryKey: ['finance', 'accounting', 'entry', row?.id],
    queryFn: () => api.get<{ data: EntryDetail }>(`/finance/accounting/journal/${row!.id}`),
    enabled: !!row,
  })
  const e = data?.data
  const link = e ? sourceLink(e.source_type, e.source_id) : null
  const totals = (e?.lines ?? []).reduce((s, l) => ({ d: s.d + (toCents(l.debit) ?? 0), c: s.c + (toCents(l.credit) ?? 0) }), { d: 0, c: 0 })

  const reverse = useMutation({
    mutationFn: (reason: string) => api.post<{ message: string }>(`/finance/accounting/journal/${row!.id}/reverse`, { reason }),
    onSuccess: (res) => {
      toast.success(res.message)
      setReverseOpen(false)
      qc.invalidateQueries({ queryKey: ['finance'] })
      onClose()
    },
    onError: (err) => toast.error(errMsg(err, 'Ters fiş kesilemedi.')),
  })

  return (
    <Drawer
      open={!!row}
      onClose={onClose}
      width={640}
      title={row ? `Fiş ${e?.entry_no ?? row.entry_no}` : 'Fiş'}
      description={e ?? (row && row.entry_date) ? `${date((e ?? row)!.entry_date)} · ${JOURNAL_EVENTS[(e ?? row)!.event]?.label ?? (e ?? row)!.event_label}` : undefined}
      footer={
        e && (
          <div className="flex w-full flex-wrap items-center gap-2">
            {e.event === 'manual' && !e.reversed && !e.is_reversal && can('finance.accounting') && (
              <Button variant="danger-soft" icon={<RotateCcw className="size-4" />} onClick={() => setReverseOpen(true)}>Ters fiş kes</Button>
            )}
            {link && (
              <Link to={link} className="ml-auto inline-flex items-center gap-1.5 text-[13px] font-medium text-primary hover:underline">
                Kaynak belgeyi aç <ExternalLink className="size-3.5" />
              </Link>
            )}
          </div>
        )
      }
    >
      {isLoading || !e ? (
        <div className="space-y-3"><Skeleton className="h-16" /><Skeleton className="h-40" /></div>
      ) : (
        <div className="flex flex-col gap-4">
          {e.reversed && e.reversal && <Alert tone="warning" title="Bu fiş ters kayıtla kapatıldı">{e.reversal.entry_no} · {date(e.reversal.entry_date)}</Alert>}
          {e.is_reversal && e.reverses && <Alert tone="info" title="Ters kayıt fişi">{e.reverses.entry_no} numaralı fişi kapatır.</Alert>}
          <div className="rounded-[var(--radius-md)] ring-1 ring-line px-3.5 py-1.5 divide-y divide-line">
            <MetricRow label="Açıklama" value={e.description} />
            <MetricRow label="Tutar" value={money(e.total)} strong />
            <MetricRow label="Kesen" value={e.created_by ?? 'Sistem (otomatik)'} />
          </div>
          <div className="overflow-x-auto rounded-[var(--radius-md)] ring-1 ring-line">
            <table className="tbl w-full min-w-[520px] text-[13px]">
              <thead>
                <tr className="border-b border-line bg-surface-2/60 text-[12px] text-ink-3">
                  <th className="h-8 px-3 font-medium text-left">Kod</th>
                  <th className="px-3 font-medium text-center">Hesap / açıklama</th>
                  <th className="px-3 font-medium text-center">Cari</th>
                  <th className="px-3 font-medium text-center">Borç</th>
                  <th className="px-3 font-medium text-center">Alacak</th>
                </tr>
              </thead>
              <tbody>
                {e.lines.map((l, i) => (
                  <tr key={i} className="border-b border-line last:border-0 align-top">
                    <td className="px-3 py-2 tabular font-medium text-left">{l.code}</td>
                    <td className={cn('px-3 py-2 text-center', Number(l.credit) > 0 && 'pl-7')}>
                      <p>{l.name}</p>
                      {l.description && <p className="text-[12px] text-ink-3">{l.description}</p>}
                    </td>
                    <td className="px-3 py-2 text-ink-2 text-center">
                      {l.partner_type === 'student' && l.partner_id ? <Link className="hover:underline" to={`/ogrenciler/${l.partner_id}`}>{l.partner}</Link> : l.partner ?? '—'}
                    </td>
                    <td className="px-3 py-2 tabular text-center">{Number(l.debit) > 0 ? money(l.debit) : ''}</td>
                    <td className="px-3 py-2 tabular text-center">{Number(l.credit) > 0 ? money(l.credit) : ''}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t border-line bg-surface-2/60 font-semibold">
                  <td className="px-3 py-2 text-left" colSpan={3}>Toplam {totals.d === totals.c && <Badge tone="success" className="ml-2">Dengede</Badge>}</td>
                  <td className="px-3 py-2 tabular text-center">{money(totals.d / 100)}</td>
                  <td className="px-3 py-2 tabular text-center">{money(totals.c / 100)}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      )}
      <ReasonDialog
        confirmLabel="Ters fiş kes"
        open={reverseOpen}
        onClose={() => setReverseOpen(false)}
        loading={reverse.isPending}
        onConfirm={(reason) => reverse.mutate(reason)}
        title="Elle fişi ters çevir"
        description={e ? `${e.entry_no} · ${money(e.total)}` : undefined}
      >
        <Alert tone="warning" className="mb-3">Fiş silinmez; borç ve alacakları yer değiştiren yeni bir ters fiş bugünün tarihiyle kesilir.</Alert>
      </ReasonDialog>
    </Drawer>
  )
}

type DraftLine = { code: string; debit: string; credit: string; description: string }
const emptyLine = (): DraftLine => ({ code: '', debit: '', credit: '', description: '' })

function ManualEntryModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient()
  const chart = useChart()
  const [entryDate, setEntryDate] = useState(todayISO())
  const [description, setDescription] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine(), emptyLine()])
  const options = (chart.data?.data.accounts ?? []).filter((a) => a.is_active).map((a) => ({ value: a.code, label: `${a.code} · ${a.name}` }))
  const debit = lines.reduce((s, l) => s + (toCents(l.debit) ?? 0), 0)
  const credit = lines.reduce((s, l) => s + (toCents(l.credit) ?? 0), 0)
  const diff = debit - credit
  const validLines = lines.filter((l) => l.code && ((toCents(l.debit) ?? 0) > 0 || (toCents(l.credit) ?? 0) > 0))
  const badLine = lines.some((l) => (toCents(l.debit) ?? 0) > 0 && (toCents(l.credit) ?? 0) > 0)
  const canSave = description.trim().length >= 3 && validLines.length >= 2 && diff === 0 && debit > 0 && !badLine

  const reset = () => {
    setEntryDate(todayISO())
    setDescription('')
    setLines([emptyLine(), emptyLine()])
  }
  const save = useMutation({
    mutationFn: () =>
      api.post<{ message: string }>('/finance/accounting/journal', {
        entry_date: entryDate,
        description: description.trim(),
        lines: validLines.map((l) => ({
          code: l.code,
          debit: (toCents(l.debit) ?? 0) > 0 ? fromCents(toCents(l.debit)!) : null,
          credit: (toCents(l.credit) ?? 0) > 0 ? fromCents(toCents(l.credit)!) : null,
          description: l.description || null,
        })),
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance'] })
      reset()
      onClose()
    },
    onError: (e) => toast.error(errMsg(e, 'Fiş kaydedilemedi.')),
  })
  const patch = (i: number, p: Partial<DraftLine>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...p } : l)))

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      title="Elle yevmiye fişi"
      description="Muhasebeci düzeltmeleri içindir. Borç ve alacak toplamı eşit olmalı; kapalı döneme fiş kesilemez."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" disabled={!canSave} loading={save.isPending} onClick={() => save.mutate()}>Fişi kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-3.5">
        <div className="grid grid-cols-1 sm:grid-cols-[180px_minmax(0,1fr)] gap-3">
          <Field label="Fiş tarihi" required>
            <Input type="date" value={entryDate} max={todayISO()} onChange={(e) => setEntryDate(e.target.value)} />
          </Field>
          <Field label="Açıklama" required>
            <Input value={description} maxLength={300} onChange={(e) => setDescription(e.target.value)} placeholder="Örn. Eylül kira tahakkuku düzeltmesi" />
          </Field>
        </div>
        <div className="flex flex-col gap-2">
          {lines.map((l, i) => (
            <div key={i} className="grid grid-cols-2 gap-2 rounded-[var(--radius-md)] bg-surface-2/50 p-2 ring-1 ring-line sm:grid-cols-[minmax(0,1.4fr)_120px_120px_minmax(0,1fr)_36px] sm:bg-transparent sm:p-0 sm:ring-0">
              <Select className="col-span-2 sm:col-span-1" value={l.code} onChange={(e) => patch(i, { code: e.target.value })} placeholder="Hesap seçin" options={options} />
              <MoneyInput value={l.debit} onChange={(v) => patch(i, { debit: v, credit: v ? '' : l.credit })} placeholder="Borç" />
              <MoneyInput value={l.credit} onChange={(v) => patch(i, { credit: v, debit: v ? '' : l.debit })} placeholder="Alacak" />
              <Input className="col-span-2 sm:col-span-1" value={l.description} onChange={(e) => patch(i, { description: e.target.value })} placeholder="Satır açıklaması" maxLength={300} />
              <Button className="col-span-2 sm:col-span-1" variant="ghost" size="icon" aria-label="Satırı sil" disabled={lines.length <= 2} onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}>
                <Trash2 className="size-4" />
              </Button>
            </div>
          ))}
          <div>
            <Button size="sm" variant="ghost" icon={<Plus className="size-3.5" />} onClick={() => setLines((ls) => [...ls, emptyLine()])} disabled={lines.length >= 50}>Satır ekle</Button>
          </div>
        </div>
        <div className="grid grid-cols-3 gap-2 rounded-[var(--radius-md)] ring-1 ring-line px-3 py-2 text-[13px]">
          <div><p className="text-[12px] text-ink-3">Borç</p><p className="font-semibold tabular">{money(debit / 100)}</p></div>
          <div><p className="text-[12px] text-ink-3">Alacak</p><p className="font-semibold tabular">{money(credit / 100)}</p></div>
          <div><p className="text-[12px] text-ink-3">Fark</p><p className={cn('font-semibold tabular', diff === 0 ? 'text-success' : 'text-danger')}>{money(diff / 100)}</p></div>
        </div>
        {diff !== 0 && debit + credit > 0 && <Alert tone="warning">Fiş dengede değil: borç ve alacak toplamı eşit olmalı.</Alert>}
        {badLine && <Alert tone="danger">Bir satıra hem borç hem alacak yazılamaz.</Alert>}
      </div>
    </Modal>
  )
}

/* ================================================================== Büyük defter / muavin */

type LedgerData = {
  account: { code: string; name: string; type: string } | null
  opening: string
  rows: { id: number; date: string; entry_id: number; entry_no: string; event: string; source_type: string | null; source_id: number | null; code: string; description: string | null; partner: string | null; partner_type: string | null; partner_id: number | null; debit: string; credit: string; balance: string }[]
  truncated: boolean
  totals: { debit: string; credit: string; closing: string }
  partners: { partner_type: string; partner_id: number; name: string | null; balance: string }[]
}

function LedgerTab() {
  const [params, setParams] = useSearchParams()
  const range = useRange()
  const chart = useChart()
  const code = params.get('code') ?? '100'
  const partnerType = params.get('partner_type')
  const partnerId = params.get('partner_id')
  const partnerName = params.get('partner_name')
  const query = { code, from: range.from, to: range.to, partner_type: partnerType ?? undefined, partner_id: partnerId ?? undefined }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'accounting', 'ledger', query],
    queryFn: () => api.get<{ data: LedgerData }>('/finance/accounting/ledger', query).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
  const setParam = (patch: Record<string, string | null>) =>
    setParams((p) => {
      Object.entries(patch).forEach(([k, v]) => (v === null ? p.delete(k) : p.set(k, v)))
      return p
    }, { replace: true })
  const clearPartner = { partner_type: null, partner_id: null, partner_name: null }

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center gap-2 rounded-[var(--radius-lg)] bg-surface p-3 ring-1 ring-line">
        <Select
          aria-label="Hesap"
          className="w-full sm:w-[280px]"
          value={code}
          onChange={(e) => setParam({ code: e.target.value, ...clearPartner })}
          options={(chart.data?.data.accounts ?? []).map((a) => ({ value: a.code, label: `${a.code} · ${a.name}` }))}
        />
        <DateRange from={range.from} to={range.to} presets={['month', 'last_month', 'year']} onChange={range.set} />
        <div className="ml-auto">
          <ExportButton path="/finance/accounting/ledger/export" query={query} name={`defter-${code}.xlsx`} label="Excel" />
        </div>
      </div>

      {partnerId && (
        <Alert tone="info" className="mb-3" action={<Button size="xs" variant="ghost" icon={<ArrowLeft className="size-3.5" />} onClick={() => setParam(clearPartner)}>Tüm cariler</Button>}>
          Muavin: <strong>{partnerName ?? `#${partnerId}`}</strong> — yalnız bu cariye ait satırlar gösteriliyor.
        </Alert>
      )}

      <div className={cn('grid grid-cols-1 gap-4', !partnerId && 'xl:grid-cols-[minmax(0,1fr)_300px]')}>
        <Panel
          title={data?.account ? `${data.account.code} · ${data.account.name}` : `Hesap ${code}`}
          description={data?.account ? LEDGER_TYPES[data.account.type] : undefined}
          flush
          className={cn(isFetching && !isLoading && 'opacity-70')}
        >
          {isLoading || !data ? (
            <div className="px-4 pb-4"><Skeleton className="h-60" /></div>
          ) : (
            <>
              <div className="grid grid-cols-2 gap-2 px-4 pb-3 sm:grid-cols-4">
                <MiniStat label="Devir" value={money(data.opening)} />
                <MiniStat label="Dönem borç" value={money(data.totals.debit)} />
                <MiniStat label="Dönem alacak" value={money(data.totals.credit)} />
                <MiniStat label="Kapanış bakiyesi" value={money(data.totals.closing)} strong tone={Number(data.totals.closing) < 0 ? 'danger' : undefined} />
              </div>
              {data.rows.length === 0 ? (
                <EmptyState compact icon={<BookOpenCheck />} title="Bu aralıkta hareket yok" />
              ) : (
                <div className="overflow-x-auto scroll-thin border-t border-line">
                  <table className="tbl w-full min-w-[720px] text-[13px]">
                    <thead>
                      <tr className="border-b border-line bg-surface-2/60 text-[12px] text-ink-3">
                        <th className="h-9 px-4 font-medium text-left">Tarih</th>
                        <th className="px-3 font-medium text-center">Fiş</th>
                        <th className="px-3 font-medium text-center">Açıklama</th>
                        <th className="px-3 font-medium text-center">Cari</th>
                        <th className="px-3 font-medium text-center">Borç</th>
                        <th className="px-3 font-medium text-center">Alacak</th>
                        <th className="px-4 font-medium text-center">Bakiye</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.rows.map((r) => {
                        const link = sourceLink(r.source_type, r.source_id)
                        return (
                          <tr key={r.id} className="border-b border-line last:border-0 hover:bg-surface-2/50">
                            <td className="px-4 py-2 tabular whitespace-nowrap text-left">{date(r.date)}</td>
                            <td className="px-3 py-2 tabular whitespace-nowrap text-center">{link ? <Link to={link} className="hover:underline">{r.entry_no}</Link> : r.entry_no}</td>
                            <td className="px-3 py-2 text-ink-2 max-w-[280px] text-center"><span className="line-clamp-2">{r.description ?? '—'}</span></td>
                            <td className="px-3 py-2 text-ink-2 max-w-[180px] text-center">
                              {r.partner_id && !partnerId ? (
                                <button type="button" className="truncate hover:underline text-left" onClick={() => setParam({ partner_type: r.partner_type, partner_id: String(r.partner_id), partner_name: r.partner })}>{r.partner}</button>
                              ) : (
                                <span className="truncate">{r.partner ?? '—'}</span>
                              )}
                            </td>
                            <td className="px-3 py-2 tabular text-center">{Number(r.debit) > 0 ? money(r.debit) : ''}</td>
                            <td className="px-3 py-2 tabular text-center">{Number(r.credit) > 0 ? money(r.credit) : ''}</td>
                            <td className={cn('px-4 py-2 tabular font-medium text-center', Number(r.balance) < 0 && 'text-danger')}>{money(r.balance)}</td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                  {data.truncated && <p className="px-4 py-2 text-[12px] text-ink-3">İlk 2.000 satır gösteriliyor; tamamı için Excel'i kullanın.</p>}
                </div>
              )}
            </>
          )}
        </Panel>

        {!partnerId && (
          <Panel title="Cari bakiyeleri" description="Bu hesapta bakiyesi olan öğrenci / veli / kurumlar (muavin)">
            {isLoading ? (
              <Skeleton className="h-40" />
            ) : !data?.partners.length ? (
              <p className="text-[13px] text-ink-3">Cari kırılımı yok.</p>
            ) : (
              <ul className="-mx-1 flex max-h-[520px] flex-col divide-y divide-line overflow-y-auto scroll-thin">
                {data.partners.map((p) => (
                  <li key={`${p.partner_type}-${p.partner_id}`}>
                    <button
                      type="button"
                      className="flex w-full items-center gap-2 rounded-[var(--radius-sm)] px-1 py-2 text-left hover:bg-surface-2"
                      onClick={() => setParam({ partner_type: p.partner_type, partner_id: String(p.partner_id), partner_name: p.name })}
                    >
                      <span className="min-w-0 flex-1 truncate text-[13px]">{p.name ?? `#${p.partner_id}`}</span>
                      <span className={cn('text-[13px] font-medium tabular', Number(p.balance) < 0 ? 'text-danger' : 'text-ink')}>{money(p.balance)}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        )}
      </div>
    </>
  )
}

function MiniStat({ label, value, strong, tone }: { label: string; value: string; strong?: boolean; tone?: 'danger' }) {
  return (
    <div className="rounded-[var(--radius-md)] bg-surface-2/60 px-3 py-2 ring-1 ring-line">
      <p className="text-[12px] text-ink-3">{label}</p>
      <p className={cn('text-[14px] tabular', strong ? 'font-semibold' : 'font-medium', tone === 'danger' && 'text-danger')}>{value}</p>
    </div>
  )
}

/* ================================================================== Mizan */

type TBRow = { code: string; name: string; type: string | null; opening_debit: string; opening_credit: string; period_debit: string; period_credit: string; total_debit: string; total_credit: string; closing_debit: string; closing_credit: string }
type TBData = { rows: TBRow[]; totals: Omit<TBRow, 'code' | 'name' | 'type'>; balanced: boolean; from: string; to: string }

function TrialBalanceTab({ onOpenCode }: { onOpenCode: (code: string) => void }) {
  const range = useRange()
  const [mainOnly, setMainOnly] = useState(false)
  const query = { from: range.from, to: range.to, main_only: mainOnly ? 1 : undefined }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'accounting', 'tb', query],
    queryFn: () => api.get<{ data: TBData }>('/finance/accounting/trial-balance', query).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
  const amt = (v: string) => (Number(v) !== 0 ? money(v) : '')

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center gap-2 rounded-[var(--radius-lg)] bg-surface p-3 ring-1 ring-line">
        <DateRange from={range.from} to={range.to} presets={['month', 'last_month', 'year']} onChange={range.set} />
        <Switch checked={mainOnly} onChange={setMainOnly} label="Yalnız ana hesaplar" />
        <div className="ml-auto flex items-center gap-2">
          {data && (data.balanced ? <Badge tone="success" dot>Mizan dengede</Badge> : <Badge tone="danger" dot>Dengesiz</Badge>)}
          <ExportButton path="/finance/accounting/trial-balance/export" query={query} name="mizan.xlsx" label="Mizan (Excel)" />
        </div>
      </div>
      {data && !data.balanced && (
        <Alert tone="danger" className="mb-3" title="Mizan dengede değil">Toplam borç ile toplam alacak eşit değil. Sistem yöneticisine bildirin; fişler "Dönem kilidi" sekmesindeki doğrulamayla kontrol edilebilir.</Alert>
      )}
      <Panel flush className={cn(isFetching && !isLoading && 'opacity-70')}>
        {isLoading || !data ? (
          <div className="p-4"><Skeleton className="h-72" /></div>
        ) : data.rows.length === 0 ? (
          <EmptyState icon={<Scale />} title="Bu aralıkta hareket yok" />
        ) : (
          <SimpleTable
            className="[&_table]:min-w-[860px] [&_th]:px-3 [&_td]:px-3 [&_th]:py-2 [&_td]:py-2 [&_tbody_tr]:border-b [&_tbody_tr]:border-line"
            head={
              <>
                <tr className="bg-surface-2/60">
                  <th rowSpan={2}>Hesap</th>
                  <th colSpan={2} className="text-center">Devir</th>
                  <th colSpan={2} className="text-center">Dönem</th>
                  <th colSpan={2} className="text-center">Bakiye</th>
                </tr>
                <tr className="bg-surface-2/60 border-b border-line">
                  <Th right>Borç</Th><Th right>Alacak</Th><Th right>Borç</Th><Th right>Alacak</Th><Th right>Borç</Th><Th right>Alacak</Th>
                </tr>
              </>
            }
          >
            {data.rows.map((r) => (
              <tr key={r.code} className="hover:bg-surface-2/50">
                <td>
                  <button type="button" onClick={() => onOpenCode(r.code)} className="text-left hover:underline">
                    <span className="font-medium tabular">{r.code}</span> <span className="text-ink-2">{r.name}</span>
                  </button>
                </td>
                <td className="text-right tabular text-ink-3">{amt(r.opening_debit)}</td>
                <td className="text-right tabular text-ink-3">{amt(r.opening_credit)}</td>
                <td className="text-right tabular">{amt(r.period_debit)}</td>
                <td className="text-right tabular">{amt(r.period_credit)}</td>
                <td className="text-right tabular font-medium">{amt(r.closing_debit)}</td>
                <td className="text-right tabular font-medium">{amt(r.closing_credit)}</td>
              </tr>
            ))}
            <tr className="border-t-2 border-line bg-surface-2/60 font-semibold">
              <td>Toplam</td>
              <td className="text-right tabular">{money(data.totals.opening_debit)}</td>
              <td className="text-right tabular">{money(data.totals.opening_credit)}</td>
              <td className="text-right tabular">{money(data.totals.period_debit)}</td>
              <td className="text-right tabular">{money(data.totals.period_credit)}</td>
              <td className="text-right tabular">{money(data.totals.closing_debit)}</td>
              <td className="text-right tabular">{money(data.totals.closing_credit)}</td>
            </tr>
          </SimpleTable>
        )}
      </Panel>
      <p className="mt-2 px-1 text-[12px] text-ink-3">Hesap koduna tıklayınca büyük defter açılır. Kodlar kurum muhasebecisiyle teyit edilmelidir.</p>
    </>
  )
}

/* ================================================================== Hesap planı ve eşleme */

function ChartTab() {
  const can = useCan()
  const qc = useQueryClient()
  const chart = useChart()
  const [edit, setEdit] = useState<ChartAccount | 'new' | null>(null)
  const accounts = chart.data?.data.accounts ?? []
  const codeOptions = [{ value: '', label: 'Varsayılan' }, ...accounts.filter((a) => a.is_active).map((a) => ({ value: a.code, label: `${a.code} · ${a.name}` }))]

  const mapping = useMutation({
    mutationFn: (m: { source_type: string; source_key: string; code: string | null }) => api.put<{ message: string }>('/finance/accounting/mappings', m),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance', 'accounting', 'chart'] })
    },
    onError: (e) => toast.error(errMsg(e, 'Eşleme kaydedilemedi.')),
  })

  const columns = useMemo<Column<ChartAccount>[]>(
    () => [
      { key: 'code', header: 'Kod', cell: (a) => <span className={cn('font-medium tabular', !a.is_active && 'text-ink-3 line-through')}>{a.code}</span> },
      {
        key: 'name',
        header: 'Hesap adı',
        cell: (a) => (
          <span className="inline-flex flex-wrap items-center gap-1.5">
            {a.name}
            {a.is_system && <Badge tone="info">Sistem</Badge>}
            {!a.is_active && <Badge tone="neutral">Pasif</Badge>}
          </span>
        ),
      },
      { key: 'type', header: 'Hesap türü', cell: (a) => <span className="text-ink-2">{LEDGER_TYPES[a.type] ?? a.type}</span> },
      { key: 'side', header: 'Normal taraf', hideable: true, cell: (a) => <span className="text-ink-2">{a.normal_side === 'debit' ? 'Borç' : 'Alacak'}</span> },
      { key: 'lines', header: 'Fiş satırı sayısı', align: 'right', hideable: true, cell: (a) => <span className="tabular text-ink-2">{num(a.line_count)}</span> },
      { key: 'balance', header: 'Bakiye (B−A)', align: 'right', cell: (a) => <span className={cn('tabular font-medium', Number(a.balance) < 0 && 'text-danger')}>{Number(a.balance) !== 0 ? money(a.balance) : '—'}</span> },
      {
        key: 'actions',
        header: '',
        cell: (a) =>
          can('finance.accounting') ? (
            <Button size="icon-sm" variant="ghost" aria-label="Düzenle" onClick={(e) => { e.stopPropagation(); setEdit(a) }}>
              <Pencil className="size-3.5" />
            </Button>
          ) : null,
      },
    ],
    [can],
  )

  const groups = chart.data
    ? [
        { title: 'Kasa / banka / POS hesapları', rows: chart.data.data.mappings.accounts },
        { title: 'Gelir ve gider kategorileri', rows: chart.data.data.mappings.categories },
        { title: 'Sistem işlemleri (karşı hesaplar)', rows: chart.data.data.mappings.roles },
      ]
    : []

  return (
    <div className="flex flex-col gap-4">
      <Alert tone="info">Kodlar ve eşlemeler kurum muhasebecisiyle teyit edilmelidir; değişiklik yalnız yeni fişlere uygulanır.</Alert>
      <DataTable
        storageKey="finance-chart"
        mobile="table"
        dense
        columns={columns}
        rows={chart.data?.data.accounts}
        rowKey={(a) => a.id}
        loading={chart.isLoading}
        toolbar={
          <div className="flex w-full items-center gap-2">
            <p className="text-[13px] font-semibold">Hesap planı <span className="font-normal text-ink-3">(TDHP uyumlu)</span></p>
            {can('finance.accounting') && (
              <Button className="ml-auto" size="sm" variant="primary" icon={<Plus className="size-3.5" />} onClick={() => setEdit('new')}>Hesap ekle</Button>
            )}
          </div>
        }
        empty={<EmptyState icon={<BookOpenCheck />} title="Hesap planı boş" />}
      />

      <Panel title="Eşlemeler" description="Her kasa/banka hesabı, gelir/gider kategorisi ve sistem işleminin hangi hesap koduna yazılacağı. Boş = varsayılan kod.">
        {chart.isLoading ? (
          <Skeleton className="h-60" />
        ) : (
          <div className="grid grid-cols-1 gap-5 xl:grid-cols-3">
            {groups.map((g) => (
              <div key={g.title} className="min-w-0">
                <p className="mb-2 text-[12.5px] font-medium text-ink-2">{g.title}</p>
                <ul className="flex flex-col divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
                  {g.rows.map((m) => (
                    <li key={`${m.source_type}-${m.source_key}`} className="flex flex-wrap items-center gap-2 px-3 py-2">
                      <div className="min-w-0 flex-1">
                        <p className={cn('truncate text-[13px]', m.inactive && 'text-ink-3')}>{m.label}</p>
                        <p className="text-[12px] text-ink-3">
                          {m.group} · etkin kod <span className="tabular font-medium text-ink-2">{m.code}</span>
                          {m.custom && <Badge tone="accent" className="ml-1.5">özel</Badge>}
                        </p>
                      </div>
                      <Select
                        aria-label={`${m.label} hesap kodu`}
                        className="w-full sm:w-[190px]"
                        disabled={!can('finance.accounting') || mapping.isPending}
                        value={m.custom ? m.code : ''}
                        onChange={(e) => mapping.mutate({ source_type: m.source_type, source_key: m.source_key, code: e.target.value || null })}
                        options={codeOptions}
                      />
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        )}
      </Panel>

      <AccountModal account={edit} onClose={() => setEdit(null)} />
    </div>
  )
}

function AccountModal({ account, onClose }: { account: ChartAccount | 'new' | null; onClose: () => void }) {
  const qc = useQueryClient()
  const isNew = account === 'new'
  const current = account && account !== 'new' ? account : null
  const [form, setForm] = useState({ code: '', name: '', type: 'expense', is_active: true })
  const [openedFor, setOpenedFor] = useState<unknown>(null)
  if (account !== openedFor) {
    setOpenedFor(account)
    setForm(current ? { code: current.code, name: current.name, type: current.type, is_active: current.is_active } : { code: '', name: '', type: 'expense', is_active: true })
  }
  const save = useMutation({
    mutationFn: () => (isNew ? api.post<{ message: string }>('/finance/accounting/accounts', form) : api.put<{ message: string }>(`/finance/accounting/accounts/${current!.id}`, form)),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance', 'accounting'] })
      onClose()
    },
    onError: (e) => toast.error(errMsg(e, 'Hesap kaydedilemedi.')),
  })
  const validCode = /^\d{3}(\.\d{1,3}){0,3}$/.test(form.code)

  return (
    <Modal
      open={!!account}
      onClose={onClose}
      size="sm"
      title={isNew ? 'Hesap ekle' : `Hesap ${current?.code}`}
      description="Ana hesap 3 haneli (ör. 770), alt hesap 770.01 biçiminde."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" disabled={!validCode || form.name.trim().length < 2} loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <Field label="Hesap kodu" required error={form.code && !validCode ? 'Örn. 770 ya da 770.01' : null} hint={current && current.line_count > 0 ? 'Fişlerde kullanılan hesabın kodu değiştirilemez.' : undefined}>
          <Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.trim() })} inputMode="decimal" />
        </Field>
        <Field label="Hesap adı" required>
          <Input value={form.name} maxLength={160} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field label="Hesap türü" required>
          <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} options={Object.entries(LEDGER_TYPES).map(([value, label]) => ({ value, label }))} />
        </Field>
        {!isNew && <Switch checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} label="Aktif" />}
      </div>
    </Modal>
  )
}

/* ================================================================== Dönem kilidi */

type PeriodRow = { period: string; label: string; status: 'open' | 'closed'; ended: boolean; entries: number; amount: string; closed_at: string | null; closed_by: string | null; reopened_at: string | null; reopened_by: string | null; note: string | null }
type Verify = { entries: number; unbalanced: string[]; header_mismatch: string[]; accounts: { id: number; name: string; balance: string; ledger: string; ok: boolean }[] }

function PeriodsTab() {
  const can = useCan()
  const qc = useQueryClient()
  const periods = useQuery({ queryKey: ['finance', 'accounting', 'periods'], queryFn: () => api.get<{ data: PeriodRow[] }>('/finance/accounting/periods') })
  const verify = useQuery({ queryKey: ['finance', 'accounting', 'verify'], queryFn: () => api.get<{ data: Verify }>('/finance/accounting/verify').then((r) => r.data) })
  const [closing, setClosing] = useState<PeriodRow | null>(null)
  const [reopening, setReopening] = useState<PeriodRow | null>(null)
  const [note, setNote] = useState('')

  const done = (res: { message: string }) => {
    toast.success(res.message)
    qc.invalidateQueries({ queryKey: ['finance'] })
    setClosing(null)
    setReopening(null)
    setNote('')
  }
  const close = useMutation({
    mutationFn: (p: PeriodRow) => api.post<{ message: string }>('/finance/accounting/periods/close', { period: p.period, note: note || null }),
    onSuccess: done,
    onError: (e) => toast.error(errMsg(e, 'Dönem kapatılamadı.')),
  })
  const reopen = useMutation({
    mutationFn: ({ p, reason }: { p: PeriodRow; reason: string }) => api.post<{ message: string }>('/finance/accounting/periods/reopen', { period: p.period, reason }),
    onSuccess: done,
    onError: (e) => toast.error(errMsg(e, 'Dönem açılamadı.')),
  })

  const v = verify.data
  const healthy = v && v.unbalanced.length === 0 && v.header_mismatch.length === 0 && v.accounts.every((a) => a.ok)

  return (
    <div className="flex flex-col gap-4">
      {verify.isLoading ? (
        <Skeleton className="h-16" />
      ) : v ? (
        healthy ? (
          <Alert tone="success" title="Tüm fişler dengede; kasa/banka bakiyeleri muhasebeyle tutuyor" icon={<CheckCircle2 />}>
            {num(v.entries)} yevmiye fişi doğrulandı · {v.accounts.map((a) => `${a.name} ${money(a.balance)}`).join(' · ')}
          </Alert>
        ) : (
          <Alert tone="danger" title="Muhasebe doğrulamasında fark var">
            Dengesiz fiş: {v.unbalanced.length} · başlık/satır uyuşmazlığı: {v.header_mismatch.length}
            {v.accounts.filter((a) => !a.ok).map((a) => ` · ${a.name}: hesap ${money(a.balance)}, muhasebe ${money(a.ledger)}`).join('')}
          </Alert>
        )
      ) : null}

      <Alert tone="info">Kapatılan aya hiçbir işlem (tahsilat, iade, gider, transfer, fatura, elle fiş) yazılamaz. Yalnız bitmiş aylar kapatılabilir; yeniden açmak gerekçe ister ve denetim kaydına düşer.</Alert>

      <Panel flush title="Muhasebe dönemleri">
        {periods.isLoading ? (
          <div className="px-4 pb-4"><Skeleton className="h-48" /></div>
        ) : !periods.data?.data.length ? (
          <EmptyState compact icon={<Lock />} title="Henüz dönem yok" />
        ) : (
          <ul className="divide-y divide-line border-t border-line">
            {periods.data.data.map((p) => (
              <li key={p.period} className="flex flex-wrap items-center gap-3 px-4 py-3">
                <span className={cn('grid size-9 shrink-0 place-items-center rounded-[var(--radius-sm)] ring-1 [&_svg]:size-4', p.status === 'closed' ? 'bg-primary-soft text-primary ring-primary/20' : 'bg-success-soft text-success ring-success/25')}>
                  {p.status === 'closed' ? <Lock /> : <LockOpen />}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="text-[14px] font-medium">
                    {p.label}{' '}
                    {p.status === 'closed' ? <Badge tone="primary">Kapalı</Badge> : <Badge tone="success">Açık</Badge>}
                    {!p.ended && <Badge tone="info" className="ml-1">İçinde bulunulan ay</Badge>}
                  </p>
                  <p className="text-[12px] text-ink-3">
                    {num(p.entries)} fiş · {money(p.amount)}
                    {p.status === 'closed' && p.closed_at && ` · ${dateTime(p.closed_at)} ${p.closed_by ?? ''} kapattı`}
                    {p.status === 'open' && p.reopened_at && ` · ${dateTime(p.reopened_at)} ${p.reopened_by ?? ''} yeniden açtı`}
                    {p.note && ` · ${p.note}`}
                  </p>
                </div>
                {can('finance.period_close') && p.status === 'open' && p.ended && (
                  <Button size="sm" variant="info" icon={<Lock className="size-3.5" />} onClick={() => { setNote(''); setClosing(p) }}>Dönemi kapat</Button>
                )}
                {can('finance.period_close') && p.status === 'closed' && (
                  <Button size="sm" variant="danger-soft" icon={<LockOpen className="size-3.5" />} onClick={() => setReopening(p)}>Yeniden aç</Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <ConfirmDialog
        open={!!closing}
        onClose={() => setClosing(null)}
        title={closing ? `${closing.label} dönemini kapat` : 'Dönemi kapat'}
        description="Kapatılan aya tarihli hiçbir kayıt atılamaz."
        confirmLabel="Dönemi kapat"
        loading={close.isPending}
        onConfirm={() => closing && close.mutate(closing)}
      >
        <Field label="Not" optional hint="Denetim kaydına yazılır.">
          <Textarea rows={2} value={note} maxLength={300} onChange={(e) => setNote(e.target.value)} placeholder="Örn. Muhasebeciye teslim edildi" />
        </Field>
      </ConfirmDialog>

      <ReasonDialog
        confirmLabel="Dönemi aç"
        open={!!reopening}
        onClose={() => setReopening(null)}
        loading={reopen.isPending}
        onConfirm={(reason) => reopening && reopen.mutate({ p: reopening, reason })}
        title={reopening ? `${reopening.label} dönemini yeniden aç` : 'Dönemi aç'}
        description="Açılan döneme yeniden kayıt atılabilir. Muhasebeciye teslim edilmiş bir ayı açmadan önce haber verin."
      >
        <Alert tone="warning" className="mb-3">Gerekçe zorunludur ve denetim kaydına yazılır.</Alert>
      </ReasonDialog>
    </div>
  )
}
