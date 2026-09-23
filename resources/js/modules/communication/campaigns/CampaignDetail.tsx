import { useState } from 'react'
import { MailText, PhoneText } from '@/components/ui/contact'
import { Navigate, useNavigate, useParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, Copy, Mail, RotateCw, Send, Smartphone } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, num } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useListState } from '@/hooks/useListState'
import { DescriptionList, PageHeader, Panel, Stat } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import { ChannelBadges } from './CampaignList'
import { CAMPAIGN_STATUS_TONE, CHANNEL_NAME, RECIPIENT_STATUS_TONE, SKIP_LABEL, type CampaignChannel, type CampaignDetailData, type ChannelCounts, type RecipientRow } from './types'

const GROUP_LABEL: Record<string, string> = { students: 'Öğrenciler', guardians: 'Veliler', teachers: 'Öğretmenler', employees: 'Personel', leads: 'Ön kayıt adayları', manual: 'Elle eklenenler' }

export default function CampaignDetail() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const list = useListState({ sort: 'id' })
  const [confirm, setConfirm] = useState<'cancel' | 'retry' | null>(null)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['campaigns', 'detail', Number(id)],
    queryFn: () => api.get<{ data: CampaignDetailData }>(`/campaigns/${id}`),
    refetchInterval: (q) => (['sending', 'scheduled'].includes((q.state.data as { data: CampaignDetailData } | undefined)?.data.status ?? '') ? 5000 : false),
  })
  const c = data?.data
  const live = c && ['sending', 'scheduled'].includes(c.status)

  const recipients = useQuery({
    queryKey: ['campaigns', 'recipients', Number(id), list.query],
    queryFn: () => api.get<Paginated<RecipientRow>>(`/campaigns/${id}/recipients`, list.query),
    placeholderData: keepPreviousData,
    enabled: !!c && c.status !== 'draft',
    refetchInterval: live ? 8000 : false,
  })

  const refresh = () => qc.invalidateQueries({ queryKey: ['campaigns'] })
  const onError = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')

  const cancel = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/campaigns/${id}/cancel`),
    onSuccess: (r) => { toast.success(r.message); setConfirm(null); refresh() },
    onError,
  })
  const retry = useMutation({
    mutationFn: (ids?: number[]) => api.post<{ message: string }>(`/campaigns/${id}/retry`, ids ? { ids } : {}),
    onSuccess: (r) => { toast.success(r.message); setConfirm(null); refresh() },
    onError,
  })
  const duplicate = useMutation({
    mutationFn: () => api.post<{ message: string; data: { id: number } }>(`/campaigns/${id}/duplicate`),
    onSuccess: (r) => { toast.success(r.message); refresh(); navigate(`/iletisim/toplu-gonderim/${r.data.id}/duzenle`) },
    onError,
  })

  if (isLoading) return <div className="flex flex-col gap-4"><Skeleton className="h-10 w-72" /><Skeleton className="h-28" /><Skeleton className="h-80" /></div>
  if (isError || !c) return <EmptyState icon={<Send />} title="Gönderim bulunamadı" action={<ButtonLink to="/iletisim/toplu-gonderim">Listeye dön</ButtonLink>} />
  if (c.status === 'draft' && can('messages.campaign')) return <Navigate to={`/iletisim/toplu-gonderim/${c.id}/duzenle`} replace />

  const totals = (Object.values(c.counts) as (ChannelCounts | undefined)[]).reduce<ChannelCounts>((acc, x) => {
    (Object.keys(acc) as (keyof ChannelCounts)[]).forEach((k) => { acc[k] += x?.[k] ?? 0 })
    return acc
  }, { total: 0, pending: 0, sending: 0, sent: 0, delivered: 0, failed: 0, skipped: 0, parts_sent: 0 })
  const target = totals.total - totals.skipped
  const done = totals.sent + totals.delivered + totals.failed
  const canSend = can('messages.campaign_send')
  const a = c.audience

  const columns: Column<RecipientRow>[] = [
    {
      key: 'name', header: 'Alıcı', sortKey: 'name', cell: (r) => (
        <div className="min-w-0">
          <p className="truncate text-ink">{r.name || '—'}</p>
          <p className="text-[12px] text-ink-3">Grup: {r.group_label}</p>
        </div>
      ),
    },
    { key: 'channel', header: 'Kanal', cell: (r) => <Badge tone="info">{r.channel === 'sms' ? <Smartphone className="size-3" /> : <Mail className="size-3" />}{CHANNEL_NAME[r.channel]}</Badge> },
    { key: 'to', align: 'left', header: 'Telefon / e-posta', cell: (r) => r.channel === 'sms' ? <PhoneText value={r.to} /> : <MailText value={r.to} className="break-all" /> },
    {
      key: 'status', header: 'Durum', sortKey: 'status', cell: (r) => (
        <div className="min-w-0">
          <span className="inline-flex flex-wrap items-center gap-1">
            <Badge tone={RECIPIENT_STATUS_TONE[r.status]} dot>{r.status_label}</Badge>
            {r.simulated && ['sent', 'delivered'].includes(r.status) && <Badge tone="warning">simülasyon</Badge>}
          </span>
          {r.skip_label && <p className="mt-1 text-[12px] text-ink-3">{r.skip_label}</p>}
          {r.status === 'failed' && r.error && <p className="mt-1 max-w-[280px] text-[12px] text-danger" title={r.error}>{r.error}</p>}
        </div>
      ),
    },
    { key: 'parts', header: 'SMS parça sayısı', align: 'right', hideable: true, mobileHidden: true, cell: (r) => <span className="tabular text-ink-2">{r.sms_parts ?? '—'}</span> },
    { key: 'updated_at', header: 'Gönderim zamanı', sortKey: 'updated_at', hideable: true, cell: (r) => <span className="tabular text-ink-2">{r.status === 'skipped' || r.status === 'pending' ? '—' : dateTime(r.delivered_at ?? r.sent_at ?? r.updated_at)}</span> },
    {
      key: 'actions', header: '', align: 'right', cell: (r) => r.status === 'failed' && canSend && c.status !== 'cancelled' ? (
        <Button size="xs" variant="ghost" icon={<RotateCw className="size-3.5" />} loading={retry.isPending} onClick={(e) => { e.stopPropagation(); retry.mutate([r.id]) }}>Yeniden dene</Button>
      ) : null,
    },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title={c.name}
        breadcrumbs={[{ label: 'Toplu gönderim', to: '/iletisim/toplu-gonderim' }, { label: c.name }]}
        description={<span className="inline-flex flex-wrap items-center gap-2"><Badge tone={CAMPAIGN_STATUS_TONE[c.status]} dot>{c.status_label}</Badge><ChannelBadges channels={c.channels} commercial={c.is_commercial} /></span>}
        actions={
          <>
            {can('messages.campaign') && <Button icon={<Copy className="size-4" />} loading={duplicate.isPending} onClick={() => duplicate.mutate()}>Kopyala</Button>}
            {canSend && totals.failed > 0 && c.status !== 'cancelled' && <Button variant="warning" icon={<RotateCw className="size-4" />} onClick={() => setConfirm('retry')}>Başarısızları yeniden dene</Button>}
            {canSend && live && <Button variant="danger-soft" icon={<Ban className="size-4" />} onClick={() => setConfirm('cancel')}>İptal et</Button>}
          </>
        }
      />

      {c.status === 'scheduled' && c.scheduled_at && (
        <Alert tone="info" className="mb-4" title={`Zamanlandı: ${dateTime(c.scheduled_at)}`}>Zamanı gelince gönderim kendiliğinden başlar. O zamana kadar iptal edebilirsiniz.</Alert>
      )}
      {c.status === 'sending' && (
        <Alert tone="primary" className="mb-4" title="Gönderiliyor">Alıcılar kuyrukta dakikalık sınırla sırayla işleniyor; bu sayfa kendini yeniler.</Alert>
      )}

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <Stat label="Hedef alıcı" value={num(target)} sub={totals.skipped ? `${num(totals.skipped)} atlandı` : undefined} />
        <Stat label="Gönderildi" value={num(totals.sent + totals.delivered)} />
        <Stat label="İletildi" value={num(totals.delivered)} sub={c.channels.includes('email') ? 'e-postada teslim bilgisi yok' : undefined} />
        <Stat label="Başarısız" value={num(totals.failed)} tone={totals.failed ? 'danger' : undefined} />
        <Stat label="Sırada" value={num(totals.pending + totals.sending)} />
        <Stat label="Harcanan SMS" value={num(totals.parts_sent)} sub={c.sms_parts ? `tahmin ${num(c.sms_parts)}` : undefined} />
      </div>
      {target > 0 && (
        <div className="mb-5">
          <ProgressBar value={(done / target) * 100} tone={totals.failed ? 'warning' : 'success'} />
          <p className="mt-1 text-[12px] text-ink-3 tabular">%{Math.round((done / target) * 100)} işlendi</p>
        </div>
      )}

      <div className="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel title="İçerik">
          <div className="flex flex-col gap-3">
            {c.channels.includes('sms') && (
              <div>
                <p className="mb-1 text-[12px] font-medium text-ink-3">SMS</p>
                <p className="whitespace-pre-wrap break-words rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 text-[13px] text-ink ring-1 ring-line">{c.sms_body}</p>
                {c.estimate?.opt_out_text && <p className="mt-1 text-[12px] text-ink-3">Sona eklenen: {c.estimate.opt_out_text}</p>}
              </div>
            )}
            {c.channels.includes('email') && (
              <div>
                <p className="mb-1 text-[12px] font-medium text-ink-3">E-posta</p>
                <div className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 ring-1 ring-line">
                  <p className="font-medium text-ink break-words">{c.email_subject}</p>
                  <p className="mt-1 max-h-48 overflow-y-auto whitespace-pre-wrap break-words text-[13px] text-ink-2 scroll-thin">{c.email_body}</p>
                </div>
              </div>
            )}
          </div>
        </Panel>
        <Panel title="Bilgiler">
          <DescriptionList columns={1} items={[
            { label: 'Alıcı grupları', value: (a.groups ?? []).map((g) => GROUP_LABEL[g] ?? g).join(', ') || '—' },
            { label: 'Filtre', value: [(a.class_group_ids ?? []).length && `${a.class_group_ids!.length} sınıf`, (a.program_ids ?? []).length && `${a.program_ids!.length} program`, (a.student_statuses ?? []).length && `${a.student_statuses!.length} öğrenci durumu`, (a.manual ?? []).length && `${a.manual!.length} elle eklenen`].filter(Boolean).join(' · ') || 'Yok', hidden: false },
            { label: 'Hazırlayan', value: c.created_by ?? '—' },
            { label: 'Onaylayan', value: c.approved_by ? `${c.approved_by} · ${dateTime(c.approved_at)}` : '—' },
            { label: 'Başlangıç', value: c.started_at ? dateTime(c.started_at) : '—' },
            { label: 'Bitiş', value: c.completed_at ? dateTime(c.completed_at) : '—' },
          ]} />
        </Panel>
      </div>

      <h2 className="mb-2 text-[15px] font-semibold text-ink">Alıcılar</h2>
      <DataTable
        storageKey="campaign-recipients"
        columns={columns}
        rows={recipients.data?.data}
        rowKey={(r) => r.id}
        loading={recipients.isLoading || recipients.isFetching}
        meta={recipients.data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        toolbar={
          <div className="grid w-full grid-cols-1 gap-2 sm:flex sm:w-auto sm:flex-wrap">
            <Input placeholder="Ad ya da adres ara" value={list.q} onChange={(e) => list.update({ q: e.target.value })} className="sm:w-[200px]" />
            <div className="grid grid-cols-2 gap-2 sm:flex">
              <Select value={list.filters.status ?? ''} onChange={(e) => list.update({ filters: { status: e.target.value } })} placeholder="Tüm durumlar" className="sm:w-[140px]"
                options={[{ value: 'pending', label: 'Sırada' }, { value: 'sent', label: 'Gönderildi' }, { value: 'delivered', label: 'İletildi' }, { value: 'failed', label: 'Başarısız' }, { value: 'skipped', label: 'Atlandı' }]} />
              {c.channels.length > 1 && (
                <Select value={list.filters.channel ?? ''} onChange={(e) => list.update({ filters: { channel: e.target.value } })} placeholder="Tüm kanallar" className="sm:w-[130px]"
                  options={c.channels.map((x: CampaignChannel) => ({ value: x, label: CHANNEL_NAME[x] }))} />
              )}
              {list.filters.status === 'skipped' && (
                <Select value={list.filters.skip_reason ?? ''} onChange={(e) => list.update({ filters: { skip_reason: e.target.value } })} placeholder="Tüm nedenler" className="sm:w-[180px]"
                  options={Object.entries(SKIP_LABEL).map(([value, label]) => ({ value, label }))} />
              )}
            </div>
          </div>
        }
        empty={<EmptyState compact icon={<Send />} title="Bu filtrede alıcı yok" />}
      />

      <ConfirmDialog open={confirm === 'cancel'} onClose={() => setConfirm(null)} onConfirm={() => cancel.mutate()} loading={cancel.isPending} danger
        title="Gönderim iptal edilsin mi?" description={`Henüz gönderilmemiş ${num(totals.pending)} alıcıya ileti gitmeyecek. Gönderilmiş iletiler geri alınamaz.`} confirmLabel="İptal et" />
      <ConfirmDialog open={confirm === 'retry'} onClose={() => setConfirm(null)} onConfirm={() => retry.mutate(undefined)} loading={retry.isPending}
        title="Başarısızlar yeniden denensin mi?" description={`${num(totals.failed)} alıcı yeniden sıraya alınır${totals.failed && c.channels.includes('sms') ? ' ve yeniden SMS kredisi harcanır' : ''}.`} confirmLabel="Yeniden dene" />
    </div>
  )
}
