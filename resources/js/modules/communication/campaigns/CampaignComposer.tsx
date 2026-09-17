import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarClock, CheckCircle2, Eye, FileText, Mail, Save, Send, Smartphone, Users } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, money, num } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Checkbox, Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { analyzeSms, ENCODING_LABEL, renderVars } from './smsLength'
import {
  emptyAudience, SKIP_LABEL, type Audience, type AudienceGroup, type CampaignChannel, type CampaignDetailData,
  type CampaignOptions, type ChannelStats, type PreviewSummary,
} from './types'

type Form = {
  name: string
  channels: CampaignChannel[]
  is_commercial: boolean
  audience: Audience
  sms_opt_out: boolean
  sms_body: string
  email_subject: string
  email_body: string
  when: 'now' | 'later'
  scheduled_at: string
}

const EMPTY: Form = {
  name: '', channels: ['sms'], is_commercial: false, audience: emptyAudience(), sms_opt_out: false,
  sms_body: '', email_subject: '', email_body: '', when: 'now', scheduled_at: '',
}

const SAMPLE_VARS: Record<string, string> = { ad: 'Ayşe', soyad: 'Yılmaz', ad_soyad: 'Ayşe Yılmaz', ogrenci_ad: 'Ali Yılmaz', sinif: '11-A', kurum: 'Kurum', kurum_tel: '0356 000 00 00' }

/** "2026-09-20T14:30" (yerel) ↔ ISO */
const toLocalInput = (iso: string | null) => {
  if (!iso) return ''
  const d = new Date(iso)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms)
    return () => clearTimeout(t)
  }, [value, ms])
  return v
}

/** Seçim çipleri (sınıf, program, durum) — dokunmatikte de rahat. */
function ChipSelect<T extends string | number>({ options, value, onChange, empty }: { options: { value: T; label: string }[]; value: T[]; onChange: (v: T[]) => void; empty?: string }) {
  if (options.length === 0) return <p className="text-[12.5px] text-ink-3">{empty ?? 'Seçenek yok'}</p>
  return (
    <div className="flex flex-wrap gap-1.5">
      {options.map((o) => {
        const on = value.includes(o.value)
        return (
          <button key={String(o.value)} type="button" aria-pressed={on}
            onClick={() => onChange(on ? value.filter((x) => x !== o.value) : [...value, o.value])}
            className={cn('h-7 max-w-full truncate rounded-[var(--radius-xs)] border px-2.5 text-[12.5px] transition-colors',
              on ? 'border-primary bg-primary-soft font-medium text-primary-ink' : 'border-line bg-surface text-ink-2 hover:border-line-strong hover:text-ink')}>
            {o.label}
          </button>
        )
      })}
    </div>
  )
}

function VariableChips({ variables, onInsert }: { variables: Record<string, string>; onInsert: (token: string) => void }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <span className="text-[12px] text-ink-3">Değişken ekle:</span>
      {Object.entries(variables).map(([k, label]) => (
        <button key={k} type="button" title={label} onClick={() => onInsert(`{${k}}`)}
          className="h-6 rounded-[var(--radius-xs)] border border-line bg-surface-2 px-1.5 font-mono text-[12px] text-ink-2 hover:border-primary/40 hover:text-primary">
          {`{${k}}`}
        </button>
      ))}
    </div>
  )
}

function insertAt(el: HTMLTextAreaElement | HTMLInputElement | null, current: string, token: string): string {
  if (!el) return current + token
  const start = el.selectionStart ?? current.length
  const end = el.selectionEnd ?? current.length
  const next = current.slice(0, start) + token + current.slice(end)
  requestAnimationFrame(() => { el.focus(); el.setSelectionRange(start + token.length, start + token.length) })
  return next
}

function Section({ step, title, description, children }: { step: number; title: string; description?: ReactNode; children: ReactNode }) {
  return (
    <Panel title={<span className="flex items-center gap-2"><span className="grid size-5 place-items-center rounded-full bg-primary text-[12px] font-semibold text-white">{step}</span>{title}</span>} description={description}>
      <div className="flex flex-col gap-4">{children}</div>
    </Panel>
  )
}

export default function CampaignComposer() {
  const { id } = useParams()
  const editingId = id ? Number(id) : null
  const can = useCan()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const canSend = can('messages.campaign_send')

  const [form, setForm] = useState<Form>(EMPTY)
  const [loaded, setLoaded] = useState(!editingId)
  const [manualText, setManualText] = useState('')
  const [sampleIndex, setSampleIndex] = useState(0)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [ack, setAck] = useState(false)
  const smsRef = useRef<HTMLTextAreaElement>(null)
  const subjectRef = useRef<HTMLInputElement>(null)
  const emailRef = useRef<HTMLTextAreaElement>(null)
  const set = (patch: Partial<Form>) => setForm((f) => ({ ...f, ...patch }))
  const setAudience = (patch: Partial<Audience>) => setForm((f) => ({ ...f, audience: { ...f.audience, ...patch } }))

  const options = useQuery({ queryKey: ['campaigns', 'options'], queryFn: () => api.get<CampaignOptions>('/campaigns/options'), staleTime: 60_000 })
  const existing = useQuery({
    queryKey: ['campaigns', 'detail', editingId],
    queryFn: () => api.get<{ data: CampaignDetailData }>(`/campaigns/${editingId}`),
    enabled: !!editingId,
  })

  useEffect(() => {
    const c = existing.data?.data
    if (!c) return
    if (c.status !== 'draft') {
      navigate(`/iletisim/toplu-gonderim/${c.id}`, { replace: true })
      return
    }
    setForm({
      name: c.name, channels: c.channels, is_commercial: c.is_commercial,
      audience: { ...emptyAudience(), ...c.audience },
      sms_opt_out: !!c.options?.sms_opt_out, sms_body: c.sms_body ?? '', email_subject: c.email_subject ?? '', email_body: c.email_body ?? '',
      when: c.scheduled_at ? 'later' : 'now', scheduled_at: toLocalInput(c.scheduled_at),
    })
    setManualText((c.audience.manual ?? []).map((m) => [m.name, m.phone, m.email].filter(Boolean).join('; ')).join('\n'))
    setLoaded(true)
  }, [existing.data, navigate])

  const opt = options.data
  const ch = opt?.channels
  const variables = opt?.variables ?? {}
  const knownVars = Object.keys(variables)
  const hasSms = form.channels.includes('sms')
  const hasEmail = form.channels.includes('email')
  const groups = form.audience.groups

  const payload = useMemo(() => ({
    name: form.name.trim() || 'Adsız gönderim',
    channels: form.channels,
    is_commercial: form.is_commercial,
    audience: form.audience,
    options: { sms_opt_out: form.sms_opt_out },
    sms_body: hasSms ? form.sms_body : null,
    email_subject: hasEmail ? form.email_subject : null,
    email_body: hasEmail ? form.email_body : null,
    scheduled_at: form.when === 'later' && form.scheduled_at ? new Date(form.scheduled_at).toISOString() : null,
  }), [form, hasSms, hasEmail])

  const debounced = useDebounced(payload, 500)
  const previewEnabled = loaded && debounced.channels.length > 0 && debounced.audience.groups.length > 0
  const preview = useQuery({
    queryKey: ['campaigns', 'preview', JSON.stringify({ ...debounced, name: '', scheduled_at: null })],
    queryFn: () => api.post<{ data: PreviewSummary }>('/campaigns/preview', debounced),
    enabled: previewEnabled,
    placeholderData: (prev) => prev,
    staleTime: 15_000,
  })
  const summary = previewEnabled ? preview.data?.data : undefined

  // Yerel (anlık) SMS sayacı: örnek değişkenlerle + ret metni
  const optOutText = hasSms && (form.is_commercial || form.sms_opt_out) ? ch?.sms.opt_out_text ?? null : null
  const smsLocal = useMemo(() => {
    const body = renderVars(form.sms_body, { ...SAMPLE_VARS, kurum: SAMPLE_VARS.kurum! }, knownVars).trimEnd()
    return analyzeSms(optOutText ? `${body} ${optOutText}` : body, ch?.sms.encoding ?? 'tr')
  }, [form.sms_body, optOutText, ch?.sms.encoding, knownVars])

  const parseManual = useMutation({
    mutationFn: () => api.post<{ data: Audience['manual'] }>('/campaigns/parse-manual', { text: manualText }),
    onSuccess: (r) => {
      setAudience({ manual: r.data, groups: r.data.length && !groups.includes('manual') ? [...groups, 'manual'] : groups })
      toast.success(`${r.data.length} kişi listeye alındı.`)
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Liste işlenemedi.'),
  })

  const saveDraft = async (): Promise<number> => {
    const body = { ...payload, name: form.name.trim(), scheduled_at: payload.scheduled_at }
    if (editingId) {
      await api.put(`/campaigns/${editingId}`, body)
      return editingId
    }
    const r = await api.post<{ data: { id: number } }>('/campaigns', body)
    return r.data.id
  }

  const save = useMutation({
    mutationFn: saveDraft,
    onSuccess: (newId) => {
      toast.success('Taslak kaydedildi.')
      qc.invalidateQueries({ queryKey: ['campaigns'] })
      if (!editingId) navigate(`/iletisim/toplu-gonderim/${newId}/duzenle`, { replace: true })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const sendable = summary ? Object.values(summary.channels).reduce((n, c) => n + (c?.sendable ?? 0), 0) : 0
  const smsStats = summary?.channels.sms
  const emailStats = summary?.channels.email

  const approve = useMutation({
    mutationFn: async () => {
      const campaignId = await saveDraft()
      return api.post<{ message: string; data: { id: number } }>(`/campaigns/${campaignId}/approve`, { sendable, sms_parts: smsStats?.parts ?? 0 })
    },
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['campaigns'] })
      qc.invalidateQueries({ queryKey: ['messages'] })
      setConfirmOpen(false)
      navigate(`/iletisim/toplu-gonderim/${r.data.id}`, { replace: true })
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'estimate_changed') {
        qc.invalidateQueries({ queryKey: ['campaigns', 'preview'] })
        toast.warning(e.firstError())
        setConfirmOpen(false)
        return
      }
      toast.error(e instanceof ApiError ? e.firstError() : 'Gönderim başlatılamadı.')
    },
  })

  const readiness = summary?.readiness ?? {}
  const notReady = form.channels.filter((c) => readiness[c] && !readiness[c]!.ok)
  const blockers: string[] = []
  if (!form.name.trim()) blockers.push('Gönderime bir ad verin.')
  if (form.channels.length === 0) blockers.push('En az bir kanal seçin.')
  if (groups.length === 0) blockers.push('En az bir alıcı grubu seçin.')
  if (hasSms && !form.sms_body.trim()) blockers.push('SMS metnini yazın.')
  if (hasEmail && (!form.email_subject.trim() || !form.email_body.trim())) blockers.push('E-posta konusu ve metnini yazın.')
  if (form.when === 'later' && (!form.scheduled_at || new Date(form.scheduled_at) <= new Date())) blockers.push('İleri bir gönderim zamanı seçin.')
  if (summary?.unknown_vars.length) blockers.push(`Tanımsız değişken: ${summary.unknown_vars.map((v) => `{${v}}`).join(', ')}`)
  notReady.forEach((c) => blockers.push(readiness[c]!.reason ?? 'Kanal hazır değil.'))
  if (summary && sendable === 0) blockers.push('Gönderilebilecek alıcı yok.')
  const canApprove = canSend && blockers.length === 0 && !!summary && !preview.isFetching

  const samples = summary?.samples ?? []
  const sample = samples[Math.min(sampleIndex, Math.max(0, samples.length - 1))]

  if (editingId && (existing.isLoading || !loaded)) {
    return <div className="flex flex-col gap-4"><Skeleton className="h-10 w-72" /><Skeleton className="h-96" /></div>
  }
  if (editingId && existing.isError) {
    return <EmptyState icon={<FileText />} title="Gönderim bulunamadı" action={<ButtonLink to="/iletisim/toplu-gonderim">Listeye dön</ButtonLink>} />
  }

  const channelToggle = (c: CampaignChannel, on: boolean) => set({ channels: on ? [...form.channels, c] : form.channels.filter((x) => x !== c) })

  return (
    <div className="animate-fade-in">
      <PageHeader
        title={editingId ? 'Gönderimi düzenle' : 'Yeni toplu gönderim'}
        description="Alıcıları seçin, metni yazın, sayıları kontrol edip onaylayın."
        breadcrumbs={[{ label: 'Toplu gönderim', to: '/iletisim/toplu-gonderim' }, { label: editingId ? form.name || 'Taslak' : 'Yeni' }]}
        actions={
          <>
            <Button icon={<Save className="size-4" />} loading={save.isPending} disabled={!form.name.trim() || form.channels.length === 0} onClick={() => save.mutate()}>Taslağı kaydet</Button>
            {canSend && (
              <Button variant="primary" icon={form.when === 'later' ? <CalendarClock className="size-4" /> : <Send className="size-4" />} disabled={!canApprove} onClick={() => { setAck(false); setConfirmOpen(true) }}>
                {form.when === 'later' ? 'Zamanla…' : 'Gönder…'}
              </Button>
            )}
          </>
        }
      />

      {!canSend && (
        <Alert tone="info" className="mb-4" title="Gönderim onayı yetkili personelde">
          Taslağı hazırlayıp kaydedebilirsiniz; gönderimi müdür ya da yönetici onaylar.
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_400px]">
        <div className="flex min-w-0 flex-col gap-4">
          {/* 1 — Temel */}
          <Section step={1} title="Gönderim türü ve kanal">
            <Field label="Gönderim adı" required hint="Yalnız sizin listenizde görünür (ör. Eylül kayıt duyurusu).">
              <Input value={form.name} maxLength={160} onChange={(e) => set({ name: e.target.value })} placeholder="Ör. 2026-2027 kayıt dönemi duyurusu" />
            </Field>
            <Field label="İleti türü" required>
              <Segmented<'info' | 'commercial'> value={form.is_commercial ? 'commercial' : 'info'} onChange={(v) => set({ is_commercial: v === 'commercial' })}
                options={[{ value: 'info', label: 'Duyuru / bilgilendirme' }, { value: 'commercial', label: 'Ticari (reklam)' }]} />
            </Field>
            {form.is_commercial ? (
              <Alert tone="accent" title="Ticari elektronik ileti">
                Yalnız ilgili kanalda <strong>ticari ileti onayı kayıtlı</strong> kişilere gönderilir (İYS). SMS'in sonuna ret metni, e-postaya abonelikten çıkma bağlantısı eklenir.
                {can('messages.consents') && <> Onayları <ButtonLink size="xs" variant="ghost" to="/iletisim/ileti-izinleri">İleti izinleri</ButtonLink> ekranından kaydedin.</>}
              </Alert>
            ) : (
              <p className="text-[12.5px] text-ink-3">Duyuru: kurum işleyişiyle ilgili bilgilendirme (tatil, sınav takvimi, veli toplantısı). Reklam/indirim içeren metinler için "Ticari" seçilmelidir.</p>
            )}
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {(['sms', 'email'] as const).map((c) => {
                const info = ch?.[c]
                const on = form.channels.includes(c)
                return (
                  <label key={c} className={cn('flex cursor-pointer items-start gap-3 rounded-[var(--radius-md)] border p-3 transition-colors', on ? 'border-primary bg-primary-soft/40' : 'border-line hover:border-line-strong')}>
                    <Checkbox checked={on} onChange={(v) => channelToggle(c, v)} />
                    <span className="min-w-0 flex-1">
                      <span className="flex flex-wrap items-center gap-1.5 text-[13.5px] font-medium text-ink">
                        {c === 'sms' ? <Smartphone className="size-4 text-ink-3" /> : <Mail className="size-4 text-ink-3" />}
                        {c === 'sms' ? 'SMS' : 'E-posta'}
                        {info && (info.ok ? <Badge tone={info.simulation ? 'warning' : 'success'} dot>{info.simulation ? 'Simülasyon' : 'Bağlı'}</Badge> : <Badge tone="neutral" dot>Bağlı değil</Badge>)}
                      </span>
                      {info && !info.ok && <span className="mt-1 block text-[12px] text-ink-3">{info.reason}</span>}
                      {c === 'sms' && info?.ok && ch?.sms.header && <span className="mt-1 block text-[12px] text-ink-3">Başlık: {ch.sms.header}</span>}
                      {c === 'email' && info?.ok && ch?.email.from && <span className="mt-1 block truncate text-[12px] text-ink-3">Gönderen: {ch.email.from}</span>}
                    </span>
                  </label>
                )
              })}
            </div>
            {ch && (!ch.sms.ok || !ch.email.ok) && can(['integrations.manage', 'integrations.sms', 'integrations.email']) && (
              <p className="text-[12.5px] text-ink-3">Bağlı olmayan kanal için taslak hazırlanabilir, gönderilemez. <ButtonLink size="xs" variant="ghost" to="/ayarlar/mesaj-kanallari">Mesaj kanallarını ayarla</ButtonLink></p>
            )}
          </Section>

          {/* 2 — Alıcılar */}
          <Section step={2} title="Alıcılar" description="Bir kişi birden çok grupta olsa da aynı adrese bir kez gönderilir.">
            {!opt ? <Skeleton className="h-24" /> : (
              <>
                <Field label="Kime gönderilecek" required hint="Bir ya da birkaç grup seçin.">
                  <ChipSelect<AudienceGroup> value={groups} onChange={(v) => setAudience({ groups: v })}
                    options={(Object.entries(opt.groups) as [AudienceGroup, string][]).map(([value, label]) => ({ value, label }))} />
                </Field>
                {(groups.includes('students') || groups.includes('guardians')) && (
                  <div className="flex flex-col gap-3 rounded-[var(--radius-md)] bg-surface-2 p-3 ring-1 ring-line">
                    <p className="text-[12.5px] font-medium text-ink-2">Öğrenci ve veli filtresi <span className="font-normal text-ink-3">(seçmezseniz tümü)</span></p>
                    <Field label="Öğrenci durumu" optional hint="Seçim yoksa yalnız aktif öğrenciler.">
                      <ChipSelect value={form.audience.student_statuses} onChange={(v) => setAudience({ student_statuses: v })}
                        options={Object.entries(opt.student_statuses).map(([value, label]) => ({ value, label }))} />
                    </Field>
                    <Field label="Program" optional>
                      <ChipSelect value={form.audience.program_ids} onChange={(v) => setAudience({ program_ids: v })} empty="Tanımlı program yok"
                        options={opt.programs.map((p) => ({ value: p.id, label: p.name }))} />
                    </Field>
                    <Field label="Sınıf" optional>
                      <ChipSelect value={form.audience.class_group_ids} onChange={(v) => setAudience({ class_group_ids: v })} empty="Aktif sınıf yok"
                        options={opt.class_groups.map((c) => ({ value: c.id, label: c.name }))} />
                    </Field>
                  </div>
                )}
                {groups.includes('leads') && (
                  <Field label="Ön kayıt aşaması" optional hint="Seçim yoksa kaydolmamış açık adaylar (kayıt olan ve kaybedilenler hariç).">
                    <ChipSelect value={form.audience.lead_stages} onChange={(v) => setAudience({ lead_stages: v })}
                      options={Object.entries(opt.lead_stages).map(([value, label]) => ({ value, label }))} />
                  </Field>
                )}
                <Field label="Listede olmayan kişileri elle ekle" optional hint={`Her satıra bir kişi: "Ad Soyad; 0532 000 00 00; ad@ornek.com". En fazla ${num(opt.limits.manual)} satır.${form.is_commercial ? ' Ticari gönderimde elle eklenenlerin onay kaydı olmadığından atlanır.' : ''}`}>
                  <Textarea rows={3} value={manualText} onChange={(e) => setManualText(e.target.value)} placeholder={'Ayşe Yılmaz; 0532 111 22 33; ayse@ornek.com\nMehmet Kaya; 0555 222 33 44'} />
                  <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" icon={<Users className="size-4" />} loading={parseManual.isPending} disabled={!manualText.trim()} onClick={() => parseManual.mutate()}>Listeyi işle</Button>
                    {form.audience.manual.length > 0 && (
                      <>
                        <Badge tone="info">{num(form.audience.manual.length)} kişi eklendi</Badge>
                        <Button size="xs" variant="ghost" onClick={() => { setAudience({ manual: [], groups: groups.filter((g) => g !== 'manual') }); setManualText('') }}>Temizle</Button>
                      </>
                    )}
                  </div>
                </Field>
              </>
            )}
          </Section>

          {/* 3 — İçerik */}
          <Section step={3} title="Metin" description="Değişkenler her alıcı için doldurulur; sağdaki önizlemeden kontrol edin.">
            {opt && opt.templates.length > 0 && (
              <Field label="Hazır şablondan başlat" optional>
                <Select value="" placeholder="Şablon seçin…" onChange={(e) => {
                  const t = opt.templates.find((x) => String(x.id) === e.target.value)
                  if (!t) return
                  if (t.channel === 'sms') set({ sms_body: t.body, channels: hasSms ? form.channels : [...form.channels, 'sms'] })
                  else set({ email_subject: t.subject ?? form.email_subject, email_body: t.body, channels: hasEmail ? form.channels : [...form.channels, 'email'] })
                  toast.success(`"${t.name}" şablonu yüklendi.`)
                }} options={opt.templates.map((t) => ({ value: t.id, label: `${t.channel === 'sms' ? 'SMS' : 'E-posta'} · ${t.name}` }))} />
              </Field>
            )}

            {hasSms && (
              <Field label="SMS metni" required>
                <Textarea ref={smsRef} rows={4} maxLength={opt?.limits.sms_body ?? 1000} value={form.sms_body} onChange={(e) => set({ sms_body: e.target.value })}
                  placeholder="Sayın {ad}, 2026-2027 kayıtlarımız başladı. Bilgi için {kurum_tel}" />
                <VariableChips variables={variables} onInsert={(t) => set({ sms_body: insertAt(smsRef.current, form.sms_body, t) })} />
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 text-[12.5px] text-ink-2 ring-1 ring-line tabular">
                  <span><strong className="text-ink">{num(smsLocal.length)}</strong> karakter</span>
                  <span><strong className={cn(smsLocal.parts > 1 ? 'text-warning' : 'text-ink')}>{smsLocal.parts}</strong> SMS</span>
                  <span>{ENCODING_LABEL[smsLocal.encoding]} · {smsLocal.parts > 1 ? `parça başı ${smsLocal.perPart}` : `tek SMS ${smsLocal.perPart}`}</span>
                  <span className="text-ink-3">bu SMS'te {smsLocal.remaining} karakter kaldı</span>
                </div>
                <p className="text-[12px] text-ink-3">Sayaç örnek adlarla hesaplanır; kişiye göre uzunluk değişebilir. Kesin toplam sağdaki önizlemededir.
                  {smsLocal.encoding === 'unicode' && ' Metinde emoji ya da özel karakter var: SMS başına 70 karaktere düşer.'}</p>
                {optOutText ? (
                  <p className="text-[12px] text-ink-2">Sona eklenecek: <em>{optOutText}</em></p>
                ) : form.is_commercial ? (
                  <Alert tone="warning">Ret metni tanımlı değil: Ayarlar › Mesaj kanalları › SMS'te RET numarasını girin.</Alert>
                ) : null}
                {!form.is_commercial && (
                  <Checkbox checked={form.sms_opt_out} onChange={(v) => set({ sms_opt_out: v })} label="Duyuruya da ret (RET) metni ekle" />
                )}
              </Field>
            )}

            {hasEmail && (
              <>
                <Field label="E-posta konusu" required>
                  <Input ref={subjectRef} maxLength={200} value={form.email_subject} onChange={(e) => set({ email_subject: e.target.value })} placeholder="{ad}, yeni dönem kayıtları başladı" />
                </Field>
                <Field label="E-posta metni" required hint="Düz metin: boş satır paragraf olur, bağlantılar tıklanabilir. Kurum logosu, iletişim bilgisi ve abonelikten çıkma bağlantısı otomatik eklenir.">
                  <Textarea ref={emailRef} rows={8} maxLength={opt?.limits.email_body ?? 20000} value={form.email_body} onChange={(e) => set({ email_body: e.target.value })}
                    placeholder={'Sayın {ad_soyad},\n\n…'} />
                  <VariableChips variables={variables} onInsert={(t) => set({ email_body: insertAt(emailRef.current, form.email_body, t) })} />
                </Field>
              </>
            )}
            {!hasSms && !hasEmail && <p className="text-[13px] text-ink-3">Önce bir kanal seçin.</p>}
          </Section>

          {/* 4 — Zaman */}
          <Section step={4} title="Gönderim zamanı">
            <Segmented<'now' | 'later'> value={form.when} onChange={(v) => set({ when: v })} options={[{ value: 'now', label: 'Onaylayınca hemen' }, { value: 'later', label: 'İleri tarihte' }]} />
            {form.when === 'later' && (
              <Field label="Gönderim tarihi ve saati" required hint="Zamanı gelince sırayla gönderilir; o zamana kadar iptal edilebilir.">
                <Input type="datetime-local" value={form.scheduled_at} min={toLocalInput(new Date().toISOString())} onChange={(e) => set({ scheduled_at: e.target.value })} className="sm:max-w-[260px]" />
              </Field>
            )}
            <p className="text-[12.5px] text-ink-3">Gönderim kuyrukta parça parça yapılır (dakikada sınırlı sayıda); büyük listelerde birkaç dakika sürebilir. Gece 21:00 sonrası ticari ileti göndermemeniz önerilir.</p>
          </Section>
        </div>

        {/* Önizleme */}
        <aside className="min-w-0 xl:sticky xl:top-4 xl:self-start">
          <Panel title={<span className="flex items-center gap-2"><Eye className="size-4 text-ink-3" />Önizleme</span>}
            actions={preview.isFetching ? <span className="text-[12px] text-ink-3">Hesaplanıyor…</span> : undefined}>
            {!previewEnabled ? (
              <p className="text-[13px] text-ink-3">Kanal ve alıcı grubu seçince kişi sayısı ve SMS kredisi burada görünür.</p>
            ) : !summary ? <Skeleton className="h-40" /> : (
              <div className="flex flex-col gap-4">
                <div className="grid grid-cols-2 gap-2">
                  <MiniStat label="Bulunan kişi" value={num(summary.people)} />
                  <MiniStat label="Ulaşılacak kişi" value={num(summary.reachable_people)} strong />
                </div>
                {smsStats && <ChannelSummary channel="sms" stats={smsStats} />}
                {emailStats && <ChannelSummary channel="email" stats={emailStats} />}

                {samples.length > 0 && (
                  <div className="flex flex-col gap-2">
                    <div className="flex items-center justify-between gap-2">
                      <p className="text-[12.5px] font-medium text-ink-2">Kişiselleştirme</p>
                      <Select aria-label="Örnek alıcı" className="w-[190px]" value={String(Math.min(sampleIndex, samples.length - 1))} onChange={(e) => setSampleIndex(Number(e.target.value))}
                        options={samples.map((s, i) => ({ value: i, label: `${s.name} (${opt?.groups[s.group] ?? s.group})` }))} />
                    </div>
                    {sample?.sms != null && (
                      <div className="rounded-[var(--radius-md)] bg-surface-2 p-3 ring-1 ring-line">
                        <p className="mb-1.5 flex items-center justify-between text-[12px] text-ink-3">
                          <span className="inline-flex items-center gap-1"><Smartphone className="size-3.5" />{ch?.sms.header ?? 'SMS'}</span>
                          {sample.sms_analysis && <span className="tabular">{sample.sms_analysis.length} kr · {sample.sms_analysis.parts} SMS</span>}
                        </p>
                        <p className="whitespace-pre-wrap break-words rounded-[12px] rounded-tl-[4px] bg-surface px-3 py-2 text-[13px] text-ink ring-1 ring-line">{sample.sms || '—'}</p>
                      </div>
                    )}
                    {sample?.email_subject != null && (
                      <div className="overflow-hidden rounded-[var(--radius-md)] ring-1 ring-line">
                        <div className="bg-primary px-3 py-2 text-[12px] font-semibold text-white">E-posta</div>
                        <div className="bg-surface px-3 py-3">
                          <p className="text-[14px] font-semibold text-ink break-words">{sample.email_subject || '(konu yok)'}</p>
                          <p className="mt-2 max-h-56 overflow-y-auto whitespace-pre-wrap break-words text-[13px] leading-relaxed text-ink-2 scroll-thin">{sample.email_body || '—'}</p>
                          <p className="mt-3 border-t border-line pt-2 text-[12px] text-ink-3">Altbilgi: kurum bilgileri · abonelikten çıkma bağlantısı</p>
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {blockers.length > 0 && (
                  <Alert tone="warning" title="Göndermeden önce">
                    <ul className="list-disc pl-4">{blockers.map((b) => <li key={b}>{b}</li>)}</ul>
                  </Alert>
                )}
              </div>
            )}
          </Panel>
        </aside>
      </div>

      <ConfirmSend
        open={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        loading={approve.isPending}
        onConfirm={() => approve.mutate()}
        summary={summary}
        commercial={form.is_commercial}
        scheduledAt={form.when === 'later' ? payload.scheduled_at : null}
        ack={ack}
        setAck={setAck}
        simulation={{ sms: !!ch?.sms.simulation && hasSms, email: !!ch?.email.simulation && hasEmail }}
      />
    </div>
  )
}

function MiniStat({ label, value, strong }: { label: string; value: ReactNode; strong?: boolean }) {
  return (
    <div className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 ring-1 ring-line">
      <p className="text-[12px] text-ink-3">{label}</p>
      <p className={cn('text-[18px] font-semibold tabular', strong ? 'text-primary' : 'text-ink')}>{value}</p>
    </div>
  )
}

function ChannelSummary({ channel, stats }: { channel: CampaignChannel; stats: ChannelStats }) {
  const skipped = (['no_address', 'invalid', 'no_consent', 'denied', 'suppressed', 'duplicate'] as const).filter((k) => stats[k] > 0)
  return (
    <div className="rounded-[var(--radius-md)] ring-1 ring-line">
      <div className="flex items-center justify-between gap-2 border-b border-line px-3 py-2">
        <span className="inline-flex items-center gap-1.5 text-[13px] font-medium text-ink">
          {channel === 'sms' ? <Smartphone className="size-4 text-ink-3" /> : <Mail className="size-4 text-ink-3" />}
          {channel === 'sms' ? 'SMS' : 'E-posta'}
        </span>
        <span className="text-[13px] tabular"><strong className="text-ink">{num(stats.sendable)}</strong> <span className="text-ink-3">/ {num(stats.total)} alıcı</span></span>
      </div>
      <div className="flex flex-col gap-1.5 px-3 py-2 text-[12.5px]">
        {channel === 'sms' && (
          <div className="flex flex-wrap items-center justify-between gap-2 tabular">
            <span className="text-ink-2">{num(stats.parts ?? 0)} SMS ≈ {num(stats.parts ?? 0)} kredi</span>
            <span className="text-ink-3">
              {stats.encoding ? ENCODING_LABEL[stats.encoding] : ''}{(stats.max_parts ?? 0) > 1 ? ` · kişi başı en çok ${stats.max_parts}` : ''}
            </span>
          </div>
        )}
        {channel === 'sms' && stats.cost != null && <p className="text-ink-2 tabular">Tahmini maliyet: <strong className="text-ink">{money(stats.cost)}</strong> <span className="text-ink-3">(SMS başı {money(stats.unit_price ?? 0)})</span></p>}
        {skipped.length === 0 ? (
          <p className="inline-flex items-center gap-1 text-success"><CheckCircle2 className="size-3.5" />Atlanan alıcı yok</p>
        ) : (
          <ul className="flex flex-col gap-0.5">
            {skipped.map((k) => (
              <li key={k} className="flex items-center justify-between gap-2">
                <span className="text-ink-2">{SKIP_LABEL[k]}</span>
                <span className="tabular text-warning">{num(stats[k])}</span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}

function ConfirmSend({ open, onClose, onConfirm, loading, summary, commercial, scheduledAt, ack, setAck, simulation }: {
  open: boolean; onClose: () => void; onConfirm: () => void; loading: boolean; summary?: PreviewSummary; commercial: boolean
  scheduledAt: string | null; ack: boolean; setAck: (v: boolean) => void; simulation: { sms: boolean; email: boolean }
}) {
  const sms = summary?.channels.sms
  const email = summary?.channels.email
  return (
    <Modal open={open} onClose={onClose} title={scheduledAt ? 'Gönderim zamanlansın mı?' : 'Gönderim başlasın mı?'}
      description="Onaydan sonra alıcı listesi sabitlenir; gönderim kuyrukta sırayla yapılır."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={loading} disabled={commercial && !ack} icon={<Send className="size-4" />} onClick={onConfirm}>
            {scheduledAt ? 'Onayla ve zamanla' : 'Onayla ve gönder'}
          </Button>
        </>
      }>
      <div className="flex flex-col gap-3">
        {sms && (
          <div className="rounded-[var(--radius-md)] bg-surface-2 p-3 ring-1 ring-line">
            <p className="flex items-center gap-2 text-[14px] text-ink"><Smartphone className="size-4 text-ink-3" />
              <span><strong className="tabular">{num(sms.sendable)}</strong> kişiye <strong className="tabular">{num(sms.parts ?? 0)}</strong> SMS ≈ <strong className="tabular">{num(sms.parts ?? 0)}</strong> kredi</span>
            </p>
            {sms.cost != null && <p className="mt-1 pl-6 text-[12.5px] text-ink-2">Tahmini maliyet {money(sms.cost)}</p>}
            {simulation.sms && <p className="mt-1 pl-6 text-[12.5px] text-warning">Simülasyon: gerçek SMS gönderilmez.</p>}
          </div>
        )}
        {email && (
          <div className="rounded-[var(--radius-md)] bg-surface-2 p-3 ring-1 ring-line">
            <p className="flex items-center gap-2 text-[14px] text-ink"><Mail className="size-4 text-ink-3" /><span><strong className="tabular">{num(email.sendable)}</strong> kişiye e-posta</span></p>
            {simulation.email && <p className="mt-1 pl-6 text-[12.5px] text-warning">Simülasyon: gerçek e-posta gönderilmez.</p>}
          </div>
        )}
        {scheduledAt && <p className="text-[13px] text-ink-2">Gönderim zamanı: <strong>{dateTime(scheduledAt)}</strong></p>}
        {commercial && (
          <Checkbox checked={ack} onChange={setAck}
            label="Bu ileti ticaridir; yalnız onay veren alıcılara gönderileceğini ve ret seçeneği içerdiğini onaylıyorum." />
        )}
      </div>
    </Modal>
  )
}
