import { useEffect, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Mail, MessageCircle, Plug, Power, Save, Send, Smartphone, Wallet } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Switch } from '@/components/ui/form'

type Card = {
  kind: 'whatsapp' | 'sms' | 'email'
  provider: string | null
  provider_label: string | null
  status: 'connected' | 'disconnected' | 'error'
  is_enabled: boolean
  connected: boolean
  last_error: string | null
  last_checked_at: string | null
  config: Record<string, string | number | boolean | null>
}

type Response = {
  data: Record<'whatsapp' | 'sms' | 'email', Card>
  sms_providers: Record<string, string>
  email_providers: Record<string, string>
  sms_ready: { ok: boolean; reason: string | null }
  email_ready: { ok: boolean; reason: string | null }
  opt_out_preview: string | null
  can: { sms: boolean; email: boolean; whatsapp: boolean }
}

type FieldDef = { key: string; label: string; secret?: boolean; hint?: string; placeholder?: string; type?: string }

const SMS_CREDENTIALS: Record<string, FieldDef[]> = {
  netgsm: [
    { key: 'username', label: 'Kullanıcı kodu', hint: 'NetGSM abone numarası (850… / 312…)' },
    { key: 'password', label: 'API şifresi', secret: true, hint: 'Panelde API yetkili alt kullanıcı şifresi önerilir.' },
  ],
  mutlucell: [
    { key: 'username', label: 'Kullanıcı adı' },
    { key: 'password', label: 'Şifre', secret: true },
  ],
  vatansms: [
    { key: 'api_id', label: 'API kimliği (api_id)', secret: true },
    { key: 'api_key', label: 'API anahtarı (api_key)', secret: true },
  ],
  simulation: [
    { key: 'fail_numbers', label: 'Başarısız sayılacak numaralar (isteğe bağlı)', hint: 'Virgülle: hata akışını denemek için. Simülasyonda hiçbir yere SMS gitmez.', placeholder: '05320000000' },
  ],
  generic_http: [],
}

const STATUS: Record<Card['status'], { tone: 'success' | 'danger' | 'neutral'; label: string }> = {
  connected: { tone: 'success', label: 'Bağlı' },
  error: { tone: 'danger', label: 'Hata' },
  disconnected: { tone: 'neutral', label: 'Bağlı değil' },
}

function statusOf(c: Card): { tone: 'success' | 'danger' | 'neutral' | 'warning'; label: string } {
  if (c.connected && c.provider === 'simulation') return { tone: 'warning', label: 'Simülasyon' }
  if (c.status === 'connected' && !c.is_enabled) return { tone: 'neutral', label: 'Kapalı' }
  return STATUS[c.status]
}

const onErr = (fallback: string) => (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : fallback)

/** Ayarlar › Mesaj kanalları: WhatsApp / SMS / E-posta durum kartları + SMS sağlayıcı ve SMTP ayarları. */
export default function MessagingChannels() {
  const { data, isLoading } = useQuery({ queryKey: ['messaging-channels'], queryFn: () => api.get<Response>('/messaging-channels') })

  return (
    <div className="animate-fade-in">
      <PageHeader title="Mesaj kanalları" description="Toplu gönderim ve otomatik bildirimler için SMS sağlayıcısı ve kurum e-posta sunucusu" />

      {isLoading || !data ? (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-28 rounded-[var(--radius-lg)]" />)}</div>
      ) : (
        <>
          <div className="mb-5 grid grid-cols-1 gap-3 md:grid-cols-3">
            <StatusCard card={data.data.whatsapp} icon={<MessageCircle />} title="WhatsApp"
              action={data.can.whatsapp ? <ButtonLink size="sm" to="/ayarlar/entegrasyonlar">Entegrasyonlar</ButtonLink> : undefined}
              note="Bire bir bildirimler (devamsızlık, taksit…) için." />
            <StatusCard card={data.data.sms} icon={<Smartphone />} title="SMS" note={data.sms_ready.ok ? 'Toplu SMS gönderilebilir.' : data.sms_ready.reason} />
            <StatusCard card={data.data.email} icon={<Mail />} title="E-posta" note={data.email_ready.ok ? 'Toplu e-posta gönderilebilir.' : data.email_ready.reason} />
          </div>

          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            {data.can.sms ? <SmsForm card={data.data.sms} providers={data.sms_providers} optOut={data.opt_out_preview} /> : <NoPermission title="SMS" />}
            {data.can.email ? <EmailForm card={data.data.email} providers={data.email_providers} /> : <NoPermission title="E-posta" />}
          </div>
        </>
      )}
    </div>
  )
}

function NoPermission({ title }: { title: string }) {
  return <Panel title={title}><p className="text-[13px] text-ink-3">Bu kanalın ayarlarını değiştirme yetkiniz yok.</p></Panel>
}

function StatusCard({ card, icon, title, note, action }: { card: Card; icon: ReactNode; title: string; note?: string | null; action?: ReactNode }) {
  const st = statusOf(card)
  return (
    <div className="flex flex-col gap-2 rounded-[var(--radius-lg)] bg-surface p-4 ring-1 ring-line">
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 items-center gap-2.5">
          <span className="grid size-9 shrink-0 place-items-center rounded-[var(--radius-md)] bg-surface-2 text-ink-2 [&_svg]:size-[18px]">{icon}</span>
          <div className="min-w-0">
            <p className="font-medium text-ink">{title}</p>
            <p className="truncate text-[12px] text-ink-3">{card.provider_label ?? 'Yapılandırılmadı'}</p>
          </div>
        </div>
        <Badge tone={st.tone} dot>{st.label}</Badge>
      </div>
      {card.last_error ? <p className="line-clamp-2 text-[12px] text-danger">{card.last_error}</p> : note ? <p className="text-[12px] text-ink-2">{note}</p> : null}
      <div className="mt-auto flex flex-wrap items-center justify-between gap-2">
        <span className="text-[12px] text-ink-3">{card.last_checked_at ? `Son test: ${dateTime(card.last_checked_at)}` : 'Henüz test edilmedi'}</span>
        {action}
      </div>
    </div>
  )
}

function SecretInput({ def, card, value, onChange }: { def: FieldDef; card: Card; value: string; onChange: (v: string) => void }) {
  const isSet = !!card.config[`${def.key}_set`]
  const hint = card.config[`${def.key}_hint`] as string | null
  return (
    <Field label={def.label} hint={isSet ? `Kayıtlı (${hint ?? '••••'}). Değiştirmek için yenisini yazın; boş bırakırsanız korunur.` : def.hint}>
      <Input type="password" autoComplete="new-password" value={value} placeholder={isSet ? '••••••••' : def.placeholder} onChange={(e) => onChange(e.target.value)} />
    </Field>
  )
}

function useChannelMutations(kind: 'sms' | 'email') {
  const qc = useQueryClient()
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['messaging-channels'] })
    qc.invalidateQueries({ queryKey: ['campaigns', 'options'] })
    qc.invalidateQueries({ queryKey: ['integrations'] })
  }
  const test = useMutation({
    mutationFn: () => api.post<{ success: boolean; message: string; enabled: boolean }>(`/messaging-channels/${kind}/test`),
    onSuccess: (r) => {
      if (r.success) {
        toast.success(r.message)
        if (!r.enabled) toast.warning('Bağlantı doğrulandı ama kanal "Etkin" değil; gönderim için etkinleştirip kaydedin.')
      } else toast.error(r.message)
      refresh()
    },
    onError: onErr('Test edilemedi.'),
  })
  const disable = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/messaging-channels/${kind}/disable`),
    onSuccess: (r) => { toast.success(r.message); refresh() },
    onError: onErr('Kapatılamadı.'),
  })
  return { test, disable, refresh }
}

function SmsForm({ card, providers, optOut }: { card: Card; providers: Record<string, string>; optOut: string | null }) {
  const cfg = card.config
  const initial = () => ({
    provider: card.provider ?? 'netgsm',
    is_enabled: card.provider ? card.is_enabled : true,
    values: {
      username: String(cfg.username ?? ''), header: String(cfg.header ?? ''), encoding: String(cfg.encoding ?? 'tr'),
      iys_brand_code: String(cfg.iys_brand_code ?? ''), iys_recipient_type: String(cfg.iys_recipient_type ?? 'BIREYSEL'),
      ret_number: String(cfg.ret_number ?? ''), ret_code: String(cfg.ret_code ?? ''), mersis_no: String(cfg.mersis_no ?? ''),
      opt_out_text: String(cfg.opt_out_text ?? ''), unit_price: cfg.unit_price == null ? '' : String(cfg.unit_price),
      rate_per_minute: cfg.rate_per_minute == null ? '' : String(cfg.rate_per_minute), fail_numbers: String(cfg.fail_numbers ?? ''),
      password: '', api_id: '', api_key: '',
    } as Record<string, string>,
  })
  const [state, setState] = useState(initial)
  const [originators, setOriginators] = useState<string[] | null>(null)
  const [balance, setBalance] = useState<string | null>(null)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => setState(initial()), [card.provider, card.last_checked_at, card.status])
  const { test, disable, refresh } = useChannelMutations('sms')
  const v = state.values
  const setV = (k: string, val: string) => setState((s) => ({ ...s, values: { ...s.values, [k]: val } }))
  const credentials = SMS_CREDENTIALS[state.provider] ?? []
  const isSim = state.provider === 'simulation'
  const isGateway = state.provider !== 'generic_http'
  const providerChanged = !!card.provider && card.provider !== state.provider

  const save = useMutation({
    mutationFn: () => {
      const config: Record<string, string | number | null> = {}
      const keys = ['header', 'encoding', 'iys_brand_code', 'iys_recipient_type', 'ret_number', 'ret_code', 'mersis_no', 'opt_out_text', 'unit_price', 'rate_per_minute', ...credentials.map((c) => c.key)]
      for (const k of keys) config[k] = v[k] === '' ? null : v[k]!
      if (config.unit_price != null) config.unit_price = Number(String(config.unit_price).replace(',', '.'))
      if (config.rate_per_minute != null) config.rate_per_minute = Number(config.rate_per_minute)
      return api.put<{ message: string }>('/messaging-channels/sms', { provider: state.provider, is_enabled: state.is_enabled, config })
    },
    onSuccess: (r) => { toast.success(r.message); refresh() },
    onError: onErr('Kaydedilemedi.'),
  })

  const fetchOriginators = useMutation({
    mutationFn: () => api.get<{ data: string[]; message: string | null }>('/messaging-channels/sms/originators'),
    onSuccess: (r) => { setOriginators(r.data); if (r.message) toast.warning(r.message) },
    onError: onErr('Başlıklar alınamadı.'),
  })
  const fetchBalance = useMutation({
    mutationFn: () => api.get<{ success: boolean; message: string }>('/messaging-channels/sms/balance'),
    onSuccess: (r) => { setBalance(r.message); if (!r.success) toast.error(r.message) },
    onError: onErr('Bakiye sorgulanamadı.'),
  })

  return (
    <Panel title={<span className="flex items-center gap-2"><Smartphone className="size-4 text-ink-3" />SMS sağlayıcısı</span>}
      description="Kimlik bilgileri şifreli saklanır ve bir daha gösterilmez.">
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
          <Field label="Sağlayıcı">
            <Select value={state.provider} onChange={(e) => setState((s) => ({ ...s, provider: e.target.value }))} options={Object.entries(providers).map(([value, label]) => ({ value, label }))} />
          </Field>
          <Switch checked={state.is_enabled} onChange={(x) => setState((s) => ({ ...s, is_enabled: x }))} label="Etkin" />
        </div>
        {providerChanged && <Alert tone="warning">Sağlayıcı değişince eski kimlik bilgileri ve başlık silinir; yenilerini girin.</Alert>}
        {isSim && <Alert tone="warning" title="Simülasyon kipi">Hiçbir numaraya SMS gönderilmez; iletiler "gönderildi (simülasyon)" olarak işaretlenir. Canlı kullanımdan önce gerçek sağlayıcıyı seçin.</Alert>}
        {state.provider === 'generic_http' && <Alert tone="neutral">Genel HTTP yalnız tekli bildirimler içindir; toplu gönderim, bakiye ve başlık listesi için NetGSM, Mutlucell ya da VatanSMS seçin. Ayrıntılı alanlar Entegrasyonlar ekranındadır.</Alert>}

        {credentials.length > 0 && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {credentials.map((d) => d.secret
              ? <SecretInput key={d.key} def={d} card={providerChanged ? { ...card, config: {} } : card} value={v[d.key] ?? ''} onChange={(x) => setV(d.key, x)} />
              : <Field key={d.key} label={d.label} hint={d.hint}><Input value={v[d.key] ?? ''} placeholder={d.placeholder} onChange={(e) => setV(d.key, e.target.value)} /></Field>)}
          </div>
        )}

        {isGateway && !isSim && (
          <Field label="SMS başlığı (gönderici adı)" hint="Sağlayıcıda onaylı başlık. Kaydedip bağlantıyı test ettikten sonra listeden seçebilirsiniz.">
            <div className="flex flex-col gap-2 sm:flex-row">
              {originators && originators.length > 0 ? (
                <Select className="flex-1" value={v.header} onChange={(e) => setV('header', e.target.value)} placeholder="Başlık seçin" options={originators.map((o) => ({ value: o, label: o }))} />
              ) : (
                <Input className="flex-1" value={v.header} maxLength={20} onChange={(e) => setV('header', e.target.value.toUpperCase())} placeholder="ERBAABILGI" />
              )}
              <Button icon={<Download className="size-4" />} loading={fetchOriginators.isPending} disabled={!card.provider || providerChanged} onClick={() => fetchOriginators.mutate()}>Başlıkları getir</Button>
            </div>
          </Field>
        )}

        {isGateway && (
          <Field label="Karakter kodlaması" hint={v.encoding === 'tr' ? 'Türkçe karakterli SMS: tek SMS 155, çok parçada 150 karakter.' : v.encoding === 'unicode' ? 'Unicode: tek SMS 70, çok parçada 67 karakter (emoji desteklenir).' : 'Türkçe harfler dönüştürülür (ş→s): tek SMS 160, çok parçada 153.'}>
            <div className="max-w-full overflow-x-auto scroll-thin">
              <Segmented value={v.encoding as 'tr' | 'unicode' | 'ascii'} onChange={(x) => setV('encoding', x)} options={[{ value: 'tr', label: 'Türkçe' }, { value: 'ascii', label: 'Türkçesiz (ekonomik)' }, { value: 'unicode', label: 'Unicode' }]} />
            </div>
          </Field>
        )}

        {isGateway && (
          <fieldset className="flex flex-col gap-3 rounded-[var(--radius-md)] bg-surface-2 p-3 ring-1 ring-line">
            <legend className="px-1 text-[12.5px] font-medium text-ink-2">Ticari ileti (İYS) ve ret</legend>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label="İYS marka kodu" hint="Ticari SMS için zorunlu (İYS panelindeki marka kodu).">
                <Input value={v.iys_brand_code} onChange={(e) => setV('iys_brand_code', e.target.value)} />
              </Field>
              <Field label="İYS alıcı türü">
                <Select value={v.iys_recipient_type} onChange={(e) => setV('iys_recipient_type', e.target.value)} options={[{ value: 'BIREYSEL', label: 'Bireysel' }, { value: 'TACIR', label: 'Tacir' }]} />
              </Field>
              <Field label="RET numarası" hint="Sağlayıcının verdiği kısa numara (ör. 4609).">
                <Input value={v.ret_number} maxLength={20} onChange={(e) => setV('ret_number', e.target.value)} />
              </Field>
              <Field label="Sağlayıcı ret kodu (isteğe bağlı)" hint="Ör. B001 — sağlayıcı istiyorsa.">
                <Input value={v.ret_code} maxLength={20} onChange={(e) => setV('ret_code', e.target.value)} />
              </Field>
              <Field label="MERSİS no (isteğe bağlı)">
                <Input value={v.mersis_no} maxLength={30} onChange={(e) => setV('mersis_no', e.target.value)} />
              </Field>
              <Field label="Ret metni (isteğe bağlı)" hint="{ret_no} numarayla değişir.">
                <Input value={v.opt_out_text} maxLength={160} placeholder="SMS almamak için RET yazıp {ret_no} numarasına ücretsiz gönderin." onChange={(e) => setV('opt_out_text', e.target.value)} />
              </Field>
            </div>
            {optOut && <p className="text-[12px] text-ink-2">Kayıtlı ret metni: <em>{optOut}</em></p>}
            <p className="text-[12px] text-ink-3">Metnin hukuki uygunluğu (ret seçeneği, kimlik bilgisi) kurum tarafından teyit edilmelidir.</p>
          </fieldset>
        )}

        {isGateway && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="SMS birim fiyatı (TL, isteğe bağlı)" hint="Maliyet tahmini ve raporlar için.">
              <Input inputMode="decimal" value={v.unit_price} onChange={(e) => setV('unit_price', e.target.value)} placeholder="0,20" />
            </Field>
            <Field label="Dakikadaki en çok SMS" hint="Boşsa 300.">
              <Input type="number" min={1} max={5000} value={v.rate_per_minute} onChange={(e) => setV('rate_per_minute', e.target.value)} />
            </Field>
          </div>
        )}

        {balance && <Alert tone="info" icon={<Wallet className="size-4" />}>{balance}</Alert>}

        <div className="flex flex-wrap items-center gap-2 border-t border-line pt-3">
          <Button variant="primary" icon={<Save className="size-4" />} loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button>
          <Button icon={<Plug className="size-4" />} loading={test.isPending} disabled={!card.provider || providerChanged} onClick={() => test.mutate()}>Bağlantıyı test et</Button>
          {isGateway && <Button variant="info" icon={<Wallet className="size-4" />} loading={fetchBalance.isPending} disabled={!card.provider || providerChanged} onClick={() => fetchBalance.mutate()}>Bakiye</Button>}
          {card.is_enabled && <Button variant="danger-soft" icon={<Power className="size-4" />} loading={disable.isPending} onClick={() => disable.mutate()} className="sm:ml-auto">Kanalı kapat</Button>}
        </div>
      </div>
    </Panel>
  )
}

function EmailForm({ card, providers }: { card: Card; providers: Record<string, string> }) {
  const cfg = card.config
  const initial = () => ({
    provider: card.provider ?? 'smtp',
    is_enabled: card.provider ? card.is_enabled : true,
    values: {
      host: String(cfg.host ?? ''), port: cfg.port == null ? '587' : String(cfg.port), encryption: String(cfg.encryption ?? 'tls'),
      username: String(cfg.username ?? ''), password: '', from_address: String(cfg.from_address ?? ''), from_name: String(cfg.from_name ?? ''),
      reply_to: String(cfg.reply_to ?? ''), rate_per_minute: cfg.rate_per_minute == null ? '' : String(cfg.rate_per_minute),
    } as Record<string, string>,
  })
  const [state, setState] = useState(initial)
  const [testTo, setTestTo] = useState('')
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => setState(initial()), [card.provider, card.last_checked_at, card.status])
  const { test, disable, refresh } = useChannelMutations('email')
  const v = state.values
  const setV = (k: string, val: string) => setState((s) => ({ ...s, values: { ...s.values, [k]: val } }))
  const isSim = state.provider === 'simulation'
  const configured = !!card.provider && (card.provider === 'simulation' || !!cfg.host)

  const save = useMutation({
    mutationFn: () => {
      const config: Record<string, string | number | null> = {}
      const keys = isSim ? ['from_address', 'from_name', 'rate_per_minute'] : Object.keys(v)
      for (const k of keys) config[k] = v[k] === '' ? null : v[k]!
      if (config.port != null) config.port = Number(config.port)
      if (config.rate_per_minute != null) config.rate_per_minute = Number(config.rate_per_minute)
      return api.put<{ message: string }>('/messaging-channels/email', { provider: state.provider, is_enabled: state.is_enabled, config })
    },
    onSuccess: (r) => { toast.success(r.message); refresh() },
    onError: onErr('Kaydedilemedi.'),
  })
  const sendTest = useMutation({
    mutationFn: () => api.post<{ success: boolean; message: string }>('/messaging-channels/email/test-message', { to: testTo }),
    onSuccess: (r) => (r.success ? toast.success(r.message) : toast.error(r.message)),
    onError: onErr('Test e-postası gönderilemedi.'),
  })

  const setEncryption = (x: string) => setState((s) => ({ ...s, values: { ...s.values, encryption: x, port: x === 'ssl' ? '465' : x === 'tls' ? '587' : s.values.port! } }))

  return (
    <Panel title={<span className="flex items-center gap-2"><Mail className="size-4 text-ink-3" />E-posta (SMTP)</span>}
      description="Kurumunuzun e-posta sunucusu. Şifre sunucuda şifrelenerek saklanır.">
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
          <Field label="Gönderim yöntemi">
            <Select value={state.provider} onChange={(e) => setState((s) => ({ ...s, provider: e.target.value }))} options={Object.entries(providers).map(([value, label]) => ({ value, label }))} />
          </Field>
          <Switch checked={state.is_enabled} onChange={(x) => setState((s) => ({ ...s, is_enabled: x }))} label="Etkin" />
        </div>
        {isSim && <Alert tone="warning" title="Simülasyon kipi">Hiçbir adrese e-posta gönderilmez. Canlı kullanım için SMTP seçin.</Alert>}

        {!isSim && (
          <>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_110px]">
              <Field label="SMTP sunucusu"><Input value={v.host} placeholder="mail.kurumunuz.com" onChange={(e) => setV('host', e.target.value)} /></Field>
              <Field label="Port"><Input type="number" value={v.port} onChange={(e) => setV('port', e.target.value)} /></Field>
            </div>
            <Field label="Şifreleme">
              <Segmented value={v.encryption as 'tls' | 'ssl' | 'none'} onChange={setEncryption}
                options={[{ value: 'tls', label: 'TLS (587)' }, { value: 'ssl', label: 'SSL (465)' }, { value: 'none', label: 'Yok' }]} />
            </Field>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label="Kullanıcı adı"><Input autoComplete="off" value={v.username} onChange={(e) => setV('username', e.target.value)} /></Field>
              <SecretInput def={{ key: 'password', label: 'Şifre' }} card={card} value={v.password ?? ''} onChange={(x) => setV('password', x)} />
            </div>
          </>
        )}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Gönderen adresi"><Input type="email" value={v.from_address} placeholder="bilgi@kurumunuz.com" onChange={(e) => setV('from_address', e.target.value)} /></Field>
          <Field label="Gönderen adı"><Input value={v.from_name} placeholder="Erbaa Bilgi Eğitim" onChange={(e) => setV('from_name', e.target.value)} /></Field>
          {!isSim && <Field label="Yanıt adresi (isteğe bağlı)"><Input type="email" value={v.reply_to} onChange={(e) => setV('reply_to', e.target.value)} /></Field>}
          <Field label="Dakikadaki en çok e-posta" hint="Boşsa 30. Paylaşımlı sunucuların saatlik sınırına dikkat edin.">
            <Input type="number" min={1} max={600} value={v.rate_per_minute} onChange={(e) => setV('rate_per_minute', e.target.value)} />
          </Field>
        </div>

        <div className="flex flex-wrap items-center gap-2 border-t border-line pt-3">
          <Button variant="primary" icon={<Save className="size-4" />} loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button>
          <Button icon={<Plug className="size-4" />} loading={test.isPending} disabled={!card.provider} onClick={() => test.mutate()}>Bağlantıyı test et</Button>
          {card.is_enabled && <Button variant="danger-soft" icon={<Power className="size-4" />} loading={disable.isPending} onClick={() => disable.mutate()} className="sm:ml-auto">Kanalı kapat</Button>}
        </div>

        <div className={cn('flex flex-col gap-2 rounded-[var(--radius-md)] bg-surface-2 p-3 ring-1 ring-line', !configured && 'opacity-70')}>
          <p className="text-[12.5px] font-medium text-ink-2">Test e-postası gönder</p>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Input className="flex-1" type="email" value={testTo} placeholder="kendi-adresiniz@ornek.com" disabled={!configured} onChange={(e) => setTestTo(e.target.value)} />
            <Button icon={<Send className="size-4" />} loading={sendTest.isPending} disabled={!configured || !/^\S+@\S+\.\S+$/.test(testTo)} onClick={() => sendTest.mutate()}>Gönder</Button>
          </div>
          <p className="text-[12px] text-ink-3">{configured ? 'Kayıtlı ayarlarla tek bir deneme iletisi gönderilir.' : 'Önce SMTP ayarlarını kaydedin.'}</p>
        </div>
      </div>
    </Panel>
  )
}
