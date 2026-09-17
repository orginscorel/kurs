import { useEffect, useMemo, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowRight, CheckCheck, GitCompareArrows, Monitor, Search, Server, Undo2 } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, num, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader, Tabs } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select, Textarea } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import { kindTone, sourceLabel, type ConflictKind, type SyncConflict } from './types'

type StatusTab = 'acik' | 'cozuldu' | 'tumu'

const KIND_OPTIONS: { value: ConflictKind; label: string }[] = [
  { value: 'field', label: 'Alan çakışması' },
  { value: 'attendance', label: 'Yoklama' },
  { value: 'delete', label: 'Silme / güncelleme' },
  { value: 'finance', label: 'Finans mutabakatı' },
  { value: 'rejected', label: 'Sunucu reddetti' },
]

function shortValue(v: string | null, max = 40): string {
  if (v === null || v === undefined || v === '') return '—'
  return v.length > max ? v.slice(0, max - 1) + '…' : v
}

function WinnerBadge({ c }: { c: SyncConflict }) {
  if (c.kind === 'finance') return <Badge tone="danger" dot>İkisi de kaydedildi</Badge>
  if (c.kind === 'rejected') return <Badge tone="neutral" dot>Uygulanmadı</Badge>
  if (c.winner === 'device') return <Badge tone="info" dot>Cihaz kazandı</Badge>
  return <Badge tone="accent" dot>Web kazandı</Badge>
}

export default function ConflictsPage() {
  const list = useListState({ filters: { durum: 'acik' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [openId, setOpenId] = useState<number | null>(null)
  const tab = (list.filters.durum as StatusTab) || 'acik'

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced, page: 1 })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['sync', 'conflicts', list.query],
    queryFn: () => api.get<Paginated<SyncConflict> & { meta: { counts: Record<string, number> } }>('/sync/conflicts', list.query),
    placeholderData: keepPreviousData,
  })
  const counts = (data?.meta.counts ?? {}) as Record<string, number>
  const openTotal = Object.values(counts).reduce((a, b) => a + Number(b), 0)

  const columns = useMemo<Column<SyncConflict>[]>(
    () => [
      {
        key: 'row',
        header: 'Kayıt',
        cell: (c) => (
          <div className="min-w-0">
            <div className="break-words font-medium text-ink sm:truncate">{c.row_label ?? '—'}</div>
            <div className="break-words text-[12px] text-ink-3">
              {c.command_label ?? c.table_label}{c.field ? ` · ${c.field_label}` : ''}
              {c.device ? <span> · {c.device.name} ({c.device.code})</span> : null}
            </div>
          </div>
        ),
      },
      { key: 'kind', header: 'Tür', cell: (c) => <Badge tone={kindTone[c.kind]}>{c.kind_label}</Badge> },
      {
        key: 'values',
        header: 'Cihaz → Web',
        mobileHidden: true,
        cell: (c) =>
          c.kind === 'field' || c.kind === 'attendance' ? (
            <span className="inline-flex items-center gap-1.5 whitespace-nowrap text-[12.5px]">
              <span className={cn('rounded-[4px] px-1.5 py-0.5 tabular', c.winner === 'device' ? 'bg-info-soft text-info' : 'bg-surface-2 text-ink-2 line-through decoration-ink-3/60')}>{shortValue(c.device_value, 24)}</span>
              <ArrowRight className="size-3 text-ink-3" />
              <span className={cn('rounded-[4px] px-1.5 py-0.5 tabular', c.winner === 'server' ? 'bg-accent-soft text-accent' : 'bg-surface-2 text-ink-2 line-through decoration-ink-3/60')}>{shortValue(c.server_value, 24)}</span>
            </span>
          ) : (
            <span className="line-clamp-2 block max-w-[220px] whitespace-normal text-[12.5px] text-ink-2 lg:max-w-[320px]" title={c.note ?? undefined}>{shortValue(c.note, 110)}</span>
          ),
      },
      { key: 'winner', header: 'Sonuç', cell: (c) => <WinnerBadge c={c} /> },
      { key: 'device', header: 'Cihaz', hideable: true, defaultHidden: true, cell: (c) => <span className="text-ink-2 sm:whitespace-nowrap">{c.device ? `${c.device.name} (${c.device.code})` : '—'}</span> },
      {
        key: 'when',
        header: 'Zaman',
        cell: (c) => <span className="whitespace-nowrap text-ink-2" title={dateTime(c.created_at)}>{relative(c.created_at)}</span>,
      },
      {
        key: 'status',
        header: 'Durum',
        hideable: true,
        defaultHidden: true,
        cell: (c) => (c.status === 'open' ? <Badge tone="warning">Açık</Badge> : <Badge tone="success">{c.status === 'dismissed' ? 'Yok sayıldı' : 'Çözüldü'}</Badge>),
      },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Eşitleme çakışmaları"
        description="Çevrimdışı cihazlarla web aynı kaydı değiştirdiğinde son yazan kazanır; burada karşılaştırıp gerekirse diğer değeri uygulayın."
      />
      {counts.finance > 0 && tab === 'acik' && (
        <Alert tone="danger" title={`${num(counts.finance)} finans işlemi mutabakat bekliyor`} className="mb-4">
          Çevrimdışı cihazdaki bir finans işlemi (tahsilat, iade, gelir-gider, aktarım, POS yatışı, senet) web'de yeniden yürütülürken
          farklılık bulundu. Para hareketleri silinmedi ve ezilmedi; ayrıntıdaki notu okuyup gerekirse iade, mahsup ya da kasa sayımı yapın.
        </Alert>
      )}
      <Tabs<StatusTab>
        value={tab}
        onChange={(v) => list.update({ filters: { durum: v }, page: 1 })}
        className="mb-4"
        tabs={[
          { value: 'acik', label: 'Açık', count: openTotal },
          { value: 'cozuldu', label: 'Çözülen' },
          { value: 'tumu', label: 'Tümü' },
        ]}
      />
      <DataTable
        storageKey="sync-conflicts-v2"
        columns={columns}
        rows={data?.data}
        rowKey={(c) => c.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        onRowClick={(c) => setOpenId(c.id)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Kayıt ya da alan ara" leading={<Search />} className="w-full sm:w-[260px]" />
            <Select
              value={list.filters.tur ?? ''}
              onChange={(e) => list.update({ filters: { tur: e.target.value }, page: 1 })}
              placeholder="Tüm türler"
              options={KIND_OPTIONS.map((k) => ({ value: k.value, label: counts[k.value] ? `${k.label} (${counts[k.value]})` : k.label }))}
              className="w-full sm:w-[210px]"
            />
          </div>
        }
        empty={
          <EmptyState
            icon={<GitCompareArrows />}
            title={tab === 'acik' ? 'Açık çakışma yok' : 'Kayıt bulunamadı'}
            description={tab === 'acik' ? 'Tüm cihazlar web ile uyumlu.' : undefined}
          />
        }
      />
      <ConflictDrawer id={openId} onClose={() => setOpenId(null)} />
    </div>
  )
}

function ValueCard({ side, value, at, winner, device }: { side: 'device' | 'server'; value: string | null; at: string | null; winner: boolean; device?: string | null }) {
  return (
    <div className={cn('rounded-[var(--radius-md)] p-3 ring-1', winner ? 'bg-success-soft/60 ring-success/30' : 'bg-surface-2 ring-line')}>
      <div className="mb-1.5 flex items-center justify-between gap-2 text-[12px] text-ink-3">
        <span className="inline-flex items-center gap-1.5 font-medium text-ink-2">
          {side === 'device' ? <Monitor className="size-3.5" /> : <Server className="size-3.5" />}
          {side === 'device' ? (device ?? 'Cihaz') : 'Web'}
        </span>
        {winner && <Badge tone="success">Geçerli değer</Badge>}
      </div>
      <div className="break-words text-[15px] font-medium text-ink tabular">{value === null || value === '' ? '—' : value}</div>
      <div className="mt-1 text-[12px] text-ink-3">{at ? dateTime(at) : '—'}</div>
    </div>
  )
}

function ConflictDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const qc = useQueryClient()
  const [note, setNote] = useState('')
  const { data, isLoading } = useQuery({
    queryKey: ['sync', 'conflict', id],
    queryFn: () => api.get<{ data: SyncConflict }>(`/sync/conflicts/${id}`),
    enabled: !!id,
  })
  const c = data?.data
  useEffect(() => setNote(''), [id])

  const resolve = useMutation({
    mutationFn: (action: 'keep' | 'apply_other' | 'reconciled' | 'dismiss') => api.post<{ message: string }>(`/sync/conflicts/${id}/resolve`, { action, note: note || null }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['sync'] })
      onClose()
    },
    onError: (e: ApiError) => toast.error(e.firstError()),
  })

  const open = c?.status === 'open'
  const isField = c && (c.kind === 'field' || c.kind === 'attendance')
  const other = c?.winner === 'device' ? 'web' : 'cihaz'

  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      width={560}
      title={c ? c.row_label ?? c.table_label : 'Çakışma'}
      description={c ? `${c.kind_label}${c.field ? ` · ${c.field_label}` : ''} · ${c.table_label}` : undefined}
      footer={
        c && open ? (
          <div className="flex w-full flex-wrap justify-end gap-2">
            {c.kind === 'rejected' || c.kind === 'delete' ? (
              <Button variant="primary" icon={<CheckCheck className="size-4" />} loading={resolve.isPending} onClick={() => resolve.mutate('dismiss')}>Gördüm, kapat</Button>
            ) : c.kind === 'finance' ? (
              <Button variant="primary" icon={<CheckCheck className="size-4" />} loading={resolve.isPending} onClick={() => resolve.mutate('reconciled')}>Mutabakat tamam</Button>
            ) : (
              <>
                <Button icon={<Undo2 className="size-4" />} loading={resolve.isPending} onClick={() => resolve.mutate('apply_other')}>
                  {other === 'web' ? 'Web değerini geri yükle' : 'Cihaz değerini uygula'}
                </Button>
                <Button variant="primary" icon={<CheckCheck className="size-4" />} loading={resolve.isPending} onClick={() => resolve.mutate('keep')}>Geçerli değer kalsın</Button>
              </>
            )}
          </div>
        ) : undefined
      }
    >
      {isLoading || !c ? (
        <div className="grid gap-3"><Skeleton className="h-24" /><Skeleton className="h-40" /></div>
      ) : (
        <div className="flex flex-col gap-4">
          {isField && (
            <div className="grid gap-3 sm:grid-cols-2">
              <ValueCard side="device" value={c.device_value} at={c.device_at} winner={c.winner === 'device'} device={c.device ? `${c.device.name} (${c.device.code})` : null} />
              <ValueCard side="server" value={c.server_value} at={c.server_at} winner={c.winner === 'server'} />
            </div>
          )}
          {c.command_label && (
            <div className="flex flex-wrap items-center gap-2 text-[13px] text-ink-2">
              <span>İşlem:</span>
              <Badge tone={c.kind === 'finance' ? 'danger' : 'neutral'}>{c.command_label}</Badge>
              {c.kind === 'finance' && <span className="text-ink-3">Cihazdaki ve web'deki kayıtların ikisi de korunur.</span>}
            </div>
          )}
          {c.note && <Alert tone={c.kind === 'finance' ? 'danger' : c.kind === 'rejected' ? 'warning' : 'info'}><span className="break-words">{c.note}</span></Alert>}
          {c.kind === 'rejected' && c.device_value && (
            <div>
              <h3 className="mb-1.5 text-[12px] font-semibold uppercase tracking-[0.05em] text-ink-3">Cihazın gönderdiği (teknik ayrıntı)</h3>
              <pre className="max-h-48 overflow-auto scroll-thin whitespace-pre-wrap break-words rounded-[var(--radius-sm)] bg-surface-2 p-2.5 text-[11.5px] text-ink-2 ring-1 ring-line">{c.device_value}</pre>
            </div>
          )}
          {!open && (
            <p className="text-[13px] text-ink-2">
              {c.resolved_by ?? 'Sistem'} {relative(c.resolved_at)} kapattı
              {c.resolution === 'apply_other' ? ' (diğer değer uygulandı)' : c.resolution === 'reconciled' ? ' (mutabakat tamam)' : ''}.
            </p>
          )}
          {open && c.kind !== 'rejected' && (
            <Textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2} maxLength={300} placeholder="Not (isteğe bağlı): neden bu değer?" />
          )}
          {c.history && c.history.length > 0 && (
            <div>
              <h3 className="mb-2 text-[12px] font-semibold uppercase tracking-[0.05em] text-ink-3">Kaydın tarihçesi</h3>
              <ol className="grid gap-1.5">
                {c.history.map((h) => (
                  <li key={h.id} className="flex flex-wrap items-start justify-between gap-x-3 gap-y-0.5 rounded-[var(--radius-sm)] px-2.5 py-1.5 text-[12.5px] ring-1 ring-line">
                    <div className="min-w-0">
                      <span className="text-ink">{sourceLabel[h.source] ?? h.source}</span>
                      <span className="text-ink-3"> · {h.device ?? h.user ?? 'Sistem'}</span>
                      {c.field && h.has_field && <div className="break-words text-ink-2">{c.field_label}: <span className="tabular text-ink">{h.value === null ? '—' : String(h.value)}</span></div>}
                      {!h.has_field && <div className="text-ink-3">{h.op === 'insert' ? 'Oluşturuldu' : h.op === 'delete' ? 'Silindi' : 'Başka alanlar değişti'}</div>}
                    </div>
                    <span className="shrink-0 whitespace-nowrap text-ink-3" title={dateTime(h.at)}>{relative(h.at)}</span>
                  </li>
                ))}
              </ol>
            </div>
          )}
        </div>
      )}
    </Drawer>
  )
}
