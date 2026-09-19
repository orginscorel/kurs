import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Activity, Clock, Download, FileSpreadsheet, Fingerprint, Link2, Lock, Search, Upload, UserPlus, X } from 'lucide-react'
import { api, ApiError, isLocalNode, type Paginated } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useDebounced } from '@/hooks/useListState'
import { PageHeader, Panel, Tabs, DescriptionList } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'

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
}
type PendingRow = { kullanici_no: string; son_okutma: string | null; eslesmeyen_okutma: number }
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
    { key: 'tur', header: 'Tür', cell: (r) => <Badge tone={r.kisi_turu === 'student' ? 'info' : 'accent'}>{r.kisi_turu_etiketi}</Badge> },
    { key: 'kimlik', header: 'Kimlik', cell: (r) => (r.tur === 'card' ? 'Kart' : 'Parmak / yüz') },
    { key: 'son', header: 'Son okutma', cell: (r) => (r.son_okutma ? relative(r.son_okutma) : <span className="text-ink-3">—</span>) },
  ]

  return (
    <div className="space-y-3">
      {data && data.bekleyen.length > 0 && (
        <Panel title="Bekleyen eşleştirme" description="Cihazdan okutma gelmiş ama hiçbir kişiye bağlı olmayan numaralar. Okutmalar kaybolmaz; eşleyince bağlanır.">
          <ul className="divide-y divide-line">
            {data.bekleyen.map((p) => (
              <li key={p.kullanici_no} className="flex flex-wrap items-center gap-2 py-2">
                <code className="text-[13px]">{p.kullanici_no}</code>
                <span className="text-[12.5px] text-ink-3">{p.eslesmeyen_okutma} okutma · son {p.son_okutma ? relative(p.son_okutma) : '—'}</span>
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

const DEVICE_OPS: { label: string; how: string }[] = [
  { label: 'Cihaza kullanıcı ekle', how: 'Cihaz menüsü › Kullanıcı Yönetimi › Yeni Kullanıcı → bir Kullanıcı No verin. Sonra bu ekranda "Kişi eşleştir" ile o numarayı öğrenci/personelle bağlayın.' },
  { label: 'Parmak izi / yüz kaydı başlat', how: 'Cihazda kullanıcıyı açın › Parmak İzi (ya da Yüz) Kaydet → kişiye okutun. Kayıt cihazda kalır; biyometrik veri bu sisteme hiç gelmez.' },
  { label: 'Cihazdan kullanıcı sil', how: 'Cihaz menüsü › Kullanıcı Yönetimi › kullanıcıyı seçin › Sil. Buradaki eşleşmeyi de "Kişiler" listesinden kaldırın.' },
  { label: 'Cihazdan kullanıcı / kayıt indir', how: 'Okutmalar push (Terminal Köprüsü › Push) ya da doğrulanmış protokolle otomatik gelir. Şimdilik cihaz web panelinden (Dynamic Face) dışa aktarabilirsiniz.' },
  { label: 'Cihaz saatini eşitle', how: 'Cihaz menüsü ya da web paneli › Sistem › Tarih/Saat. Saat yanlışsa giriş-çıkış saatleri de yanlış kaydedilir.' },
]

function DeviceOpsTab() {
  return (
    <div className="space-y-3">
      <Alert tone="info" title="Protokol verisi bekleniyor" icon={<Lock className="size-4" />}>
        Bu işlemler cihaza komut gönderir. YT33 protokolü gerçek trafik kaydıyla doğrulanana kadar uygulama cihaza tahmini komut göndermez;
        işlemi cihazın kendi menüsünden ya da web panelinden yapın. Kişi eşleştirme, giriş-çıkış kayıtları ve raporlar bugün tam çalışır.
      </Alert>
      <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
        {DEVICE_OPS.map((op) => (
          <li key={op.label} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-start">
            <Button size="sm" disabled className="shrink-0">{op.label}</Button>
            <p className="text-[12.5px] text-ink-2"><span className="font-medium">Nasıl yapılır: </span>{op.how}</p>
          </li>
        ))}
      </ul>
    </div>
  )
}

export default function Pdks() {
  const local = isLocalNode()
  const [tab, setTab] = useState<'kisiler' | 'kayitlar' | 'ozet' | 'saglik' | 'cihaz'>('kisiler')

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
          { value: 'kayitlar', label: 'Giriş-çıkış' },
          { value: 'ozet', label: 'Günlük / aylık özet' },
          { value: 'saglik', label: 'Terminal sağlığı' },
          { value: 'cihaz', label: 'Cihaz işlemleri' },
        ]}
      />
      {tab === 'kisiler' && <PeopleTab local={local} />}
      {tab === 'kayitlar' && <EventsTab />}
      {tab === 'ozet' && <SummaryTab />}
      {tab === 'saglik' && <HealthTab />}
      {tab === 'cihaz' && <DeviceOpsTab />}
    </div>
  )
}
