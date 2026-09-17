import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, Check, ShieldCheck, Trash2, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, num } from '@/lib/format'
import { MailText, PhoneText } from '@/components/ui/contact'
import { useListState } from '@/hooks/useListState'
import { PageHeader, Tabs } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Checkbox, Field, Input, Segmented, Select } from '@/components/ui/form'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'

type ConsentState = { granted: boolean; source: string | null; recorded_at: string | null } | null
type PersonRow = {
  type: string; id: number; name: string; phone: string | null; email: string | null
  sms: ConsentState; email_consent: ConsentState; sms_suppressed: boolean; email_suppressed: boolean
}
type PeopleResponse = Paginated<PersonRow> & { meta: { summary: { sms_granted: number; email_granted: number } } }
type SuppressionRow = { id: number; channel: 'sms' | 'email'; address: string; reason: string; reason_label: string; source: string | null; created_at: string }

const GROUPS = [
  { value: 'guardians', label: 'Veliler' },
  { value: 'students', label: 'Öğrenciler' },
  { value: 'teachers', label: 'Öğretmenler' },
  { value: 'employees', label: 'Personel' },
  { value: 'leads', label: 'Adaylar' },
] as const

function ConsentBadge({ state, suppressed }: { state: ConsentState; suppressed: boolean }) {
  if (suppressed) return <Badge tone="danger">Ret listesinde</Badge>
  if (!state) return <Badge tone="neutral">Kayıt yok</Badge>
  return (
    <span title={[state.source, state.recorded_at && dateTime(state.recorded_at)].filter(Boolean).join(' · ')}>
      {state.granted ? <Badge tone="success" dot>Onaylı</Badge> : <Badge tone="warning" dot>Reddetti</Badge>}
    </span>
  )
}

/** İletişim › İleti izinleri: ticari ileti onayı (İYS) kaydı + ret listesi. */
export default function ConsentList() {
  const [tab, setTab] = useState<'consents' | 'suppressions'>('consents')

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="İleti izinleri"
        description="Ticari SMS / e-posta (reklam, kampanya) yalnız onay veren kişilere gönderilir"
        breadcrumbs={[{ label: 'Toplu gönderim', to: '/iletisim/toplu-gonderim' }, { label: 'İleti izinleri' }]}
        actions={<ButtonLink to="/iletisim/toplu-gonderim">Toplu gönderim</ButtonLink>}
      />
      <Alert tone="info" className="mb-4" title="İYS (İleti Yönetim Sistemi) hakkında">
        Ticari elektronik ileti için alıcının önceden onayı gerekir ve onaylar İYS'ye kayıtlı olmalıdır. Buraya kaydettiğiniz onay kurumun kendi kaydıdır (kaynak, tarih, kaydeden);
        İYS'ye aktarımı SMS sağlayıcınızın ya da İYS panelinin üzerinden yapılır ve gönderimde sağlayıcı İYS kontrolünü ayrıca uygular. Duyuru (bilgilendirme) iletileri için onay gerekmez.
      </Alert>
      <Tabs value={tab} onChange={setTab} tabs={[{ value: 'consents', label: 'Onaylar' }, { value: 'suppressions', label: 'Ret listesi' }]} />
      <div className="mt-4">{tab === 'consents' ? <ConsentsTab /> : <SuppressionsTab />}</div>
    </div>
  )
}

function ConsentsTab() {
  const qc = useQueryClient()
  const list = useListState({ filters: { group: 'guardians' } })
  const [selected, setSelected] = useState<Set<string | number>>(new Set())
  const [modal, setModal] = useState<null | boolean>(null)
  const [channels, setChannels] = useState<('sms' | 'email')[]>(['sms', 'email'])
  const [source, setSource] = useState('')

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['message-consents', list.query],
    queryFn: () => api.get<PeopleResponse>('/message-consents', list.query),
    placeholderData: keepPreviousData,
  })
  const rows = data?.data ?? []
  const keyOf = (r: PersonRow) => `${r.type}:${r.id}`

  const save = useMutation({
    mutationFn: (granted: boolean) => api.post<{ message: string }>('/message-consents', {
      items: [...selected].map((k) => { const [type, id] = String(k).split(':'); return { type, id: Number(id) } }),
      channels, granted, source,
    }),
    onSuccess: (r) => {
      toast.success(r.message)
      setSelected(new Set())
      setModal(null)
      qc.invalidateQueries({ queryKey: ['message-consents'] })
      qc.invalidateQueries({ queryKey: ['campaigns', 'preview'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const columns: Column<PersonRow>[] = [
    { key: 'name', header: 'Kişi', cell: (r) => <span className="font-medium text-ink">{r.name}</span> },
    { key: 'phone', header: 'Telefon', cell: (r) => <PhoneText value={r.phone} /> },
    { key: 'email', header: 'E-posta', cell: (r) => <MailText value={r.email} className="break-all" /> },
    { key: 'sms', header: 'SMS izni', cell: (r) => <ConsentBadge state={r.sms} suppressed={r.sms_suppressed} /> },
    { key: 'email_consent', header: 'E-posta izni', cell: (r) => <ConsentBadge state={r.email_consent} suppressed={r.email_suppressed} /> },
  ]

  const open = (granted: boolean) => { setSource(granted ? 'Kayıt formu — yazılı onay' : 'Kişinin talebi'); setModal(granted) }
  const summary = data?.meta.summary

  return (
    <>
      <DataTable
        storageKey="message-consents"
        columns={columns}
        rows={data?.data}
        rowKey={keyOf}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        selectable
        selected={selected}
        onSelectedChange={setSelected}
        bulkActions={
          <>
            <Button size="sm" variant="success" icon={<Check className="size-4" />} onClick={() => open(true)}>Onay kaydet</Button>
            <Button size="sm" variant="danger-soft" icon={<X className="size-4" />} onClick={() => open(false)}>Ret kaydet</Button>
          </>
        }
        toolbar={
          <div className="flex w-full min-w-0 flex-col gap-2">
            <div className="hidden sm:block">
              <Segmented value={list.filters.group ?? 'guardians'} onChange={(v) => { setSelected(new Set()); list.update({ filters: { group: v } }) }} options={GROUPS.map((g) => ({ value: g.value, label: g.label }))} />
            </div>
            <Select aria-label="Grup" className="sm:hidden" value={list.filters.group ?? 'guardians'} onChange={(e) => { setSelected(new Set()); list.update({ filters: { group: e.target.value } }) }}
              options={GROUPS.map((g) => ({ value: g.value, label: g.label }))} />
            <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-wrap sm:items-center">
              <Input placeholder="Ad, telefon ya da e-posta" value={list.q} onChange={(e) => list.update({ q: e.target.value })} className="sm:w-[220px]" />
              <div className="grid grid-cols-2 gap-2 sm:flex">
                <Select value={list.filters.channel ?? ''} onChange={(e) => list.update({ filters: { channel: e.target.value } })} placeholder="Kanal: SMS" className="sm:w-[140px]"
                  options={[{ value: 'sms', label: 'SMS' }, { value: 'email', label: 'E-posta' }]} />
                <Select value={list.filters.state ?? ''} onChange={(e) => list.update({ filters: { state: e.target.value } })} placeholder="Tüm izin durumları" className="sm:w-[170px]"
                  options={[{ value: 'granted', label: 'Onaylı' }, { value: 'denied', label: 'Reddetti' }, { value: 'none', label: 'Kayıt yok' }]} />
              </div>
              {summary && <span className="text-[12.5px] text-ink-3 tabular sm:ml-auto">Bu grupta onaylı: SMS {num(summary.sms_granted)} · e-posta {num(summary.email_granted)}</span>}
            </div>
          </div>
        }
        empty={<EmptyState icon={<ShieldCheck />} title="Bu filtrede kişi yok" />}
      />
      {rows.length > 0 && <p className="mt-2 text-[12px] text-ink-3">Birden çok kişiyi seçip onay ya da ret kaydedebilirsiniz. Onayın kaynağını (ıslak imzalı form, web formu, sözlü + kayıt) mutlaka yazın.</p>}

      <Modal open={modal !== null} onClose={() => setModal(null)} title={modal ? 'Ticari ileti onayı kaydet' : 'Ret (onay geri alma) kaydet'}
        description={`${num(selected.size)} kişi seçili`}
        footer={
          <>
            <Button variant="ghost" onClick={() => setModal(null)}>Vazgeç</Button>
            <Button variant="primary" loading={save.isPending} disabled={!source.trim() || channels.length === 0} onClick={() => save.mutate(!!modal)}>Kaydet</Button>
          </>
        }>
        <div className="flex flex-col gap-4">
          <Field label="İzin verilen kanallar" required>
            <div className="flex flex-wrap gap-4">
              {(['sms', 'email'] as const).map((c) => (
                <Checkbox key={c} checked={channels.includes(c)} onChange={(v) => setChannels(v ? [...channels, c] : channels.filter((x) => x !== c))} label={c === 'sms' ? 'SMS' : 'E-posta'} />
              ))}
            </div>
          </Field>
          <Field label="Onayın alındığı yer" required hint="Ör. kayıt formu, ıslak imzalı dilekçe, web sitesi. Denetim kaydına da yazılır.">
            <Input value={source} maxLength={60} onChange={(e) => setSource(e.target.value)} />
          </Field>
          {modal && <Alert tone="warning">Yalnız gerçekten onay alınmış kişiler için kaydedin. Onaysız ticari ileti idari para cezasına konu olabilir.</Alert>}
        </div>
      </Modal>
    </>
  )
}

function SuppressionsTab() {
  const qc = useQueryClient()
  const list = useListState({})
  const [channel, setChannel] = useState<'sms' | 'email'>('sms')
  const [address, setAddress] = useState('')
  const [removing, setRemoving] = useState<SuppressionRow | null>(null)

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['message-suppressions', list.query],
    queryFn: () => api.get<Paginated<SuppressionRow>>('/message-suppressions', list.query),
    placeholderData: keepPreviousData,
  })
  const done = (msg: string) => {
    toast.success(msg)
    qc.invalidateQueries({ queryKey: ['message-suppressions'] })
    qc.invalidateQueries({ queryKey: ['message-consents'] })
  }
  const add = useMutation({
    mutationFn: () => api.post<{ message: string }>('/message-suppressions', { channel, address, reason: 'manual', source: 'Elle eklendi' }),
    onSuccess: (r) => { setAddress(''); done(r.message) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Eklenemedi.'),
  })
  const remove = useMutation({
    mutationFn: (id: number) => api.delete<{ message: string }>(`/message-suppressions/${id}`),
    onSuccess: (r) => { setRemoving(null); done(r.message) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Çıkarılamadı.'),
  })

  const columns: Column<SuppressionRow>[] = [
    { key: 'address', align: 'left', header: 'Telefon / e-posta', cell: (r) => r.channel === 'sms' ? <PhoneText value={r.address} /> : <MailText value={r.address} className="break-all" /> },
    { key: 'channel', header: 'Kanal', cell: (r) => <Badge tone="info">{r.channel === 'sms' ? 'SMS' : 'E-posta'}</Badge> },
    { key: 'reason', header: 'Listeye alınma nedeni', cell: (r) => <div><p className="text-ink-2">{r.reason_label}</p>{r.source && <p className="text-[12px] text-ink-3">{r.source}</p>}</div> },
    { key: 'created_at', header: 'Eklenme zamanı', cell: (r) => <span className="tabular text-ink-2">{dateTime(r.created_at)}</span> },
    { key: 'actions', header: '', align: 'right', cell: (r) => <Button size="xs" variant="ghost" icon={<Trash2 className="size-3.5" />} onClick={() => setRemoving(r)}>Çıkar</Button> },
  ]

  return (
    <>
      <div className="mb-4 flex flex-col gap-2 rounded-[var(--radius-lg)] bg-surface p-3 ring-1 ring-line sm:flex-row sm:items-end">
        <Field label="Kanal" required><Segmented value={channel} onChange={setChannel} options={[{ value: 'sms', label: 'SMS' }, { value: 'email', label: 'E-posta' }]} /></Field>
        <Field label={channel === 'sms' ? 'Cep telefonu' : 'E-posta adresi'} required className="flex-1">
          <Input value={address} onChange={(e) => setAddress(e.target.value)} placeholder={channel === 'sms' ? '0532 000 00 00' : 'ad@ornek.com'} />
        </Field>
        <Button icon={<Ban className="size-4" />} loading={add.isPending} disabled={!address.trim()} onClick={() => add.mutate()}>Ret listesine ekle</Button>
      </div>
      <p className="mb-3 text-[12.5px] text-ink-3">Ret listesindeki adreslere hiçbir toplu gönderim (duyuru dahil) yapılmaz. E-postadaki "abonelikten çık" bağlantısı adresi buraya kendiliğinden ekler; SMS'e "RET" yanıtı veren numaraları sağlayıcı panelinden görüp buraya ekleyebilirsiniz.</p>
      <DataTable
        storageKey="message-suppressions"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        toolbar={
          <div className="grid w-full grid-cols-1 gap-2 sm:flex sm:w-auto">
            <Input placeholder="Adres ara" value={list.q} onChange={(e) => list.update({ q: e.target.value })} className="sm:w-[200px]" />
            <Select value={list.filters.channel ?? ''} onChange={(e) => list.update({ filters: { channel: e.target.value } })} placeholder="Tüm kanallar" className="sm:w-[140px]"
              options={[{ value: 'sms', label: 'SMS' }, { value: 'email', label: 'E-posta' }]} />
          </div>
        }
        empty={<EmptyState compact icon={<Ban />} title="Ret listesi boş" description="Abonelikten çıkan ya da RET bildiren adresler burada listelenir." />}
      />
      <ConfirmDialog open={!!removing} onClose={() => setRemoving(null)} onConfirm={() => removing && remove.mutate(removing.id)} loading={remove.isPending}
        title="Ret listesinden çıkarılsın mı?" description="Bu adrese yeniden toplu gönderim yapılabilir. Kişinin açık talebi olmadan çıkarmayın." confirmLabel="Çıkar" />
    </>
  )
}
