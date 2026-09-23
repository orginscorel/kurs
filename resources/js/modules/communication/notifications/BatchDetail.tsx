import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, CheckCircle2, Download, Send, Users } from 'lucide-react'
import { api, ApiError, isLocalNode } from '@/lib/api'
import { openOnWeb } from '@/lib/webOnly'
import { useCan } from '@/app/auth'
import { date } from '@/lib/format'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/overlay'
import { useState } from 'react'
import { statusTone, msgStatusTone, MSG_STATUS_LABEL, type BatchDetail as Batch } from './types'

/** Küçük sayaç çipi. */
function Chip({ label, v, tone }: { label: string; v: number; tone?: 'info' | 'success' | 'danger' }) {
  const color = tone === 'success' ? 'text-success' : tone === 'danger' ? 'text-danger' : tone === 'info' ? 'text-info' : 'text-ink'
  return (
    <div className="rounded-[var(--radius-md)] bg-surface-2 px-2.5 py-1.5 text-center ring-1 ring-line">
      <p className={`tabular text-[17px] font-semibold leading-none ${v > 0 ? color : 'text-ink-3'}`}>{v}</p>
      <p className="mt-1 text-[11px] text-ink-3">{label}</p>
    </div>
  )
}

export default function BatchDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const can = useCan()
  const local = isLocalNode()
  const [confirmCancel, setConfirmCancel] = useState(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['notif', 'batch', id],
    queryFn: () => api.get<{ data: Batch }>(`/notifications/batches/${id}`).then((r) => r.data),
    enabled: !!id,
    // Gönderim sürüyorsa (bekleyen mesaj varsa) 4 sn'de bir canlı yenile.
    refetchInterval: (q) => ((q.state.data as Batch | undefined)?.live ? 4000 : false),
  })

  const approve = useMutation({
    mutationFn: () => api.post<{ data: Batch }>(`/notifications/batches/${id}/approve`, {}).then((r) => r.data),
    onSuccess: () => { toast.success('Onaylandı ve gönderildi.'); qc.invalidateQueries({ queryKey: ['notif', 'batch', id] }); qc.invalidateQueries({ queryKey: ['notif', 'batches'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Onaylanamadı.'),
  })

  const cancel = useMutation({
    mutationFn: () => api.post(`/notifications/batches/${id}/cancel`, {}),
    onSuccess: () => { toast.success('Gönderim iptal edildi.'); setConfirmCancel(false); qc.invalidateQueries({ queryKey: ['notif', 'batch', id] }); qc.invalidateQueries({ queryKey: ['notif', 'batches'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İptal edilemedi.'),
  })

  if (isLoading) return <div className="animate-fade-in"><Skeleton className="h-9 w-64" /><Skeleton className="mt-4 h-64" /></div>
  if (isError || !data) return <EmptyState title="Gönderim bulunamadı" description="Kayıt silinmiş ya da erişiminiz yok olabilir." action={<Button onClick={() => navigate('/iletisim/bildirim-merkezi')}>Bildirim Merkezi</Button>} />

  const canSend = can('messages.send')
  const pending = data.status === 'draft' || data.status === 'pending'

  return (
    <div className="animate-fade-in">
      <PageHeader
        title={data.title || data.event_label}
        description={<span className="flex flex-wrap items-center gap-2"><Badge tone="info">{data.event_label}</Badge><Badge tone={statusTone(data.status)} dot>{data.status_label}</Badge><span className="text-ink-3">{date(data.created_at)}</span></span>}
        breadcrumbs={[{ label: 'İletişim' }, { label: 'Bildirim Merkezi', to: '/iletisim/bildirim-merkezi' }, { label: 'Gönderim' }]}
        actions={
          <>
            {data.pdf_available && (
              <Button variant="outline" icon={<Download className="size-4" />} onClick={() => api.download(`/notifications/batches/${id}/pdf`, undefined, 'bildirim.pdf').catch(() => toast.error('PDF alınamadı.'))}>PDF indir</Button>
            )}
            {canSend && pending && <Button variant="ghost" icon={<Ban className="size-4" />} onClick={() => setConfirmCancel(true)}>İptal et</Button>}
            {canSend && pending && <Button variant="primary" icon={<Send className="size-4" />} loading={approve.isPending} disabled={local} onClick={() => approve.mutate()}>Onayla ve gönder</Button>}
          </>
        }
      />

      {local && pending && (
        <Alert tone="warning" className="mb-4">
          Onay ve gönderim yalnızca web üzerinden yapılır. <button className="underline" onClick={() => openOnWeb('/iletisim/bildirim-merkezi')}>Web'de aç</button>.
        </Alert>
      )}
      {data.total > 0 && (
        <div className="mb-4 rounded-[var(--radius-lg)] bg-surface p-4 ring-1 ring-line">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <span className="flex items-center gap-2 text-[13px] font-medium text-ink-2">
              <b className="tabular text-ink">{data.reached}/{data.total}</b> ulaştı
              {data.live && <span className="inline-flex items-center gap-1 text-[12px] text-success"><span className="size-2 animate-pulse rounded-full bg-success" />canlı</span>}
              {!data.live && data.pending === 0 && data.reached === data.total && data.total > 0 && <CheckCircle2 className="size-4 text-success" />}
            </span>
            <span className="tabular text-[15px] font-semibold text-ink">%{data.total ? Math.round((data.reached / data.total) * 100) : 0}</span>
          </div>
          <span className="mb-3 flex h-2 overflow-hidden rounded-full bg-surface-3">
            <span className="bg-success" style={{ width: `${Math.min((data.reached / Math.max(data.total, 1)) * 100, 100)}%` }} />
            <span className="bg-danger" style={{ width: `${Math.min((data.failed / Math.max(data.total, 1)) * 100, 100)}%` }} />
          </span>
          <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
            <Chip label="Toplam" v={data.total} />
            <Chip label="Gönderildi" v={data.counts.sent} tone="info" />
            <Chip label="İletildi" v={data.delivered_like} tone="success" />
            <Chip label="Okundu" v={data.counts.read} tone="success" />
            <Chip label="Başarısız" v={data.failed} tone="danger" />
            <Chip label="Bekliyor" v={data.pending} />
          </div>
          {data.simulation > 0 && <p className="mt-2 text-[12px] text-warning">{data.simulation} mesaj simülasyon (WhatsApp bağlı değilken) — gerçek gönderim yapılmadı.</p>}
        </div>
      )}

      <div className="flex flex-col gap-4">
        {data.groups.map((g) => (
          <Panel key={g.audience} title={<span className="flex items-center gap-2"><Users className="size-4 text-primary" />{g.audience_label}</span>} description={`${g.count} alıcı`} flush>
            <div className="max-h-96 divide-y divide-line overflow-y-auto scroll-thin">
              {g.messages.map((m) => (
                <div key={m.id} className="px-4 py-2.5">
                  <div className="flex items-center justify-between gap-2">
                    <p className="text-[12px] text-ink-3">{m.to}</p>
                    <Badge tone={msgStatusTone(m.status, m.provider === 'simulation')}>{m.provider === 'simulation' ? 'Simülasyon' : (MSG_STATUS_LABEL[m.status] ?? m.status)}</Badge>
                  </div>
                  <p className="mt-0.5 whitespace-pre-wrap break-words text-[13px] leading-relaxed text-ink">{m.body}</p>
                </div>
              ))}
            </div>
          </Panel>
        ))}
      </div>

      <ConfirmDialog open={confirmCancel} onClose={() => setConfirmCancel(false)} onConfirm={() => cancel.mutate()} title="Gönderimi iptal et?" description="Bu taslak/onay bekleyen gönderim iptal edilecek. Bu işlem geri alınamaz." confirmLabel="İptal et" danger loading={cancel.isPending} />
    </div>
  )
}
