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
  })

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
    { key: 'total', header: 'Mesaj', priority: 3, cell: (b) => <span className="tabular text-ink-2">{b.sent > 0 ? `${b.sent}/${b.total}` : b.total}</span> },
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
