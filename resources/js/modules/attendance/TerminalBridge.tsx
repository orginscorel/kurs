import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  Activity, Cable, CheckCircle2, CircleDashed, CircleSlash, Download, FileDown, Fingerprint, Link2, Lock, Radio, RefreshCw, Search,
  TriangleAlert, X, XCircle,
} from 'lucide-react'
import { api, ApiError, isLocalNode, type Paginated } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useListState } from '@/hooks/useListState'
import { Panel, DescriptionList } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton, type Tone } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { StudentSearch, type StudentHit } from './LivePresence'
import type { DeviceRow } from './types'
import DevTools from './TerminalDevTools'

/*
| TERMİNAL KÖPRÜSÜ (sürücü bağımsız) — docs/CIHAZ-KOPRUSU.md
|
| Sürücüler: ZKTeco (4370) · Perkotek YT33 / FK Dynamic Face · Genel TCP. Ayar, iki aşamalı test
| (Ağ/TCP → Protokol), ham TCP tanılaması ve teşhis YALNIZ masaüstü uygulamasında çalışır; web salt
| okunur durumu gösterir (sunucu kurumun yerel ağındaki cihaza ulaşamaz ve denemez).
*/

type StageStatus = 'basarili' | 'basarisiz' | 'denenmedi' | 'dogrulama_bekliyor' | 'bekliyor'
type Stage = { etiket: string; durum: StageStatus; ayrinti: string; oneri: string | null }

type Socket = {
  baglandi: boolean
  uzak_ip: string
  uzak_port: number
  sure_ms: number
  yerel_ip: string | null
  yerel_port: number | null
  hata_no: number | null
  hata: string | null
  neden: string | null
  macos_yerel_ag_izni_olasi: boolean
  mesaj: string
  oneri: string
}

type TestReport = {
  durum: 'ok' | 'kismi' | 'hata'
  kod: string | null
  mesaj: string
  oneri: string
  hedef: string
  surucu: { anahtar: string; etiket: string }
  kopru_ip: string | null
  asamalar: Record<'ag' | 'tcp' | 'protokol' | 'kimlik', Stage>
  teknik?: string
  soket: Socket | null
  cihaz: Record<string, string | number | null> | null
}

type DriverInfo = {
  anahtar: string
  etiket: string
  markalar: string[]
  durum: string
  kurulum: string[]
  varsayilan_port: number | null
  aktarimlar: string[]
  protokol_dogrulandi: boolean
}

type TerminalDevice = {
  id: number
  ad: string
  surucu: string | null
  surucu_etiketi: string | null
  protokol_dogrulandi: boolean
  marka: string | null
  model: string | null
  ip: string | null
  port: number | null
  aktarim: string | null
  makine_id?: number
  baglanti_tipi?: 'pull' | 'push'
  sifre_tanimli: boolean
  son_cekme: string | null
  son_cekme_durumu: string | null
  son_cekme_hatasi: string | null
  durum: {
    kopru_ip: string | null
    tcp: StageStatus | null
    tcp_sure_ms: number | null
    protokol: StageStatus | null
    son_test: string | null
    son_test_durum: string | null
    son_hata: string | null
    macos_yerel_ag_izni_olasi: boolean
    son_paket: { zaman: string; bayt: number; hex: string } | null
  }
}

type RawRun = {
  id: string
  durum: 'ok' | 'hata'
  baslangic: string
  hedef: string
  cozumleme: string
  soket: Socket
  gonderilen_bayt: number
  gonderilen_hex: string | null
  alinan_bayt: number
  dinleme_sn: number
  kapanis: string
  kapanis_metni: string
  hex: string
  ascii: string
  zaman_cizelgesi: { t_ms: number; olay: string }[]
  sure_ms: number
}

type Diagnostics = {
  isletim_sistemi: string
  macos: boolean
  arayuzler: { arayuz: string; ip: string; agi: string }[]
  cihazlar: TerminalDevice[]
  son_yoklama: { zaman: string; cihaz_id: number; kullanici_no: string | null; yon: string; eslesti: boolean } | null
  bekleyen_eslesme: number
  bekleyen_esitleme: number | null
  push_dinleyici: PushStatus
  push_paketleri: PacketRow[]
  ham_tanilamalar: RawRun[]
}

type PushStatus = {
  durum: 'aktif' | 'kapali' | 'hata' | 'baslatiliyor'
  durum_metni: string
  oneri: string | null
  acik: boolean
  port: number
  aktar_ip: string | null
  aktar_port: number | null
  aktarma_hatasi: string | null
  son_ip: string | null
  son_zaman: string | null
  baglanti_sayisi: number
  paket_sayisi: number
  kopru_ip: string | null
  cihaz_menusu: { server_ip: string; push_address: string; port: number; push: string } | null
}

type PacketDirection = 'device_to_bridge' | 'device_to_upstream' | 'upstream_to_device'

type PacketRow = {
  id: number
  direction: PacketDirection
  connection_id: string
  upstream: string | null
  upstream_status: string | null
  remote_ip: string
  remote_port: number | null
  received_at: string
  byte_count: number
  truncated: boolean | number
  duplicate_of: number | null
  format: string
  http_method: string | null
  http_path: string | null
}

type PacketDetail = {
  id: number
  yon: PacketDirection
  yon_metni: string
  aktarma: string | null
  aktarma_durumu: string | null
  kaynak_ip: string
  kaynak_port: number | null
  zaman: string
  bayt: number
  kesildi: boolean
  sha256: string
  tekrar_of: number | null
  bicim: string
  kapanis: string | null
  not: string | null
  http: { metot: string; yol: string; basliklar: string | null; govde_bayt: number; govde_ascii: string; govde_hex: string } | null
  hex: string
  ascii: string
}

type BridgeBlock = { via?: string | null; reported_at?: string | null; last_pull_at?: string | null; status?: string | null; error?: string | null; connected?: boolean | null }
type DeviceWithBridge = DeviceRow & { bridge?: BridgeBlock | null }

type ZkUserRow = {
  kullanici_no: string
  uid: number
  ad: string
  kart: string
  yetki: string
  eslesen_ogrenci: { id: number; ad: string; no: string } | null
  oneriler: { id: number; ad: string; no: string; benzerlik: number }[]
}

type PendingRow = {
  id: number
  kullanici_no: string | null
  zaman: string
  yon: string
  kaynak: string
  cihaz: string | null
}

const CIHAZ_ETIKET: Record<string, string> = {
  seri_no: 'Seri no',
  cihaz_adi: 'Cihaz adı',
  platform: 'Platform',
  yazilim: 'Yazılım sürümü',
  parmak_algoritmasi: 'Parmak algoritması',
  mac: 'MAC adresi',
  kullanici_sayisi: 'Cihazdaki kullanıcı',
  parmak_sayisi: 'Kayıtlı parmak',
  kayit_sayisi: 'Cihazdaki kayıt',
  kullanici_kapasitesi: 'Kullanıcı kapasitesi',
  kayit_kapasitesi: 'Kayıt kapasitesi',
  cihaz_saati: 'Cihaz saati',
}

const STAGE_META: Record<StageStatus, { tone: Tone; label: string; icon: ReactNode }> = {
  basarili: { tone: 'success', label: 'Başarılı', icon: <CheckCircle2 className="size-4 text-success" /> },
  basarisiz: { tone: 'danger', label: 'Başarısız', icon: <XCircle className="size-4 text-danger" /> },
  dogrulama_bekliyor: { tone: 'warning', label: 'Doğrulama bekliyor', icon: <CircleSlash className="size-4 text-warning" /> },
  bekliyor: { tone: 'neutral', label: 'Bekliyor', icon: <CircleDashed className="size-4 text-ink-3" /> },
  denenmedi: { tone: 'neutral', label: 'Denenmedi', icon: <CircleDashed className="size-4 text-ink-3" /> },
}

/** Eski sürümlerin kaydettiği durum adları (ör. 1.12.3'te 'dogrulanamadi') ve bilinmeyen değerler ekranı çökertmesin. */
const STAGE_ALIAS: Record<string, StageStatus> = { dogrulanamadi: 'dogrulama_bekliyor' }
export function stageMeta(status: string | null | undefined) {
  if (!status) return null
  return STAGE_META[(STAGE_ALIAS[status] ?? status) as StageStatus] ?? STAGE_META.bekliyor
}

function StageBadge({ status }: { status: StageStatus | string | null | undefined }) {
  const m = stageMeta(status)
  if (!m) return <Badge tone="neutral">Test edilmedi</Badge>
  return <Badge tone={m.tone} dot>{m.label}</Badge>
}

const errorText = (e: unknown, fallback: string) => (e instanceof ApiError ? e.firstError() : fallback)

// ---------------------------------------------------------------- iki aşamalı test sonucu

function StageTable({ report }: { report: TestReport }) {
  const tone: Tone = report.durum === 'ok' ? 'success' : report.durum === 'kismi' ? 'warning' : 'danger'
  const rows: ['ag' | 'tcp' | 'protokol' | 'kimlik', string][] = [['ag', 'Ağ (Network)'], ['tcp', 'TCP portu'], ['protokol', 'Protokol el sıkışması'], ['kimlik', 'Cihaz tanıma']]

  return (
    <div className="space-y-3">
      <Alert tone={tone} title={report.mesaj} icon={report.durum === 'ok' ? <CheckCircle2 className="size-4" /> : <TriangleAlert className="size-4" />}>
        {report.oneri && <span className="block">{report.oneri}</span>}
        {report.soket?.macos_yerel_ag_izni_olasi && (
          <span className="mt-1.5 block font-medium">
            macOS Yerel Ağ izni: Sistem Ayarları › Gizlilik ve Güvenlik › Yerel Ağ › “Erbaa Kurs” açık olmalı; açtıktan sonra uygulamayı yeniden başlatın.
          </span>
        )}
      </Alert>

      {report.teknik && <p className="break-words rounded-[var(--radius-sm)] bg-surface-2 px-2.5 py-1.5 font-mono text-[11.5px] text-ink-2">{report.teknik}</p>}

      <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
        {rows.map(([key, title]) => {
          const s = report.asamalar[key]
          return (
            <li key={key} className="flex items-start gap-2.5 px-3 py-2.5">
              <span className="mt-0.5 shrink-0">{stageMeta(s.durum)?.icon}</span>
              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span className="text-[13px] font-medium">{title}</span>
                  <span className="text-[12px] text-ink-3">{s.etiket}</span>
                  <StageBadge status={s.durum} />
                </span>
                {s.ayrinti && <span className="mt-0.5 block break-words text-[12.5px] text-ink-2">{s.ayrinti}</span>}
                {s.oneri && s.durum !== 'basarili' && key !== 'ag' && <span className="mt-0.5 block break-words text-[12px] text-ink-3">{s.oneri}</span>}
              </span>
            </li>
          )
        })}
      </ul>

      {report.soket && (
        <DescriptionList
          columns={2}
          items={[
            { label: 'Köprü IP (bu Mac)', value: report.soket.yerel_ip ?? '—' },
            { label: 'Cihaz', value: `${report.soket.uzak_ip}:${report.soket.uzak_port}` },
            { label: 'Bağlanma süresi', value: `${report.soket.sure_ms} ms` },
            { label: 'Hata kodu (errno)', value: report.soket.hata_no ? `${report.soket.hata_no} · ${report.soket.hata ?? ''}` : '—' },
          ]}
        />
      )}

      {report.cihaz && Object.keys(report.cihaz).length > 0 && (
        <DescriptionList columns={2} items={Object.entries(report.cihaz).map(([k, v]) => ({ label: CIHAZ_ETIKET[k] ?? k, value: String(v) }))} />
      )}
    </div>
  )
}

// ---------------------------------------------------------------- bağlantı ayarları (yalnız masaüstü)

function ConnectionPanel({ device, drivers }: { device: DeviceRow; drivers: DriverInfo[] }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ surucu: 'zk', ip: '', port: '', transport: 'tcp', comm_key: '', marka: '', model: '', makine_id: '1', baglanti_tipi: 'pull' })
  const [keyTouched, setKeyTouched] = useState(false)
  const [report, setReport] = useState<TestReport | null>(null)

  const { data: info, isLoading } = useQuery({
    queryKey: ['terminal', 'device', device.id],
    queryFn: () => api.get<TerminalDevice>(`/attendance/terminal/cihazlar/${device.id}`),
    refetchInterval: 30_000,
  })

  useEffect(() => {
    if (!info) return
    setForm({
      surucu: info.surucu ?? 'zk',
      ip: info.ip ?? '',
      port: info.port ? String(info.port) : '',
      transport: info.aktarim ?? 'tcp',
      comm_key: '',
      marka: info.marka ?? '',
      model: info.model ?? '',
      makine_id: String(info.makine_id ?? 1),
      baglanti_tipi: info.baglanti_tipi ?? 'pull',
    })
    setKeyTouched(false)
  }, [info?.surucu, info?.ip, info?.port, info?.aktarim, info?.marka, info?.model, device.id])

  const driver = drivers.find((d) => d.anahtar === form.surucu)

  const payload = () => ({
    surucu: form.surucu,
    ip: form.ip.trim(),
    port: form.port ? Number(form.port) : driver?.varsayilan_port ?? undefined,
    transport: form.transport,
    marka: form.marka || null,
    model: form.model || null,
    makine_id: Number(form.makine_id) || 1,
    baglanti_tipi: form.baglanti_tipi,
    ...(keyTouched ? { comm_key: form.comm_key } : {}),
  })

  const save = useMutation({
    mutationFn: () => api.post(`/attendance/terminal/cihazlar/${device.id}/ayar`, payload()),
    onSuccess: () => {
      toast.success('Terminal ayarı kaydedildi.')
      setKeyTouched(false)
      qc.invalidateQueries({ queryKey: ['terminal'] })
    },
    onError: (e) => toast.error(errorText(e, 'Kaydedilemedi.')),
  })

  // Formdaki (kaydedilmemiş olabilir) değerlerle test: kullanıcı önce dener, sonra kaydeder.
  const runTest = useMutation({
    mutationFn: () => api.post<TestReport>(`/attendance/terminal/cihazlar/${device.id}/test`, payload()),
    onSuccess: (res) => { setReport(res); qc.invalidateQueries({ queryKey: ['terminal'] }) },
    onError: (e) => toast.error(errorText(e, 'Test yapılamadı.')),
  })

  const pull = useMutation({
    mutationFn: (full: boolean) => api.post<Record<string, number | string>>(`/attendance/zk/cihazlar/${device.id}/cek`, { tam: full }),
    onSuccess: (res) => {
      toast.success(`${res.okunan} kayıt okundu · ${res.islenen} işlendi · ${res.eslesmeyen} eşleşmedi`)
      qc.invalidateQueries({ queryKey: ['terminal'] })
      qc.invalidateQueries({ queryKey: ['attendance'] })
    },
    onError: (e) => toast.error(errorText(e, 'Çekilemedi.')),
  })

  if (isLoading) return <Skeleton className="h-64 rounded-[var(--radius-lg)]" />

  const s = info?.durum
  const canPull = info?.surucu === 'zk' && !!info?.ip

  return (
    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
      <Panel title="Bağlantı" description="Cihazın yerel ağdaki adresi ve konuşulacak protokol (sürücü).">
        <div className="space-y-3.5">
          <Field label="Sürücü" required hint={driver ? driver.markalar.join(' · ') : undefined}>
            <Select
              value={form.surucu}
              onChange={(e) => {
                const next = drivers.find((d) => d.anahtar === e.target.value)
                setForm({
                  ...form,
                  surucu: e.target.value,
                  port: form.port || (next?.varsayilan_port ? String(next.varsayilan_port) : ''),
                  transport: next?.aktarimlar.includes(form.transport) ? form.transport : (next?.aktarimlar[0] ?? 'tcp'),
                  marka: form.marka || (e.target.value === 'perkotek_fk' ? 'Perkotek' : form.marka),
                  model: form.model || (e.target.value === 'perkotek_fk' ? 'YT33' : form.model),
                })
                setReport(null)
              }}
              options={drivers.map((d) => ({ value: d.anahtar, label: d.protokol_dogrulandi ? d.etiket : `${d.etiket} — protokol doğrulanmadı` }))}
            />
          </Field>

          {driver && !driver.protokol_dogrulandi && (
            <Alert tone="info" title="Bu sürücüde yalnız ağ testi ve ham tanılama çalışır">
              Protokol üretici belgesiyle doğrulanmadan cihaza tahmini komut gönderilmez; kayıt çekme şimdilik yapılamaz.
            </Alert>
          )}

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Üretici" optional>
              <Input value={form.marka} onChange={(e) => setForm({ ...form, marka: e.target.value })} placeholder="Perkotek" />
            </Field>
            <Field label="Model" optional>
              <Input value={form.model} onChange={(e) => setForm({ ...form, model: e.target.value })} placeholder="YT33" />
            </Field>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Bağlantı tipi" hint={form.baglanti_tipi === 'push' ? 'Cihaz bu Mac\'e bağlanır; ayar Push sekmesinde.' : 'Bu Mac cihaza bağlanır (IP + port).'}>
              <Select
                value={form.baglanti_tipi}
                onChange={(e) => setForm({ ...form, baglanti_tipi: e.target.value })}
                options={[{ value: 'pull', label: 'LAN / TCP Pull' }, { value: 'push', label: 'Server / Push' }]}
              />
            </Field>
            <Field label="Cihaz / Machine ID" hint="Cihaz menüsündeki makine numarası (fabrika değeri 1).">
              <Input value={form.makine_id} onChange={(e) => setForm({ ...form, makine_id: e.target.value })} inputMode="numeric" />
            </Field>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div className="sm:col-span-2">
              <Field label="IP adresi" required>
                <Input value={form.ip} onChange={(e) => setForm({ ...form, ip: e.target.value })} placeholder="192.168.1.50" inputMode="decimal" />
              </Field>
            </div>
            <Field label="Port" required>
              <Input value={form.port} onChange={(e) => setForm({ ...form, port: e.target.value })} placeholder={driver?.varsayilan_port ? String(driver.varsayilan_port) : 'ör. 5005'} inputMode="numeric" />
            </Field>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Bağlantı türü">
              <Select
                value={form.transport}
                onChange={(e) => setForm({ ...form, transport: e.target.value })}
                options={(driver?.aktarimlar.length ? driver.aktarimlar : ['tcp']).map((t) => ({ value: t, label: t.toUpperCase() }))}
              />
            </Field>
            <Field
              label="İletişim şifresi"
              optional
              hint={info?.sifre_tanimli ? 'Kayıtlı (gösterilmez). Değiştirmek için yeni değeri yazın.' : 'Yalnız rakam; fabrika değeri 0. Cihazın web arayüzü şifresi (admin) değildir.'}
            >
              <Input
                value={form.comm_key}
                onChange={(e) => { setForm({ ...form, comm_key: e.target.value }); setKeyTouched(true) }}
                placeholder={info?.sifre_tanimli ? '••••••' : '0'}
                inputMode="numeric"
                type="password"
                autoComplete="off"
              />
            </Field>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Button variant="primary" disabled={!form.ip} loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button>
            <Button icon={<Cable className="size-4" />} disabled={!form.ip || !(form.port || driver?.varsayilan_port)} loading={runTest.isPending} onClick={() => runTest.mutate()}>
              Bağlantıyı test et
            </Button>
          </div>

          {report && <StageTable report={report} />}

          {driver && driver.kurulum.length > 0 && (
            <details className="text-[12.5px] text-ink-2">
              <summary className="cursor-pointer select-none text-ink-3">Cihaz tarafında yapılacaklar</summary>
              <ol className="mt-2 list-decimal space-y-1 pl-5">{driver.kurulum.map((step, i) => <li key={i}>{step}</li>)}</ol>
            </details>
          )}
        </div>
      </Panel>

      <Panel title="Durum" description="Bu Mac'teki köprünün son ölçümleri.">
        <DescriptionList
          columns={2}
          items={[
            { label: 'Yerel köprü', value: <Badge tone="success" dot>Çalışıyor (bu Mac)</Badge> },
            { label: 'Köprü IP', value: s?.kopru_ip ?? '—' },
            { label: 'Cihaz IP', value: info?.ip ? `${info.ip}:${info.port ?? ''}` : '—' },
            { label: `TCP ${info?.port ?? ''}`, value: <StageBadge status={s?.tcp} /> },
            { label: 'Protokol', value: <StageBadge status={s?.protokol} /> },
            { label: 'Push dinleyicisi', value: <PushBadge /> },
            { label: 'Son test', value: s?.son_test ? relative(s.son_test) : 'Hiç' },
            { label: 'Son çekme', value: info?.son_cekme ? relative(info.son_cekme) : 'Hiç' },
          ]}
        />

        {s?.son_hata && (
          <Alert tone={s.tcp === 'basarili' ? 'warning' : 'danger'} className="mt-3" title="Son test" icon={<TriangleAlert className="size-4" />}>
            {s.son_hata}
          </Alert>
        )}
        {s?.macos_yerel_ag_izni_olasi && (
          <Alert tone="warning" className="mt-3" title="macOS Yerel Ağ izni gerekli olabilir" icon={<Lock className="size-4" />}>
            Sistem Ayarları › Gizlilik ve Güvenlik › Yerel Ağ › “Erbaa Kurs” açık olmalı. Açtıktan sonra uygulamayı kapatıp yeniden açın ve tekrar test edin.
          </Alert>
        )}

        {canPull ? (
          <>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <Button icon={<Download className="size-4" />} loading={pull.isPending} onClick={() => pull.mutate(false)}>Yeni kayıtları çek</Button>
              <Button variant="ghost" icon={<RefreshCw className="size-4" />} loading={pull.isPending} onClick={() => pull.mutate(true)}>Tümünü çek</Button>
            </div>
            <p className="mt-2 text-[12px] text-ink-3">"Tümünü çek" cihazdaki bütün kayıtları okur; daha önce işlenenler tekrar yazılmaz.</p>
          </>
        ) : (
          <p className="mt-3 text-[12.5px] text-ink-3">
            {info?.surucu === 'zk' ? 'Kayıt çekmek için önce IP adresini kaydedin.' : 'Bu sürücüde kayıt çekme henüz yok (protokol doğrulanmadı).'}
          </p>
        )}
      </Panel>
    </div>
  )
}

// ---------------------------------------------------------------- teşhis + ham TCP (geliştirici modu)

function hexPreview(hex: string, lines = 3) {
  return hex.split('\n').slice(0, lines).join('\n')
}

function RawRunCard({ run }: { run: RawRun }) {
  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2 text-[12.5px]">
        <Badge tone={run.soket.baglandi ? 'success' : 'danger'} dot>{run.soket.baglandi ? `Bağlandı · ${run.soket.sure_ms} ms` : run.soket.mesaj}</Badge>
        <span className="text-ink-3">{run.hedef}</span>
        <span className="text-ink-3">gönderilen {run.gonderilen_bayt} B · alınan {run.alinan_bayt} B</span>
        <span className="ml-auto flex gap-1.5">
          <Button size="sm" variant="ghost" icon={<FileDown className="size-3.5" />} onClick={() => api.download(`/attendance/terminal/ham-tani/${run.id}/indir`, undefined, 'tani.txt')}>.txt</Button>
          <Button size="sm" variant="ghost" icon={<FileDown className="size-3.5" />} onClick={() => api.download(`/attendance/terminal/ham-tani/${run.id}/indir`, { bicim: 'hex' }, 'tani.hex')}>.hex</Button>
        </span>
      </div>
      <p className="text-[12.5px] text-ink-2">{run.kapanis_metni}</p>
      {!run.soket.baglandi && run.soket.oneri && <p className="text-[12px] text-ink-3">{run.soket.oneri}</p>}
      {run.alinan_bayt > 0 && (
        <pre className="max-h-64 overflow-auto rounded-[var(--radius-md)] bg-surface-2 p-2.5 text-[11.5px] leading-relaxed scroll-thin whitespace-pre">{run.hex}</pre>
      )}
      <details className="text-[12px] text-ink-3">
        <summary className="cursor-pointer select-none">Zaman çizelgesi</summary>
        <ul className="mt-1 space-y-0.5 font-mono text-[11.5px]">
          {run.zaman_cizelgesi.map((e, i) => <li key={i}>{String(e.t_ms).padStart(6, ' ')} ms · {e.olay}</li>)}
        </ul>
      </details>
    </div>
  )
}

function DiagnosticsPanel({ device }: { device: DeviceRow }) {
  const qc = useQueryClient()
  const [raw, setRaw] = useState({ ip: '', port: '', sn: '5' })
  const [last, setLast] = useState<RawRun | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['terminal', 'diagnostics'],
    queryFn: () => api.get<Diagnostics>('/attendance/terminal/teshis'),
    refetchInterval: 30_000,
  })

  const current = data?.cihazlar.find((d) => d.id === device.id)

  useEffect(() => {
    if (current && !raw.ip) setRaw((r) => ({ ...r, ip: current.ip ?? '', port: current.port ? String(current.port) : '' }))
  }, [current?.ip, current?.port])

  const run = useMutation({
    mutationFn: () => api.post<RawRun>('/attendance/terminal/ham-tani', {
      ip: raw.ip.trim(), port: Number(raw.port), dinleme_sn: Number(raw.sn) || 5, cihaz_id: device.id,
    }),
    onSuccess: (res) => { setLast(res); qc.invalidateQueries({ queryKey: ['terminal'] }) },
    onError: (e) => toast.error(errorText(e, 'Tanılama yapılamadı.')),
  })

  if (isLoading) return <Skeleton className="h-64 rounded-[var(--radius-lg)]" />

  const lastPacket = current?.durum.son_paket
  const lastAtt = data?.son_yoklama

  return (
    <div className="space-y-3">
      <Panel title="Terminal teşhisi" description="Tek bakışta: bu Mac, cihaz ve köprü.">
        <DescriptionList
          columns={2}
          items={[
            { label: 'Köprü IP (bu Mac)', value: current?.durum.kopru_ip ?? data?.arayuzler.map((a) => a.ip).join(', ') ?? '—' },
            { label: 'Cihaz IP', value: current?.ip ?? '—' },
            { label: 'TCP portu', value: current?.port ?? '—' },
            { label: 'TCP durumu', value: <StageBadge status={current?.durum.tcp} /> },
            { label: 'Protokol durumu', value: <StageBadge status={current?.durum.protokol} /> },
            {
              label: 'Push dinleyicisi',
              value: data ? (
                <span className="flex flex-wrap items-center gap-1.5">
                  <Badge tone={PUSH_TONE[data.push_dinleyici.durum] ?? "neutral"} dot>{data.push_dinleyici.durum_metni}</Badge>
                  {data.push_dinleyici.durum === 'aktif' && (
                    <span className="text-[12px] text-ink-3">
                      {data.push_dinleyici.son_ip ? `son bağlantı ${data.push_dinleyici.son_ip}` : 'henüz bağlantı yok'} · {data.push_dinleyici.paket_sayisi} paket
                    </span>
                  )}
                </span>
              ) : '—',
            },
            { label: 'Son paket', value: lastPacket ? `${relative(lastPacket.zaman)} · ${lastPacket.bayt} B` : '—' },
            { label: 'Son yoklama', value: lastAtt ? `${dateTime(lastAtt.zaman)} · ${lastAtt.kullanici_no ?? '?'}${lastAtt.eslesti ? '' : ' (eşleşmedi)'}` : '—' },
            { label: 'Eşitleme bekleyen', value: data?.bekleyen_esitleme ?? '—' },
            { label: 'Eşleşme bekleyen okutma', value: data?.bekleyen_eslesme ?? 0 },
          ]}
        />
        {lastPacket && lastPacket.bayt > 0 && (
          <pre className="mt-3 overflow-x-auto rounded-[var(--radius-md)] bg-surface-2 p-2.5 text-[11.5px] scroll-thin whitespace-pre">{hexPreview(lastPacket.hex)}</pre>
        )}
        {current?.durum.son_hata && (
          <Alert tone={current.durum.tcp === 'basarili' ? 'warning' : 'danger'} className="mt-3" title="Son hata">{current.durum.son_hata}</Alert>
        )}
        {data?.push_dinleyici.aktarma_hatasi && (
          <Alert tone="danger" className="mt-3" title="Aktarma">{data.push_dinleyici.aktarma_hatasi}</Alert>
        )}
        {data?.push_dinleyici.durum === 'hata' && (
          <Alert tone="danger" className="mt-3" title={data.push_dinleyici.durum_metni}>{data.push_dinleyici.oneri}</Alert>
        )}
      </Panel>

      <PacketsPanel packets={data?.push_paketleri ?? []} />

      <Panel title="Bağlantıda dinle" description="Soketi açar, HİÇBİR ŞEY göndermez, cihazın kendiliğinden gönderdiği baytları kaydeder.">
        <div className="space-y-3">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div className="sm:col-span-2">
              <Field label="IP adresi" required><Input value={raw.ip} onChange={(e) => setRaw({ ...raw, ip: e.target.value })} inputMode="decimal" placeholder="192.168.1.50" /></Field>
            </div>
            <Field label="Port" required><Input value={raw.port} onChange={(e) => setRaw({ ...raw, port: e.target.value })} inputMode="numeric" /></Field>
            <Field label="Dinleme (sn)"><Input value={raw.sn} onChange={(e) => setRaw({ ...raw, sn: e.target.value })} inputMode="decimal" /></Field>
          </div>
          <Button variant="primary" icon={<Activity className="size-4" />} disabled={!raw.ip || !raw.port} loading={run.isPending} onClick={() => run.mutate()}>Dinlemeyi başlat</Button>
          {last && <RawRunCard run={last} />}
        </div>
      </Panel>

      <Panel title="Son 50 ham kayıt" description="Bu Mac'te tutulur; sunucuya gönderilmez.">
        {!data?.ham_tanilamalar.length ? (
          <EmptyState icon={<Radio />} title="Henüz ham kayıt yok" description="Ham TCP tanılaması çalıştırınca burada listelenir." />
        ) : (
          <ul className="divide-y divide-line">
            {data.ham_tanilamalar.map((r) => (
              <li key={r.id} className="py-2.5">
                <p className="mb-1.5 text-[12px] text-ink-3">{dateTime(r.baslangic)}</p>
                <RawRunCard run={r} />
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <DevTools device={device} ip={current?.ip ?? ''} port={current?.port ?? null} machineId={current?.makine_id ?? 1} keySet={!!current?.sifre_tanimli} />
    </div>
  )
}

// ---------------------------------------------------------------- push dinleyicisi (yalnız masaüstü)

const PUSH_TONE: Record<PushStatus['durum'], Tone> = { aktif: 'success', kapali: 'neutral', hata: 'danger', baslatiliyor: 'warning' }

const DIRECTION_LABEL: Record<PacketDirection, string> = {
  device_to_bridge: 'Cihaz → Köprü',
  device_to_upstream: 'Cihaz → Köprü → Sunucu',
  upstream_to_device: 'Sunucu → Köprü → Cihaz',
}

function usePushStatus() {
  return useQuery({ queryKey: ['terminal', 'push'], queryFn: () => api.get<PushStatus>('/attendance/terminal/push'), refetchInterval: 10_000 })
}

function PushBadge() {
  const { data } = usePushStatus()
  if (!data) return <Badge tone="neutral">—</Badge>
  return <Badge tone={PUSH_TONE[data.durum] ?? "neutral"} dot>{data.durum_metni}</Badge>
}

function PushPanel() {
  const qc = useQueryClient()
  const { data, isLoading } = usePushStatus()
  const [form, setForm] = useState({ acik: false, port: '7005', aktar: false, aktar_ip: '', aktar_port: '7005' })

  useEffect(() => {
    if (!data) return
    setForm({
      acik: data.acik,
      port: String(data.port),
      aktar: !!data.aktar_ip,
      aktar_ip: data.aktar_ip ?? '',
      aktar_port: data.aktar_port ? String(data.aktar_port) : '7005',
    })
  }, [data?.acik, data?.port, data?.aktar_ip, data?.aktar_port])

  const save = useMutation({
    mutationFn: () => api.post('/attendance/terminal/push', {
      acik: form.acik,
      port: Number(form.port) || 7005,
      aktar_ip: form.aktar && form.aktar_ip.trim() ? form.aktar_ip.trim() : null,
      aktar_port: form.aktar && form.aktar_ip.trim() ? Number(form.aktar_port) || null : null,
    }),
    onSuccess: () => { toast.success(form.acik ? 'Kaydedildi. Dinleyici birkaç saniye içinde başlar.' : 'Push dinleyicisi kapatıldı.'); qc.invalidateQueries({ queryKey: ['terminal'] }) },
    onError: (e) => toast.error(errorText(e, 'Kaydedilemedi.')),
  })

  if (isLoading || !data) return <Skeleton className="h-48 rounded-[var(--radius-lg)]" />

  const menu = data.cihaz_menusu

  return (
    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
      <Panel title="Push dinleyicisi" description="Cihazın kendisi bu Mac'e bağlanıp veri gönderir (PUSH kipi).">
        <div className="space-y-3.5">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={PUSH_TONE[data.durum] ?? "neutral"} dot>{data.durum_metni}</Badge>
            {data.durum === 'aktif' && (
              <span className="text-[12.5px] text-ink-3">
                {data.son_ip ? `son bağlantı ${data.son_ip}${data.son_zaman ? ` · ${relative(data.son_zaman)}` : ''}` : 'henüz bağlantı yok'} · {data.paket_sayisi} paket
              </span>
            )}
          </div>
          {data.durum === 'hata' && <Alert tone="danger" title={data.durum_metni}>{data.oneri}</Alert>}
          {data.aktarma_hatasi && <Alert tone="danger" title="Aktarma">{data.aktarma_hatasi}</Alert>}

          <Switch checked={form.acik} onChange={(v) => setForm({ ...form, acik: v })} label="Push verisini bu Mac'te dinle" />
          <Field label="Dinleme portu" hint="Önerilen 7005. Cihaz menüsündeki push portuyla aynı olmalı.">
            <Input value={form.port} onChange={(e) => setForm({ ...form, port: e.target.value })} inputMode="numeric" className="sm:max-w-[160px]" />
          </Field>

          <div className="rounded-[var(--radius-md)] ring-1 ring-line p-3 space-y-3">
            <Switch checked={form.aktar} onChange={(v) => setForm({ ...form, aktar: v })} label="Gelişmiş: gelen veriyi başka bir sunucuya da aktar (isteğe bağlı)" />
            <p className="text-[12.5px] text-ink-3">
              Normalde kapalı kalır — PDKS bu uygulamanın içindedir. Yalnız başka bir sistemin de aynı veriyi alması gerekiyorsa açın:
              cihazdan gelen baytlar değiştirilmeden o adrese iletilir, yanıtı cihaza döner, iki yön ayrı kaydedilir.
            </p>
            {form.aktar && (
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="sm:col-span-2">
                  <Field label="Aktarılacak IP" required><Input value={form.aktar_ip} onChange={(e) => setForm({ ...form, aktar_ip: e.target.value })} placeholder="192.168.68.5" inputMode="decimal" /></Field>
                </div>
                <Field label="Port" required><Input value={form.aktar_port} onChange={(e) => setForm({ ...form, aktar_port: e.target.value })} inputMode="numeric" /></Field>
              </div>
            )}
          </div>

          <Button variant="primary" loading={save.isPending} disabled={form.aktar && !form.aktar_ip.trim()} onClick={() => save.mutate()}>Kaydet</Button>
        </div>
      </Panel>

      <Panel title="Cihaz menüsünde yapılacaklar" description="Uygulama cihazın ayarını değiştirmez; bu değerleri cihazın web arayüzüne siz girersiniz.">
        {menu ? (
          <DescriptionList
            columns={1}
            items={[
              { label: 'Server IP', value: <code className="text-[13px]">{menu.server_ip}</code> },
              { label: 'Push address', value: <code className="text-[13px]">{menu.push_address}</code> },
              { label: 'Server Port / Push Port', value: <code className="text-[13px]">{menu.port}</code> },
              { label: 'Push', value: <code className="text-[13px]">{menu.push}</code> },
            ]}
          />
        ) : (
          <p className="text-[12.5px] text-ink-3">Köprü IP'si henüz bilinmiyor. Önce Bağlantı bölümünde "Bağlantıyı test et" düğmesine basın.</p>
        )}
        <ul className="mt-3 list-disc space-y-1.5 pl-5 text-[12.5px] text-ink-2">
          <li>Köprü IP'si bu Mac'in yerel ağ adresidir (soket testindeki yerel kaynak IP). Mac'in IP'si değişirse cihazdaki adres de güncellenmeli; yönlendiricide bu Mac'e sabit IP verin.</li>
          <li>macOS ilk dinlemede “Erbaa Kurs gelen ağ bağlantılarını kabul etsin mi?” diye sorabilir → <strong>İzin Ver</strong>. Sorulmadıysa: Sistem Ayarları › Ağ › Güvenlik Duvarı › Seçenekler'de Erbaa Kurs “Gelen bağlantılara izin ver” olmalı.</li>
          <li>Cihaz bu Mac'e bağlandığında Teşhis bölümünde paketler görünür.</li>
        </ul>
      </Panel>
    </div>
  )
}

function PacketsPanel({ packets }: { packets: PacketRow[] }) {
  const [openId, setOpenId] = useState<number | null>(null)
  const { data: detail, isFetching } = useQuery({
    queryKey: ['terminal', 'packet', openId],
    queryFn: () => api.get<PacketDetail>(`/attendance/terminal/paketler/${openId}`),
    enabled: openId !== null,
  })

  return (
    <Panel title="Push paketleri (son 50)" description="Cihazın bu Mac'e gönderdiği ham veriler. Silinmez; sunucuya gönderilmez.">
      {!packets.length ? (
        <EmptyState icon={<Radio />} title="Henüz push paketi yok" description="Push dinleyicisi açık ve cihaz bu Mac'e yönlendirilmişse okutma yapınca burada görünür." />
      ) : (
        <ul className="divide-y divide-line">
          {packets.map((p) => (
            <li key={p.id}>
              <button type="button" onClick={() => setOpenId(p.id)} className="flex w-full flex-wrap items-center gap-x-2 gap-y-1 py-2 text-left hover:bg-surface-2 rounded-[var(--radius-sm)] px-1.5">
                <span className="tabular text-[12px] text-ink-3">#{p.id}</span>
                <Badge tone={p.direction === 'upstream_to_device' ? 'info' : 'accent'}>{DIRECTION_LABEL[p.direction]}</Badge>
                <span className="text-[12.5px]">{p.remote_ip}</span>
                <span className="text-[12px] text-ink-3">{dateTime(p.received_at)} · {p.byte_count} B · {p.format}{p.http_method ? ` ${p.http_method} ${p.http_path ?? ''}` : ''}</span>
                {p.duplicate_of && <Badge tone="neutral">tekrar</Badge>}
                {p.upstream && p.upstream_status && p.upstream_status !== 'connected' && <Badge tone="danger">aktarılamadı</Badge>}
              </button>
            </li>
          ))}
        </ul>
      )}

      <Modal open={openId !== null} onClose={() => setOpenId(null)} size="xl" title={`Paket #${openId ?? ''}`} description={detail?.yon_metni}>
        {!detail || isFetching ? (
          <Skeleton className="h-40" />
        ) : (
          <div className="space-y-3">
            <DescriptionList
              columns={2}
              items={[
                { label: 'Kaynak', value: `${detail.kaynak_ip}:${detail.kaynak_port ?? ''}` },
                { label: 'Zaman', value: dateTime(detail.zaman) },
                { label: 'Bayt', value: `${detail.bayt}${detail.kesildi ? ' (1 MB sınırında kesildi)' : ''}` },
                { label: 'Biçim', value: detail.bicim },
                { label: 'Aktarma', value: detail.aktarma ? `${detail.aktarma} · ${detail.aktarma_durumu ?? '?'}` : 'Yok (yalnız dinleme)' },
                { label: 'Tekrar', value: detail.tekrar_of ? `Evet — ilk kayıt #${detail.tekrar_of}` : 'Hayır' },
              ]}
            />
            <p className="break-all text-[11.5px] text-ink-3">SHA-256: {detail.sha256}</p>
            {detail.not && <p className="text-[12px] text-ink-3">{detail.not}</p>}
            {detail.http && (
              <div>
                <p className="mb-1 text-[12.5px] font-medium">HTTP</p>
                <pre className="max-h-48 overflow-auto rounded-[var(--radius-md)] bg-surface-2 p-2.5 text-[11.5px] scroll-thin whitespace-pre">{`${detail.http.metot} ${detail.http.yol}\n${detail.http.basliklar ?? ''}\n\n${detail.http.govde_ascii}`}</pre>
              </div>
            )}
            <div>
              <p className="mb-1 text-[12.5px] font-medium">HEX</p>
              <pre className="max-h-64 overflow-auto rounded-[var(--radius-md)] bg-surface-2 p-2.5 text-[11.5px] scroll-thin whitespace-pre">{detail.hex || '(boş)'}</pre>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button size="sm" icon={<FileDown className="size-3.5" />} onClick={() => api.download(`/attendance/terminal/paketler/${detail.id}/indir`, undefined, `paket-${detail.id}.txt`)}>.txt indir</Button>
              <Button size="sm" variant="ghost" icon={<FileDown className="size-3.5" />} onClick={() => api.download(`/attendance/terminal/paketler/${detail.id}/indir`, { bicim: 'hex' }, `paket-${detail.id}.hex`)}>.hex indir</Button>
            </div>
          </div>
        )}
      </Modal>
    </Panel>
  )
}

// ---------------------------------------------------------------- web: salt okunur

function ReadOnlyView({ devices }: { devices: DeviceWithBridge[] }) {
  return (
    <div className="space-y-3">
      <Alert tone="info" title="Terminal ayarı yalnız Mac'teki masaüstü uygulamasında" icon={<Lock className="size-4" />}>
        Web sunucusu kurumun yerel ağındaki cihaza ulaşamaz ve bağlanmayı denemez. Ekleme, bağlantı testi, ham tanılama,
        kayıt çekme ve eşleştirme masaüstü uygulamasından yapılır; burada yalnız son durum görünür.
      </Alert>
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        {devices.map((d) => {
          const b = d.bridge
          return (
            <Panel key={d.id} title={d.name} description={d.location ?? undefined}>
              <DescriptionList
                columns={2}
                items={[
                  { label: 'Bağlantı', value: b?.connected ? <Badge tone="success" dot>Bağlı</Badge> : b ? <Badge tone="danger" dot>Bağlı değil</Badge> : <Badge tone="neutral">Rapor yok</Badge> },
                  { label: 'Hangi Mac üzerinden', value: b?.via ?? '—' },
                  { label: 'Son okutma', value: d.last_event_at ? relative(d.last_event_at) : '—' },
                  { label: 'Son rapor', value: b?.reported_at ? relative(b.reported_at) : '—' },
                ]}
              />
              {b?.error && <Alert tone="danger" className="mt-3" title="Son hata">{b.error}</Alert>}
            </Panel>
          )
        })}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------- cihaz kullanıcıları

function DeviceUsersPanel({ device }: { device: DeviceRow }) {
  const qc = useQueryClient()
  const [fetched, setFetched] = useState(false)
  const [linkFor, setLinkFor] = useState<ZkUserRow | null>(null)
  const [picked, setPicked] = useState<StudentHit | null>(null)

  const { data, isFetching, refetch, error } = useQuery({
    queryKey: ['zk', 'users', device.id],
    retry: false,
    queryFn: () => api.get<{ data: ZkUserRow[]; hata?: { mesaj: string; oneri: string } }>(`/attendance/zk/cihazlar/${device.id}/kullanicilar`),
    enabled: fetched,
  })

  const link = useMutation({
    mutationFn: (payload: { kullanici_no: string; ogrenci_id: number }) => api.post('/attendance/zk/eslemeler', payload),
    onSuccess: () => {
      toast.success('Eşleme kaydedildi.')
      setLinkFor(null)
      setPicked(null)
      qc.invalidateQueries({ queryKey: ['zk'] })
      refetch()
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Eşlenemedi.'),
  })

  const columns: Column<ZkUserRow>[] = [
    { key: 'no', header: 'Kullanıcı no', cell: (r) => <code className="text-[12.5px]">{r.kullanici_no}</code> },
    { key: 'ad', header: 'Cihazdaki ad', cell: (r) => <span className="font-medium">{r.ad}</span> },
    { key: 'yetki', header: 'Yetki', cell: (r) => <Badge tone="neutral">{r.yetki}</Badge> },
    {
      key: 'ogrenci',
      header: 'Eşleşen öğrenci',
      cell: (r) => r.eslesen_ogrenci
        ? <span><span className="font-medium">{r.eslesen_ogrenci.ad}</span> <span className="text-ink-3 text-[12px] tabular">· {r.eslesen_ogrenci.no}</span></span>
        : <span className="text-ink-3">Eşleşmedi</span>,
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      cell: (r) => r.eslesen_ogrenci ? null : (
        <div className="flex flex-wrap items-center justify-end gap-1.5">
          {r.oneriler.slice(0, 2).map((s) => (
            <Button key={s.id} size="sm" variant="soft" loading={link.isPending} onClick={() => link.mutate({ kullanici_no: r.kullanici_no, ogrenci_id: s.id })}>
              {s.ad} <span className="opacity-60">%{s.benzerlik}</span>
            </Button>
          ))}
          <Button size="sm" icon={<Link2 className="size-3.5" />} onClick={() => setLinkFor(r)}>Eşle</Button>
        </div>
      ),
    },
  ]

  if (!fetched) {
    return (
      <EmptyState
        icon={<Fingerprint />}
        title="Cihazdaki kullanıcıları getirin"
        description="Cihaza bağlanıp kayıtlı kullanıcı numaralarını okur ve ada göre öğrenci önerir. Bu işlem yalnız cihazla aynı ağdayken çalışır."
        action={<Button variant="primary" icon={<Search className="size-4" />} onClick={() => setFetched(true)}>Kullanıcıları getir</Button>}
      />
    )
  }

  if (error) {
    return (
      <Alert tone="warning" title="Cihaz kullanıcıları okunamadı" icon={<TriangleAlert className="size-4" />}>
        {errorText(error, 'Bu sürücü cihaz kullanıcılarını okuyamıyor.')} Eşleştirmeyi Kimlik Eşlemeleri sekmesinden kullanıcı numarasıyla elle yapabilirsiniz.
      </Alert>
    )
  }

  return (
    <div>
      {data?.hata && (
        <Alert tone="danger" className="mb-3" title={data.hata.mesaj} icon={<TriangleAlert className="size-4" />}>{data.hata.oneri}</Alert>
      )}

      <DataTable
        storageKey="zk-device-users"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.kullanici_no}
        loading={isFetching}
        toolbar={
          <div className="flex flex-1 items-center gap-2">
            <p className="text-[12.5px] text-ink-3">Cihazda {(data?.data.length ?? 0).toLocaleString('tr-TR')} kullanıcı</p>
            <Button className="ml-auto" size="sm" icon={<RefreshCw className="size-3.5" />} loading={isFetching} onClick={() => refetch()}>Yenile</Button>
          </div>
        }
        empty={<EmptyState icon={<Fingerprint />} title="Cihazda kullanıcı yok" description="Önce cihazda öğrencilerin parmak izini kaydedin." />}
      />

      <Modal
        open={linkFor !== null}
        onClose={() => { setLinkFor(null); setPicked(null) }}
        title={`${linkFor?.kullanici_no} numaralı kullanıcıyı eşle`}
        description={linkFor?.ad ? `Cihazdaki ad: ${linkFor.ad}` : undefined}
        footer={
          <>
            <Button variant="ghost" onClick={() => { setLinkFor(null); setPicked(null) }}>Vazgeç</Button>
            <Button variant="primary" disabled={!picked} loading={link.isPending} onClick={() => link.mutate({ kullanici_no: linkFor!.kullanici_no, ogrenci_id: picked!.id })}>Eşle</Button>
          </>
        }
      >
        <div className="space-y-3">
          {picked ? (
            <div className="flex items-center gap-3 rounded-[var(--radius-md)] ring-1 ring-line px-3 py-2.5">
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[13.5px] font-medium">{picked.full_name}</span>
                <span className="block text-[12px] text-ink-3 tabular">Öğrenci no: {picked.student_no}</span>
              </span>
              <Button size="icon-sm" variant="ghost" onClick={() => setPicked(null)} aria-label="Değiştir"><X className="size-4" /></Button>
            </div>
          ) : (
            <StudentSearch onPick={setPicked} />
          )}
          <p className="text-[12px] text-ink-3">
            Eşleme yanlış yapılırsa yanlış velinin telefonuna "giriş yaptı" bildirimi gider. Öğrenciyi dikkatle seçin.
          </p>
        </div>
      </Modal>
    </div>
  )
}

// ---------------------------------------------------------------- bekleyen okutmalar

function PendingPanel() {
  const list = useListState({})

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['zk', 'pending', list.query],
    queryFn: () => api.get<Paginated<PendingRow>>('/attendance/zk/bekleyenler', list.query),
    placeholderData: keepPreviousData,
    refetchInterval: 60_000,
  })

  const columns: Column<PendingRow>[] = [
    { key: 'no', header: 'Kullanıcı no', cell: (r) => <code className="text-[12.5px]">{r.kullanici_no ?? '—'}</code> },
    { key: 'zaman', header: 'Zaman', cell: (r) => <span className="tabular">{dateTime(r.zaman)}</span> },
    { key: 'yon', header: 'Yön', cell: (r) => <Badge tone={r.yon === 'EXIT' ? 'warning' : 'success'}>{r.yon === 'EXIT' ? 'Çıkış' : r.yon === 'ENTRY' ? 'Giriş' : r.yon}</Badge> },
    { key: 'cihaz', header: 'Cihaz', cell: (r) => r.cihaz ?? '—' },
  ]

  return (
    <div>
      <Alert tone="info" className="mb-3" title="Eşleşmeyen okutmalar kaybolmaz">
        Cihaz kullanıcı numarası hiçbir öğrenciye bağlı değilse okutma burada bekler. Kullanıcıyı öğrenciyle eşledikten
        sonra gelen okutmalar otomatik eşleşir; buradaki eski kayıtlar Canlı Giriş/Çıkış ekranından tek tek bağlanabilir.
      </Alert>

      <DataTable
        storageKey="zk-pending"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        empty={<EmptyState icon={<CheckCircle2 />} title="Bekleyen okutma yok" description="Cihazdan gelen bütün okutmalar bir öğrenciye bağlandı." />}
      />
    </div>
  )
}

// ---------------------------------------------------------------- sekme

export default function TerminalBridge() {
  const local = isLocalNode()
  const [deviceId, setDeviceId] = useState<number | null>(null)
  const [section, setSection] = useState<'baglanti' | 'push' | 'teshis' | 'kullanicilar' | 'bekleyen'>('baglanti')

  const { data, isLoading } = useQuery({ queryKey: ['attendance', 'devices'], queryFn: () => api.get<{ data: DeviceWithBridge[] }>('/attendance/devices') })
  const { data: driverData } = useQuery({
    queryKey: ['terminal', 'drivers'],
    queryFn: () => api.get<{ data: DriverInfo[] }>('/attendance/terminal/suruculer'),
    enabled: local,
    staleTime: 5 * 60_000,
  })

  const devices = useMemo(() => data?.data.filter((d) => d.kind === 'fingerprint' || d.kind === 'face' || d.kind === 'rfid') ?? [], [data])
  const selected = devices.find((d) => d.id === deviceId) ?? devices[0] ?? null

  if (isLoading) return <Skeleton className="h-64 rounded-[var(--radius-lg)]" />

  if (!devices.length) {
    return (
      <EmptyState
        icon={<Cable />}
        title="Terminal cihazı yok"
        description={local ? 'Önce Cihazlar sekmesinden türü “Parmak izi” ya da “Yüz tanıma” olan bir cihaz ekleyin, sonra bağlantısını burada kurun.' : 'Terminaller kurumdaki Mac uygulamasından eklenir.'}
      />
    )
  }

  if (!local) return <ReadOnlyView devices={devices} />

  const sections = [['baglanti', 'Bağlantı'], ['push', 'Push'], ['teshis', 'Teşhis'], ['kullanicilar', 'Cihaz kullanıcıları'], ['bekleyen', 'Bekleyen eşleştirme']] as const

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <div className="w-full sm:w-[280px]">
          <Select
            value={String(selected?.id ?? '')}
            onChange={(e) => setDeviceId(Number(e.target.value))}
            options={devices.map((d) => ({ value: String(d.id), label: d.location ? `${d.name} · ${d.location}` : d.name }))}
          />
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          {sections.map(([value, label]) => (
            <Button key={value} size="sm" variant={section === value ? 'primary' : 'ghost'} onClick={() => setSection(value)}>{label}</Button>
          ))}
        </div>
      </div>

      {selected && section === 'baglanti' && <ConnectionPanel key={selected.id} device={selected} drivers={driverData?.data ?? []} />}
      {section === 'push' && <PushPanel />}
      {selected && section === 'teshis' && <DiagnosticsPanel key={selected.id} device={selected} />}
      {selected && section === 'kullanicilar' && <DeviceUsersPanel key={selected.id} device={selected} />}
      {section === 'bekleyen' && <PendingPanel />}
    </div>
  )
}
