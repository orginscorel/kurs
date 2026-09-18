import { useEffect, useMemo, useState } from 'react'
import { PersonText, PhoneText } from '@/components/ui/contact'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { BarChart3, ClipboardPlus, PhoneCall, Plus, Search, UserCheck, UserPlus } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date, dateTime, num, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader, Stat } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Avatar, Badge, EmptyState } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { LeadDrawer } from './LeadDrawer'
import type { LeadOptions, LeadRow } from './types'

/**
 * ÖN KAYIT
 * Kuruma ilgi gösteren öğrenci burada kaydedilir. Kayıt olursa "Öğrenci kaydına çevir" ile
 * öğrenci (ve istenirse ödeme planı) oluşturulur; olmazsa görüşmeler ve sonraki arama tarihiyle takip edilir.
 * Arka planda aday (lead) altyapısını kullanır; sade durumlar birkaç aşamayı birleştirir.
 */

type Durum = 'aktif' | 'aranacak' | 'arandi' | 'kayit' | 'olmadi' | 'tumu'

type Ozet = {
  durum: Record<Exclude<Durum, 'tumu'>, number>
  geciken: number
  bu_ay_yeni: number
  bu_ay_kayit: number
  donusum: number | null
}

const DURUM_ETIKET: Record<Durum, string> = {
  aktif: 'Takipte',
  aranacak: 'Aranacak',
  arandi: 'Arandı',
  kayit: 'Kayıt oldu',
  olmadi: 'Kaydolmadı',
  tumu: 'Tümü',
}

/** Arama planı: planlı arama ya da hiç aranmamış → Aranacak; aranmış ve plan yok → Arandı; sonuçlanmış → arama yok. */
const aramaDurumu = (l: LeadRow): 'aranacak' | 'arandi' | null => {
  if (l.stage === 'won' || l.stage === 'lost') return null
  return l.next_action_at || l.stage === 'new' ? 'aranacak' : 'arandi'
}

const sinifMetni = (g: string | null) => (!g ? '' : /^\d+$/.test(g) ? `${g}. sınıf` : g)

export default function PreRegistrationList() {
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: '-created_at', filters: { durum: 'aktif' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [drawer, setDrawer] = useState<{ id: number | 'new'; tab?: 'info' | 'history' | 'convert' } | null>(null)
  const [gorusme, setGorusme] = useState<LeadRow | null>(null)

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  // Komut paletinden "Yeni ön kayıt": ?yeni=1
  useEffect(() => {
    if (params.get('yeni') === '1') {
      setDrawer({ id: 'new' })
      params.delete('yeni')
      setParams(params, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const durum = (list.filters.durum ?? 'aktif') as Durum
  const query = useMemo(() => {
    const q: Record<string, string | number | undefined> = { ...(list.query as Record<string, string | number | undefined>) }
    if (durum === 'tumu') delete q.durum
    return q
  }, [list.query, durum])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['crm', 'leads', 'list', query],
    queryFn: () => api.get<Paginated<LeadRow>>('/crm/leads', query),
    placeholderData: keepPreviousData,
  })
  const ozet = useQuery({ queryKey: ['crm', 'leads', 'summary'], queryFn: () => api.get<{ data: Ozet }>('/crm/leads/summary').then((r) => r.data) })
  const options = useQuery({ queryKey: ['crm', 'leads', 'options'], queryFn: () => api.get<LeadOptions>('/crm/leads/options'), staleTime: 5 * 60_000 })

  const yenile = () => qc.invalidateQueries({ queryKey: ['crm', 'leads'] })
  const yonetebilir = can('crm.manage')

  const columns = useMemo<Column<LeadRow>[]>(
    () => [
      {
        // Okul adı uzun olabiliyor; ilk sütun 440'a kadar açılıp işlem sütununu taşırmasın diye üst sınır daraltıldı
        key: 'name', header: 'Öğrenci', sortKey: 'full_name', maxWidth: 240,
        cell: (l) => (
          <div className="flex min-w-0 items-center gap-2.5">
            <Avatar name={l.full_name} size={34} tinted />
            <div className="min-w-0">
              <p className="truncate font-medium text-ink">{l.full_name}</p>
              <p className="truncate text-[12px] text-ink-3">{[sinifMetni(l.school_grade), l.school_name, date(l.created_at)].filter(Boolean).join(' · ')}</p>
            </div>
          </div>
        ),
      },
      {
        key: 'phone', header: 'Öğrenci telefonu',
        cell: (l) => <PhoneText value={l.phone} whatsapp />,
      },
      {
        key: 'guardian', header: 'Veli', hideable: true, maxWidth: 200,
        cell: (l) => l.guardian_name || l.guardian_phone ? (
          <div className="min-w-0" onClick={(e) => e.stopPropagation()}>
            <p className="text-ink-2"><PersonText>{l.guardian_name ?? '—'}</PersonText></p>
            {l.guardian_phone && <PhoneText value={l.guardian_phone} muted />}
          </div>
        ) : <span className="text-ink-3">—</span>,
      },
      {
        key: 'program', header: 'İlgilendiği program', hideable: true, maxWidth: 180,
        cell: (l) => (l.program?.name ? <span className="text-ink-2">{l.program.name}</span> : <span className="text-ink-3">—</span>),
      },
      {
        key: 'source', header: 'Nereden duydu', hideable: true, defaultHidden: true,
        cell: (l) => <span className="text-ink-2">{l.source_label || '—'}</span>,
      },
      {
        key: 'durum', header: 'Arama', sortKey: 'next_action_at',
        cell: (l) => {
          const d = aramaDurumu(l)
          return (
            <div className="min-w-0 whitespace-nowrap">
              {d === 'aranacak' ? (
                <>
                  <Badge tone={l.next_action_overdue ? 'danger' : 'warning'}>Aranacak</Badge>
                  <p className={`mt-0.5 text-[12px] tabular ${l.next_action_overdue ? 'font-medium text-danger' : 'text-ink-3'}`}>
                    {l.next_action_at ? `${dateTime(l.next_action_at)}${l.next_action_overdue ? ' · gecikti' : ''}` : 'Henüz aranmadı'}
                  </p>
                </>
              ) : d === 'arandi' ? (
                <>
                  <Badge tone="info">Arandı</Badge>
                  <p className="mt-0.5 text-[12px] text-ink-3">{l.last_contacted_at ? relative(l.last_contacted_at) : ''}</p>
                </>
              ) : (
                <>
                  <span className="text-ink-3">—</span>
                  <p className={`mt-0.5 max-w-[200px] truncate text-[12px] ${l.stage === 'won' ? 'text-success' : 'text-ink-3'}`} title={l.lost_reason ?? undefined}>
                    {l.stage === 'won' ? 'Kayıt oldu' : `Kaydolmadı${l.lost_reason ? ` · ${l.lost_reason}` : ''}`}
                  </p>
                </>
              )}
            </div>
          )
        },
      },
      {
        key: 'contacts', header: 'Görüşme', align: 'right', hideable: true, defaultHidden: true,
        // Görüşme sayısı; ikinci satır sorumlu personel
        cell: (l) => (
          <div>
            <p className={`tabular ${l.contact_count ? 'text-ink' : 'text-ink-3'}`}>{l.contact_count ?? 0}</p>
            <p className="truncate text-[12px] text-ink-3 max-w-[140px]">{l.owner?.name ?? 'Sorumlu yok'}</p>
          </div>
        ),
      },
      {
        key: 'actions', header: '', align: 'right',
        cell: (l) => (
          <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
            {l.student_id ? (
              <ButtonLink to={`/ogrenciler/${l.student_id}`} size="xs" variant="secondary" icon={<UserCheck className="size-3.5" />}>Öğrenci</ButtonLink>
            ) : yonetebilir ? (
              <>
                <Button size="xs" variant="info" icon={<PhoneCall className="size-3.5" />} onClick={() => setGorusme(l)}>Görüşme</Button>
                {can('students.create') && (
                  <Button size="xs" variant="success" icon={<UserPlus className="size-3.5" />} onClick={() => setDrawer({ id: l.id, tab: 'convert' })}>Kayıt</Button>
                )}
              </>
            ) : null}
          </div>
        ),
      },
    ],
    [can, yonetebilir],
  )

  const o = ozet.data
  const segment = (d: Durum) => ({ value: d, label: d !== 'tumu' && o ? `${DURUM_ETIKET[d]} ${num(o.durum[d])}` : DURUM_ETIKET[d] })

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Ön Kayıt"
        description="İlgilenen öğrencileri kaydedin, arayın; kayıt olursa tek tıkla öğrenci kaydına çevirin."
        actions={
          <>
            {can('reports.view') && <ButtonLink to="/raporlar/on-kayit" icon={<BarChart3 className="size-4" />}>Dönüşüm raporu</ButtonLink>}
            {yonetebilir && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setDrawer({ id: 'new' })}>Yeni ön kayıt</Button>}
          </>
        }
      />

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Takipteki ön kayıt" value={o ? num(o.durum.aktif) : '—'} sub="kayıt olmamış, vazgeçmemiş" loading={ozet.isLoading} />
        <Stat label="Araması geciken" value={o ? num(o.geciken) : '—'} tone={o && o.geciken > 0 ? 'danger' : undefined} sub="planlanan tarih geçti" loading={ozet.isLoading} />
        <Stat label="Bu ay ön kayıt" value={o ? num(o.bu_ay_yeni) : '—'} sub={o ? `bu ay ${num(o.bu_ay_kayit)} aday kayda döndü` : undefined} loading={ozet.isLoading} />
        <Stat label="Kayda dönüş" value={o?.donusum != null ? `%${num(o.donusum, 1)}` : '—'} sub="sonuçlanan ön kayıtlarda" loading={ozet.isLoading} />
      </div>

      <DataTable
        storageKey="on-kayit-v2"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => setDrawer({ id: r.id })}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ad ya da telefon ile ara" leading={<Search />} className="w-full sm:w-[260px]" />
            <Segmented
              size="sm"
              value={durum}
              onChange={(d) => list.update({ filters: { durum: d } })}
              options={(['aktif', 'aranacak', 'arandi', 'kayit', 'olmadi', 'tumu'] as Durum[]).map(segment)}
            />
          </div>
        }
        empty={
          <EmptyState
            icon={<ClipboardPlus />}
            title={durum === 'aktif' ? 'Takipte ön kayıt yok' : 'Bu durumda ön kayıt yok'}
            description="Kuruma gelen ya da arayan öğrenciyi ön kayıt olarak ekleyin."
            action={yonetebilir ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setDrawer({ id: 'new' })}>Yeni ön kayıt</Button> : undefined}
          />
        }
      />

      <LeadDrawer
        leadId={drawer?.id ?? null}
        initialTab={drawer?.tab}
        options={options.data}
        onClose={() => setDrawer(null)}
        onChanged={yenile}
        onConverted={(studentId) => { setDrawer(null); yenile(); navigate(`/ogrenciler/${studentId}`) }}
      />

      <GorusmeModal lead={gorusme} onClose={() => setGorusme(null)} onSaved={() => { setGorusme(null); yenile() }} />
    </div>
  )
}

type Sonuc = 'ulasildi' | 'ulasilamadi' | 'tekrar' | 'olmadi'

/** YYYY-MM-DDTHH:mm (yerel) — yarın saat 10:00 */
function yarinOn(): string {
  const d = new Date()
  d.setDate(d.getDate() + 1)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T10:00`
}

function GorusmeModal({ lead, onClose, onSaved }: { lead: LeadRow | null; onClose: () => void; onSaved: () => void }) {
  const [sonuc, setSonuc] = useState<Sonuc>('ulasildi')
  const [kanal, setKanal] = useState('call')
  const [not, setNot] = useState('')
  const [sonraki, setSonraki] = useState('')
  const [neden, setNeden] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!lead) return
    setSonuc('ulasildi'); setKanal('call'); setNot(''); setNeden(''); setErrors({}); setSonraki('')
  }, [lead])

  // Ulaşılamadı / tekrar aranacak seçilince sonraki arama tarihi önerilir
  useEffect(() => {
    if ((sonuc === 'ulasilamadi' || sonuc === 'tekrar') && !sonraki) setSonraki(yarinOn())
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sonuc])

  const kaydet = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/crm/leads/${lead!.id}/contact`, {
      sonuc, kanal, not: not || undefined,
      sonraki_arama: sonuc === 'olmadi' || !sonraki ? undefined : sonraki,
      neden: sonuc === 'olmadi' ? neden : undefined,
    }),
    onSuccess: (r) => { toast.success(r.message); onSaved() },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  return (
    <Modal
      open={!!lead}
      onClose={onClose}
      title="Görüşme kaydet"
      description={lead ? `${lead.full_name} · ${lead.phone}` : undefined}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={kaydet.isPending} onClick={() => kaydet.mutate()}>Kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label="Sonuç">
          <Segmented
            value={sonuc}
            onChange={setSonuc}
            options={[
              { value: 'ulasildi', label: 'Arandı' },
              { value: 'ulasilamadi', label: 'Ulaşılamadı' },
              { value: 'olmadi', label: 'Kaydolmayacak' },
            ]}
          />
        </Field>
        <Field label="Nasıl görüşüldü">
          <Select value={kanal} onChange={(e) => setKanal(e.target.value)} options={[{ value: 'call', label: 'Telefon' }, { value: 'whatsapp', label: 'WhatsApp' }, { value: 'meeting', label: 'Yüz yüze' }]} />
        </Field>
        <Field label="Not" error={errors.not?.[0]}>
          <Textarea rows={3} value={not} onChange={(e) => setNot(e.target.value)} placeholder="Görüşmede ne konuşuldu?" />
        </Field>
        {sonuc === 'olmadi' ? (
          <Field label="Neden kaydolmadı?" required error={errors.neden?.[0]}>
            <Input value={neden} onChange={(e) => setNeden(e.target.value)} placeholder="Örn. başka kuruma gitti, ücret" autoFocus />
          </Field>
        ) : (
          <Field label="Tekrar aranacak mı? (tarih)" hint="Tarih verirseniz ön kayıt &quot;Aranacak&quot; olur; boş bırakırsanız &quot;Arandı&quot; olarak kalır" error={errors.sonraki_arama?.[0]}>
            <Input type="datetime-local" value={sonraki} onChange={(e) => setSonraki(e.target.value)} />
          </Field>
        )}
      </div>
    </Modal>
  )
}
