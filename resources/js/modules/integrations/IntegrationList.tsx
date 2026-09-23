import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { BellRing, Mail, MessageCircle, Plug, HardDrive, Fingerprint, ScanLine, CreditCard, Smartphone } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Select, Switch } from '@/components/ui/form'
import { Drawer, Modal } from '@/components/ui/overlay'
import type { RecommendedRule } from '@/modules/communication/types'
import WhatsAppConnectionPanel from '@/modules/communication/notifications/WhatsAppConnectionPanel'

type Card = {
  kind: string
  providers: Record<string, string>
  provider: string | null
  status: 'connected' | 'disconnected' | 'error'
  is_enabled: boolean
  last_error: string | null
  last_checked_at: string | null
  config: Record<string, string>
}

const KIND_META: Record<string, { label: string; icon: any }> = {
  whatsapp: { label: 'WhatsApp', icon: MessageCircle },
  sms: { label: 'SMS', icon: Smartphone },
  email: { label: 'E-posta', icon: Mail },
  payment: { label: 'Ödeme', icon: CreditCard },
  optical: { label: 'Optik Okuyucu', icon: ScanLine },
  biometric: { label: 'Biyometrik Cihaz', icon: Fingerprint },
  storage: { label: 'Depolama', icon: HardDrive },
}

/** Sağlayıcıya göre yapılandırma alanları; listelenmeyen alanlar JSON serbest metin olarak düzenlenir. */
const FIELD_DEFS: Record<string, { key: string; label: string; type?: 'password' | 'text' }[]> = {
  wwebjs: [
    { key: 'base_url', label: 'Bot adresi (URL)' },
    { key: 'api_key', label: 'Bot jetonu (API Token)', type: 'password' },
    { key: 'session_id', label: 'Oturum adı (her numara için ayrı)' },
  ],
  meta_cloud: [
    { key: 'phone_number_id', label: 'Telefon numarası kimliği (Phone Number ID)' },
    { key: 'business_account_id', label: 'İşletme hesabı kimliği (WABA ID)' },
    { key: 'access_token', label: 'Erişim jetonu (Access Token)', type: 'password' },
    { key: 'app_secret', label: 'Uygulama sırrı (App Secret)', type: 'password' },
    { key: 'verify_token', label: 'Doğrulama jetonu (Verify Token)', type: 'password' },
  ],
  generic_http: [
    { key: 'url', label: 'Adres (URL)' },
    { key: 'method', label: 'HTTP Metodu (POST/GET)' },
    { key: 'headers', label: 'Başlıklar (JSON, ör. {"Authorization":"Bearer …"})' },
    { key: 'body_template', label: 'Gövde şablonu (JSON, {{to}}/{{body}} yer tutucu)' },
    { key: 'success_path', label: 'Başarı alanı (yanıt JSON yolu, opsiyonel)' },
    { key: 'message_id_path', label: 'Mesaj kimliği alanı (opsiyonel)' },
  ],
  smtp: [
    { key: 'host', label: 'SMTP sunucusu' },
    { key: 'port', label: 'Port' },
    { key: 'username', label: 'Kullanıcı adı' },
    { key: 'password', label: 'Şifre', type: 'password' },
    { key: 'encryption', label: 'Şifreleme (tls/ssl)' },
    { key: 'from_address', label: 'Gönderen adresi' },
    { key: 'from_name', label: 'Gönderen adı' },
  ],
  s3: [
    { key: 'disk', label: 'Disk adı (filesystems.php içindeki)' },
    { key: 'key', label: 'Anahtar (Access Key)', type: 'password' },
    { key: 'secret', label: 'Sır (Secret Key)', type: 'password' },
    { key: 'bucket', label: 'Kova (Bucket)' },
    { key: 'region', label: 'Bölge' },
  ],
  local: [{ key: 'disk', label: 'Disk adı (varsayılan: local)' }],
  public: [{ key: 'disk', label: 'Disk adı (varsayılan: public)' }],
}

/** Sağlayıcıya göre yeni kayıtta önceden doldurulan alanlar (yalnız kayıtlı değer yoksa). */
function providerDefaults(card: Card, provider: string): Record<string, string> {
  if (card.kind === 'whatsapp' && provider === 'wwebjs') {
    const d: Record<string, string> = {}
    if (!card.config.base_url) d.base_url = 'http://5.180.32.49:3000'
    if (!card.config.session_id) d.session_id = 'kurs'
    return d
  }
  return {}
}

export default function IntegrationList() {
  const can = useCan()
  const { data, isLoading } = useQuery({ queryKey: ['integrations'], queryFn: () => api.get<{ data: Card[] }>('/integrations') })
  const [editing, setEditing] = useState<Card | null>(null)
  const [recommendOpen, setRecommendOpen] = useState(false)

  return (
    <div className="animate-fade-in">
      <PageHeader title="Entegrasyonlar" description="Dış sağlayıcı bağlantıları: WhatsApp, SMS, E-posta, Ödeme, Optik Okuyucu, Biyometrik, Depolama" />

      {isLoading ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-40 rounded-[var(--radius-lg)]" />)}</div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {(data?.data ?? []).map((c) => {
            const meta = KIND_META[c.kind]
            const Icon = meta?.icon ?? Plug
            return (
              <Panel key={c.kind} className="hover:ring-line-strong transition-shadow">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex items-center gap-2.5">
                    <div className="grid size-9 place-items-center rounded-[var(--radius-md)] bg-surface-2 text-ink-2"><Icon className="size-4.5" /></div>
                    <div>
                      <p className="font-medium text-ink">{meta?.label ?? c.kind}</p>
                      <p className="text-[12px] text-ink-3">{c.provider ? c.providers[c.provider] : 'Yapılandırılmadı'}</p>
                    </div>
                  </div>
                  <StatusBadge status={c.status} />
                </div>

                {c.last_error && <p className="mt-3 text-[12px] text-danger line-clamp-2">{c.last_error}</p>}
                {c.last_checked_at && <p className="mt-2 text-[12px] text-ink-3">Son test: {dateTime(c.last_checked_at)}</p>}

                {can('integrations.manage') && (
                  <div className="mt-4 flex flex-wrap gap-2">
                    {c.kind === 'sms' || c.kind === 'email' ? (
                      <ButtonLink size="sm" to="/ayarlar/mesaj-kanallari">Yapılandır</ButtonLink>
                    ) : c.kind === 'whatsapp' && c.status !== 'connected' ? (
                      <Button size="sm" variant="primary" icon={<MessageCircle className="size-4" />} onClick={() => setEditing(c)}>Bağla / QR okut</Button>
                    ) : (
                      <Button size="sm" onClick={() => setEditing(c)}>Yapılandır</Button>
                    )}
                    {c.kind === 'whatsapp' && can('automations.manage') && c.status === 'connected' && c.is_enabled && (
                      <Button size="sm" variant="primary" icon={<BellRing className="size-4" />} onClick={() => setRecommendOpen(true)}>Önerilen veli bildirimlerini aç</Button>
                    )}
                  </div>
                )}
                {c.kind === 'whatsapp' && can('automations.manage') && !(c.status === 'connected' && c.is_enabled) && (
                  <p className="mt-3 text-[12px] text-ink-3">Bağlantı kurulup test edildikten sonra önerilen veli bildirimlerini (devamsızlık, geç kalma, taksit, sınav sonucu, sınıf değişikliği) buradan tek tıkla açabilirsiniz.</p>
                )}
              </Panel>
            )
          })}
        </div>
      )}

      <IntegrationFormDrawer card={editing} onClose={() => setEditing(null)} />
      <RecommendedRulesModal open={recommendOpen} onClose={() => setRecommendOpen(false)} />
    </div>
  )
}

/** WhatsApp bağlandıktan sonra: önerilen veli bildirim kurallarını onayla ve aç. */
function RecommendedRulesModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['automations', 'recommended'],
    queryFn: () => api.get<{ data: RecommendedRule[]; channels: Record<string, boolean> }>('/automations/recommended'),
    enabled: open,
  })
  const rules = data?.data ?? []
  const toEnable = rules.filter((r) => !r.is_active && r.missing_channels.length === 0)

  const enable = useMutation({
    mutationFn: () => api.post<{ message: string; enabled: string[] }>('/automations/recommended/enable'),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['automations'] })
      onClose()
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Bildirimler açılamadı.'),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Önerilen veli bildirimleri açılsın mı?"
      description="Aşağıdaki otomasyon kuralları açılır ve koşulları oluştuğunda velilere WhatsApp mesajı gönderilmeye başlar."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={enable.isPending} disabled={isLoading || toEnable.length === 0} onClick={() => enable.mutate()}>
            {toEnable.length ? `${toEnable.length} kuralı aç` : 'Açılacak kural yok'}
          </Button>
        </>
      }
    >
      {isLoading ? (
        <Skeleton className="h-40" />
      ) : rules.length === 0 ? (
        <Alert tone="neutral">Önerilen veli bildirim kuralı bulunamadı. Otomasyonlar ekranından kural oluşturabilirsiniz.</Alert>
      ) : (
        <ul className="flex flex-col divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
          {rules.map((r) => (
            <li key={r.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-[13px]">
              <span className="min-w-0">
                <span className="block truncate font-medium">{r.name}</span>
                <span className="block truncate text-[12px] text-ink-3">{r.trigger_label}</span>
              </span>
              {r.is_active ? <Badge tone="success">Zaten açık</Badge> : r.missing_channels.length ? <Badge tone="warning">Kanal bağlı değil</Badge> : <Badge tone="primary">Açılacak</Badge>}
            </li>
          ))}
        </ul>
      )}
      <p className="mt-3 text-[12px] text-ink-3">WhatsApp bilgilendirme izni kapalı olarak kaydedilmiş velilere mesaj gönderilmez. Kuralları istediğiniz zaman İletişim › Otomasyonlar ekranından kapatabilirsiniz.</p>
    </Modal>
  )
}

function StatusBadge({ status }: { status: Card['status'] }) {
  if (status === 'connected') return <Badge tone="success" dot>Bağlı</Badge>
  if (status === 'error') return <Badge tone="danger" dot>Hata</Badge>
  return <Badge tone="neutral" dot>Bağlı değil</Badge>
}

function IntegrationFormDrawer({ card, onClose }: { card: Card | null; onClose: () => void }) {
  const qc = useQueryClient()
  const [provider, setProvider] = useState('')
  const [isEnabled, setIsEnabled] = useState(false)
  const [config, setConfig] = useState<Record<string, string>>({})

  useEffect(() => {
    if (!card) return
    const p = card.provider ?? Object.keys(card.providers)[0] ?? ''
    setProvider(p)
    setIsEnabled(card.is_enabled)
    setConfig(providerDefaults(card, p)) // sır alanları maskeli döner; boş bırakılan alan mevcut değeri korur
  }, [card])

  const changeProvider = (p: string) => {
    setProvider(p)
    if (card) setConfig(providerDefaults(card, p))
  }

  const fields = FIELD_DEFS[provider] ?? []
  const isWhatsAppQr = card?.kind === 'whatsapp' && provider === 'wwebjs'

  const save = useMutation({
    mutationFn: () => api.put<{ message: string }>(`/integrations/${card!.kind}`, { provider, is_enabled: isEnabled, config }),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['integrations'] })
      qc.invalidateQueries({ queryKey: ['whatsapp', 'connection'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const test = useMutation({
    mutationFn: () => api.post<{ success: boolean; message: string }>(`/integrations/${card!.kind}/test`),
    onSuccess: (res) => { res.success ? toast.success(res.message) : toast.error(res.message); qc.invalidateQueries({ queryKey: ['integrations'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Test edilemedi.'),
  })

  if (!card) return null
  const meta = KIND_META[card.kind]

  return (
    <Drawer
      open={!!card}
      onClose={onClose}
      title={`${meta?.label ?? card.kind} entegrasyonu`}
      description="Sır alanları güvenlik için maskeli görünür; değiştirmek istemediğiniz alanı boş bırakın."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Kapat</Button>
          <Button icon={<Plug className="size-4" />} loading={test.isPending} onClick={() => test.mutate()}>Bağlantıyı test et</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label="Sağlayıcı" required><Select value={provider} onChange={(e) => changeProvider(e.target.value)} options={Object.entries(card.providers).map(([value, label]) => ({ value, label }))} /></Field>
        <Switch checked={isEnabled} onChange={setIsEnabled} label="Etkin" />

        {fields.map((f) => (
          <Field key={f.key} label={f.label}>
            <Input
              type={f.type === 'password' ? 'password' : 'text'}
              placeholder={card.config[f.key] ? String(card.config[f.key]) : undefined}
              value={config[f.key] ?? ''}
              onChange={(e) => setConfig((c) => ({ ...c, [f.key]: e.target.value }))}
            />
          </Field>
        ))}

        {fields.length === 0 && !isWhatsAppQr && (
          <Field label="Yapılandırma (JSON)" optional hint="Bu entegrasyon türü için serbest anahtar/değer.">
            <textarea
              className="w-full min-h-[120px] rounded-[var(--radius-sm)] border border-line bg-surface px-3 py-2 text-[13px] font-mono"
              placeholder={JSON.stringify(card.config, null, 2)}
              onChange={(e) => {
                try { setConfig(JSON.parse(e.target.value || '{}')) } catch { /* geçersiz JSON — kaydetmeden önce düzeltilmeli */ }
              }}
            />
          </Field>
        )}

        {isWhatsAppQr && (
          <div className="mt-1 border-t border-line pt-4">
            <p className="mb-3 text-[12.5px] text-ink-3">Adres ve jetonu <strong>Kaydet</strong>'e bastıktan sonra buradan QR kodu okutup numaranızı bağlayın. Adres ve oturum adı sizin için hazır dolduruldu; yalnızca bot jetonunu girin.</p>
            <WhatsAppConnectionPanel embedded />
          </div>
        )}
      </div>
    </Drawer>
  )
}
