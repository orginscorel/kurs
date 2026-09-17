import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Cable, CheckCircle2, Clock, Download, Fingerprint, Link2, RefreshCw, Search, TriangleAlert, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useListState } from '@/hooks/useListState'
import { Panel, DescriptionList } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { StudentSearch, type StudentHit } from './LivePresence'
import type { DeviceRow } from './types'

/*
| TERMİNAL KÖPRÜSÜ (Perkotek YT-33 / ZKTeco) — docs/CIHAZ-KOPRUSU.md
|
| Cihaz kurumun yerel ağındadır; web sunucusu ona ULAŞAMAZ. Bu yüzden "Bağlantıyı test et",
| "Kullanıcıları getir" ve "Şimdi çek" düğmeleri yalnız MASAÜSTÜ uygulamasından (aynı wifi)
| çalışır. Sunucudan denendiğinde ekran bunu açıkça söyler, istek asılı kalmaz.
*/

type BridgeStatus = {
  protokol: string | null
  ip: string | null
  port: number | null
  aktarim: string | null
  sifre_tanimli: boolean
  son_cekme: string | null
  son_durum: 'ok' | 'error' | null
  son_hata: string | null
  son_kayit_sayisi: number
  imlec: string | null
}

type TestResult = {
  durum: 'ok' | 'hata'
  hedef?: string
  mesaj?: string
  oneri?: string
  kod?: string
  cihaz?: Record<string, string | number | null>
  son_kayitlar?: { kullanici_no: string; zaman: string; punch: number; dogrulama: number }[]
  toplam_kayit?: number
}

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

// ---------------------------------------------------------------- bağlantı ayarları

function ConnectionPanel({ device }: { device: DeviceRow }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ ip: '', port: '4370', transport: 'tcp', comm_key: '' })
  const [keyTouched, setKeyTouched] = useState(false)
  const [test, setTest] = useState<TestResult | null>(null)

  const { data: status, isLoading } = useQuery({
    queryKey: ['zk', 'status', device.id],
    queryFn: () => api.get<BridgeStatus>(`/attendance/zk/cihazlar/${device.id}/durum`),
    refetchInterval: 30_000,
  })

  useEffect(() => {
    if (!status) return
    setForm({ ip: status.ip ?? '', port: String(status.port ?? 4370), transport: status.aktarim ?? 'tcp', comm_key: '' })
    setKeyTouched(false)
    setTest(null)
  }, [status?.ip, status?.port, status?.aktarim, device.id])

  const save = useMutation({
    mutationFn: () =>
      api.post(`/attendance/zk/cihazlar/${device.id}/baglanti`, {
        ip: form.ip,
        port: Number(form.port) || 4370,
        transport: form.transport,
        ...(keyTouched ? { comm_key: form.comm_key } : {}),
      }),
    onSuccess: () => {
      toast.success('Bağlantı bilgileri kaydedildi.')
      setKeyTouched(false)
      qc.invalidateQueries({ queryKey: ['zk', 'status', device.id] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const runTest = useMutation({
    mutationFn: () => api.post<TestResult>(`/attendance/zk/cihazlar/${device.id}/test`, {}),
    onSuccess: (res) => setTest(res),
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Test yapılamadı.'),
  })

  const pull = useMutation({
    mutationFn: (full: boolean) => api.post<Record<string, number | string>>(`/attendance/zk/cihazlar/${device.id}/cek`, { tam: full }),
    onSuccess: (res) => {
      toast.success(`${res.okunan} kayıt okundu · ${res.islenen} işlendi · ${res.eslesmeyen} eşleşmedi`)
      qc.invalidateQueries({ queryKey: ['zk'] })
      qc.invalidateQueries({ queryKey: ['attendance'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Çekilemedi.'),
  })

  if (isLoading) return <Skeleton className="h-64 rounded-[var(--radius-lg)]" />

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
      <Panel title="Bağlantı" description="Cihazın yerel ağdaki adresi. Cihaz menüsü: Comm > Ethernet.">
        <div className="space-y-3.5">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div className="sm:col-span-2">
              <Field label="IP adresi" required>
                <Input value={form.ip} onChange={(e) => setForm({ ...form, ip: e.target.value })} placeholder="192.168.1.50" inputMode="decimal" />
              </Field>
            </div>
            <Field label="Port" required>
              <Input value={form.port} onChange={(e) => setForm({ ...form, port: e.target.value })} placeholder="4370" inputMode="numeric" />
            </Field>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Field label="Bağlantı türü" hint="Önce TCP deneyin; eski cihazlar yalnız UDP konuşur.">
              <Select
                value={form.transport}
                onChange={(e) => setForm({ ...form, transport: e.target.value })}
                options={[{ value: 'tcp', label: 'TCP (önerilen)' }, { value: 'udp', label: 'UDP' }]}
              />
            </Field>
            <Field label="İletişim şifresi" optional hint={status?.sifre_tanimli ? 'Kayıtlı. Değiştirmek için yeni değeri yazın.' : 'Cihazda kapalıysa boş bırakın.'}>
              <Input
                value={form.comm_key}
                onChange={(e) => { setForm({ ...form, comm_key: e.target.value }); setKeyTouched(true) }}
                placeholder={status?.sifre_tanimli ? '••••••' : '0'}
                inputMode="numeric"
              />
            </Field>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Button variant="primary" disabled={!form.ip} loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button>
            <Button icon={<Cable className="size-4" />} disabled={!status?.ip} loading={runTest.isPending} onClick={() => runTest.mutate()}>Bağlantıyı test et</Button>
          </div>

          {test && <TestOutcome result={test} />}
        </div>
      </Panel>

      <Panel title="Köprü durumu" description="Kayıtlar masaüstü uygulamasından çekilir; internet gelince sunucuya eşitlenir.">
        <DescriptionList
          columns={2}
          items={[
            { label: 'Son çekme', value: status?.son_cekme ? relative(status.son_cekme) : 'Hiç çekilmedi' },
            {
              label: 'Sonuç',
              value: !status?.son_durum ? <Badge tone="neutral">Bilinmiyor</Badge>
                : status.son_durum === 'ok' ? <Badge tone="success" dot>Başarılı</Badge>
                  : <Badge tone="danger" dot>Hata</Badge>,
            },
            { label: 'Son okunan kayıt', value: (status?.son_kayit_sayisi ?? 0).toLocaleString('tr-TR') },
            { label: 'İmleç (son işlenen an)', value: status?.imlec ? dateTime(status.imlec) : '—' },
          ]}
        />

        {status?.son_hata && (
          <Alert tone="danger" className="mt-3" title="Son çekmede hata" icon={<TriangleAlert className="size-4" />}>
            {status.son_hata}
          </Alert>
        )}

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <Button icon={<Download className="size-4" />} disabled={!status?.ip} loading={pull.isPending} onClick={() => pull.mutate(false)}>Yeni kayıtları çek</Button>
          <Button variant="ghost" icon={<RefreshCw className="size-4" />} disabled={!status?.ip} loading={pull.isPending} onClick={() => pull.mutate(true)}>Tümünü çek</Button>
        </div>
        <p className="mt-2 text-[12px] text-ink-3">
          "Tümünü çek" cihazdaki bütün kayıtları okur; daha önce işlenenler tekrar yazılmaz.
        </p>
      </Panel>
    </div>
  )
}

function TestOutcome({ result }: { result: TestResult }) {
  if (result.durum !== 'ok') {
    return (
      <Alert tone="danger" title={result.mesaj ?? 'Cihaza ulaşılamadı'} icon={<TriangleAlert className="size-4" />}>
        {result.oneri}
        <p className="mt-1.5 text-[12px] text-ink-3">
          Bu ekran web sunucusundan açıldıysa cihaza ulaşılamaması normaldir: cihaz kurumun yerel ağındadır.
          Testi kurumdaki masaüstü uygulamasından yapın.
        </p>
      </Alert>
    )
  }

  const items = Object.entries(result.cihaz ?? {})
    .filter(([, v]) => v !== null && v !== '')
    .map(([k, v]) => ({ label: CIHAZ_ETIKET[k] ?? k, value: String(v) }))

  return (
    <div className="space-y-3">
      <Alert tone="success" title="Cihaza bağlanıldı" icon={<CheckCircle2 className="size-4" />}>
        {result.hedef} · cihazda {(result.toplam_kayit ?? 0).toLocaleString('tr-TR')} kayıt okundu.
      </Alert>
      <DescriptionList columns={2} items={items} />
      {!!result.son_kayitlar?.length && (
        <div>
          <p className="mb-1.5 text-[12.5px] font-medium text-ink-2">Son okutmalar</p>
          <ul className="space-y-1 text-[12.5px] text-ink-2">
            {result.son_kayitlar.map((r, i) => (
              <li key={i} className="flex items-center gap-2">
                <Clock className="size-3.5 text-ink-3" />
                <code className="text-[12px]">{r.kullanici_no}</code>
                <span className="text-ink-3">{dateTime(r.zaman)}</span>
                <Badge tone={r.punch === 1 ? 'warning' : 'success'}>{r.punch === 1 ? 'Çıkış' : 'Giriş'}</Badge>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

// ---------------------------------------------------------------- cihaz kullanıcıları

function DeviceUsersPanel({ device }: { device: DeviceRow }) {
  const qc = useQueryClient()
  const [fetched, setFetched] = useState(false)
  const [linkFor, setLinkFor] = useState<ZkUserRow | null>(null)
  const [picked, setPicked] = useState<StudentHit | null>(null)

  const { data, isFetching, refetch } = useQuery({
    queryKey: ['zk', 'users', device.id],
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

export default function ZkBridge() {
  const [deviceId, setDeviceId] = useState<number | null>(null)
  const [section, setSection] = useState<'baglanti' | 'kullanicilar' | 'bekleyen'>('baglanti')

  const { data, isLoading } = useQuery({ queryKey: ['attendance', 'devices'], queryFn: () => api.get<{ data: DeviceRow[] }>('/attendance/devices') })

  const devices = data?.data.filter((d) => d.kind === 'fingerprint' || d.kind === 'face') ?? []
  const selected = devices.find((d) => d.id === deviceId) ?? devices[0] ?? null

  if (isLoading) return <Skeleton className="h-64 rounded-[var(--radius-lg)]" />

  if (!devices.length) {
    return (
      <EmptyState
        icon={<Cable />}
        title="Terminal cihazı yok"
        description="Önce Cihazlar sekmesinden türü “Parmak izi” ya da “Yüz tanıma” olan bir cihaz ekleyin, sonra IP adresini burada girin."
      />
    )
  }

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
          {([['baglanti', 'Bağlantı'], ['kullanicilar', 'Cihaz kullanıcıları'], ['bekleyen', 'Bekleyen okutmalar']] as const).map(([value, label]) => (
            <Button key={value} size="sm" variant={section === value ? 'primary' : 'ghost'} onClick={() => setSection(value)}>{label}</Button>
          ))}
        </div>
      </div>

      {selected && section === 'baglanti' && <ConnectionPanel key={selected.id} device={selected} />}
      {selected && section === 'kullanicilar' && <DeviceUsersPanel key={selected.id} device={selected} />}
      {section === 'bekleyen' && <PendingPanel />}
    </div>
  )
}
