import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Activity, CheckCircle2, Clock, CreditCard, Download, FileSpreadsheet, Fingerprint, Link2, Loader2, Lock, ScanFace, Search, Upload, UserPlus, X } from 'lucide-react'
import { api, ApiError, isLocalNode, type Paginated } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useDebounced } from '@/hooks/useListState'
import { PageHeader, Panel, Tabs, DescriptionList } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/form'
import { Modal, ConfirmDialog } from '@/components/ui/overlay'

/*
| PDKS — kurumun KENDİ personel ve öğrenci devam kontrol sistemi (Perkotek yazılımına gerek yok).
| Terminal kişileri + eşleştirme (öğrenci / öğretmen / çalışan), giriş-çıkış kayıtları, günlük/aylık özet, terminal sağlığı.
| Cihaza dokunan işlemler (kullanıcı ekle/sil, parmak/yüz kaydı, cihazdan indir, saat eşitle) YT33 protokolü doğrulanana
| kadar pasif; ekranda cihaz menüsünden nasıl yapılacağı yazar. Web: salt okunur.
*/

type PersonType = 'student' | 'teacher' | 'employee'

type PersonRow = {
  id: number
  kullanici_no: string
  tur: string
  kisi_turu: PersonType
  kisi_turu_etiketi: string
  kisi: string
  kisi_no: string | null
  aktif: boolean
  son_okutma: string | null
  eslesmeyen_okutma: number
  cihazdaki_ad?: string | null
  terminal?: TerminalInfo | null
}
type TerminalInfo = { ad?: string | null; parmak: number | null; yuz: number; kart: boolean; zaman: string | null }
type EnrollSession = {
  id: number
  durum: 'bekliyor' | 'kaydedildi' | 'suresi_doldu'
  panel_var: boolean
  kisi: string
  kisi_no: string | null
  kisi_turu_etiketi: string
  cihaz_no: string
  ayrilan_no: string
  yeni_numara: boolean
  bitis: string
  terminal: TerminalInfo | null
  son_okutma: string | null
}
type DeviceStartResult = { baslatildi: boolean; ozellik?: 'fp' | 'face'; asama?: string; mesaj: string; oneri?: string; oturum: EnrollSession }
type PendingRow = { kullanici_no: string; son_okutma: string | null; eslesmeyen_okutma: number; cihazdaki_ad?: string | null; oto_eslesme?: string | null }

const AUTO_LINK_NOTE: Record<string, string> = {
  ambiguous: 'Aynı adda birden çok kişi var',
  no_match: 'Bu adla kişi bulunamadı',
  no_name: 'Cihazda ad girilmemiş',
}
type Hit = { kisi_turu: PersonType; kisi_id: number; ad: string; no: string | null; etiket: string }
type EventRow = {
  id: number
  zaman: string
  yon: string
  yontem: string
  kullanici_no: string | null
  eslesti: boolean
  kisi_turu: PersonType | null
  kisi_turu_etiketi: string | null
  kisi: string | null
  kisi_no: string | null
  cihaz: string | null
}
type DayRow = { kisi: string; kisi_turu_etiketi: string; tarih: string; ilk_giris: string | null; son_cikis: string | null; sure: string; gec: boolean; cikis_eksik: boolean; okutma: number }
type TotalRow = { kisi: string; kisi_turu_etiketi: string; gun: number; sure: string; gec: number; cikis_eksik: number }
type CsvRow = { satir: number; kullanici_no: string; ogrenci_no: string; ogrenci: string | null; durum: 'yeni' | 'ayni' | 'cakisma' | 'hata'; mesaj: string }
type Health = {
  yerel: boolean
  esitleme: { bekleyen: number } | null
  cihazlar: { id: number; ad: string; ip: string | null; surucu: string | null; cevrimici: boolean | null; son_tcp_testi: string | null; tcp: string | null; son_push_paketi: string | null; son_okutma: string | null; bekleyen_eslesme: number }[]
}

const err = (e: unknown, f: string) => (e instanceof ApiError ? e.firstError() : f)
const today = () => new Date().toISOString().slice(0, 10)
const monthStart = () => today().slice(0, 8) + '01'
const dirBadge = (yon: string) =>
  yon === 'ENTRY' ? <Badge tone="success">Giriş</Badge> : yon === 'EXIT' ? <Badge tone="warning">Çıkış</Badge> : <Badge tone="neutral">Bilinmiyor</Badge>

// ---------------------------------------------------------------- eşleştirme penceresi

function LinkModal({ open, onClose, initialNo }: { open: boolean; onClose: () => void; initialNo: string }) {
  const qc = useQueryClient()
  const [no, setNo] = useState(initialNo)
  const [type, setType] = useState<PersonType | ''>('')
  const [q, setQ] = useState('')
  const [picked, setPicked] = useState<Hit | null>(null)
  const [conflict, setConflict] = useState<string | null>(null)
  const dq = useDebounced(q, 250)

  const { data, isFetching } = useQuery({
    queryKey: ['pdks', 'search', dq, type],
    queryFn: () => api.get<{ data: Hit[] }>('/attendance/pdks/kisi-ara', { q: dq, tur: type || undefined }),
    enabled: open && dq.trim().length >= 2 && !picked,
  })

  const link = useMutation({
    mutationFn: (onay: boolean) => api.post<{ message: string }>('/attendance/pdks/eslestir', { kullanici_no: no.trim(), kisi_turu: picked!.kisi_turu, kisi_id: picked!.kisi_id, onay }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['pdks'] }); close() },
    onError: (e) => {
      if (e instanceof ApiError && e.status === 409) setConflict(e.message)
      else toast.error(err(e, 'Eşlenemedi.'))
    },
  })

  const close = () => { setPicked(null); setQ(''); setConflict(null); onClose() }

  return (
    <Modal
      open={open}
      onClose={close}
      title="Cihaz kullanıcısını kişiyle eşleştir"
      description="Cihazda kayıtlı kullanıcı numarasını öğrenciye ya da personele bağlayın."
      footer={
        <>
          <Button variant="ghost" onClick={close}>Vazgeç</Button>
          {conflict ? (
            <Button variant="danger" loading={link.isPending} onClick={() => link.mutate(true)}>Evet, değiştir</Button>
          ) : (
            <Button variant="primary" disabled={!picked || !no.trim()} loading={link.isPending} onClick={() => link.mutate(false)}>Eşleştir</Button>
          )}
        </>
      }
    >
      <div className="space-y-3">
        <Field label="Cihaz kullanıcı no" required hint="Cihaz menüsünde kişiyi kaydederken verilen numara (Kullanıcı No / ID).">
          <Input value={no} onChange={(e) => setNo(e.target.value)} inputMode="numeric" placeholder="ör. 1042" />
        </Field>
        {picked ? (
          <div className="flex items-center gap-3 rounded-[var(--radius-md)] ring-1 ring-line px-3 py-2.5">
            <span className="min-w-0 flex-1">
              <span className="block truncate text-[13.5px] font-medium">{picked.ad}</span>
              <span className="block text-[12px] text-ink-3">{picked.etiket}{picked.no ? ` · No: ${picked.no}` : ''}</span>
            </span>
            <Button size="icon-sm" variant="ghost" onClick={() => { setPicked(null); setConflict(null) }} aria-label="Değiştir"><X className="size-4" /></Button>
          </div>
        ) : (
          <>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
              <Select value={type} onChange={(e) => setType(e.target.value as PersonType | '')} options={[{ value: '', label: 'Tüm kişiler' }, { value: 'student', label: 'Öğrenci' }, { value: 'teacher', label: 'Öğretmen' }, { value: 'employee', label: 'Çalışan' }]} />
              <div className="sm:col-span-2"><Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Ad, soyad ya da öğrenci no (en az 2 harf)" leading={<Search className="size-4" />} /></div>
            </div>
            <ul className="max-h-60 overflow-y-auto scroll-thin divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
              {isFetching && <li className="px-3 py-2 text-[12.5px] text-ink-3">Aranıyor…</li>}
              {!isFetching && dq.trim().length >= 2 && !data?.data.length && <li className="px-3 py-2 text-[12.5px] text-ink-3">Sonuç yok.</li>}
              {data?.data.map((h) => (
                <li key={`${h.kisi_turu}-${h.kisi_id}`}>
                  <button type="button" className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-surface-2" onClick={() => setPicked(h)}>
                    <Badge tone={h.kisi_turu === 'student' ? 'info' : 'accent'}>{h.etiket}</Badge>
                    <span className="truncate text-[13px]">{h.ad}</span>
                    {h.no && <span className="text-[12px] text-ink-3 tabular">{h.no}</span>}
                  </button>
                </li>
              ))}
            </ul>
          </>
        )}
        {conflict && <Alert tone="warning" title="Bu numara başka birine bağlı">{conflict}</Alert>}
        <p className="text-[12px] text-ink-3">Yanlış eşleştirme yanlış kişiye giriş-çıkış (öğrencide veliye bildirim) yazar. Kişiyi dikkatle seçin.</p>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------- terminale kayıt sihirbazı

function TerminalBadges({ t }: { t?: TerminalInfo | null }) {
  if (!t) return <span className="text-[12.5px] text-ink-3">Kayıt bilgisi yok</span>
  const none = !t.parmak && !t.yuz && !t.kart
  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      {!!t.parmak && <Badge tone="success"><Fingerprint className="size-3" /> {t.parmak} parmak</Badge>}
      {!!t.yuz && <Badge tone="success"><ScanFace className="size-3" /> Yüz</Badge>}
      {t.kart && <Badge tone="success"><CreditCard className="size-3" /> Kart</Badge>}
      {none && <Badge tone="warning">Okutma kaydı yok</Badge>}
    </span>
  )
}

function ManualEnrollSteps({ s }: { s: EnrollSession }) {
  return (
    <ol className="list-decimal space-y-1.5 pl-5 text-[13.5px] text-ink-2">
      {s.yeni_numara ? (
        <li>Cihazda <strong>Menü › Kullanıcı Yönetimi › Yeni Kullanıcı</strong> açın, <strong>Kullanıcı No</strong> alanına <strong className="tabular">{s.cihaz_no}</strong> yazın (ad girmeniz gerekmez).</li>
      ) : (
        <li>Bu kişi cihazda zaten <strong className="tabular">{s.cihaz_no}</strong> numarasıyla tanımlı. Cihazda <strong>Menü › Kullanıcı Yönetimi</strong> içinden bu kullanıcıyı açın.</li>
      )}
      <li><strong>Parmak izi:</strong> aynı parmağı istendiği kadar (genelde 3 kez) okutun; yedek olarak ikinci bir parmak da kaydedin.</li>
      <li><strong>Kart:</strong> kartı okuyucuya yaklaştırın. <strong>Yüz:</strong> kişi ekrana bakarak yüz kaydını tamamlasın.</li>
      <li>Cihazda <strong>Kaydet</strong>'e basın. Bu pencere kaydı kendiliğinden görecek.</li>
    </ol>
  )
}

function EnrollModal({ person, onClose, onNext }: { person: Hit | null; onClose: () => void; onNext?: () => void }) {
  const qc = useQueryClient()
  const [picked, setPicked] = useState<Hit | null>(person)
  const [q, setQ] = useState('')
  const [session, setSession] = useState<EnrollSession | null>(null)
  const dq = useDebounced(q, 250)

  const search = useQuery({
    queryKey: ['pdks', 'search', dq, ''],
    queryFn: () => api.get<{ data: Hit[] }>('/attendance/pdks/kisi-ara', { q: dq }),
    enabled: !picked && dq.trim().length >= 2,
  })

  const start = useMutation({
    mutationFn: (h: Hit) => api.post<{ data: EnrollSession }>('/attendance/pdks/terminal-kayit', { kisi_turu: h.kisi_turu, kisi_id: h.kisi_id }),
    onSuccess: (r) => setSession(r.data),
    onError: (e) => toast.error(err(e, 'Kayıt başlatılamadı.')),
  })

  // Kişi hazırsa oturumu hemen aç
  useEffect(() => {
    if (picked && !session && !start.isPending && !start.isError) start.mutate(picked)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [picked])

  const poll = useQuery({
    queryKey: ['pdks', 'enroll', session?.id],
    queryFn: () => api.get<{ data: EnrollSession }>(`/attendance/pdks/terminal-kayit/${session!.id}`),
    enabled: !!session,
    refetchInterval: (query) => (query.state.data?.data.durum ?? session?.durum) === 'bekliyor' ? 2000 : false,
  })
  const s = poll.data?.data ?? session
  const done = s?.durum === 'kaydedildi'

  useEffect(() => {
    if (done) {
      qc.invalidateQueries({ queryKey: ['pdks', 'people'] })
      qc.invalidateQueries({ queryKey: ['pdks', 'unenrolled'] })
    }
  }, [done, qc])

  const extend = useMutation({
    mutationFn: () => api.post<{ data: EnrollSession }>(`/attendance/pdks/terminal-kayit/${s!.id}/uzat`, {}),
    onSuccess: (r) => { setSession(r.data); poll.refetch() },
  })

  const deviceStart = useMutation({
    mutationFn: (ozellik: 'fp' | 'face') => api.post<{ data: DeviceStartResult }>(`/attendance/pdks/terminal-kayit/${s!.id}/cihazda-baslat`, { ozellik }),
    onSuccess: (r) => {
      setSession(r.data.oturum)
      if (r.data.baslatildi) toast.success(r.data.mesaj)
      else toast.error(r.data.mesaj)
      poll.refetch()
    },
    onError: (e) => toast.error(err(e, 'Cihazda başlatılamadı.')),
  })
  const startResult = deviceStart.data?.data

  const close = async () => {
    if (s && s.durum !== 'kaydedildi') {
      try { await api.delete(`/attendance/pdks/terminal-kayit/${s.id}`) } catch { /* oturum kendiliğinden düşer */ }
    }
    qc.invalidateQueries({ queryKey: ['pdks'] })
    onClose()
  }

  return (
    <Modal
      open
      onClose={close}
      size="lg"
      title="Terminale kaydet"
      description="Kişi cihazın başındayken parmak izi, kart ya da yüz kaydı yapın; sistem kaydı kendiliğinden bu kişiye bağlar."
      footer={
        <>
          <Button variant="ghost" onClick={close}>{done ? 'Kapat' : 'Vazgeç'}</Button>
          {done && onNext && <Button variant="primary" icon={<UserPlus className="size-4" />} onClick={onNext}>Sıradaki kişi</Button>}
        </>
      }
    >
      {!picked ? (
        <div className="space-y-2">
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Kaydedilecek kişi: ad, soyad ya da öğrenci no" leading={<Search className="size-4" />} autoFocus />
          <ul className="max-h-72 overflow-y-auto scroll-thin divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
            {dq.trim().length < 2 && <li className="px-3 py-2 text-[12.5px] text-ink-3">En az 2 harf yazın.</li>}
            {search.isFetching && <li className="px-3 py-2 text-[12.5px] text-ink-3">Aranıyor…</li>}
            {!search.isFetching && dq.trim().length >= 2 && !search.data?.data.length && <li className="px-3 py-2 text-[12.5px] text-ink-3">Sonuç yok.</li>}
            {search.data?.data.map((h) => (
              <li key={`${h.kisi_turu}-${h.kisi_id}`}>
                <button type="button" className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-surface-2" onClick={() => setPicked(h)}>
                  <Badge tone={h.kisi_turu === 'student' ? 'info' : 'accent'}>{h.etiket}</Badge>
                  <span className="truncate text-[13px]">{h.ad}</span>
                  {h.no && <span className="text-[12px] text-ink-3 tabular">{h.no}</span>}
                </button>
              </li>
            ))}
          </ul>
        </div>
      ) : !s ? (
        start.isError ? (
          <Alert tone="danger" title="Kayıt başlatılamadı">{err(start.error, 'Tekrar deneyin.')}</Alert>
        ) : (
          <div className="flex items-center gap-2 py-6 text-ink-3"><Loader2 className="size-4 animate-spin" /> Cihaz numarası ayrılıyor…</div>
        )
      ) : (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-4 rounded-[var(--radius-md)] bg-surface-2 p-4 ring-1 ring-line">
            <div className="min-w-0 flex-1">
              <p className="text-[12px] text-ink-3">{s.kisi_turu_etiketi}{s.kisi_no ? ` · ${s.kisi_no}` : ''}</p>
              <p className="truncate text-[17px] font-semibold">{s.kisi}</p>
            </div>
            <div className="text-center">
              <p className="text-[12px] text-ink-3">Cihaz kullanıcı no</p>
              <p className="tabular text-[34px] font-bold leading-none tracking-wide">{s.cihaz_no}</p>
            </div>
          </div>

          {done ? (
            <Alert tone="success" title="Terminale kaydedildi" icon={<CheckCircle2 className="size-4" />}>
              <div className="space-y-1.5">
                <TerminalBadges t={s.terminal} />
                <p>Bu kişinin okutmaları artık {s.kisi_turu_etiketi === 'Öğrenci' ? 'yoklamaya' : 'PDKS giriş-çıkışına'} işlenecek.
                  {s.cihaz_no !== s.ayrilan_no && ` Cihaz ${s.cihaz_no} numarasını verdi; eşleme bu numaraya taşındı.`}</p>
              </div>
            </Alert>
          ) : (
            <>
              {s.panel_var ? (
                <div className="space-y-2 rounded-[var(--radius-md)] bg-primary-soft/60 p-3 ring-1 ring-primary/20">
                  <p className="text-[13px] font-semibold text-ink">Cihazda otomatik başlat</p>
                  <p className="text-[12.5px] text-ink-2">Kişiyi cihazda <strong className="tabular">{s.cihaz_no}</strong> numarasıyla açar ve cihazı kayıt ekranına geçirir. Kişi cihazın başındayken seçin:</p>
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="primary" icon={<Fingerprint className="size-3.5" />} loading={deviceStart.isPending && deviceStart.variables === 'fp'} disabled={deviceStart.isPending} onClick={() => deviceStart.mutate('fp')}>Parmak izi kaydını başlat</Button>
                    <Button size="sm" variant="primary" icon={<ScanFace className="size-3.5" />} loading={deviceStart.isPending && deviceStart.variables === 'face'} disabled={deviceStart.isPending} onClick={() => deviceStart.mutate('face')}>Yüz kaydını başlat</Button>
                  </div>
                  {startResult && (startResult.baslatildi
                    ? <p className="text-[12.5px] text-success">✓ {startResult.mesaj}</p>
                    : <p className="text-[12.5px] text-danger">{startResult.mesaj}{startResult.oneri ? ` — ${startResult.oneri}` : ''}</p>
                  )}
                  <details className="text-[12.5px] text-ink-3">
                    <summary className="cursor-pointer select-none">Cihaza ulaşılamıyorsa elle yapın</summary>
                    <div className="mt-2"><ManualEnrollSteps s={s} /></div>
                  </details>
                </div>
              ) : (
                <ManualEnrollSteps s={s} />
              )}
              {s.durum === 'bekliyor' ? (
                <div className="flex items-center gap-2 rounded-[var(--radius-md)] px-3 py-2.5 ring-1 ring-line text-[13px] text-ink-2">
                  <Loader2 className="size-4 animate-spin text-info" /> Cihazdan kayıt bekleniyor…
                  <span className="ml-auto text-[12px] text-ink-3">süre {relative(s.bitis)} doluyor</span>
                </div>
              ) : (
                <Alert tone="warning" title="Süre doldu">
                  <div className="flex flex-wrap items-center gap-2">
                    <span>Cihazdan kayıt gelmedi. Kişi hâlâ cihazın başındaysa süreyi uzatın.</span>
                    <Button size="sm" loading={extend.isPending} onClick={() => extend.mutate()}>15 dk uzat</Button>
                  </div>
                </Alert>
              )}
              <p className="text-[12px] text-ink-3">
                Cihaz başka bir numara önerirse de olur: kayıt geldiği anda bu kişiye bağlanır. Kayıt gelmiyorsa Terminal Köprüsü'nde push dinleyicisinin
                "Aktif" olduğunu kontrol edin. Parmak izi ve yüz verisi cihazda kalır; bu sisteme yalnız kaç parmak kaydedildiği bilgisi gelir.
              </p>
            </>
          )}
        </div>
      )}
    </Modal>
  )
}

function EnrollTab({ local }: { local: boolean }) {
  const [type, setType] = useState<PersonType | ''>('')
  const [q, setQ] = useState('')
  const dq = useDebounced(q, 300)
  const [current, setCurrent] = useState<{ person: Hit | null; key: number } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['pdks', 'unenrolled', type, dq],
    queryFn: () => api.get<{ toplam: number; kisiler: Hit[] }>('/attendance/pdks/kayitsiz', { tur: type || undefined, q: dq || undefined }),
    placeholderData: keepPreviousData,
  })
  const list = data?.kisiler ?? []

  const next = () => {
    const idx = current?.person ? list.findIndex((h) => h.kisi_turu === current.person!.kisi_turu && h.kisi_id === current.person!.kisi_id) : -1
    const candidate = list.find((h, i) => i !== idx && !(current?.person && h.kisi_turu === current.person.kisi_turu && h.kisi_id === current.person.kisi_id))
    setCurrent(candidate ? { person: candidate, key: Date.now() } : null)
  }

  return (
    <div className="space-y-3">
      <Alert tone="info" title="Kursa kayıtlı kişileri terminale nasıl kaydederim?" icon={<Fingerprint className="size-4" />}>
        Listeden kişiyi seçip <strong>Kaydet</strong>'e basın. Sistem ona bir cihaz numarası verir; kişi cihazın başında parmağını, kartını ya da yüzünü okutur
        ve kayıt kendiliğinden bu kişiye bağlanır. Toplu kayıt gününde <strong>Sıradaki kişi</strong> ile listede ilerleyin.
      </Alert>
      {!local && <Alert tone="warning" title="Yalnız Mac uygulamasında">Terminale kayıt, cihazın bağlı olduğu kurumdaki Mac uygulamasından yapılır.</Alert>}
      <Panel
        title={`Terminale kaydı olmayanlar${data ? ` · ${data.toplam}` : ''}`}
        description="Etkin öğrenci, öğretmen ve çalışanlardan henüz bir cihaz numarasına bağlı olmayanlar."
        actions={local ? <Button size="sm" variant="primary" icon={<UserPlus className="size-3.5" />} onClick={() => setCurrent({ person: null, key: Date.now() })}>Kişi ara ve kaydet</Button> : undefined}
      >
        <div className="mb-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
          <Select value={type} onChange={(e) => setType(e.target.value as PersonType | '')} options={[{ value: '', label: 'Tüm kişiler' }, { value: 'student', label: 'Öğrenciler' }, { value: 'teacher', label: 'Öğretmenler' }, { value: 'employee', label: 'Çalışanlar' }]} />
          <div className="sm:col-span-2"><Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Ad ya da öğrenci no" leading={<Search className="size-4" />} /></div>
        </div>
        {isLoading ? (
          <Skeleton className="h-40" />
        ) : list.length === 0 ? (
          <EmptyState icon={<CheckCircle2 />} title="Herkes terminale kayıtlı" description="Bu süzgeçte terminal numarası olmayan kişi yok." />
        ) : (
          <ul className="divide-y divide-line">
            {list.map((h) => (
              <li key={`${h.kisi_turu}-${h.kisi_id}`} className="flex flex-wrap items-center gap-2 py-2">
                <Badge tone={h.kisi_turu === 'student' ? 'info' : 'accent'}>{h.etiket}</Badge>
                <span className="font-medium">{h.ad}</span>
                {h.no && <span className="text-[12px] text-ink-3 tabular">{h.no}</span>}
                {local && <Button className="ml-auto" size="sm" icon={<Fingerprint className="size-3.5" />} onClick={() => setCurrent({ person: h, key: Date.now() })}>Kaydet</Button>}
              </li>
            ))}
          </ul>
        )}
        {data && data.toplam > list.length && <p className="mt-2 text-[12px] text-ink-3">İlk {list.length} kişi gösteriliyor; aramayla daraltın.</p>}
      </Panel>
      {current && <EnrollModal key={current.key} person={current.person} onClose={() => setCurrent(null)} onNext={current.person ? next : undefined} />}
    </div>
  )
}

// ---------------------------------------------------------------- CSV toplu eşleştirme

function CsvModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient()
  const [text, setText] = useState('')
  const [rows, setRows] = useState<CsvRow[] | null>(null)
  const [overwrite, setOverwrite] = useState(false)

  const preview = useMutation({
    mutationFn: () => api.post<{ data: CsvRow[] }>('/attendance/pdks/csv/onizle', { icerik: text }),
    onSuccess: (r) => setRows(r.data),
    onError: (e) => toast.error(err(e, 'Ön izleme yapılamadı.')),
  })
  const apply = useMutation({
    mutationFn: () => api.post<{ message: string }>('/attendance/pdks/csv/uygula', { icerik: text, uzerine_yaz: overwrite }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['pdks'] }); setRows(null); setText(''); onClose() },
    onError: (e) => toast.error(err(e, 'Uygulanamadı.')),
  })

  const counts = useMemo(() => ({
    yeni: rows?.filter((r) => r.durum === 'yeni').length ?? 0,
    cakisma: rows?.filter((r) => r.durum === 'cakisma').length ?? 0,
    hata: rows?.filter((r) => r.durum === 'hata').length ?? 0,
  }), [rows])

  return (
    <Modal open={open} onClose={onClose} size="lg" title="CSV ile toplu öğrenci eşleştirme" description="Her satır: cihaz kullanıcı no;öğrenci no (ayraç ; ya da ,). Önce ön izleme, sonra onay."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          {rows ? (
            <Button variant="primary" disabled={counts.yeni + (overwrite ? counts.cakisma : 0) === 0} loading={apply.isPending} onClick={() => apply.mutate()}>
              {counts.yeni + (overwrite ? counts.cakisma : 0)} eşlemeyi kaydet
            </Button>
          ) : (
            <Button variant="primary" disabled={!text.trim()} loading={preview.isPending} onClick={() => preview.mutate()}>Ön izle</Button>
          )}
        </>
      }
    >
      {!rows ? (
        <div className="space-y-2">
          <Textarea rows={8} value={text} onChange={(e) => setText(e.target.value)} placeholder={'1001;2024015\n1002;2024016'} className="font-mono" />
          <input type="file" accept=".csv,text/csv,text/plain" className="text-[12.5px]" onChange={async (e) => { const f = e.target.files?.[0]; if (f) setText(await f.text()) }} />
        </div>
      ) : (
        <div className="space-y-3">
          <div className="flex flex-wrap gap-2 text-[12.5px]">
            <Badge tone="success">{counts.yeni} yeni</Badge>
            <Badge tone="warning">{counts.cakisma} çakışma</Badge>
            <Badge tone="danger">{counts.hata} hatalı</Badge>
          </div>
          {counts.cakisma > 0 && (
            <label className="flex items-center gap-2 text-[13px]">
              <input type="checkbox" checked={overwrite} onChange={(e) => setOverwrite(e.target.checked)} />
              Çakışan {counts.cakisma} numarayı yeni öğrenciye bağla (eski eşleşme değişir)
            </label>
          )}
          <ul className="max-h-72 overflow-y-auto scroll-thin divide-y divide-line text-[12.5px]">
            {rows.map((r) => (
              <li key={r.satir} className="flex flex-wrap items-center gap-2 py-1.5">
                <span className="text-ink-3 tabular">#{r.satir}</span>
                <code>{r.kullanici_no}</code>→<span>{r.ogrenci ?? r.ogrenci_no}</span>
                <Badge tone={r.durum === 'yeni' ? 'success' : r.durum === 'cakisma' ? 'warning' : r.durum === 'ayni' ? 'neutral' : 'danger'}>{r.mesaj}</Badge>
              </li>
            ))}
          </ul>
          <Button size="sm" variant="ghost" onClick={() => setRows(null)}>Metne dön</Button>
        </div>
      )}
    </Modal>
  )
}

// ---------------------------------------------------------------- sekmeler

function PeopleTab({ local }: { local: boolean }) {
  const [q, setQ] = useState('')
  const dq = useDebounced(q, 300)
  const [linkNo, setLinkNo] = useState<string | null>(null)
  const [csv, setCsv] = useState(false)
  const { data, isLoading } = useQuery({
    queryKey: ['pdks', 'people', dq],
    queryFn: () => api.get<{ kisiler: PersonRow[]; bekleyen: PendingRow[] }>('/attendance/pdks/kisiler', { q: dq || undefined }),
    placeholderData: keepPreviousData,
  })

  const columns: Column<PersonRow>[] = [
    { key: 'no', header: 'Cihaz no', cell: (r) => <code className="text-[12.5px]">{r.kullanici_no}</code> },
    { key: 'kisi', header: 'Kişi', cell: (r) => <span><span className="font-medium">{r.kisi}</span>{r.kisi_no && <span className="text-[12px] text-ink-3 tabular"> · {r.kisi_no}</span>}</span> },
    { key: 'cihaz_ad', header: 'Cihazdaki ad', mobileHidden: true, cell: (r) => (r.cihazdaki_ad ? r.cihazdaki_ad : <span className="text-ink-3">—</span>) },
    { key: 'tur', header: 'Tür', cell: (r) => <Badge tone={r.kisi_turu === 'student' ? 'info' : 'accent'}>{r.kisi_turu_etiketi}</Badge> },
    { key: 'terminal', header: 'Terminal kaydı', cell: (r) => <TerminalBadges t={r.terminal} /> },
    { key: 'son', header: 'Son okutma', cell: (r) => (r.son_okutma ? relative(r.son_okutma) : <span className="text-ink-3">—</span>) },
  ]

  return (
    <div className="space-y-3">
      {data && data.bekleyen.length > 0 && (
        <Panel title="Bekleyen eşleştirme" description="Cihaza kayıtlı ya da okutma yapmış ama hiçbir kişiye bağlı olmayan numaralar. Cihazdaki ad tek bir kişiyle birebir uyuşursa eşleme kendiliğinden yapılır; diğerlerini buradan eşleyin. Okutmalar kaybolmaz; eşleyince bağlanır.">
          <ul className="divide-y divide-line">
            {data.bekleyen.map((p) => (
              <li key={p.kullanici_no} className="flex flex-wrap items-center gap-2 py-2">
                <code className="text-[13px]">{p.kullanici_no}</code>
                {p.cihazdaki_ad && <span className="font-medium">{p.cihazdaki_ad}</span>}
                <span className="text-[12.5px] text-ink-3">{p.eslesmeyen_okutma} okutma · son {p.son_okutma ? relative(p.son_okutma) : '—'}</span>
                {p.oto_eslesme && AUTO_LINK_NOTE[p.oto_eslesme] && <Badge tone="warning">{AUTO_LINK_NOTE[p.oto_eslesme]}</Badge>}
                {local && <Button className="ml-auto" size="sm" variant="primary" icon={<Link2 className="size-3.5" />} onClick={() => setLinkNo(p.kullanici_no)}>Eşleştir</Button>}
              </li>
            ))}
          </ul>
        </Panel>
      )}

      <DataTable
        storageKey="pdks-people"
        columns={columns}
        rows={data?.kisiler}
        rowKey={(r) => r.id}
        loading={isLoading}
        toolbar={
          <div className="flex flex-1 flex-wrap items-center gap-2">
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Ad, cihaz no ya da öğrenci no" className="w-full sm:w-[260px]" />
            {local && (
              <div className="ml-auto flex flex-wrap gap-1.5">
                <Button size="sm" icon={<Upload className="size-3.5" />} onClick={() => setCsv(true)}>CSV ile toplu</Button>
                <Button size="sm" variant="primary" icon={<UserPlus className="size-3.5" />} onClick={() => setLinkNo('')}>Kişi eşleştir</Button>
              </div>
            )}
          </div>
        }
        empty={<EmptyState icon={<Fingerprint />} title="Henüz eşleşme yok" description="Cihaz menüsünden kişiyi kaydedip verdiğiniz numarayı burada öğrenci ya da personelle eşleştirin." />}
      />

      {linkNo !== null && <LinkModal open onClose={() => setLinkNo(null)} initialNo={linkNo} />}
      <CsvModal open={csv} onClose={() => setCsv(false)} />
    </div>
  )
}

function EventsTab() {
  const [f, setF] = useState({ baslangic: monthStart(), bitis: today(), kisi_turu: 'hepsi', yon: '', kullanici_no: '' })
  const [page, setPage] = useState(1)
  const query = { ...f, yon: f.yon || undefined, kullanici_no: f.kullanici_no || undefined, page }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['pdks', 'events', query],
    queryFn: () => api.get<Paginated<EventRow>>('/attendance/pdks/kayitlar', query),
    placeholderData: keepPreviousData,
  })

  const columns: Column<EventRow>[] = [
    { key: 'zaman', header: 'Zaman', cell: (r) => <span className="tabular">{dateTime(r.zaman)}</span> },
    { key: 'yon', header: 'Yön', cell: (r) => dirBadge(r.yon) },
    { key: 'kisi', header: 'Kişi', cell: (r) => (r.eslesti && r.kisi ? <span><span className="font-medium">{r.kisi}</span> <span className="text-[12px] text-ink-3">· {r.kisi_turu_etiketi}</span></span> : <Badge tone="danger">Eşleşmedi · {r.kullanici_no ?? '?'}</Badge>) },
    { key: 'no', header: 'Cihaz no', cell: (r) => <code className="text-[12px]">{r.kullanici_no ?? '—'}</code> },
    { key: 'yontem', header: 'Doğrulama', cell: (r) => r.yontem },
    { key: 'cihaz', header: 'Cihaz', cell: (r) => r.cihaz ?? '—' },
  ]

  return (
    <DataTable
      storageKey="pdks-events"
      columns={columns}
      rows={data?.data}
      rowKey={(r) => r.id}
      loading={isLoading || isFetching}
      meta={data?.meta}
      onPage={setPage}
      toolbar={
        <div className="grid w-full grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
          <Input type="date" value={f.baslangic} onChange={(e) => { setF({ ...f, baslangic: e.target.value }); setPage(1) }} />
          <Input type="date" value={f.bitis} onChange={(e) => { setF({ ...f, bitis: e.target.value }); setPage(1) }} />
          <Select value={f.kisi_turu} onChange={(e) => { setF({ ...f, kisi_turu: e.target.value }); setPage(1) }} options={[{ value: 'hepsi', label: 'Herkes' }, { value: 'ogrenci', label: 'Öğrenciler' }, { value: 'personel', label: 'Personel' }, { value: 'eslesmeyen', label: 'Eşleşmeyenler' }]} />
          <Select value={f.yon} onChange={(e) => { setF({ ...f, yon: e.target.value }); setPage(1) }} options={[{ value: '', label: 'Giriş + çıkış' }, { value: 'ENTRY', label: 'Giriş' }, { value: 'EXIT', label: 'Çıkış' }]} />
          <Input value={f.kullanici_no} onChange={(e) => { setF({ ...f, kullanici_no: e.target.value }); setPage(1) }} placeholder="Cihaz no" />
          <Button icon={<FileSpreadsheet className="size-4" />} onClick={() => api.download('/attendance/pdks/kayitlar/excel', { ...f, yon: f.yon || undefined, kullanici_no: f.kullanici_no || undefined }, 'pdks.xlsx')}>Excel</Button>
        </div>
      }
      empty={<EmptyState icon={<Clock />} title="Bu aralıkta kayıt yok" description="Terminalden okutma geldikçe burada listelenir." />}
    />
  )
}

function SummaryTab() {
  const [f, setF] = useState({ baslangic: monthStart(), bitis: today(), kisi_turu: 'personel', mesai_baslangic: '09:00', tolerans_dk: '5' })
  const { data, isLoading } = useQuery({
    queryKey: ['pdks', 'summary', f],
    queryFn: () => api.get<{ gunluk: DayRow[]; toplam: TotalRow[] }>('/attendance/pdks/ozet', f),
    placeholderData: keepPreviousData,
  })

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        <Input type="date" value={f.baslangic} onChange={(e) => setF({ ...f, baslangic: e.target.value })} />
        <Input type="date" value={f.bitis} onChange={(e) => setF({ ...f, bitis: e.target.value })} />
        <Select value={f.kisi_turu} onChange={(e) => setF({ ...f, kisi_turu: e.target.value })} options={[{ value: 'personel', label: 'Personel' }, { value: 'ogrenci', label: 'Öğrenciler' }, { value: 'hepsi', label: 'Herkes' }]} />
        <Field label="Mesai başlangıcı"><Input type="time" value={f.mesai_baslangic} onChange={(e) => setF({ ...f, mesai_baslangic: e.target.value })} /></Field>
        <Field label="Tolerans (dk)"><Input value={f.tolerans_dk} onChange={(e) => setF({ ...f, tolerans_dk: e.target.value })} inputMode="numeric" /></Field>
      </div>
      {isLoading ? <Skeleton className="h-40" /> : (
        <>
          <Panel title="Dönem toplamı" description="Seçili aralıkta kişi başına çalışma günü, toplam süre, geç kalma ve çıkışı okutulmamış günler.">
            {!data?.toplam.length ? <p className="text-[12.5px] text-ink-3">Kayıt yok.</p> : (
              <div className="overflow-x-auto scroll-thin">
                <table className="w-full min-w-[520px] text-[13px]">
                  <thead><tr className="text-left text-[12px] text-ink-3"><th className="py-1.5">Kişi</th><th>Tür</th><th>Gün</th><th>Toplam süre</th><th>Geç</th><th>Çıkış yok</th></tr></thead>
                  <tbody className="divide-y divide-line">
                    {data.toplam.map((t, i) => (
                      <tr key={i}><td className="py-1.5 font-medium">{t.kisi}</td><td>{t.kisi_turu_etiketi}</td><td className="tabular">{t.gun}</td><td className="tabular">{t.sure}</td><td>{t.gec ? <Badge tone="warning">{t.gec}</Badge> : 0}</td><td>{t.cikis_eksik || 0}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
          <Panel title="Günlük" description="İlk giriş, son çıkış ve içeride geçen süre (giriş-çıkış çiftlerinden).">
            {!data?.gunluk.length ? <p className="text-[12.5px] text-ink-3">Kayıt yok.</p> : (
              <div className="overflow-x-auto scroll-thin">
                <table className="w-full min-w-[560px] text-[13px]">
                  <thead><tr className="text-left text-[12px] text-ink-3"><th className="py-1.5">Tarih</th><th>Kişi</th><th>İlk giriş</th><th>Son çıkış</th><th>Süre</th><th></th></tr></thead>
                  <tbody className="divide-y divide-line">
                    {data.gunluk.map((d, i) => (
                      <tr key={i}>
                        <td className="py-1.5 tabular">{d.tarih}</td>
                        <td>{d.kisi} <span className="text-[12px] text-ink-3">· {d.kisi_turu_etiketi}</span></td>
                        <td className="tabular">{d.ilk_giris ?? '—'}</td>
                        <td className="tabular">{d.son_cikis ?? '—'}</td>
                        <td className="tabular">{d.sure}</td>
                        <td className="space-x-1">{d.gec && <Badge tone="warning">Geç</Badge>}{d.cikis_eksik && <Badge tone="neutral">Çıkış yok</Badge>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
        </>
      )}
    </div>
  )
}

function HealthTab() {
  const { data, isLoading } = useQuery({ queryKey: ['pdks', 'health'], queryFn: () => api.get<Health>('/attendance/pdks/saglik'), refetchInterval: 30_000 })
  if (isLoading || !data) return <Skeleton className="h-40" />
  if (!data.cihazlar.length) return <EmptyState icon={<Activity />} title="Terminal yok" description="Yoklama terminalleri sayfasından cihaz ekleyin." />

  return (
    <div className="space-y-3">
      {data.esitleme && <p className="text-[12.5px] text-ink-3">Sunucuya gönderilmeyi bekleyen değişiklik: <strong>{data.esitleme.bekleyen}</strong></p>}
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        {data.cihazlar.map((d) => (
          <Panel key={d.id} title={d.ad} description={d.ip ?? 'IP girilmemiş'}>
            <DescriptionList
              columns={2}
              items={[
                { label: 'Durum', value: d.cevrimici === null ? <Badge tone="neutral">Mac'ten raporlanır</Badge> : d.cevrimici ? <Badge tone="success" dot>Çevrim içi</Badge> : <Badge tone="danger" dot>Sinyal yok</Badge> },
                { label: 'Son TCP testi', value: d.son_tcp_testi ? `${relative(d.son_tcp_testi)} · ${d.tcp === 'basarili' ? 'başarılı' : d.tcp ?? '—'}` : '—' },
                { label: 'Son push paketi', value: d.son_push_paketi ? relative(d.son_push_paketi) : '—' },
                { label: 'Son okutma', value: d.son_okutma ? relative(d.son_okutma) : '—' },
                { label: 'Eşleşme bekleyen', value: d.bekleyen_eslesme },
              ]}
            />
          </Panel>
        ))}
      </div>
    </div>
  )
}

type PanelCandidate = { id: number; ad: string; ip: string | null; kullanici: string | null; port: number; hazir: boolean }
type PanelState = { cihaz: { id: number; hazir: boolean } | null; adaylar: PanelCandidate[] }
type PanelUser = { kullanici_no: string; ad: string; kart: string | null; parmak_sayisi: number | null; yuz_sayisi: number | null; sifre_var: boolean }

function DeviceOpsTab({ local }: { local: boolean }) {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['pdks', 'panel'], queryFn: () => api.get<{ data: PanelState }>('/attendance/pdks/panel-durum') })
  const st = data?.data
  const [devId, setDevId] = useState<number | ''>('')
  const [form, setForm] = useState({ ip: '', port: '80', kullanici: '', sifre: '' })
  const [testMsg, setTestMsg] = useState<{ ok: boolean; text: string } | null>(null)
  const [delNo, setDelNo] = useState<string | null>(null)
  const selected = st?.adaylar.find((d) => d.id === devId) ?? null

  useEffect(() => {
    if (devId === '' && st && st.adaylar.length) setDevId(st.cihaz?.id ?? st.adaylar[0].id)
  }, [st, devId])
  useEffect(() => {
    const d = st?.adaylar.find((x) => x.id === devId)
    if (d) { setForm({ ip: d.ip ?? '', port: String(d.port || 80), kullanici: d.kullanici ?? '', sifre: '' }); setTestMsg(null) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [devId])

  const save = useMutation({
    mutationFn: () => api.post(`/attendance/pdks/panel/${devId}`, { ip: form.ip || undefined, port: Number(form.port) || 80, kullanici: form.kullanici, ...(form.sifre ? { sifre: form.sifre } : {}) }),
    onSuccess: () => { toast.success('Web paneli ayarı kaydedildi.'); setForm((f) => ({ ...f, sifre: '' })); qc.invalidateQueries({ queryKey: ['pdks', 'panel'] }) },
    onError: (e) => toast.error(err(e, 'Kaydedilemedi.')),
  })
  const test = useMutation({
    mutationFn: () => api.post<{ mesaj: string; cihaz: { ad: string | null; kullanici_sayisi: number | null; firmware: string | null } }>(`/attendance/pdks/panel/${devId}/test`, {}),
    onSuccess: (r) => setTestMsg({ ok: true, text: `${r.mesaj} ${r.cihaz?.ad ?? ''} · ${r.cihaz?.kullanici_sayisi ?? '?'} kullanıcı${r.cihaz?.firmware ? ` · ${r.cihaz.firmware}` : ''}` }),
    onError: (e) => setTestMsg({ ok: false, text: err(e, 'Cihaza bağlanılamadı.') }),
  })
  const users = useQuery({ queryKey: ['pdks', 'panel', 'users', devId], queryFn: () => api.get<{ data: PanelUser[] }>(`/attendance/pdks/panel/${devId}/kullanicilar`), enabled: false })
  const del = useMutation({
    mutationFn: (no: string) => api.delete(`/attendance/pdks/panel/${devId}/kullanici/${no}`),
    onSuccess: () => { toast.success('Kullanıcı cihazdan silindi.'); setDelNo(null); users.refetch(); qc.invalidateQueries({ queryKey: ['pdks', 'people'] }) },
    onError: (e) => toast.error(err(e, 'Silinemedi.')),
  })

  if (isLoading) return <Skeleton className="h-48" />
  if (!st || st.adaylar.length === 0) {
    return <EmptyState icon={<Lock />} title="Önce cihaz ekleyin" description="Web paneline bağlanmak için önce Yoklama › Cihazlar'da bir cihaz tanımlayın (IP dahil)." />
  }

  return (
    <div className="space-y-3">
      <Alert tone="info" title="Cihaz web paneli (Dynamic Face)" icon={<Fingerprint className="size-4" />}>
        Cihazın kendi web paneli üzerinden kullanıcı ekleme/okuma/silme ve parmak-yüz kaydı başlatma buradan yapılır.
        İşlemler yalnız cihazın bulunduğu yerel ağdaki Mac uygulamasından çalışır.
      </Alert>
      {!local && <Alert tone="warning" title="Yalnız Mac uygulamasında">Panel ayarı web'den görülebilir ama cihaza bağlanma (kaydet/test/sil) kurumdaki Mac uygulamasından yapılır.</Alert>}

      <Panel title="Panel bağlantısı" description="Cihaz IP'si, panel kullanıcı adı ve şifresi. Şifre HTTP Digest ile gönderilir ve şifreli saklanır.">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
          <Field label="Cihaz">
            <Select value={devId === '' ? '' : String(devId)} onChange={(e) => setDevId(Number(e.target.value))}
              options={st.adaylar.map((d) => ({ value: String(d.id), label: `${d.ad}${d.hazir ? ' ✓' : ''}` }))} />
          </Field>
          <Field label="Cihaz IP"><Input value={form.ip} onChange={(e) => setForm((f) => ({ ...f, ip: e.target.value }))} placeholder="192.168.68.60" /></Field>
          <Field label="Panel kullanıcı adı"><Input value={form.kullanici} onChange={(e) => setForm((f) => ({ ...f, kullanici: e.target.value }))} placeholder="admin" /></Field>
          <Field label="Panel portu"><Input value={form.port} onChange={(e) => setForm((f) => ({ ...f, port: e.target.value }))} placeholder="80" inputMode="numeric" /></Field>
          <Field label="Panel şifresi" hint={selected?.hazir ? 'Kayıtlı — değiştirmemek için boş bırakın' : 'Cihaz panel şifresi'}>
            <Input type="password" value={form.sifre} onChange={(e) => setForm((f) => ({ ...f, sifre: e.target.value }))} placeholder={selected?.hazir ? '••••••••' : ''} autoComplete="new-password" />
          </Field>
        </div>
        {testMsg && <p className={`mt-2 text-[12.5px] ${testMsg.ok ? 'text-success' : 'text-danger'}`}>{testMsg.text}</p>}
        <div className="mt-3 flex flex-wrap gap-2">
          <Button variant="primary" loading={save.isPending} disabled={!local || !form.kullanici} onClick={() => save.mutate()}>Kaydet</Button>
          <Button variant="secondary" loading={test.isPending} disabled={!local} onClick={() => test.mutate()}>Bağlantıyı test et</Button>
        </div>
      </Panel>

      <Panel title="Cihazdaki kullanıcılar" description="Cihaz belleğindeki kullanıcılar. Biyometrik veri gelmez; yalnız kaç parmak/yüz kayıtlı olduğu görünür."
        actions={<Button size="sm" loading={users.isFetching} disabled={!local || !selected?.hazir} onClick={() => users.refetch()} icon={<Download className="size-3.5" />}>Cihazdan oku</Button>}>
        {!selected?.hazir ? (
          <EmptyState icon={<Lock />} title="Panel ayarı eksik" description="Önce bu cihaz için panel kullanıcı adı ve şifresini kaydedin." />
        ) : users.isError ? (
          <Alert tone="danger" title="Okunamadı">{err(users.error, 'Cihazdan kullanıcı listesi alınamadı.')}</Alert>
        ) : !users.data ? (
          <p className="text-[12.5px] text-ink-3">Listeyi getirmek için "Cihazdan oku"ya basın.</p>
        ) : users.data.data.length === 0 ? (
          <EmptyState icon={<Fingerprint />} title="Cihazda kullanıcı yok" />
        ) : (
          <ul className="divide-y divide-line">
            {users.data.data.map((u) => (
              <li key={u.kullanici_no} className="flex flex-wrap items-center gap-2 py-2">
                <code className="text-[13px]">{u.kullanici_no}</code>
                <span className="font-medium">{u.ad || <span className="text-ink-3">(ad yok)</span>}</span>
                <span className="text-[12px] text-ink-3">{u.parmak_sayisi ? `${u.parmak_sayisi} parmak` : ''}{u.yuz_sayisi ? ' · yüz' : ''}{u.kart ? ' · kart' : ''}</span>
                {local && <Button className="ml-auto" size="sm" variant="danger" icon={<X className="size-3.5" />} onClick={() => setDelNo(u.kullanici_no)}>Cihazdan sil</Button>}
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <ConfirmDialog open={delNo !== null} onClose={() => setDelNo(null)} danger confirmLabel="Cihazdan sil" loading={del.isPending}
        onConfirm={() => delNo && del.mutate(delNo)} title={`${delNo} numaralı kullanıcıyı sil?`}
        description="Bu kullanıcı cihaz belleğinden (parmak/yüz kayıtlarıyla) silinir. İşlem geri alınamaz; yalnız bu numara silinir." />
    </div>
  )
}

export default function Pdks() {
  const local = isLocalNode()
  const [tab, setTab] = useState<'kisiler' | 'kayit' | 'kayitlar' | 'ozet' | 'saglik' | 'cihaz'>('kisiler')

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="PDKS"
        description="Kurumun kendi personel ve öğrenci devam kontrol sistemi — ayrı bir Perkotek yazılımına gerek yok."
        actions={<Button variant="ghost" icon={<Download className="size-4" />} onClick={() => setTab('kayitlar')}>Giriş-çıkış kayıtları</Button>}
      />
      {!local && (
        <Alert tone="info" className="mb-3" title="Web: salt okunur">Eşleştirme ve terminal işlemleri kurumdaki Mac uygulamasından yapılır.</Alert>
      )}
      <Tabs
        className="mb-4"
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'kisiler', label: 'Kişiler ve eşleştirme' },
          { value: 'kayit', label: 'Terminale kaydet' },
          { value: 'kayitlar', label: 'Giriş-çıkış' },
          { value: 'ozet', label: 'Günlük / aylık özet' },
          { value: 'saglik', label: 'Terminal sağlığı' },
          { value: 'cihaz', label: 'Cihaz işlemleri' },
        ]}
      />
      {tab === 'kisiler' && <PeopleTab local={local} />}
      {tab === 'kayit' && <EnrollTab local={local} />}
      {tab === 'kayitlar' && <EventsTab />}
      {tab === 'ozet' && <SummaryTab />}
      {tab === 'saglik' && <HealthTab />}
      {tab === 'cihaz' && <DeviceOpsTab local={local} />}
    </div>
  )
}
