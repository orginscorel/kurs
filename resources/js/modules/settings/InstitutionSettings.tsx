import { useEffect, useRef, useState } from 'react'
import { PhoneText } from '@/components/ui/contact'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Building2, ImagePlus, Plus, Star, Wand2 } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import { useCan } from '@/app/auth'
import { api, ApiError } from '@/lib/api'
import { date } from '@/lib/format'
import { PageHeader, Panel, Tabs } from '@/components/ui/layout'
import { Field, Input, Select, Switch } from '@/components/ui/form'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Drawer } from '@/components/ui/overlay'
import type { AcademicTerm, AttendanceSettings, BackupSettings, Branch, DisciplinePortalSettings, FinanceReminderSettings, Institution, PortalSettings, RetentionSettings } from './types'

type Tab = 'kurum' | 'yoklama' | 'odeme' | 'portal' | 'kvkk' | 'yedek' | 'subeler' | 'donemler'
const TABS: Tab[] = ['kurum', 'yoklama', 'odeme', 'portal', 'kvkk', 'yedek', 'subeler', 'donemler']

export default function InstitutionSettings() {
  // ?sekme=portal ile doğrudan sekme açılabilir
  const [params, setParams] = useSearchParams()
  const fromUrl = params.get('sekme') as Tab | null
  const [tab, setTabState] = useState<Tab>(fromUrl && TABS.includes(fromUrl) ? fromUrl : 'kurum')
  const setTab = (t: Tab) => {
    setTabState(t)
    setParams((p) => { if (t === 'kurum') p.delete('sekme'); else p.set('sekme', t); return p }, { replace: true })
  }

  return (
    <div className="animate-fade-in">
      <PageHeader title="Kurum ayarları" description="Kurum bilgileri, kurallar ve şube yönetimi" actions={<ButtonLink to="/kurulum" icon={<Wand2 className="size-4" />}>Kurulum sihirbazını aç</ButtonLink>} />
      <Tabs
        value={tab}
        onChange={setTab}
        className="mb-5"
        tabs={[
          { value: 'kurum', label: 'Kurum bilgileri' },
          { value: 'yoklama', label: 'Yoklama kuralları' },
          { value: 'odeme', label: 'Ödeme hatırlatma' },
          { value: 'portal', label: 'Portal' },
          { value: 'kvkk', label: 'KVKK saklama' },
          { value: 'yedek', label: 'Yedekleme politikası' },
          { value: 'subeler', label: 'Şubeler' },
          { value: 'donemler', label: 'Akademik dönemler' },
        ]}
      />
      {tab === 'kurum' && <InstitutionTab />}
      {tab === 'yoklama' && <GroupTab<AttendanceSettings> group="attendance" title="Yoklama kuralları" render={AttendanceFields} />}
      {tab === 'odeme' && <GroupTab<FinanceReminderSettings> group="finance" title="Ödeme hatırlatma kuralları" render={FinanceFields} />}
      {tab === 'portal' && <PortalTab />}
      {tab === 'kvkk' && <GroupTab<RetentionSettings> group="retention" title="KVKK saklama süreleri" render={RetentionFields} />}
      {tab === 'yedek' && <GroupTab<BackupSettings> group="backup" title="Yedekleme politikası" render={BackupFields} />}
      {tab === 'subeler' && <BranchesTab />}
      {tab === 'donemler' && <TermsTab />}
    </div>
  )
}

function InstitutionTab() {
  const qc = useQueryClient()
  const fileRef = useRef<HTMLInputElement>(null)
  const { data } = useQuery({ queryKey: ['settings', 'institution'], queryFn: () => api.get<Institution>('/settings/institution') })
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => { if (data) setForm(data) }, [data])

  const save = useMutation({
    mutationFn: () => api.put<Institution>('/settings/institution', {
      name: form.name, short_name: form.short_name, phone: form.phone || null, email: form.email || null, website: form.website || null,
      address: form.address || null, tax_office: form.tax_office || null, tax_number: form.tax_number || null, currency: form.currency, timezone: form.timezone,
    }),
    onSuccess: () => { toast.success('Kurum bilgileri güncellendi.'); qc.invalidateQueries({ queryKey: ['settings', 'institution'] }) },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  const uploadLogo = useMutation({
    mutationFn: (file: File) => { const fd = new FormData(); fd.append('logo', file); return api.post<{ logo_url: string }>('/settings-institution/logo', fd) },
    onSuccess: () => { toast.success('Logo güncellendi.'); qc.invalidateQueries({ queryKey: ['settings', 'institution'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Logo yüklenemedi.'),
  })

  if (!data) return null

  return (
    <Panel title="Kurum bilgileri">
      <div className="flex items-center gap-4 mb-5">
        <div className="size-16 rounded-[var(--radius-md)] ring-1 ring-line bg-surface-2 grid place-items-center overflow-hidden shrink-0">
          {data.logo_url ? <img src={data.logo_url} alt="Logo" className="h-full w-full object-contain" /> : <Building2 className="size-6 text-ink-3" />}
        </div>
        <div>
          <Button size="sm" icon={<ImagePlus className="size-3.5" />} onClick={() => fileRef.current?.click()} loading={uploadLogo.isPending}>Logo yükle</Button>
          <input ref={fileRef} type="file" accept="image/*" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadLogo.mutate(f) }} />
          <p className="mt-1 text-[12px] text-ink-3">PNG/JPG, en fazla 2 MB</p>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Kurum adı" required error={errors.name?.[0]}><Input value={form.name ?? ''} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
        <Field label="Kısa ad" optional hint="Belge ve bildirim başlıklarında"><Input value={form.short_name ?? ''} onChange={(e) => setForm((f) => ({ ...f, short_name: e.target.value }))} /></Field>
        <Field label="Telefon" optional error={errors.phone?.[0]}><Input inputMode="tel" placeholder="0356 xxx xx xx" value={form.phone ?? ''} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} /></Field>
        <Field label="E-posta" optional error={errors.email?.[0]}><Input type="email" inputMode="email" value={form.email ?? ''} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} /></Field>
        <Field label="Web sitesi" optional error={errors.website?.[0]}><Input placeholder="https://" value={form.website ?? ''} onChange={(e) => setForm((f) => ({ ...f, website: e.target.value }))} /></Field>
        <Field label="Adres" optional className="sm:col-span-2"><Input value={form.address ?? ''} onChange={(e) => setForm((f) => ({ ...f, address: e.target.value }))} /></Field>
        <Field label="Vergi dairesi" optional><Input value={form.tax_office ?? ''} onChange={(e) => setForm((f) => ({ ...f, tax_office: e.target.value }))} /></Field>
        <Field label="Vergi numarası" optional error={errors.tax_number?.[0]}><Input inputMode="numeric" value={form.tax_number ?? ''} onChange={(e) => setForm((f) => ({ ...f, tax_number: e.target.value }))} /></Field>
        <Field label="Para birimi" required><Select value={form.currency ?? 'TRY'} onChange={(e) => setForm((f) => ({ ...f, currency: e.target.value }))} options={[{ value: 'TRY', label: 'TRY — Türk Lirası' }]} /></Field>
        <Field label="Saat dilimi" required><Select value={form.timezone ?? 'Europe/Istanbul'} onChange={(e) => setForm((f) => ({ ...f, timezone: e.target.value }))} options={[{ value: 'Europe/Istanbul', label: 'Europe/Istanbul' }]} /></Field>
      </div>
      <div className="mt-4 flex justify-end"><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button></div>
    </Panel>
  )
}

function GroupTab<T extends Record<string, any>>({ group, title, render }: { group: string; title: string; render: (form: T, set: (k: string, v: unknown) => void) => React.ReactNode }) {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['settings', group], queryFn: () => api.get<T>(`/settings/${group}`) })
  const [form, setForm] = useState<Record<string, any>>({})

  useEffect(() => { if (data) setForm(data) }, [data])

  const save = useMutation({
    mutationFn: () => api.put(`/settings/${group}`, form),
    onSuccess: () => { toast.success(`${title} güncellendi.`); qc.invalidateQueries({ queryKey: ['settings', group] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const set = (key: string, value: unknown) => setForm((f) => ({ ...f, [key]: value }))
  if (!data) return null

  return (
    <Panel title={title}>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">{render(form as T, set)}</div>
      <div className="mt-4 flex justify-end"><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button></div>
    </Panel>
  )
}

/**
 * Öğrenci / veli / öğretmen portalı ayarları tek yerde: veli talepleri, gecikme uyarısı (Finans ayarlarıyla ortak anahtar)
 * ve disiplin portal görünürlüğü (Disiplin ayarlarıyla ortak anahtar).
 */
function PortalTab() {
  const can = useCan()
  const qc = useQueryClient()
  const canDiscipline = can('discipline.view')
  const canDisciplineEdit = can('discipline.settings')
  const portal = useQuery({ queryKey: ['settings', 'portal'], queryFn: () => api.get<PortalSettings>('/settings/portal') })
  const discipline = useQuery({
    queryKey: ['discipline', 'settings'],
    queryFn: () => api.get<{ data: DisciplinePortalSettings }>('/discipline/settings').then((r) => r.data),
    enabled: canDiscipline,
  })
  const [form, setForm] = useState<PortalSettings | null>(null)
  const [disc, setDisc] = useState<DisciplinePortalSettings | null>(null)

  useEffect(() => { if (portal.data) setForm(portal.data) }, [portal.data])
  useEffect(() => { if (discipline.data) setDisc(discipline.data) }, [discipline.data])

  const save = useMutation({
    mutationFn: async () => {
      if (!form) return
      await api.put('/settings/portal', {
        guardian_requests_enabled: !!form.guardian_requests_enabled,
        show_student_overdue_alert: !!form.show_student_overdue_alert,
        show_guardian_overdue_alert: !!form.show_guardian_overdue_alert,
      })
      if (disc && canDisciplineEdit) {
        await api.put('/discipline/settings', {
          portal_enabled: disc.portal_enabled,
          portal_defense_requests: disc.portal_defense_requests,
          portal_defense_submission: disc.portal_defense_submission,
          teacher_portal_reporting: disc.teacher_portal_reporting,
        })
      }
    },
    onSuccess: () => {
      toast.success('Portal ayarları kaydedildi.')
      qc.invalidateQueries({ queryKey: ['settings', 'portal'] })
      qc.invalidateQueries({ queryKey: ['discipline'] })
      qc.invalidateQueries({ queryKey: ['finance', 'settings'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  if (!form || (canDiscipline && !disc && discipline.isLoading)) return <Skeleton className="h-72 rounded-[var(--radius-lg)]" />
  const setP = (k: keyof PortalSettings, v: boolean) => setForm((f) => (f ? { ...f, [k]: v } : f))
  const setD = (k: keyof DisciplinePortalSettings, v: boolean) => setDisc((f) => (f ? { ...f, [k]: v } : f))

  return (
    <div className="flex flex-col gap-4">
      <Panel title="Veli portalı" description="Velinin portal üzerinden neler yapabileceği">
        <div className="flex flex-col gap-3">
          <SettingSwitch
            checked={!!form.guardian_requests_enabled}
            onChange={(v) => setP('guardian_requests_enabled', v)}
            label="Velilerden öğretmene mesaj / görüşme talebi al"
            hint="Talep kayda düşer ve öğretmene uygulama içi bildirim gider; SMS/WhatsApp gönderilmez. Kapalıyken veli yalnız kurum telefonunu görür."
          />
          <SettingSwitch
            checked={!!form.show_guardian_overdue_alert}
            onChange={(v) => setP('show_guardian_overdue_alert', v)}
            label="Veliye gecikmiş ödeme uyarısı göster"
            hint="Veli portalının üstünde vadesi geçmiş taksit bandı."
          />
        </div>
      </Panel>

      <Panel title="Öğrenci portalı">
        <SettingSwitch
          checked={!!form.show_student_overdue_alert}
          onChange={(v) => setP('show_student_overdue_alert', v)}
          label="Öğrenciye gecikmiş ödeme uyarısı göster"
          hint="Kapatırsanız ödeme uyarısı yalnız veliye gösterilir."
        />
      </Panel>

      {canDiscipline && disc && (
        <Panel title="Disiplin (portal görünürlüğü)" description="Disiplin › Ayarlar ekranındaki anahtarlarla aynıdır">
          {!canDisciplineEdit && <Alert tone="info" className="mb-3">Bu ayarları değiştirmek için disiplin ayarları yetkisi gerekir.</Alert>}
          <div className="flex flex-col gap-3">
            <SettingSwitch disabled={!canDisciplineEdit} checked={disc.portal_enabled} onChange={(v) => setD('portal_enabled', v)}
              label="Sonuçlanan yaptırımlar öğrenci/veli portalında görünsün" hint="Yalnız yürürlüğe girmiş ve portala açık işaretli yaptırımlar." />
            <SettingSwitch disabled={!canDisciplineEdit || !disc.portal_enabled} checked={disc.portal_defense_requests} onChange={(v) => setD('portal_defense_requests', v)}
              label="Savunma istemleri portalda görünsün" />
            <SettingSwitch disabled={!canDisciplineEdit || !disc.portal_enabled || !disc.portal_defense_requests} checked={disc.portal_defense_submission} onChange={(v) => setD('portal_defense_submission', v)}
              label="Öğrenci savunmasını portaldan yazabilsin" />
            <SettingSwitch disabled={!canDisciplineEdit} checked={disc.teacher_portal_reporting} onChange={(v) => setD('teacher_portal_reporting', v)}
              label="Öğretmenler portaldan olay bildirebilsin" hint="Öğretmen portalı › Olay bildir." />
          </div>
        </Panel>
      )}

      <div className="flex justify-end"><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button></div>
    </div>
  )
}

function SettingSwitch({ checked, onChange, label, hint, disabled }: { checked: boolean; onChange: (v: boolean) => void; label: string; hint?: string; disabled?: boolean }) {
  return (
    <div className="flex flex-col gap-0.5">
      <Switch checked={checked} onChange={onChange} disabled={disabled} label={label} />
      {hint && <p className="pl-[46px] text-[12px] text-ink-3">{hint}</p>}
    </div>
  )
}

function AttendanceFields(form: AttendanceSettings, set: (k: string, v: unknown) => void) {
  return (
    <>
      <Field required label="Kaç dakika sonra geç sayılsın" hint="Ders başlangıcından itibaren"><Input type="number" min={0} value={form.late_after_minutes ?? 0} onChange={(e) => set('late_after_minutes', Number(e.target.value))} /></Field>
      <Field required label="Kaç dakika sonra gelmedi sayılsın"><Input type="number" min={0} value={form.absent_after_minutes ?? 0} onChange={(e) => set('absent_after_minutes', Number(e.target.value))} /></Field>
      <Field required label="Veliye devamsızlık bildirimi kaç dakika sonra gitsin"><Input type="number" min={0} value={form.notify_guardian_absent_delay_minutes ?? 0} onChange={(e) => set('notify_guardian_absent_delay_minutes', Number(e.target.value))} /></Field>
      <Field required label="Aynı kart okutması için en az aralık (dakika)" hint="Art arda okutmalar tek sayılır"><Input type="number" min={0} value={form.min_minutes_between_events ?? 0} onChange={(e) => set('min_minutes_between_events', Number(e.target.value))} /></Field>
      <div className="sm:col-span-2 flex flex-col gap-2.5 pt-1">
        <Switch checked={!!form.auto_absence_enabled} onChange={(v) => set('auto_absence_enabled', v)} label="Otomatik devamsızlık tespiti" />
        <Switch checked={!!form.notify_guardian_entry} onChange={(v) => set('notify_guardian_entry', v)} label="Girişte veliyi bilgilendir" />
        <Switch checked={!!form.notify_guardian_exit} onChange={(v) => set('notify_guardian_exit', v)} label="Çıkışta veliyi bilgilendir" />
      </div>
    </>
  )
}

function FinanceFields(form: FinanceReminderSettings, set: (k: string, v: unknown) => void) {
  return (
    <>
      <Field required label="Hatırlatma saati"><Input type="time" value={form.reminder_hour ?? '10:00'} onChange={(e) => set('reminder_hour', e.target.value)} /></Field>
      <Field required label="Hatırlatma günleri (vadeye göre)" hint="Virgülle ayırın. Ör. -3, 0, 7 → vadeden 3 gün önce, vade günü ve 7 gün sonra" className="sm:col-span-2">
        <Input value={(form.reminder_offsets ?? []).join(', ')} onChange={(e) => set('reminder_offsets', e.target.value.split(',').map((s) => Number(s.trim())).filter((n) => !Number.isNaN(n)))} placeholder="-5, -2, 0, 3, 7" />
      </Field>
      <div className="sm:col-span-2"><Switch checked={!!form.reminders_enabled} onChange={(v) => set('reminders_enabled', v)} label="Otomatik hatırlatmalar açık" /></div>
      <div className="sm:col-span-2 border-t border-line pt-3 text-[13px] font-medium text-ink-2">Belge numarası önekleri</div>
      <Field required label="Makbuz öneki" hint={`Örnek: ${form.receipt_prefix || 'MKB'}-${new Date().getFullYear()}-000123`}>
        <Input value={form.receipt_prefix ?? ''} maxLength={10} placeholder="MKB" onChange={(e) => set('receipt_prefix', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''))} />
      </Field>
      <Field required label="Kayıt öneki" hint={`Örnek: ${form.enrollment_prefix || 'KYT'}-${new Date().getFullYear()}-000045`}>
        <Input value={form.enrollment_prefix ?? ''} maxLength={10} placeholder="KYT" onChange={(e) => set('enrollment_prefix', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''))} />
      </Field>
      <p className="sm:col-span-2 text-[12px] text-ink-3">Önek yalnız yeni belgelere uygulanır; sıra numarası devam eder, eski makbuz ve kayıt numaraları değişmez.</p>
    </>
  )
}

function RetentionFields(form: RetentionSettings, set: (k: string, v: unknown) => void) {
  return (
    <>
      <Field required label="Kart/yoklama kayıtları kaç gün saklansın"><Input type="number" min={30} value={form.attendance_events_days ?? 0} onChange={(e) => set('attendance_events_days', Number(e.target.value))} /></Field>
      <Field required label="Gönderilen mesajlar kaç gün saklansın"><Input type="number" min={30} value={form.outbound_messages_days ?? 0} onChange={(e) => set('outbound_messages_days', Number(e.target.value))} /></Field>
      <Field required label="Giriş denemeleri kaç gün saklansın"><Input type="number" min={30} value={form.login_events_days ?? 0} onChange={(e) => set('login_events_days', Number(e.target.value))} /></Field>
      <Field required label="Ayrılan öğrencinin kişisel bilgileri kaç gün sonra silinsin" hint="KVKK; en az 365 gün"><Input type="number" min={365} value={form.withdrawn_student_anonymize_after_days ?? 0} onChange={(e) => set('withdrawn_student_anonymize_after_days', Number(e.target.value))} /></Field>
    </>
  )
}

function BackupFields(form: BackupSettings, set: (k: string, v: unknown) => void) {
  return (
    <>
      <Field required label="Kaç günlük yedek saklansın"><Input type="number" min={1} value={form.daily_keep ?? 0} onChange={(e) => set('daily_keep', Number(e.target.value))} /></Field>
      <Field required label="Kaç haftalık yedek saklansın"><Input type="number" min={1} value={form.weekly_keep ?? 0} onChange={(e) => set('weekly_keep', Number(e.target.value))} /></Field>
      <div className="sm:col-span-2"><Switch checked={!!form.daily_enabled} onChange={(v) => set('daily_enabled', v)} label="Günlük otomatik yedekleme açık" /></div>
    </>
  )
}

function BranchesTab() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['branches'], queryFn: () => api.get<Branch[]>('/branches') })
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const save = useMutation({
    mutationFn: () => api.post('/branches', form),
    onSuccess: () => { toast.success('Şube eklendi.'); qc.invalidateQueries({ queryKey: ['branches'] }); setOpen(false); setForm({}) },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  const toggle = useMutation({
    mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => api.put(`/branches/${id}`, { is_active }),
    onSuccess: () => { toast.success('Şube güncellendi.'); qc.invalidateQueries({ queryKey: ['branches'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Güncellenemedi.'),
  })

  return (
    <Panel title="Şubeler" description="Çok şube hazırlığı" actions={<Button size="sm" icon={<Plus className="size-3.5" />} onClick={() => setOpen(true)}>Şube ekle</Button>}>
      {!data || data.length === 0 ? <EmptyState title="Şube yok" /> : (
        <div className="flex flex-col divide-y divide-line">
          {data.map((b) => (
            <div key={b.id} className="flex items-center justify-between py-2.5">
              <div>
                <p className="text-[13.5px] text-ink font-medium">{b.name} <span className="text-ink-3 font-normal">({b.code})</span></p>
                <p className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[12.5px] text-ink-3">{b.city && <span>İl: {b.city}</span>}{b.phone && <PhoneText value={b.phone} muted />}<span>{b.users_count} kullanıcı</span></p>
              </div>
              <div className="flex items-center gap-2">
                <Badge tone={b.is_active ? 'success' : 'neutral'} dot>{b.is_active ? 'Aktif' : 'Pasif'}</Badge>
                <Switch checked={b.is_active} onChange={(v) => toggle.mutate({ id: b.id, is_active: v })} />
              </div>
            </div>
          ))}
        </div>
      )}

      <Drawer open={open} onClose={() => setOpen(false)} width={420} title="Yeni şube" footer={<><Button variant="ghost" onClick={() => setOpen(false)}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Ekle</Button></>}>
        <div className="grid grid-cols-1 gap-3">
          <Field label="Şube kodu" required hint="Kısa, benzersiz; ör. MRK" error={errors.code?.[0]}><Input value={form.code ?? ''} onChange={(e) => setForm((f) => ({ ...f, code: e.target.value }))} /></Field>
          <Field label="Şube adı" required error={errors.name?.[0]}><Input value={form.name ?? ''} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
          <Field label="İl" optional><Input value={form.city ?? ''} onChange={(e) => setForm((f) => ({ ...f, city: e.target.value }))} /></Field>
          <Field label="Telefon" optional><Input inputMode="tel" value={form.phone ?? ''} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} /></Field>
          <Field label="Adres" optional><Input value={form.address ?? ''} onChange={(e) => setForm((f) => ({ ...f, address: e.target.value }))} /></Field>
        </div>
      </Drawer>
    </Panel>
  )
}

function TermsTab() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['academic-terms'], queryFn: () => api.get<AcademicTerm[]>('/academic-terms') })
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const save = useMutation({
    mutationFn: () => api.post('/academic-terms', form),
    onSuccess: () => { toast.success('Dönem eklendi.'); qc.invalidateQueries({ queryKey: ['academic-terms'] }); setOpen(false); setForm({}) },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  const setCurrent = useMutation({
    mutationFn: (id: number) => api.post(`/academic-terms/${id}/set-current`),
    onSuccess: () => { toast.success('Geçerli dönem güncellendi.'); qc.invalidateQueries({ queryKey: ['academic-terms'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Güncellenemedi.'),
  })

  return (
    <Panel title="Akademik dönemler" actions={<Button size="sm" icon={<Plus className="size-3.5" />} onClick={() => setOpen(true)}>Dönem ekle</Button>}>
      {!data || data.length === 0 ? <EmptyState title="Dönem yok" /> : (
        <div className="flex flex-col divide-y divide-line">
          {data.map((t) => (
            <div key={t.id} className="flex items-center justify-between py-2.5">
              <div>
                <p className="text-[13.5px] text-ink font-medium">{t.name}</p>
                <p className="text-[12px] text-ink-3">{date(t.starts_on)} – {date(t.ends_on)}</p>
              </div>
              {t.is_current ? <Badge tone="primary">Geçerli dönem</Badge> : <Button size="xs" icon={<Star className="size-3.5" />} onClick={() => setCurrent.mutate(t.id)}>Geçerli yap</Button>}
            </div>
          ))}
        </div>
      )}

      <Drawer open={open} onClose={() => setOpen(false)} width={420} title="Yeni akademik dönem" footer={<><Button variant="ghost" onClick={() => setOpen(false)}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Ekle</Button></>}>
        <div className="grid grid-cols-1 gap-3">
          <Field label="Dönem adı" required error={errors.name?.[0]}><Input value={form.name ?? ''} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="2027-2028" /></Field>
          <Field label="Başlangıç tarihi" required error={errors.starts_on?.[0]}><Input type="date" value={form.starts_on ?? ''} onChange={(e) => setForm((f) => ({ ...f, starts_on: e.target.value }))} /></Field>
          <Field label="Bitiş tarihi" required error={errors.ends_on?.[0]}><Input type="date" value={form.ends_on ?? ''} onChange={(e) => setForm((f) => ({ ...f, ends_on: e.target.value }))} /></Field>
          <Switch checked={!!form.is_current} onChange={(v) => setForm((f) => ({ ...f, is_current: v }))} label="Geçerli dönem olarak ayarla" />
        </div>
      </Drawer>
    </Panel>
  )
}
