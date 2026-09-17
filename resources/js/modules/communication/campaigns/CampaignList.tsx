import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Mail, Plus, Send, ShieldCheck, Smartphone } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { dateTime, num } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useListState } from '@/hooks/useListState'
import { PageHeader, Stat } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, ProgressBar } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { CAMPAIGN_STATUS_TONE, CHANNEL_NAME, type CampaignOptions, type CampaignRow } from './types'

type ListResponse = Paginated<CampaignRow> & { meta: { stats: { total: number; drafts: number; active: number; completed: number } } }

const STATUS_OPTIONS = [
  { value: 'draft', label: 'Taslak' },
  { value: 'scheduled', label: 'Zamanlandı' },
  { value: 'sending', label: 'Gönderiliyor' },
  { value: 'completed', label: 'Tamamlandı' },
  { value: 'cancelled', label: 'İptal edildi' },
]

export function ChannelBadges({ channels, commercial }: { channels: string[]; commercial?: boolean }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      {channels.map((c) => (
        <Badge key={c} tone="info">
          {c === 'sms' ? <Smartphone className="size-3" /> : <Mail className="size-3" />}
          {CHANNEL_NAME[c as 'sms' | 'email'] ?? c}
        </Badge>
      ))}
      {commercial ? <Badge tone="accent">Ticari</Badge> : <Badge tone="neutral">Duyuru</Badge>}
    </span>
  )
}

/** İletişim › Toplu gönderim: geçmiş + durum. */
export default function CampaignList() {
  const can = useCan()
  const navigate = useNavigate()
  const list = useListState({ sort: '-created_at' })
  const canCompose = can('messages.campaign')

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['campaigns', 'list', list.query],
    queryFn: () => api.get<ListResponse>('/campaigns', list.query),
    placeholderData: keepPreviousData,
    refetchInterval: (q) => ((q.state.data as ListResponse | undefined)?.data.some((c) => c.status === 'sending') ? 8000 : false),
  })
  const options = useQuery({ queryKey: ['campaigns', 'options'], queryFn: () => api.get<CampaignOptions>('/campaigns/options'), staleTime: 60_000 })
  const ch = options.data?.channels
  const noChannel = ch && !ch.sms.ok && !ch.email.ok
  const stats = data?.meta.stats

  const open = (c: CampaignRow) => navigate(c.status === 'draft' && canCompose ? `/iletisim/toplu-gonderim/${c.id}/duzenle` : `/iletisim/toplu-gonderim/${c.id}`)

  const columns: Column<CampaignRow>[] = [
    {
      key: 'name', header: 'Gönderim', sortKey: 'name', cell: (c) => (
        <div className="min-w-0">
          <p className="font-medium text-ink break-words">{c.name}</p>
          <div className="mt-1"><ChannelBadges channels={c.channels} commercial={c.is_commercial} /></div>
        </div>
      ),
    },
    {
      key: 'status', header: 'Durum', sortKey: 'status', cell: (c) => (
        <div>
          <Badge tone={CAMPAIGN_STATUS_TONE[c.status]} dot>{c.status_label}</Badge>
          {c.status === 'scheduled' && c.scheduled_at && <p className="mt-1 text-[12px] text-ink-3 tabular">{dateTime(c.scheduled_at)}</p>}
        </div>
      ),
    },
    {
      key: 'recipients', header: 'Alıcı sayısı', cell: (c) => c.status === 'draft' ? <span className="text-ink-3">—</span> : (
        <div className="tabular">
          <p className="text-ink">{num(c.recipients_total)}</p>
          {c.sms_parts > 0 && <p className="text-[12px] text-ink-3">Harcanan: {num(c.sms_parts)} SMS</p>}
        </div>
      ),
    },
    {
      key: 'result', header: 'Sonuç', mobileLabel: 'Sonuç', cell: (c) => {
        if (!c.counts || c.status === 'draft') return <span className="text-ink-3">—</span>
        const total = Math.max(1, c.recipients_total)
        const done = c.counts.sent + c.counts.failed
        return (
          <div className="w-full max-w-[220px]">
            <ProgressBar value={(done / total) * 100} tone={c.counts.failed > 0 ? 'warning' : 'success'} />
            <p className="mt-1 text-[12px] text-ink-2 tabular">
              {num(c.counts.sent)} gönderildi{c.counts.delivered > 0 && ` · ${num(c.counts.delivered)} iletildi`}
              {c.counts.failed > 0 && <span className="text-danger"> · {num(c.counts.failed)} başarısız</span>}
            </p>
          </div>
        )
      },
    },
    { key: 'created_by', header: 'Hazırlayan', hideable: true, mobileHidden: true, cell: (c) => <span className="text-ink-2">{c.created_by ?? '—'}{c.approved_by && <span className="block text-[12px] text-ink-3">Onay: {c.approved_by}</span>}</span> },
    { key: 'created_at', header: 'Onay / oluşturma tarihi', sortKey: 'created_at', cell: (c) => <span className="text-ink-2 tabular">{dateTime(c.approved_at ?? c.created_at)}</span> },
    {
      key: 'actions', header: '', align: 'right', cell: (c) => (
        <ButtonLink size="xs" variant="ghost" to={c.status === 'draft' && canCompose ? `/iletisim/toplu-gonderim/${c.id}/duzenle` : `/iletisim/toplu-gonderim/${c.id}`} onClick={(e) => e.stopPropagation()}>
          {c.status === 'draft' && canCompose ? 'Düzenle' : 'Ayrıntı'}
        </ButtonLink>
      ),
    },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Toplu gönderim"
        description="Öğrenci, veli, öğretmen ve personele duyuru ya da tanıtım amaçlı SMS ve e-posta"
        actions={
          <>
            {can('messages.consents') && <ButtonLink to="/iletisim/ileti-izinleri" icon={<ShieldCheck className="size-4" />}>İleti izinleri</ButtonLink>}
            {canCompose && <ButtonLink variant="primary" to="/iletisim/toplu-gonderim/yeni" icon={<Plus className="size-4" />}>Yeni gönderim</ButtonLink>}
          </>
        }
      />

      {noChannel && (
        <Alert tone="warning" className="mb-4" title="SMS ve e-posta kanalı bağlı değil"
          action={can(['integrations.manage', 'integrations.sms', 'integrations.email']) ? <ButtonLink size="sm" to="/ayarlar/mesaj-kanallari">Kanalları ayarla</ButtonLink> : undefined}>
          Taslak hazırlayabilirsiniz; gönderim için Ayarlar › Mesaj kanalları ekranından SMS sağlayıcısı ya da SMTP bilgileri girilip bağlantı test edilmeli.
        </Alert>
      )}
      {ch && (ch.sms.simulation || ch.email.simulation) && (
        <Alert tone="info" className="mb-4" title="Simülasyon kipi açık">
          {[ch.sms.simulation && 'SMS', ch.email.simulation && 'E-posta'].filter(Boolean).join(' ve ')} kanalı test sürücüsünde: gönderimler gerçek alıcıya gitmez, "gönderildi (simülasyon)" olarak işaretlenir.
        </Alert>
      )}

      {stats && (
        <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label="Toplam gönderim" value={num(stats.total)} />
          <Stat label="Taslak" value={num(stats.drafts)} />
          <Stat label="Süren / zamanlı" value={num(stats.active)} />
          <Stat label="Tamamlanan" value={num(stats.completed)} />
        </div>
      )}

      <DataTable
        storageKey="campaigns"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={open}
        toolbar={
          <div className="grid w-full grid-cols-1 gap-2 sm:flex sm:w-auto sm:flex-wrap sm:items-center">
            <Input placeholder="Gönderim adı ara" value={list.q} onChange={(e) => list.update({ q: e.target.value })} className="sm:w-[220px]" />
            <div className="grid grid-cols-2 gap-2 sm:flex">
              <Select value={list.filters.status ?? ''} onChange={(e) => list.update({ filters: { status: e.target.value } })} placeholder="Tüm durumlar" options={STATUS_OPTIONS} className="sm:w-[150px]" />
              <Select value={list.filters.channel ?? ''} onChange={(e) => list.update({ filters: { channel: e.target.value } })} placeholder="Tüm kanallar"
                options={[{ value: 'sms', label: 'SMS' }, { value: 'email', label: 'E-posta' }]} className="sm:w-[140px]" />
            </div>
          </div>
        }
        empty={
          <EmptyState icon={<Send />} title="Henüz toplu gönderim yok"
            description="Tüm öğrencilere, velilere ya da öğretmenlere duyuru veya kampanya SMS'i / e-postası hazırlayın. Göndermeden önce kişi sayısı ve SMS kredisi gösterilir."
            action={canCompose ? <ButtonLink variant="primary" to="/iletisim/toplu-gonderim/yeni" icon={<Plus className="size-4" />}>Yeni gönderim</ButtonLink> : undefined} />
        }
      />
    </div>
  )
}
