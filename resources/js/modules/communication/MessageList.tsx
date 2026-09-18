import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { MessageCircle, Plus, RotateCw } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useListState } from '@/hooks/useListState'
import { PageHeader, Stat } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { MailText, PhoneText } from '@/components/ui/contact'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Select } from '@/components/ui/form'
import { useSearchParams } from 'react-router-dom'
import { CHANNEL_LABEL, MESSAGE_STATUS_LABEL, MESSAGE_STATUS_TONE, type OutboundMessageRow } from './types'
import { SendMessageDialog } from './SendMessageDialog'

type ListResponse = Paginated<OutboundMessageRow> & { meta: { stats: { total: number; sent: number; delivered: number; read: number; failed: number } } }

export default function MessageList() {
  const can = useCan()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: '-created_at' })
  const composeOpen = params.get('yeni') === '1'

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['messages', 'list', list.query],
    queryFn: () => api.get<ListResponse>('/messages', list.query),
    placeholderData: keepPreviousData,
  })

  const retry = useMutation({
    mutationFn: (id: number) => api.post<{ message: string }>(`/messages/${id}/retry`),
    onSuccess: (res) => { toast.success(res.message); qc.invalidateQueries({ queryKey: ['messages'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Yeniden denenemedi.'),
  })

  const stats = data?.meta.stats

  const columns: Column<OutboundMessageRow>[] = [
    {
      key: 'to', align: 'left', header: 'Alıcı', cell: (m) => (
        <div className="min-w-0">
          {m.channel === 'email' ? <MailText value={m.to} /> : m.channel === 'sms' || m.channel === 'whatsapp' ? <PhoneText value={m.to} /> : <p className="text-ink truncate">{m.to || '—'}</p>}
          <p className="text-[12px] text-ink-3">Alıcı türü: {m.recipient_type === 'guardian' ? 'Veli' : m.recipient_type === 'student' ? 'Öğrenci' : m.recipient_type === 'teacher' ? 'Öğretmen' : '—'}</p>
        </div>
      ),
    },
    { key: 'channel', priority: 3, header: 'Kanal', cell: (m) => <Badge tone="neutral">{CHANNEL_LABEL[m.channel] ?? m.channel}</Badge> },
    { key: 'template', priority: 4, header: 'Kullanılan şablon', hideable: true, cell: (m) => <span className="text-ink-2 font-mono text-[12.5px]">{m.template_key ?? '—'}</span> },
    {
      key: 'status', header: 'Durum', cell: (m) => (
        <div>
          <Badge tone={MESSAGE_STATUS_TONE[m.status] ?? 'neutral'} dot>{m.status_label}</Badge>
          {m.status === 'failed' && m.error && <p className="mt-1 text-[12px] text-danger max-w-[220px] truncate" title={m.error}>{m.error}</p>}
        </div>
      ),
    },
    { key: 'created_at', priority: 2, header: 'Oluşturulma zamanı', sortKey: 'created_at', cell: (m) => <span className="text-ink-2 tabular">{dateTime(m.created_at)}</span> },
    {
      key: 'actions', header: '', align: 'right', cell: (m) =>
        m.status === 'failed' && can('messages.send') ? (
          <Button size="xs" variant="ghost" icon={<RotateCw className="size-3.5" />} onClick={() => retry.mutate(m.id)} loading={retry.isPending}>Yeniden dene</Button>
        ) : null,
    },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="WhatsApp"
        description="Gönderim geçmişi ve toplu/tekli mesaj gönderimi"
        actions={can('messages.send') && (
          <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>
            Mesaj gönder
          </Button>
        )}
      />

      {stats && (
        <div className="mb-5 grid grid-cols-2 sm:grid-cols-4 gap-3">
          <Stat label="Gönderilen" value={stats.sent.toLocaleString('tr-TR')} />
          <Stat label="İletilen" value={stats.delivered.toLocaleString('tr-TR')} tone="primary" />
          <Stat label="Okunan" value={stats.read.toLocaleString('tr-TR')} tone="success" />
          <Stat label="Başarısız" value={stats.failed.toLocaleString('tr-TR')} tone={stats.failed > 0 ? 'danger' : undefined} />
        </div>
      )}

      <DataTable
        storageKey="outbound-messages"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        toolbar={
          <div className="flex flex-wrap items-center gap-2">
            <Select value={list.filters.status ?? ''} onChange={(e) => list.update({ filters: { status: e.target.value } })} placeholder="Tüm durumlar"
              options={Object.entries(MESSAGE_STATUS_LABEL).map(([value, label]) => ({ value, label }))} className="w-[160px]" />
            <Select value={list.filters.channel ?? ''} onChange={(e) => list.update({ filters: { channel: e.target.value } })} placeholder="Tüm kanallar"
              options={Object.entries(CHANNEL_LABEL).map(([value, label]) => ({ value, label }))} className="w-[150px]" />
            <Select value={list.filters.recipient_type ?? ''} onChange={(e) => list.update({ filters: { recipient_type: e.target.value } })} placeholder="Tüm alıcı türleri"
              options={[{ value: 'guardian', label: 'Veli' }, { value: 'student', label: 'Öğrenci' }, { value: 'teacher', label: 'Öğretmen' }]} className="w-[170px]" />
          </div>
        }
        empty={<EmptyState icon={<MessageCircle />} title="Henüz mesaj gönderilmedi" description="WhatsApp, SMS veya e-posta gönderdiğinizde burada listelenir. Göndermeden önce WhatsApp bağlantısını Entegrasyonlar ekranından kurun." action={<div className="flex flex-wrap justify-center gap-2">{can('messages.send') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Mesaj gönder</Button>}{can('integrations.manage') && <ButtonLink to="/ayarlar/entegrasyonlar">Entegrasyonlar</ButtonLink>}</div>} />}
      />

      <SendMessageDialog open={composeOpen} onClose={() => setParams((p) => { p.delete('yeni'); return p })} />
    </div>
  )
}
