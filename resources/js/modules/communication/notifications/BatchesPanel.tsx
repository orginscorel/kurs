import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, Send } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { toast } from 'sonner'
import { date } from '@/lib/format'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Select } from '@/components/ui/form'
import { statusTone, type BatchJson, type Catalog } from './types'

/** İletim ilerleme çubuğu: yeşil=ulaşan, kırmızı=başarısız, gri kalan=bekleyen. */
function DeliveryBar({ b }: { b: BatchJson }) {
  const total = Math.max(b.total, 1)
  const pct = (n: number) => `${Math.min((n / total) * 100, 100)}%`
  return (
    <div className="min-w-[120px] max-w-[200px]">
      <div className="flex items-center gap-2">
        <span className="flex h-1.5 flex-1 overflow-hidden rounded-full bg-surface-3">
          <span className="bg-success" style={{ width: pct(b.reached) }} />
          <span className="bg-danger" style={{ width: pct(b.failed) }} />
        </span>
        <span className="tabular shrink-0 text-[12px] text-ink-2">{b.reached}/{b.total}</span>
      </div>
      {(b.failed > 0 || b.pending > 0 || b.simulation > 0) && (
        <div className="mt-1 flex flex-wrap gap-x-2 text-[11px] leading-tight">
          {b.pending > 0 && <span className="text-ink-3">{b.pending} bekliyor</span>}
          {b.failed > 0 && <span className="text-danger">{b.failed} başarısız</span>}
          {b.simulation > 0 && <span className="text-warning">{b.simulation} simülasyon</span>}
        </div>
      )}
    </div>
  )
}

const STATUS_OPTIONS = [
  { value: '', label: 'Tüm durumlar' },
  { value: 'draft', label: 'Taslak' },
  { value: 'pending', label: 'Onay bekliyor' },
  { value: 'approved', label: 'Onaylandı' },
  { value: 'sent', label: 'Gönderildi' },
  { value: 'cancelled', label: 'İptal' },
]

export default function BatchesPanel({ catalog }: { catalog?: Catalog }) {
  const navigate = useNavigate()
  const audienceLabels = catalog?.audiences ?? {}
  const [eventType, setEventType] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['notif', 'batches', eventType, status, page],
    queryFn: () => api.get<Paginated<BatchJson>>('/notifications/batches', { event_type: eventType, status, page }),
    // Hâlâ işlenen (pending) gönderim varsa 5 sn'de bir canlı yenile; yoksa dur.
    refetchInterval: (q) => (((q.state.data as Paginated<BatchJson> | undefined)?.data ?? []).some((b) => b.live) ? 5000 : false),
  })

  const rows = data?.data ?? []
  const sum = rows.reduce((a, b) => ({ total: a.total + b.total, reached: a.reached + b.reached, failed: a.failed + b.failed, pending: a.pending + b.pending }), { total: 0, reached: 0, failed: 0, pending: 0 })
  const anyLive = rows.some((b) => b.live)

  const columns: Column<BatchJson>[] = [
    {
      key: 'title', header: 'Gönderim', priority: 1, truncate: true, title: (b) => b.title || b.event_label,
      cell: (b) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ink">{b.title || b.event_label}</p>
          <p className="truncate text-[12px] text-ink-3">{b.event_label}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Durum', priority: 2, cell: (b) => <Badge tone={statusTone(b.status)} dot>{b.status_label}</Badge> },
    {
      key: 'audiences', header: 'Kitleler', priority: 3, cell: (b) => (
        <span className="text-ink-2">{(b.audiences ?? []).map((a) => audienceLabels[a] ?? a).join(', ') || '—'}</span>
      ),
    },
    { key: 'progress', header: 'İletim', priority: 2, cell: (b) => <DeliveryBar b={b} /> },
    { key: 'created_at', header: 'Tarih', priority: 4, cell: (b) => <span className="tabular text-ink-3">{date(b.created_at)}</span> },
    {
      key: 'actions', header: '', align: 'right', cell: (b) => (
        <div className="flex items-center justify-end gap-1" onClick={(e) => e.stopPropagation()}>
          {b.pdf_available && (
            <Button size="icon-sm" variant="ghost" aria-label="PDF indir" title="PDF indir"
              onClick={() => api.download(`/notifications/batches/${b.id}/pdf`, undefined, 'bildirim.pdf').catch(() => toast.error('PDF alınamadı.'))}>
              <Download className="size-4" />
            </Button>
          )}
        </div>
      ),
    },
  ]

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <Select className="w-full max-w-[220px]" value={eventType} onChange={(e) => { setEventType(e.target.value); setPage(1) }}
          placeholder="Tüm olaylar" options={(catalog?.events ?? []).map((ev) => ({ value: ev.event_type, label: ev.label }))} />
        <Select className="w-full max-w-[180px]" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} options={STATUS_OPTIONS} placeholder={undefined} />
        <div className="ml-auto">
          <Button variant="primary" icon={<Send className="size-4" />} onClick={() => navigate('/iletisim/bildirim-merkezi/gonder')}>Yeni gönderim</Button>
        </div>
      </div>

      {rows.length > 0 && (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-[var(--radius-md)] bg-surface-2 px-3 py-2 text-[12.5px] ring-1 ring-line">
          <span className="flex items-center gap-1.5 font-medium text-ink-2">
            {anyLive && <span className="size-2 animate-pulse rounded-full bg-success" title="Canlı" />}
            Görünen {rows.length} gönderim
          </span>
          <span className="tabular text-ink-2"><b className="text-success">{sum.reached}</b>/{sum.total} ulaştı</span>
          {sum.failed > 0 && <span className="tabular text-danger">{sum.failed} başarısız</span>}
          {sum.pending > 0 && <span className="tabular text-ink-3">{sum.pending} bekliyor</span>}
        </div>
      )}

      <DataTable
        storageKey="notif-batches"
        rowKey={(b) => b.id}
        columns={columns}
        rows={data?.data}
        loading={isLoading}
        meta={data?.meta}
        page={page}
        onPage={setPage}
        onRowClick={(b) => navigate(`/iletisim/bildirim-merkezi/gonderim/${b.id}`)}
        empty={<div className="py-14 text-center text-[13px] text-ink-3">Henüz gönderim yok. “Yeni gönderim” ile başlayın.</div>}
      />
    </div>
  )
}
