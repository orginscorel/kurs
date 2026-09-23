import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Link2Off, RefreshCw, ShieldCheck, Smartphone, Wifi, WifiOff } from 'lucide-react'
import { api, ApiError, isLocalNode } from '@/lib/api'
import { Panel } from '@/components/ui/layout'
import { Alert, Badge, Skeleton } from '@/components/ui/feedback'
import { Field, Input, Textarea } from '@/components/ui/form'
import { Button, ButtonLink } from '@/components/ui/Button'
import { WebOnlyNotice } from '@/components/layout/WebOnlyNotice'

type Rate = { send_delay_ms: number; jitter_ms: number; daily_limit: number; opt_out: string }
type Conn = {
  configured: boolean
  provider?: string | null
  message?: string
  reachable?: boolean
  connected?: boolean
  state?: string
  phone?: string
  is_enabled?: boolean
  session_id?: string
  error?: string | null
  rate?: Rate
}

const QR_ENDPOINT = '/api/v1/whatsapp/connection/qr.png'

/**
 * WhatsApp QR (kalıcı oturum) bağlantısı: numarayı QR ile bağlar, durumu canlı izler, kesebilir ve
 * anti-ban hız/limit ayarlarını yönetir. Bot sunucu servisidir → masaüstünde web'e yönlendirilir.
 */
export default function WhatsAppConnectionPanel({ embedded = false }: { embedded?: boolean } = {}) {
  const qc = useQueryClient()
  const local = isLocalNode()
  const [pairing, setPairing] = useState(false)
  const [qrTs, setQrTs] = useState(() => Date.now())

  const { data, isLoading } = useQuery({
    queryKey: ['whatsapp', 'connection'],
    queryFn: () => api.get<Conn>('/whatsapp/connection'),
    enabled: !local,
    refetchInterval: pairing ? 4000 : 30000,
  })

  const connected = !!data?.connected
  // Eşleşme tamamlanınca eşleştirme modundan çık.
  useEffect(() => {
    if (connected && pairing) {
      setPairing(false)
      toast.success('WhatsApp bağlandı.')
    }
  }, [connected, pairing])

  // Eşleştirme sırasında QR görselini periyodik tazele (WhatsApp QR ~20 sn'de bir yenilenir).
  const qrTimer = useRef<number | null>(null)
  useEffect(() => {
    if (pairing && !connected) {
      qrTimer.current = window.setInterval(() => setQrTs(Date.now()), 15000)
      return () => { if (qrTimer.current) window.clearInterval(qrTimer.current) }
    }
  }, [pairing, connected])

  const start = useMutation({
    mutationFn: () => api.post('/whatsapp/connection/start'),
    onSuccess: () => { setPairing(true); setQrTs(Date.now()); qc.invalidateQueries({ queryKey: ['whatsapp', 'connection'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Oturum başlatılamadı.'),
  })
  const disconnect = useMutation({
    mutationFn: () => api.post('/whatsapp/connection/disconnect'),
    onSuccess: () => { setPairing(false); qc.invalidateQueries({ queryKey: ['whatsapp', 'connection'] }); toast.success('Bağlantı kesildi.') },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Bağlantı kesilemedi.'),
  })

  if (local) {
    return (
      <WebOnlyNotice
        title="WhatsApp Bağlantısı"
        reason="WhatsApp botu sunucu tarafında çalışan bir servistir; QR eşleştirme ve bağlantı durumu yalnızca web panelinden yönetilir. Şablonlar ve gönderim uygulamada da kullanılabilir."
        path="/iletisim/bildirim-merkezi"
      />
    )
  }

  if (isLoading) return <Panel><Skeleton className="h-64" /></Panel>

  if (!data?.configured) {
    if (embedded) {
      return (
        <Alert tone="info" title="Bağlantı için son adım">
          Yukarıdaki alanlara bot adresi ve jetonunu girip <strong>Kaydet</strong>'e basın; ardından QR kodu burada görünür ve numaranızı okutabilirsiniz.
        </Alert>
      )
    }
    return (
      <Panel title="WhatsApp Bağlantısı">
        <Alert tone="info" title="WhatsApp QR sağlayıcısı seçilmemiş">
          {data?.message ?? 'Ayarlar › Entegrasyonlar ekranından "WhatsApp QR (kalıcı oturum)" sağlayıcısını seçip bot adresi ve jetonunu kaydedin. Ardından bu ekrandan QR okutarak numaranızı bağlayın.'}
        </Alert>
        <div className="mt-3">
          <ButtonLink to="/ayarlar/entegrasyonlar" variant="secondary">Entegrasyonlar'a git</ButtonLink>
        </div>
      </Panel>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <Panel title="WhatsApp Bağlantısı">
        <div className="flex flex-wrap items-center gap-3">
          {connected ? (
            <Badge tone="success" dot><Wifi className="size-3.5" /> Bağlı</Badge>
          ) : data.reachable ? (
            <Badge tone="warning" dot><WifiOff className="size-3.5" /> Bağlı değil</Badge>
          ) : (
            <Badge tone="danger" dot><WifiOff className="size-3.5" /> Bota ulaşılamıyor</Badge>
          )}
          <span className="text-[13px] text-ink-3">Oturum: <span className="tabular">{data.session_id}</span></span>
          {connected && data.phone && (
            <span className="inline-flex items-center gap-1 text-[13px] text-ink-2"><Smartphone className="size-3.5" /> {data.phone}</span>
          )}
        </div>

        {!data.reachable && (
          <Alert tone="danger" className="mt-3" title="Bota ulaşılamıyor">
            Bot adresi/jetonu hatalı olabilir ya da servis kapalı. {data.error}
          </Alert>
        )}

        {connected ? (
          <div className="mt-4 flex flex-wrap items-center gap-2">
            <Button variant="danger" onClick={() => disconnect.mutate()} loading={disconnect.isPending}><Link2Off className="size-4" /> Bağlantıyı kes</Button>
            <span className="text-[12.5px] text-ink-3">Numara bağlı ve 7/24 arka planda mesaj gönderebilir.</span>
          </div>
        ) : (
          <div className="mt-4">
            {!pairing ? (
              <Button onClick={() => start.mutate()} loading={start.isPending} disabled={!data.reachable}>QR ile bağlan</Button>
            ) : (
              <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center">
                <div className="rounded-xl border border-line bg-white p-3">
                  <img
                    key={qrTs}
                    src={`${QR_ENDPOINT}?t=${qrTs}`}
                    alt="WhatsApp QR"
                    width={232}
                    height={232}
                    className="size-[232px] object-contain"
                  />
                </div>
                <div className="text-[13px] text-ink-2">
                  <p className="font-medium text-ink">Telefonda WhatsApp → Ayarlar → Bağlı cihazlar → Cihaz bağla</p>
                  <ol className="mt-1 list-decimal pl-4 text-ink-3">
                    <li>Bu QR kodu telefonla okutun.</li>
                    <li>Bağlantı kurulunca ekran otomatik güncellenir.</li>
                  </ol>
                  <Button variant="ghost" size="sm" className="mt-2" onClick={() => setQrTs(Date.now())}><RefreshCw className="size-3.5" /> QR'ı yenile</Button>
                </div>
              </div>
            )}
          </div>
        )}
      </Panel>

      <RateForm rate={data.rate} />
    </div>
  )
}

/** Anti-ban hız/limit ayarları: gönderim gecikmesi + jitter, günlük limit, ret (opt-out) listesi. */
function RateForm({ rate }: { rate?: Rate }) {
  const qc = useQueryClient()
  const [form, setForm] = useState<Rate>(() => rate ?? { send_delay_ms: 4000, jitter_ms: 3000, daily_limit: 800, opt_out: '' })
  useEffect(() => { if (rate) setForm(rate) }, [rate])

  const save = useMutation({
    mutationFn: () => api.put('/whatsapp/connection/rate', form),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['whatsapp', 'connection'] }); toast.success('Ayarlar kaydedildi.') },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Kaydedilemedi.'),
  })

  return (
    <Panel title="Gönderim güvenliği (anti-ban)" description="Mesajları zamana yayarak ve limitleyerek engellenme riskini azaltın. (Risk sıfırlanamaz.)">
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Mesajlar arası gecikme (ms)" hint="Örn. 4000 = 4 sn">
          <Input type="number" min={0} value={form.send_delay_ms} onChange={(e) => setForm({ ...form, send_delay_ms: Number(e.target.value) })} />
        </Field>
        <Field label="Rastgele ek gecikme (ms)" hint="Doğallık için 0–bu değer arası">
          <Input type="number" min={0} value={form.jitter_ms} onChange={(e) => setForm({ ...form, jitter_ms: Number(e.target.value) })} />
        </Field>
        <Field label="Günlük gönderim limiti" hint="Bir günde en fazla mesaj">
          <Input type="number" min={1} value={form.daily_limit} onChange={(e) => setForm({ ...form, daily_limit: Number(e.target.value) })} />
        </Field>
      </div>
      <Field label="Ret (opt-out) listesi" hint="Mesaj gönderilmeyecek numaralar — her satıra bir numara veya virgülle ayırın" className="mt-3">
        <Textarea rows={3} value={form.opt_out} onChange={(e) => setForm({ ...form, opt_out: e.target.value })} placeholder="05551112233&#10;05449998877" />
      </Field>
      <div className="mt-3 flex items-center gap-2">
        <Button onClick={() => save.mutate()} loading={save.isPending}><ShieldCheck className="size-4" /> Kaydet</Button>
      </div>
    </Panel>
  )
}
