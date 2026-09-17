import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Webhook as WebhookIcon, History, KeyRound, Pencil, Play, Plus, RotateCw, Trash2 } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Switch } from '@/components/ui/form'
import { Drawer, Modal, ConfirmDialog } from '@/components/ui/overlay'

type WebhookRow = { id: number; name: string; url: string; events: string[]; is_active: boolean; last_success_at: string | null; last_failure_at: string | null; deliveries_count: number }
type DeliveryRow = { id: number; event: string; status: 'pending' | 'success' | 'failed'; response_status: number | null; attempts: number; next_attempt_at: string | null; created_at: string }

const DELIVERY_TONE: Record<string, 'neutral' | 'success' | 'danger'> = { pending: 'neutral', success: 'success', failed: 'danger' }
const DELIVERY_LABEL: Record<string, string> = { pending: 'Bekliyor', success: 'Başarılı', failed: 'Başarısız' }

export default function WebhookList() {
  const can = useCan()
  const qc = useQueryClient()
  const [editing, setEditing] = useState<WebhookRow | 'new' | null>(null)
  const [deliveriesOf, setDeliveriesOf] = useState<WebhookRow | null>(null)
  const [deleting, setDeleting] = useState<WebhookRow | null>(null)
  const [newSecret, setNewSecret] = useState<string | null>(null)

  const { data, isLoading } = useQuery({ queryKey: ['webhooks'], queryFn: () => api.get<{ data: WebhookRow[]; events: string[] }>('/webhooks') })

  const del = useMutation({
    mutationFn: (id: number) => api.delete<{ message: string }>(`/webhooks/${id}`),
    onSuccess: (res) => { toast.success(res.message); qc.invalidateQueries({ queryKey: ['webhooks'] }); setDeleting(null) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
  })

  const sendTest = useMutation({
    mutationFn: (id: number) => api.post<{ message: string }>(`/webhooks/${id}/test`),
    onSuccess: (res) => toast.success(res.message),
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Test olayı gönderilemedi.'),
  })

  const rotate = useMutation({
    mutationFn: (id: number) => api.post<{ message: string; secret: string }>(`/webhooks/${id}/rotate-secret`),
    onSuccess: (res) => { toast.success(res.message); setNewSecret(res.secret) },
  })

  const columns: Column<WebhookRow>[] = [
    { key: 'name', header: 'Bağlantı', cell: (w) => <div><p className="text-ink font-medium">{w.name}</p><p className="text-[12px] text-ink-3 truncate max-w-[260px]">Adres: {w.url}</p></div> },
    { key: 'events', header: 'Bildirilen olaylar', hideable: true, cell: (w) => <div className="flex flex-wrap gap-1 max-w-[260px]">{w.events.slice(0, 3).map((e) => <Badge key={e} tone="neutral">{e}</Badge>)}{w.events.length > 3 && <Badge tone="neutral">+{w.events.length - 3}</Badge>}</div> },
    { key: 'deliveries_count', header: 'Gönderim sayısı', align: 'right', cell: (w) => <button className="tabular text-ink hover:text-primary underline decoration-dotted" onClick={() => setDeliveriesOf(w)}>{w.deliveries_count}</button> },
    { key: 'last_success_at', header: 'Son başarılı gönderim', hideable: true, cell: (w) => <span className="text-ink-2">{w.last_success_at ? relative(w.last_success_at) : '—'}</span> },
    { key: 'is_active', header: 'Durum', cell: (w) => <Badge tone={w.is_active ? 'success' : 'neutral'} dot>{w.is_active ? 'Aktif' : 'Pasif'}</Badge> },
    {
      key: 'actions', header: '', align: 'right', cell: (w) => (
        <div className="flex items-center justify-end gap-1">
          <Button size="icon-sm" variant="ghost" onClick={() => sendTest.mutate(w.id)} title="Test olayı gönder"><Play className="size-4" /></Button>
          <Button size="icon-sm" variant="ghost" onClick={() => setDeliveriesOf(w)} title="Teslimat geçmişi"><History className="size-4" /></Button>
          <Button size="icon-sm" variant="ghost" onClick={() => rotate.mutate(w.id)} title="Gizli anahtarı yenile"><KeyRound className="size-4" /></Button>
          <Button size="icon-sm" variant="ghost" onClick={() => setEditing(w)}><Pencil className="size-4" /></Button>
          <Button size="icon-sm" variant="ghost" onClick={() => setDeleting(w)}><Trash2 className="size-4 text-danger" /></Button>
        </div>
      ),
    },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Webhooklar"
        description="Olayları dış sistemlere HMAC imzalı bildirin"
        actions={can('integrations.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Yeni webhook</Button>}
      />

      <Panel flush>
        <DataTable storageKey="webhooks" columns={columns} rows={data?.data} rowKey={(r) => r.id} loading={isLoading}
          empty={<EmptyState icon={<WebhookIcon />} title="Henüz webhook yok" description="Dış sistemleri olaylardan haberdar edin." action={can('integrations.manage') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Yeni webhook</Button> : undefined} />} />
      </Panel>

      <WebhookFormDrawer open={editing !== null} webhook={editing === 'new' ? undefined : editing ?? undefined} events={data?.events ?? []} onClose={() => setEditing(null)}
        onCreatedSecret={(s) => setNewSecret(s)} />
      <DeliveriesModal webhook={deliveriesOf} onClose={() => setDeliveriesOf(null)} />

      <Modal open={!!newSecret} onClose={() => setNewSecret(null)} size="sm" title="Gizli anahtar" description="Bu anahtar bir daha gösterilmeyecek; şimdi kopyalayın.">
        <code className="block rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 text-[13px] break-all ring-1 ring-line">{newSecret}</code>
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={() => deleting && del.mutate(deleting.id)} loading={del.isPending} danger
        title="Webhook'u sil" description={`"${deleting?.name}" webhook'unu silmek istediğinize emin misiniz?`} />
    </div>
  )
}

function WebhookFormDrawer({ open, webhook, events, onClose, onCreatedSecret }: { open: boolean; webhook?: WebhookRow; events: string[]; onClose: () => void; onCreatedSecret: (s: string) => void }) {
  const qc = useQueryClient()
  const editing = !!webhook
  const [name, setName] = useState('')
  const [url, setUrl] = useState('')
  const [selected, setSelected] = useState<string[]>([])
  const [isActive, setIsActive] = useState(true)
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!open) return
    setErrors({})
    setName(webhook?.name ?? ''); setUrl(webhook?.url ?? ''); setSelected(webhook?.events ?? []); setIsActive(webhook?.is_active ?? true)
  }, [open, webhook])

  const toggle = (e: string) => setSelected((s) => (s.includes(e) ? s.filter((x) => x !== e) : [...s, e]))

  const save = useMutation({
    mutationFn: () => {
      const payload = { name, url, events: selected, is_active: isActive }
      return editing
        ? api.put<{ message: string }>(`/webhooks/${webhook!.id}`, payload)
        : api.post<{ message: string; secret: string }>('/webhooks', payload)
    },
    onSuccess: (res: any) => {
      toast.success(res.message)
      if (res.secret) onCreatedSecret(res.secret)
      qc.invalidateQueries({ queryKey: ['webhooks'] })
      onClose()
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={editing ? 'Webhook düzenle' : 'Yeni webhook'}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={!name || !url || selected.length === 0} onClick={() => save.mutate()}>Kaydet</Button></>}
    >
      <div className="flex flex-col gap-4">
        <Field label="Ad" required error={errors.name?.[0]}><Input value={name} onChange={(e) => setName(e.target.value)} /></Field>
        <Field label="URL" required error={errors.url?.[0]}><Input value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://…" /></Field>
        <Switch checked={isActive} onChange={setIsActive} label="Aktif" />
        <Field label="Olaylar" required error={errors.events?.[0]}>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
            {events.map((e) => <Checkbox key={e} checked={selected.includes(e)} onChange={() => toggle(e)} label={<span className="font-mono text-[12.5px]">{e}</span>} />)}
          </div>
        </Field>
      </div>
    </Drawer>
  )
}

function DeliveriesModal({ webhook, onClose }: { webhook: WebhookRow | null; onClose: () => void }) {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['webhooks', webhook?.id, 'deliveries'],
    queryFn: () => api.get<Paginated<DeliveryRow>>(`/webhooks/${webhook!.id}/deliveries`),
    enabled: !!webhook,
  })

  const redeliver = useMutation({
    mutationFn: (id: number) => api.post<{ message: string }>(`/webhook-deliveries/${id}/redeliver`),
    onSuccess: (res) => { toast.success(res.message); qc.invalidateQueries({ queryKey: ['webhooks', webhook?.id, 'deliveries'] }) },
  })

  return (
    <Modal open={!!webhook} onClose={onClose} size="lg" title="Teslimat geçmişi" description={webhook?.name}>
      {isLoading ? (
        <div className="flex flex-col gap-2">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-10" />)}</div>
      ) : !data?.data.length ? (
        <EmptyState compact icon={<History />} title="Henüz teslimat yok" />
      ) : (
        <div className="overflow-x-auto">
          <table className="tbl w-full text-[13px]">
            <thead><tr className="text-left text-ink-3 border-b border-line"><th className="py-2 pr-3 text-left">Olay</th><th className="py-2 pr-3 text-center">Durum</th><th className="py-2 pr-3 text-center">Yanıt kodu</th><th className="py-2 pr-3 text-center">Deneme sayısı</th><th className="py-2 pr-3 text-center">Zaman</th><th className="py-2 text-center"></th></tr></thead>
            <tbody>
              {data.data.map((d) => (
                <tr key={d.id} className="border-b border-line last:border-0">
                  <td className="py-2 pr-3 font-mono text-[12px] text-ink-2 text-left">{d.event}</td>
                  <td className="py-2 pr-3 text-center"><Badge tone={DELIVERY_TONE[d.status]}>{DELIVERY_LABEL[d.status]}</Badge></td>
                  <td className="py-2 pr-3 tabular text-ink-2 text-center">{d.response_status ?? '—'}</td>
                  <td className="py-2 pr-3 tabular text-ink-2 text-center">{d.attempts}</td>
                  <td className="py-2 pr-3 tabular text-ink-2 text-center">{dateTime(d.created_at)}</td>
                  <td className="py-2 text-right">{d.status === 'failed' && <Button size="xs" variant="ghost" icon={<RotateCw className="size-3.5" />} onClick={() => redeliver.mutate(d.id)}>Tekrar gönder</Button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  )
}
