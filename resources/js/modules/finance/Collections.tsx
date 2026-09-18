import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlarmClock, CalendarCheck, CalendarX, Copy, Download, FileText, HandCoins, MessageSquareText, Search, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date, dateTime, money, num, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader, Panel } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton, type Tone } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Drawer } from '@/components/ui/overlay'
import { PersonText, PhoneText } from '@/components/ui/contact'
import { MetricRow, MoneyInput } from './components'
import { addDaysISO, fromCents, openPdf, toCents } from './shared'
import { NOTE_STATUS, type CollectionNoteRow } from './ledger'

type Row = {
  student_id: number
  student: string
  student_no: string
  overdue: string
  count: number
  oldest: string
  days: number
  bucket: string
  buckets: Record<string, string>
  guardian: { id: number; name: string; phone: string | null } | null
  promise: CollectionNoteRow | null
  last_note: CollectionNoteRow | null
  responsible: string | null
  credit: string
}
type Response = Paginated<Row> & {
  meta: {
    summary: { total: string; overdue: string; buckets: { key: string; label: string; amount: string; count: number }[] }
    promises: { open: number; today: number; late: number }
  }
}
type Staff = { id: number; name: string }
type Preview = { student_id: number; guardian_id: number | null; guardian: string | null; phone: string | null; body: string; overdue: string; count: number }

const BUCKET_TONE: Record<string, Tone> = { d0_30: 'info', d31_60: 'warning', d61_90: 'warning', d90_plus: 'danger', current: 'neutral' }
const BUCKET_SHORT: Record<string, string> = { d0_30: '0-30', d31_60: '31-60', d61_90: '61-90', d90_plus: '90+' }

function promiseTone(d: string | null): Tone {
  if (!d) return 'neutral'
  const t = todayISO()
  return d < t ? 'danger' : d === t ? 'warning' : 'info'
}

export default function Collections() {
  const can = useCan()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: '-overdue' })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [selected, setSelected] = useState<Set<string | number>>(new Set())
  const [confirmDrafts, setConfirmDrafts] = useState(false)
  const detailId = Number(params.get('ogrenci') || 0) || null

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'collections', list.query],
    queryFn: () => api.get<Response>('/finance/collections', list.query),
    placeholderData: keepPreviousData,
  })
  const staff = useQuery({ queryKey: ['finance', 'collection-staff'], queryFn: () => api.get<{ data: Staff[] }>('/finance/collections/staff'), staleTime: 300_000 })

  const drafts = useMutation({
    mutationFn: () => api.post<{ message: string; count: number }>('/finance/collections/reminder-drafts', { student_ids: [...selected] }),
    onSuccess: (res) => {
      toast.success(res.message)
      setConfirmDrafts(false)
      setSelected(new Set())
      qc.invalidateQueries({ queryKey: ['finance'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Taslaklar hazırlanamadı.'),
  })

  const openDetail = (id: number | null) =>
    setParams((p) => {
      if (id) p.set('ogrenci', String(id))
      else p.delete('ogrenci')
      return p
    }, { replace: true })

  const m = data?.meta
  const bucket = list.filters.bucket ?? ''

  const columns = useMemo<Column<Row>[]>(
    () => [
      {
        key: 'student',
        header: 'Öğrenci',
        sortKey: 'student',
        cell: (r) => (
          <div className="min-w-0">
            <p className="truncate font-medium text-ink">{r.student}</p>
            <p className="truncate text-[12px] text-ink-3">Öğrenci no: {r.student_no}</p>
          </div>
        ),
      },
      {
        key: 'guardian',
        header: 'Veli',
        cell: (r) =>
          r.guardian ? (
            <div className="min-w-0 space-y-0.5">
              <PersonText className="text-ink-2">{r.guardian.name}</PersonText>
              <div><PhoneText value={r.guardian.phone} muted whatsapp /></div>
            </div>
          ) : (
            <span className="text-ink-3">—</span>
          ),
      },
      {
        key: 'overdue', priority: 1,
        header: 'Gecikmiş tutar',
        sortKey: 'overdue',
        align: 'right',
        cell: (r) => (
          <div className="text-right">
            <p className="font-semibold tabular text-danger whitespace-nowrap">{money(r.overdue)}</p>
            <p className="text-[12px] text-ink-3">{r.count} taksitte</p>
          </div>
        ),
      },
      {
        key: 'days',
        header: 'En eski gecikme',
        sortKey: 'oldest',
        cell: (r) => (
          <div className="whitespace-nowrap">
            <Badge tone={BUCKET_TONE[r.bucket] ?? 'neutral'}>{r.days} gün</Badge>
            <p className="mt-0.5 text-[12px] text-ink-3 tabular">Vade: {date(r.oldest)}</p>
          </div>
        ),
      },
      {
        key: 'buckets',
        header: 'Gecikme dağılımı (gün)',
        hideable: true,
        defaultHidden: true,
        cell: (r) => (
          <div className="flex flex-wrap gap-x-2 gap-y-0.5 text-[12px] tabular text-ink-2 max-w-[220px]">
            {Object.entries(r.buckets)
              .filter(([, v]) => Number(v) > 0)
              .map(([k, v]) => (
                <span key={k} className={cn(k === 'd90_plus' && 'text-danger')}>
                  {BUCKET_SHORT[k]} gün: {money(v, { short: true })}
                </span>
              ))}
          </div>
        ),
      },
      {
        key: 'promise', priority: 3,
        header: 'Ödeme sözü',
        sortKey: 'promise',
        cell: (r) =>
          r.promise ? (
            <div className="whitespace-nowrap">
              <Badge tone={promiseTone(r.promise.promised_date)}>{date(r.promise.promised_date)}</Badge>
              {r.promise.promised_amount && <p className="mt-0.5 text-[12px] tabular text-ink-2">Söz verilen: {money(r.promise.promised_amount)}</p>}
            </div>
          ) : (
            <span className="text-ink-3">—</span>
          ),
      },
      {
        key: 'note', priority: 4,
        header: 'Son takip notu',
        hideable: true,
        maxWidth: 220,
        cell: (r) =>
          r.last_note ? (
            <p className="truncate text-[12.5px] text-ink-2" title={r.last_note.body ?? ''}>
              <span className="text-ink-3">{r.last_note.kind_label}: </span>
              {r.last_note.body ?? (r.last_note.promised_date ? date(r.last_note.promised_date) : '')}
            </p>
          ) : (
            <span className="text-ink-3">—</span>
          ),
      },
      { key: 'responsible', priority: 4, header: 'Takip sorumlusu', hideable: true, cell: (r) => <span className="text-ink-2 whitespace-nowrap">{r.responsible ?? '—'}</span> },
      {
        key: 'credit',
        header: 'Kullanılabilir avans',
        align: 'right',
        hideable: true,
        defaultHidden: true,
        cell: (r) => (Number(r.credit) > 0 ? <span className="tabular text-success whitespace-nowrap">{money(r.credit)}</span> : <span className="text-ink-3">—</span>),
      },
      {
        key: 'actions',
        header: '',
        cell: (r) => (
          <div className="flex items-stretch justify-end gap-1 max-md:flex-col" onClick={(e) => e.stopPropagation()}>
            {can('payments.create') && (
              <ButtonLink size="xs" variant="success" to={`/finans/tahsilat?ogrenci=${r.student_id}`} icon={<HandCoins className="size-3.5" />}>
                Tahsil et
              </ButtonLink>
            )}
            {can('finance.collections') && (
              <Button size="xs" variant="info" icon={<MessageSquareText className="size-3.5" />} onClick={() => openDetail(r.student_id)}>
                Not/Söz
              </Button>
            )}
            <Button size="xs" variant="ghost" icon={<FileText className="size-3.5" />} onClick={() => openPdf(`/finance/statements/students/${r.student_id}/pdf`).catch((e) => toast.error(e.message))}>
              Ekstre
            </Button>
          </div>
        ),
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [can],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Gecikme takibi"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Gecikme takibi' }]}
        description="Vadesi geçmiş alacaklar, ödeme sözleri ve hatırlatma taslakları"
        actions={<ButtonLink to="/finans/alacaklar?status=overdue" icon={<AlarmClock className="size-4" />}>Taksit listesi</ButtonLink>}
      />

      <div className="mb-3 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {(m?.summary.buckets ?? []).filter((b) => b.key !== 'current').map((b) => {
          const active = bucket === b.key
          const tone = BUCKET_TONE[b.key]
          return (
            <button
              key={b.key}
              type="button"
              onClick={() => list.update({ filters: { bucket: active ? null : b.key } })}
              className={cn(
                'rounded-[var(--radius-lg)] bg-surface px-3.5 py-3 text-left ring-1 transition-shadow hover:shadow-[var(--shadow-soft)]',
                active ? 'ring-2 ring-primary' : 'ring-line',
              )}
            >
              <p className="text-[12px] text-ink-3">{b.label} gecikmiş</p>
              <p className={cn('mt-0.5 text-[17px] font-semibold tabular', tone === 'danger' && 'text-danger', tone === 'warning' && 'text-warning')}>{money(b.amount, { short: true })}</p>
              <p className="text-[12px] text-ink-3">{num(b.count)} taksit</p>
            </button>
          )
        })}
        {!m &&
          Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-[76px] rounded-[var(--radius-lg)]" />)}
        <button type="button" aria-pressed={list.filters.promise === 'today'} onClick={() => list.update({ filters: { promise: list.filters.promise === 'today' ? null : 'today' } })}
          className={cn('rounded-[var(--radius-lg)] bg-surface px-3.5 py-3 text-left ring-1', list.filters.promise === 'today' ? 'ring-2 ring-primary' : 'ring-line')}>
          <p className="flex items-center gap-1.5 text-[12px] text-ink-3"><CalendarCheck className="size-3.5" /> Bugün sözü olan</p>
          <p className="mt-0.5 text-[17px] font-semibold tabular text-warning">{m ? num(m.promises.today) : '—'}</p>
          <p className="text-[12px] text-ink-3">{m ? `${num(m.promises.open)} öğrencide açık söz` : ''}</p>
        </button>
        <button type="button" aria-pressed={list.filters.promise === 'late'} onClick={() => list.update({ filters: { promise: list.filters.promise === 'late' ? null : 'late' } })}
          className={cn('rounded-[var(--radius-lg)] bg-surface px-3.5 py-3 text-left ring-1', list.filters.promise === 'late' ? 'ring-2 ring-primary' : 'ring-line')}>
          <p className="flex items-center gap-1.5 text-[12px] text-ink-3"><CalendarX className="size-3.5" /> Sözü geçen</p>
          <p className={cn('mt-0.5 text-[17px] font-semibold tabular', (m?.promises.late ?? 0) > 0 && 'text-danger')}>{m ? num(m.promises.late) : '—'}</p>
          <p className="text-[12px] text-ink-3">söz tarihi geçti, borç ödenmedi</p>
        </button>
      </div>

      <DataTable
        storageKey="finance-collections"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.student_id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => openDetail(r.student_id)}
        selectable={can('finance.collections')}
        selected={selected}
        onSelectedChange={setSelected}
        bulkActions={
          <Button size="sm" variant="info" icon={<MessageSquareText className="size-4" />} onClick={() => setConfirmDrafts(true)}>
            Hatırlatma taslağı hazırla
          </Button>
        }
        toolbar={
          <>
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Öğrenci ara"
              leading={<Search />}
              trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
              className="w-full sm:w-[240px]"
            />
            <Segmented
              size="sm"
              value={list.filters.promise ?? 'all'}
              onChange={(v) => list.update({ filters: { promise: v === 'all' ? null : v } })}
              options={[{ value: 'all', label: 'Tümü' }, { value: 'today', label: 'Bugün sözü olan' }, { value: 'late', label: 'Sözü geçen' }, { value: 'none', label: 'Sözü olmayan' }]}
            />
            <Select
              className="w-full sm:w-[180px]"
              value={list.filters.responsible_id ?? ''}
              onChange={(e) => list.update({ filters: { responsible_id: e.target.value || null } })}
              placeholder="Tüm sorumlular"
              options={(staff.data?.data ?? []).map((s) => ({ value: s.id, label: s.name }))}
            />
            {bucket && <Button size="sm" variant="ghost" icon={<X className="size-3.5" />} onClick={() => list.update({ filters: { bucket: null } })}>Gün aralığı filtresini kaldır</Button>}
          </>
        }
        empty={<EmptyState icon={<CalendarCheck />} title="Gecikmiş alacak yok" description="Filtreye uyan, vadesi geçmiş ödemesi olan öğrenci bulunmuyor." />}
      />

      <ConfirmDialog
        open={confirmDrafts}
        onClose={() => setConfirmDrafts(false)}
        onConfirm={() => drafts.mutate()}
        loading={drafts.isPending}
        title="Hatırlatma taslağı hazırla"
        confirmLabel="Taslakları hazırla"
        description={`${selected.size} öğrenci için gecikme hatırlatma metni hazırlanıp takip notu olarak kaydedilecek.`}
      >
        <Alert tone="warning">Gönderim yapılmaz. Metinler öğrencinin takip geçmişinde durur; kopyalayıp kendiniz iletebilirsiniz.</Alert>
      </ConfirmDialog>

      <CollectionDrawer studentId={detailId} row={data?.data.find((r) => r.student_id === detailId) ?? null} staff={staff.data?.data ?? []} onClose={() => openDetail(null)} />
    </div>
  )
}

function CollectionDrawer({ studentId, row, staff, onClose }: { studentId: number | null; row: Row | null; staff: Staff[]; onClose: () => void }) {
  const can = useCan()
  const qc = useQueryClient()
  const [kind, setKind] = useState<'promise' | 'note'>('promise')
  const [promisedDate, setPromisedDate] = useState(addDaysISO(todayISO(), 3))
  const [amount, setAmount] = useState('')
  const [responsible, setResponsible] = useState('')
  const [body, setBody] = useState('')

  useEffect(() => {
    if (!studentId) return
    setKind('promise')
    setPromisedDate(addDaysISO(todayISO(), 3))
    setAmount(row ? row.overdue.replace('.', ',') : '')
    setResponsible('')
    setBody('')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [studentId])

  const notes = useQuery({
    queryKey: ['finance', 'collection-notes', studentId],
    queryFn: () => api.get<{ data: CollectionNoteRow[] }>('/finance/collections/notes', { student_id: studentId! }),
    enabled: !!studentId,
  })
  const preview = useQuery({
    queryKey: ['finance', 'reminder-preview', studentId],
    queryFn: () => api.get<{ data: Preview }>(`/finance/collections/reminder-preview/${studentId}`),
    enabled: !!studentId,
  })

  const onErr = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')
  const save = useMutation({
    mutationFn: () => {
      const cents = toCents(amount)
      return api.post<{ message: string }>('/finance/collections/notes', {
        student_id: studentId,
        kind,
        promised_date: kind === 'promise' ? promisedDate : null,
        promised_amount: kind === 'promise' && cents ? fromCents(cents) : null,
        responsible_user_id: responsible ? Number(responsible) : null,
        body: body || null,
      })
    },
    onSuccess: (res) => {
      toast.success(res.message)
      setBody('')
      qc.invalidateQueries({ queryKey: ['finance'] })
    },
    onError: onErr,
  })
  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => api.patch<{ message: string }>(`/finance/collections/notes/${id}`, { status }),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance'] })
    },
    onError: onErr,
  })

  const p = preview.data?.data
  const invalid = kind === 'promise' ? !promisedDate || (amount.trim() !== '' && toCents(amount) === null) : body.trim().length < 3

  return (
    <Drawer
      open={!!studentId}
      onClose={onClose}
      width={560}
      title={row?.student ?? p?.guardian ?? 'Tahsilat takibi'}
      description={row ? `Öğrenci no: ${row.student_no} · ${row.count} gecikmiş taksit` : undefined}
      footer={
        studentId && (
          <div className="flex w-full flex-wrap items-center gap-2">
            <Button icon={<FileText className="size-4" />} onClick={() => openPdf(`/finance/statements/students/${studentId}/pdf`).catch((e) => toast.error(e.message))}>Cari ekstre</Button>
            <Button variant="ghost" icon={<Download className="size-4" />} onClick={() => api.download(`/finance/statements/students/${studentId}/pdf`, undefined, 'cari-ekstre.pdf').catch((e) => toast.error(e.message))}>İndir</Button>
            {can('payments.create') && (
              <ButtonLink className="ml-auto" variant="primary" to={`/finans/tahsilat?ogrenci=${studentId}`} icon={<HandCoins className="size-4" />}>Tahsil et</ButtonLink>
            )}
          </div>
        )
      }
    >
      <div className="flex flex-col gap-5">
        <div className="rounded-[var(--radius-md)] px-3.5 py-1.5 ring-1 ring-line divide-y divide-line">
          <MetricRow label="Gecikmiş tutar" value={money(row?.overdue ?? p?.overdue)} tone="danger" strong />
          {row && <MetricRow label="En eski gecikme" value={`${row.days} gün (${date(row.oldest)})`} />}
          {row?.guardian && <MetricRow label="Veli" value={<span className="flex flex-wrap items-center justify-end gap-x-3 gap-y-0.5"><PersonText>{row.guardian.name}</PersonText><PhoneText value={row.guardian.phone} whatsapp /></span>} />}
          {row && Number(row.credit) > 0 && <MetricRow label="Kullanılabilir avans" value={money(row.credit)} tone="success" />}
          {row && <Link to={`/ogrenciler/${row.student_id}`} className="block py-1.5 text-[12.5px] text-ink-3 hover:text-ink">Öğrenci profiline git →</Link>}
        </div>

        {can('finance.collections') && (
          <Panel title="Yeni takip kaydı" className="ring-line">
            <div className="flex flex-col gap-3">
              <Segmented value={kind} onChange={setKind} options={[{ value: 'promise', label: 'Ödeme sözü' }, { value: 'note', label: 'Görüşme notu' }]} />
              {kind === 'promise' && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <Field label="Söz tarihi" required>
                    <Input type="date" value={promisedDate} min={addDaysISO(todayISO(), -30)} onChange={(e) => setPromisedDate(e.target.value)} />
                  </Field>
                  <Field label="Söz verilen tutar" optional hint="Boş bırakılırsa tutar belirtilmez">
                    <MoneyInput value={amount} onChange={setAmount} />
                  </Field>
                </div>
              )}
              <Field label="Takip sorumlusu" optional hint="Boş bırakılırsa siz">
                <Select value={responsible} onChange={(e) => setResponsible(e.target.value)} placeholder="Ben" options={staff.map((s) => ({ value: s.id, label: s.name }))} />
              </Field>
              <Field label="Not" required={kind === 'note'} optional={kind !== 'note'} hint={kind === 'note' ? 'En az 3 karakter' : undefined}>
                <Textarea rows={2} value={body} onChange={(e) => setBody(e.target.value)} maxLength={2000} placeholder={kind === 'promise' ? 'Örn. Maaş gününde ödeyeceğini söyledi' : 'Görüşme özeti'} />
              </Field>
              <div className="flex justify-end">
                <Button variant="primary" disabled={invalid} loading={save.isPending} onClick={() => save.mutate()}>
                  Kaydet
                </Button>
              </div>
            </div>
          </Panel>
        )}

        <div>
          <p className="mb-2 text-[12.5px] font-medium text-ink-2">Takip geçmişi</p>
          {notes.isLoading ? (
            <Skeleton className="h-24" />
          ) : !notes.data?.data.length ? (
            <p className="text-[13px] text-ink-3">Henüz kayıt yok.</p>
          ) : (
            <ul className="flex flex-col divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
              {notes.data.data.map((n) => (
                <li key={n.id} className="px-3 py-2.5 text-[13px]">
                  <div className="flex flex-wrap items-center gap-1.5">
                    <span className="font-medium">{n.kind_label}</span>
                    <Badge tone={NOTE_STATUS[n.status]?.tone ?? 'neutral'}>{n.status_label}</Badge>
                    {n.promised_date && <Badge tone={n.status === 'open' ? promiseTone(n.promised_date) : 'neutral'}>{date(n.promised_date)}</Badge>}
                    {n.promised_amount && <span className="tabular text-ink-2">{money(n.promised_amount)}</span>}
                    <span className="ml-auto text-[12px] text-ink-3">{dateTime(n.created_at)}</span>
                  </div>
                  {n.body && <p className="mt-1 whitespace-pre-line text-ink-2 break-words">{n.body}</p>}
                  <div className="mt-1 flex flex-wrap items-center gap-2 text-[12px] text-ink-3">
                    <span>{n.responsible ? `Sorumlu: ${n.responsible}` : ''}{n.created_by ? ` · Kaydeden: ${n.created_by}` : ''}</span>
                    {n.status === 'open' && n.kind === 'promise' && can('finance.collections') && (
                      <span className="ml-auto flex gap-1">
                        <Button size="xs" variant="success" loading={setStatus.isPending} onClick={() => setStatus.mutate({ id: n.id, status: 'kept' })}>Tutuldu</Button>
                        <Button size="xs" variant="danger-soft" loading={setStatus.isPending} onClick={() => setStatus.mutate({ id: n.id, status: 'broken' })}>Tutulmadı</Button>
                      </span>
                    )}
                    {n.status === 'open' && n.kind === 'reminder' && can('finance.collections') && (
                      <Button size="xs" variant="ghost" className="ml-auto" onClick={() => setStatus.mutate({ id: n.id, status: 'done' })}>İletildi olarak işaretle</Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div>
          <p className="mb-2 text-[12.5px] font-medium text-ink-2">Hatırlatma metni</p>
          {preview.isLoading ? (
            <Skeleton className="h-28" />
          ) : p ? (
            <div className="flex flex-col gap-2">
              <Textarea readOnly rows={6} value={p.body} className="text-[12.5px]" />
              <div className="flex flex-wrap items-center gap-2">
                <Button
                  size="sm"
                  icon={<Copy className="size-3.5" />}
                  onClick={() =>
                    navigator.clipboard
                      .writeText(p.body)
                      .then(() => toast.success('Metin kopyalandı.'))
                      .catch(() => toast.error('Kopyalanamadı; metni seçip elle kopyalayın.'))
                  }
                >
                  Kopyala
                </Button>
                {p.phone && <span className="flex flex-wrap items-center gap-x-2 text-[12.5px] text-ink-3">Alıcı: <PersonText className="text-ink-2">{p.guardian}</PersonText><PhoneText value={p.phone} whatsapp /></span>}
              </div>
              <Alert tone="warning">Mesaj GÖNDERİLMEZ; metni kopyalayıp kendiniz iletin.</Alert>
            </div>
          ) : (
            <p className="text-[13px] text-ink-3">Metin hazırlanamadı.</p>
          )}
        </div>
      </div>
    </Drawer>
  )
}
