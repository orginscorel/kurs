import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Binary, ClipboardCopy, FileDown, FlaskConical, Plus, Send, Trash2, Upload } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { Panel, DescriptionList } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, type Tone } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import type { DeviceRow } from './types'

/*
| GELİŞTİRİCİ ARAÇLARI (Terminal Teşhis › Geliştirici modu, varsayılan KAPALI; yalnız masaüstü, devices.manage):
|   • Ham TCP oturumu + manuel HEX gönderici (tek bağlantıda sırayla; canlı durum geçişleri)
|   • RAW TCP log (kopyala / indir / temizle, IP ve iletişim şifresi maskeleme)
|   • Protokol analizi (örnekler, bayt bayt karşılaştırma, HAR içe aktarma) — tahmindir, sürücüye otomatik aktarılmaz.
*/

type SessionResult = {
  oturum: string
  durum: 'ok' | 'hata'
  hedef: string
  soket: { baglandi: boolean; yerel_ip: string | null; yerel_port: number | null; sure_ms: number; mesaj: string; oneri: string }
  durumlar: { t_ms: number; durum: string; not: string }[]
  son_durum: string | null
  tx_bayt: number
  rx_bayt: number
  paketler: { sira: number; tx_bayt: number; tx_hex: string; rx_bayt: number; rx_dokum: string; rx_ascii: string; yanit_ms: number | null; durum: string }[]
  alinan_hex: string
  zaman_asimi_sn: number
  sure_ms: number
  bitis: string
}
type LogRow = { id: number; session_id: string; occurred_at: string; t_ms: number; kind: 'TX' | 'RX' | 'STATE'; state: string | null; source: string | null; target: string | null; length: number; payload_hex: string | null; note: string | null; ascii: string | null }
type Sample = { id: number; name: string; tx_hex: string | null; rx_hex: string | null; note: string | null; source: string; created_at: string }
type Compare = {
  uyari: string
  hata?: string
  boylar?: number[]
  sutunlar?: { ofset: number; degerler: (string | null)[]; sabit: boolean }[]
  oneriler?: { alan: string; ofset: number; uzunluk: number | null; aciklama: string }[]
  ornekler?: { id: number; ad: string }[]
}

const STATE_TONE: Record<string, Tone> = {
  Connecting: 'neutral', Connected: 'success', 'Waiting Response': 'info', 'Data Received': 'success',
  Timeout: 'warning', 'Connection Closed': 'neutral', 'Connection Reset': 'danger', Error: 'danger',
}
const err = (e: unknown, f: string) => (e instanceof ApiError ? e.firstError() : f)
const spaced = (hex: string | null) => (hex ? hex.replace(/(..)/g, '$1 ').trim() : '')

async function fetchText(path: string): Promise<string> {
  const r = await fetch('/api/v1' + path, { credentials: 'same-origin', headers: { Accept: 'text/plain' } })
  if (!r.ok) throw new Error('Log alınamadı.')
  return r.text()
}

// ---------------------------------------------------------------- oturum + HEX gönderici

function SessionPanel({ device, ip, port, machineId, keySet }: { device: DeviceRow; ip: string; port: number | null; machineId: number; keySet: boolean }) {
  const qc = useQueryClient()
  const [f, setF] = useState({ ip, port: port ? String(port) : '', paketler: '', bekle: '3', dinle: '0' })
  const [res, setRes] = useState<SessionResult | null>(null)

  const run = useMutation({
    mutationFn: () => api.post<SessionResult>('/attendance/terminal/oturum', {
      ip: f.ip.trim(), port: Number(f.port), paketler: f.paketler, yanit_bekleme_sn: Number(f.bekle) || 3, once_dinle_sn: Number(f.dinle) || 0, cihaz_id: device.id,
    }),
    onSuccess: (r) => { setRes(r); qc.invalidateQueries({ queryKey: ['terminal', 'rawlog'] }) },
    onError: (e) => toast.error(err(e, 'Oturum çalıştırılamadı.')),
  })

  return (
    <Panel title="Canlı soket + manuel HEX gönderici" description="Her satır bir paket; hepsi AYNI bağlantıda sırayla gönderilir, her birinin yanıtı beklenir. Uygulama kendisi paket üretmez.">
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div className="col-span-2"><Field label="IP"><Input value={f.ip} onChange={(e) => setF({ ...f, ip: e.target.value })} inputMode="decimal" /></Field></div>
          <Field label="Port"><Input value={f.port} onChange={(e) => setF({ ...f, port: e.target.value })} inputMode="numeric" /></Field>
          <Field label="Yanıt bekleme (sn)"><Input value={f.bekle} onChange={(e) => setF({ ...f, bekle: e.target.value })} inputMode="decimal" /></Field>
        </div>
        <Field label="HEX paketler" hint="Boşluk/virgül serbest, her bayt iki hane (ör. A5 5A 01 00). Boş bırakırsanız yalnız dinlenir.">
          <Textarea rows={3} value={f.paketler} onChange={(e) => setF({ ...f, paketler: e.target.value })} className="font-mono" placeholder={'A5 5A 01 00\n...'} />
        </Field>
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Önce dinle (sn)"><Input value={f.dinle} onChange={(e) => setF({ ...f, dinle: e.target.value })} inputMode="decimal" className="w-24" /></Field>
          <Button variant="primary" icon={<Send className="size-4" />} disabled={!f.ip || !f.port} loading={run.isPending} onClick={() => run.mutate()}>
            {f.paketler.trim() ? 'Gönder' : 'Bağlan ve dinle'}
          </Button>
        </div>

        {res && (
          <div className="space-y-3">
            <div className="flex flex-wrap gap-1.5">
              {res.durumlar.map((s, i) => <Badge key={i} tone={STATE_TONE[s.durum] ?? 'neutral'}>+{s.t_ms} ms · {s.durum}</Badge>)}
            </div>
            <DescriptionList
              columns={2}
              items={[
                { label: 'Son durum', value: res.son_durum ?? '—' },
                { label: 'Yerel IP/Port', value: res.soket.yerel_ip ? `${res.soket.yerel_ip}:${res.soket.yerel_port}` : '—' },
                { label: 'Uzak IP/Port', value: res.hedef },
                { label: 'Bağlanma süresi', value: res.soket.baglandi ? `${res.soket.sure_ms} ms` : res.soket.mesaj },
                { label: 'TX / RX', value: `${res.tx_bayt} B / ${res.rx_bayt} B` },
                { label: 'Oturum süresi', value: `${res.sure_ms} ms` },
                { label: 'Zaman aşımı', value: `${res.zaman_asimi_sn} sn` },
                { label: 'Son paket', value: dateTime(res.bitis) },
                { label: 'Device ID', value: machineId },
                { label: 'İletişim şifresi', value: keySet ? 'Ayarlı (gösterilmez)' : 'Boş' },
              ]}
            />
            {!res.soket.baglandi && res.soket.oneri && <Alert tone="danger" title={res.soket.mesaj}>{res.soket.oneri}</Alert>}
            {res.paketler.map((p) => (
              <div key={p.sira} className="rounded-[var(--radius-md)] ring-1 ring-line p-2.5 text-[12.5px] space-y-1">
                <p><Badge tone="accent">TX #{p.sira}</Badge> {p.tx_bayt} B <code className="break-all text-[11.5px]">{spaced(p.tx_hex)}</code></p>
                <p>
                  <Badge tone={p.durum === 'yanit' ? 'success' : 'warning'}>RX</Badge> {p.rx_bayt} B
                  {p.yanit_ms !== null && <span className="text-ink-3"> · yanıt {p.yanit_ms} ms</span>}
                  {p.durum !== 'yanit' && <span className="text-ink-3"> · {p.durum === 'kapandi' ? 'bağlantı kapandı' : 'yanıt yok (zaman aşımı)'}</span>}
                </p>
                {p.rx_bayt > 0 && <pre className="max-h-48 overflow-auto rounded bg-surface-2 p-2 text-[11px] scroll-thin whitespace-pre">{p.rx_dokum}</pre>}
              </div>
            ))}
            {res.paketler.length === 0 && res.alinan_hex && <pre className="max-h-48 overflow-auto rounded bg-surface-2 p-2 text-[11px] scroll-thin whitespace-pre">{res.alinan_hex}</pre>}
          </div>
        )}
      </div>
    </Panel>
  )
}

// ---------------------------------------------------------------- RAW log

function RawLogPanel() {
  const qc = useQueryClient()
  const [maskIp, setMaskIp] = useState(false)
  const [maskKey, setMaskKey] = useState(true)
  const { data } = useQuery({ queryKey: ['terminal', 'rawlog'], queryFn: () => api.get<{ data: LogRow[] }>('/attendance/terminal/raw-log', { limit: 500 }), refetchInterval: 15_000 })
  const query = { maske_ip: maskIp ? 1 : 0, maske_sifre: maskKey ? 1 : 0 }

  const clear = useMutation({
    mutationFn: () => api.delete('/attendance/terminal/raw-log'),
    onSuccess: () => { toast.success('RAW log temizlendi.'); qc.invalidateQueries({ queryKey: ['terminal', 'rawlog'] }) },
    onError: (e) => toast.error(err(e, 'Temizlenemedi.')),
  })
  const toSample = useMutation({
    mutationFn: (id: number) => api.post(`/attendance/terminal/ornekler/log/${id}`, {}),
    onSuccess: () => { toast.success('Analize eklendi.'); qc.invalidateQueries({ queryKey: ['terminal', 'samples'] }) },
    onError: (e) => toast.error(err(e, 'Eklenemedi.')),
  })

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(await fetchText(`/attendance/terminal/raw-log/indir?maske_ip=${query.maske_ip}&maske_sifre=${query.maske_sifre}`))
      toast.success('RAW log panoya kopyalandı.')
    } catch {
      toast.error('Kopyalanamadı.')
    }
  }

  return (
    <Panel title="RAW TCP log" description="Tüm oturumların TX/RX baytları ve durum geçişleri (ms). Bu Mac'te tutulur; sunucuya gönderilmez.">
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <label className="flex items-center gap-1.5 text-[12.5px]"><input type="checkbox" checked={maskKey} onChange={(e) => setMaskKey(e.target.checked)} /> İletişim şifresini maskele</label>
        <label className="flex items-center gap-1.5 text-[12.5px]"><input type="checkbox" checked={maskIp} onChange={(e) => setMaskIp(e.target.checked)} /> IP adreslerini maskele</label>
        <span className="ml-auto flex flex-wrap gap-1.5">
          <Button size="sm" icon={<ClipboardCopy className="size-3.5" />} onClick={copy}>RAW logu kopyala</Button>
          <Button size="sm" icon={<FileDown className="size-3.5" />} onClick={() => api.download('/attendance/terminal/raw-log/indir', query, 'raw-log.txt')}>Logu indir</Button>
          <Button size="sm" variant="ghost" icon={<Trash2 className="size-3.5" />} loading={clear.isPending} onClick={() => { if (confirm('RAW log silinsin mi?')) clear.mutate() }}>Logu temizle</Button>
        </span>
      </div>
      {!data?.data.length ? (
        <EmptyState icon={<Binary />} title="Log boş" description="Oturum çalıştırınca TX/RX satırları burada görünür." />
      ) : (
        <div className="max-h-96 overflow-auto scroll-thin">
          <table className="w-full min-w-[640px] text-[11.5px] font-mono">
            <tbody className="divide-y divide-line">
              {data.data.map((r) => (
                <tr key={r.id} className={r.kind === 'STATE' ? 'text-ink-3' : ''}>
                  <td className="py-1 pr-2 whitespace-nowrap">{r.occurred_at.slice(11, 23)}</td>
                  <td className="pr-2">{r.kind === 'STATE' ? <Badge tone={STATE_TONE[r.state ?? ''] ?? 'neutral'}>{r.state}</Badge> : <Badge tone={r.kind === 'TX' ? 'accent' : 'info'}>{r.kind}</Badge>}</td>
                  <td className="pr-2 whitespace-nowrap">{r.kind === 'STATE' ? r.note : `${r.source}→${r.target} · ${r.length} B`}</td>
                  <td className="pr-2 break-all">{spaced(r.payload_hex)}{r.ascii && <span className="block text-ink-3">{r.ascii}</span>}</td>
                  <td>{r.kind !== 'STATE' && <Button size="sm" variant="ghost" onClick={() => toSample.mutate(r.id)}>Analize ekle</Button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  )
}

// ---------------------------------------------------------------- protokol analizi

function AnalysisPanel({ machineId }: { machineId: number }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ id: 0, ad: '', tx_hex: '', rx_hex: '', not: '', kaynak: 'wireshark' })
  const [picked, setPicked] = useState<number[]>([])
  const [side, setSide] = useState<'tx' | 'rx'>('tx')
  const [cmp, setCmp] = useState<Compare | null>(null)
  const { data } = useQuery({ queryKey: ['terminal', 'samples'], queryFn: () => api.get<{ data: Sample[] }>('/attendance/terminal/ornekler') })

  const save = useMutation({
    mutationFn: () => (form.id ? api.put(`/attendance/terminal/ornekler/${form.id}`, form) : api.post('/attendance/terminal/ornekler', form)),
    onSuccess: () => { toast.success('Örnek kaydedildi.'); setForm({ id: 0, ad: '', tx_hex: '', rx_hex: '', not: '', kaynak: 'wireshark' }); qc.invalidateQueries({ queryKey: ['terminal', 'samples'] }) },
    onError: (e) => toast.error(err(e, 'Kaydedilemedi.')),
  })
  const del = useMutation({
    mutationFn: (id: number) => api.delete(`/attendance/terminal/ornekler/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['terminal', 'samples'] }),
  })
  const compare = useMutation({
    mutationFn: () => api.post<Compare>('/attendance/terminal/ornekler/karsilastir', { idler: picked, taraf: side, makine_id: machineId }),
    onSuccess: setCmp,
    onError: (e) => toast.error(err(e, 'Karşılaştırılamadı.')),
  })
  const har = useMutation({
    mutationFn: (file: File) => { const fd = new FormData(); fd.append('dosya', file); return api.post<{ message: string }>('/attendance/terminal/ornekler/har', fd) },
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['terminal', 'samples'] }) },
    onError: (e) => toast.error(err(e, 'HAR içe aktarılamadı.')),
  })

  return (
    <Panel title="Protokol analizi" description="Yakalanan işlemleri kaydedin ve karşılaştırın. Otomatik analiz tahmindir; doğrulanmadan sürücüye eklenmez.">
      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="İşlem adı" required><Input value={form.ad} onChange={(e) => setForm({ ...form, ad: e.target.value })} placeholder="ör. Cihaz saatini oku" /></Field>
          <Field label="Kaynak">
            <Select value={form.kaynak} onChange={(e) => setForm({ ...form, kaynak: e.target.value })} options={[{ value: 'wireshark', label: 'Wireshark' }, { value: 'uygulama', label: 'Bu uygulama' }, { value: 'har', label: 'Web paneli (HAR)' }, { value: 'elle', label: 'Elle' }]} />
          </Field>
          <Field label="TX HEX (köprü/PC → cihaz)"><Textarea rows={2} value={form.tx_hex} onChange={(e) => setForm({ ...form, tx_hex: e.target.value })} className="font-mono" /></Field>
          <Field label="RX HEX (cihaz → köprü/PC)"><Textarea rows={2} value={form.rx_hex} onChange={(e) => setForm({ ...form, rx_hex: e.target.value })} className="font-mono" /></Field>
          <div className="sm:col-span-2"><Field label="Not" optional><Input value={form.not} onChange={(e) => setForm({ ...form, not: e.target.value })} placeholder="Machine ID=1, şifre=0, Wireshark #214" /></Field></div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="primary" icon={<Plus className="size-4" />} disabled={!form.ad} loading={save.isPending} onClick={() => save.mutate()}>{form.id ? 'Güncelle' : 'Örnek ekle'}</Button>
          <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-[var(--radius-sm)] ring-1 ring-line px-3 py-1.5 text-[13px]">
            <Upload className="size-4" /> HAR içe aktar
            <input type="file" accept=".har,application/json" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) har.mutate(f); e.target.value = '' }} />
          </label>
        </div>

        {!data?.data.length ? (
          <EmptyState icon={<FlaskConical />} title="Örnek yok" description="Wireshark/uygulama yakalamalarını ya da web paneli HAR dosyasını ekleyin." />
        ) : (
          <ul className="divide-y divide-line">
            {data.data.map((s) => (
              <li key={s.id} className="flex flex-wrap items-start gap-2 py-2 text-[12.5px]">
                <input type="checkbox" className="mt-1" checked={picked.includes(s.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, s.id] : picked.filter((x) => x !== s.id))} />
                <span className="min-w-0 flex-1">
                  <span className="font-medium">{s.name}</span> <Badge tone="neutral">{s.source}</Badge>
                  <span className="block break-all font-mono text-[11px] text-ink-3">TX {s.tx_hex ? `${s.tx_hex.length / 2} B` : '—'} · RX {s.rx_hex ? `${s.rx_hex.length / 2} B` : '—'}{s.note ? ` · ${s.note}` : ''}</span>
                </span>
                <Button size="sm" variant="ghost" onClick={() => setForm({ id: s.id, ad: s.name, tx_hex: spaced(s.tx_hex), rx_hex: spaced(s.rx_hex), not: s.note ?? '', kaynak: s.source })}>Düzenle</Button>
                <Button size="icon-sm" variant="ghost" onClick={() => del.mutate(s.id)} aria-label="Sil"><Trash2 className="size-3.5" /></Button>
              </li>
            ))}
          </ul>
        )}

        <div className="flex flex-wrap items-center gap-2">
          <Select value={side} onChange={(e) => setSide(e.target.value as 'tx' | 'rx')} options={[{ value: 'tx', label: 'TX paketlerini' }, { value: 'rx', label: 'RX paketlerini' }]} />
          <Button disabled={picked.length < 2} loading={compare.isPending} onClick={() => compare.mutate()}>Seçili {picked.length} örneği karşılaştır</Button>
        </div>

        {cmp && (
          <div className="space-y-2">
            <Alert tone="warning" title={cmp.uyari}>{cmp.hata}</Alert>
            {cmp.sutunlar && (
              <div className="overflow-x-auto scroll-thin">
                <table className="font-mono text-[11px]">
                  <thead><tr><th className="pr-2 text-left text-ink-3">ofset</th>{cmp.sutunlar.map((c) => <th key={c.ofset} className="px-0.5 text-ink-3">{c.ofset}</th>)}</tr></thead>
                  <tbody>
                    {(cmp.ornekler ?? []).map((o, row) => (
                      <tr key={o.id}>
                        <td className="whitespace-nowrap pr-2 text-ink-3">{o.ad.slice(0, 18)}</td>
                        {cmp.sutunlar!.map((c) => (
                          <td key={c.ofset} className={'px-0.5 text-center ' + (c.degerler[row] === null ? 'text-ink-3' : c.sabit ? 'bg-success/15' : 'bg-warning/20')}>{c.degerler[row] ?? '··'}</td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {cmp.boylar && <p className="text-[12px] text-ink-3">Boylar: {cmp.boylar.join(', ')} bayt · yeşil = tüm paketlerde sabit, sarı = değişen</p>}
            {cmp.oneriler && (
              <ul className="space-y-1 text-[12.5px]">
                {cmp.oneriler.map((o, i) => (
                  <li key={i}><Badge tone="info">{o.alan}</Badge> ofset {o.ofset}{o.uzunluk ? ` · ${o.uzunluk} B` : ''} — {o.aciklama}</li>
                ))}
              </ul>
            )}
          </div>
        )}
      </div>
    </Panel>
  )
}

export default function DevTools({ device, ip, port, machineId, keySet }: { device: DeviceRow; ip: string; port: number | null; machineId: number; keySet: boolean }) {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['terminal', 'devmode'], queryFn: () => api.get<{ acik: boolean }>('/attendance/terminal/gelistirici') })
  const toggle = useMutation({
    mutationFn: (acik: boolean) => api.post('/attendance/terminal/gelistirici', { acik }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['terminal', 'devmode'] }),
    onError: (e) => toast.error(err(e, 'Değiştirilemedi.')),
  })
  const on = !!data?.acik

  return (
    <div className="space-y-3">
      <Panel>
        <div className="flex flex-wrap items-center gap-3">
          <Switch checked={on} onChange={(v) => toggle.mutate(v)} label="Geliştirici modu" />
          <span className="text-[12.5px] text-ink-3">Ham TCP/HEX gönderici, RAW log ve protokol analizi. Günlük kullanımda kapalı kalabilir.</span>
        </div>
      </Panel>
      {on && (
        <>
          <SessionPanel key={`${device.id}-${ip}-${port}`} device={device} ip={ip} port={port} machineId={machineId} keySet={keySet} />
          <RawLogPanel />
          <AnalysisPanel machineId={machineId} />
        </>
      )}
    </div>
  )
}
