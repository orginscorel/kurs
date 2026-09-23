import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  BookOpen, Building2, CalendarRange, Check, CheckCircle2, ChevronLeft, ChevronRight, Clock, DoorOpen, FileUp, GraduationCap, ImagePlus, Info,
  LayoutGrid, MessageCircle, Package, Pencil, Plus, School, Star, UserPlus, Users, X,
} from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date, money } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useAuth, useCan } from '@/app/auth'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Checkbox, Field, Input, Select } from '@/components/ui/form'
import { Alert, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { ExcelImportDialog } from '@/components/import/ExcelImportDialog'
import { StudentFormDrawer } from '@/modules/students/StudentFormDrawer'
import type { StudentOptions } from '@/modules/students/types'
import { TeacherFormDrawer } from '@/modules/staff/TeacherFormDrawer'
import type { TeacherOptions } from '@/modules/staff/types'
import { generatePeriods, type GeneratorInput } from '@/modules/academic/timetable/types'
import type { Institution } from './types'
import { RegionPicker, type Region } from './SchoolSettings'

type StepKey = 'kurum' | 'logo' | 'donem' | 'dersler' | 'programlar' | 'derslikler' | 'zaman' | 'siniflar' | 'paketler' | 'ogretmenler' | 'whatsapp' | 'ogrenciler'

type Overview = {
  institution: { name: string | null; short_name: string | null; onboarding_completed: boolean; logo_url: string | null }
  current_term: { id: number; name: string; starts_on: string; ends_on: string } | null
  counts: Record<'terms' | 'subjects' | 'programs' | 'programs_with_subjects' | 'classrooms' | 'time_templates' | 'class_groups' | 'packages' | 'accounts' | 'teachers' | 'teacher_users' | 'students' | 'guardians', number>
  steps: Record<StepKey, boolean>
  done: number
  total: number
  class_structure_saved: boolean
  programs: { id: number; name: string; code: string; subjects: number; weekly_hours: number }[]
  class_groups: { id: number; name: string; capacity: number }[]
  whatsapp: { provider: string; status: string; is_enabled: boolean } | null
}

type StepDef = { key: StepKey; label: string; short: string; why: string; icon: typeof Building2; optional?: boolean }

const STEPS: StepDef[] = [
  { key: 'kurum', label: 'Kurum bilgileri', short: 'Ad, telefon, adres', icon: Building2, why: 'Makbuz, sözleşme, veli mesajları ve portal bu bilgileri kullanır.' },
  { key: 'logo', label: 'Logo', short: 'Belgelerde görünür', icon: ImagePlus, optional: true, why: 'Makbuz ve sözleşme PDF’lerinde, öğrenci portalında kurum kimliği olarak görünür.' },
  { key: 'donem', label: 'Eğitim dönemi', short: 'Geçerli öğretim yılı', icon: CalendarRange, why: 'Sınıflar, eğitim paketleri ve ders programı geçerli döneme bağlanır; dönem yoksa sınıf açılamaz.' },
  { key: 'dersler', label: 'Dersler', short: 'Matematik, Türkçe…', icon: BookOpen, why: 'Ders programı, sınav analizi, ödev ve öğretmen branşları bu listeden seçilir.' },
  { key: 'programlar', label: 'Programlar', short: 'LGS, TYT, AYT + saatler', icon: GraduationCap, why: 'Program botu her sınıfın haftalık ders saatini programdan alır; kayıtlar programa yapılır.' },
  { key: 'derslikler', label: 'Derslikler', short: 'Oda ve kapasite', icon: DoorOpen, why: 'Program botu dersleri boş dersliklere yerleştirir; kapasite yerleştirmede kullanılır.' },
  { key: 'zaman', label: 'Zaman şablonu', short: 'Ders saatleri', icon: Clock, why: 'Hangi gün kaçta ders olacağını belirler; program botu yalnız bu dilimlere ders koyar.' },
  { key: 'siniflar', label: 'Sınıf yapısı', short: 'Seviye, şube, kontenjan', icon: LayoutGrid, why: 'Öğrenciler sınıflara yerleşir; yoklama, program ve sınav raporları sınıf bazında çalışır.' },
  { key: 'paketler', label: 'Eğitim paketleri', short: 'Fiyat ve taksit', icon: Package, why: 'Kayıt ekranında fiyat ve taksit sayısı paketten otomatik gelir; indirim hesapları bu fiyata göre yapılır.' },
  { key: 'ogretmenler', label: 'Öğretmenler', short: 'Branş ve kullanıcı', icon: Users, why: 'Ders programı ve yoklama öğretmene bağlıdır; öğretmen paneli için kullanıcı açılabilir.' },
  { key: 'whatsapp', label: 'WhatsApp', short: 'Veli bildirimleri', icon: MessageCircle, optional: true, why: 'Devamsızlık, ödeme ve sınav bildirimleri velilere WhatsApp ile gider. Bağlanmadan mesaj gönderilmez.' },
  { key: 'ogrenciler', label: 'İlk öğrenciler', short: 'Tek tek ya da Excel', icon: UserPlus, why: 'Öğrenci ve veli kayıtları; portal hesabı otomatik açılır.' },
]

const onError = (fallback: string) => (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : fallback)

/** Türkçe ad → program/ders kodu (A-Z, 0-9) */
function codeOf(name: string, max = 10) {
  const map: Record<string, string> = { ç: 'C', ğ: 'G', ı: 'I', i: 'I', ö: 'O', ş: 'S', ü: 'U', Ç: 'C', Ğ: 'G', İ: 'I', Ö: 'O', Ş: 'S', Ü: 'U' }
  return name.replace(/[çğıiöşüÇĞİÖŞÜ]/g, (c) => map[c] ?? c).toUpperCase().replace(/[^A-Z0-9]+/g, '').slice(0, max) || 'KOD'
}

function uniqueCode(base: string, taken: string[]) {
  let code = base
  let i = 2
  while (taken.includes(code)) code = `${base.slice(0, 8)}${i++}`
  return code
}

export default function OnboardingWizard() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const me = useAuth((s) => s.me)
  const [params, setParams] = useSearchParams()
  const overview = useQuery({ queryKey: ['onboarding', 'overview'], queryFn: () => api.get<Overview>('/onboarding/overview') })
  const o = overview.data
  const stepParam = params.get('adim')
  const validKey = (k: string | null): k is StepKey => !!k && STEPS.some((s) => s.key === k)
  // Kaldığı yerden devam: geçerli ?adim yoksa localStorage'daki son adım, o da yoksa ilk tamamlanmamış adım.
  const resumeKey: StepKey = validKey(stepParam)
    ? stepParam
    : (() => {
        try { const s = localStorage.getItem('onboarding.step'); if (validKey(s)) return s } catch { /* erişilemezse yok say */ }
        if (o) return (STEPS.find((s) => !s.optional && !o.steps[s.key]) ?? STEPS.find((s) => !o.steps[s.key]))?.key ?? STEPS[0]!.key
        return STEPS[0]!.key
      })()
  const index = Math.max(0, STEPS.findIndex((s) => s.key === resumeKey))
  const current = STEPS[index]!
  const railRef = useRef<HTMLDivElement>(null)

  // URL'yi çözülen adıma eşitle (yenileme/derin bağlantıya dayanıklı) + son adımı sakla.
  useEffect(() => {
    if (stepParam !== resumeKey) setParams((p) => { p.set('adim', resumeKey); return p }, { replace: true })
    try { localStorage.setItem('onboarding.step', resumeKey) } catch { /* erişilemezse yok say */ }
  }, [resumeKey, stepParam, setParams])

  // Mobilde seçili adım yatay şeritte görünür kalsın
  useEffect(() => {
    railRef.current?.querySelector<HTMLElement>('[aria-current="step"]')?.scrollIntoView({ block: 'nearest', inline: 'center' })
  }, [index])

  const go = (i: number) => {
    setParams((p) => { p.set('adim', STEPS[Math.min(STEPS.length - 1, Math.max(0, i))]!.key); return p }, { replace: true })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const complete = useMutation({
    mutationFn: () => api.post('/onboarding/complete'),
    onSuccess: () => {
      toast.success('Kurulum tamamlandı. Hoş geldiniz!')
      // Oturum bilgisi zustand deposunda: pano yeniden /kurulum'a yönlendirmesin
      const cur = useAuth.getState().me
      if (cur) useAuth.getState().setMe({ ...cur, institution: { ...cur.institution, onboarding_completed: true } })
      qc.invalidateQueries({ queryKey: ['onboarding'] })
      navigate('/')
    },
    onError: onError('Tamamlanamadı.'),
  })

  const missing = o ? STEPS.filter((s) => !s.optional && !o.steps[s.key]) : []
  const isLast = index === STEPS.length - 1
  const doneHere = o?.steps[current.key]

  return (
    <div className="mx-auto w-full max-w-[1120px] py-2 sm:py-6 animate-fade-in">
      {/* Üst bölüm: başlık + genel ilerleme */}
      <header className="mb-6 rounded-[var(--radius-lg)] bg-surface ring-1 ring-line px-5 py-5 sm:px-7 sm:py-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0 max-w-2xl">
            <p className="text-[12.5px] font-medium uppercase tracking-[0.06em] text-ink-3">Kurulum sihirbazı</p>
            <h1 className="mt-1 text-[26px] sm:text-[28px] font-semibold leading-tight tracking-[-0.015em] text-ink">{me?.institution.name ?? 'Kurumunuzu'} kullanıma hazırlayalım</h1>
            <p className="mt-2 text-[14.5px] leading-relaxed text-ink-2">Adımları istediğiniz sırayla yapabilirsiniz; her adımda girdiğiniz bilgi hemen kaydedilir. Tamamlanan adımlar işaretlenir.</p>
          </div>
          {o?.institution.onboarding_completed && <Badge tone="success" dot>Kurulum tamamlandı</Badge>}
        </div>
        <div className="mt-5">
          <div className="mb-1.5 flex items-baseline justify-between text-[13.5px]">
            <span className="font-medium text-ink">Genel ilerleme</span>
            <span className="tabular text-ink-2">{o ? `${o.done}/${o.total} adım` : '…'}</span>
          </div>
          <ProgressBar value={o ? (o.done / o.total) * 100 : 0} tone={o && o.done === o.total ? 'success' : 'primary'} className="h-2" />
        </div>
      </header>

      <div className="grid grid-cols-1 lg:grid-cols-[300px_minmax(0,1fr)] gap-5 lg:gap-6 items-start">
        {/* Adım listesi: mobilde yatay şerit, masaüstünde dikey liste */}
        <nav aria-label="Kurulum adımları" className="min-w-0 lg:sticky lg:top-4">
          <div ref={railRef} className="-mx-4 px-4 lg:mx-0 lg:px-0 flex lg:flex-col gap-2 lg:gap-1 overflow-x-auto lg:overflow-visible scroll-thin pb-2 lg:pb-0 snap-x lg:rounded-[var(--radius-lg)] lg:bg-surface lg:ring-1 lg:ring-line lg:p-2">
            {STEPS.map((s, i) => {
              const done = o?.steps[s.key]
              const active = i === index
              return (
                <button
                  key={s.key}
                  type="button"
                  onClick={() => go(i)}
                  aria-current={active ? 'step' : undefined}
                  className={cn(
                    'snap-start flex shrink-0 items-center gap-3 rounded-[var(--radius-md)] text-left transition-colors',
                    'px-3 py-2 ring-1 lg:ring-0 lg:w-full lg:py-2.5',
                    active ? 'bg-primary-soft text-primary-ink ring-primary/30' : 'bg-surface ring-line text-ink-2 hover:bg-surface-2 lg:bg-transparent',
                  )}
                >
                  <span className={cn(
                    'grid size-7 shrink-0 place-items-center rounded-full text-[12.5px] font-semibold tabular ring-1',
                    done ? 'bg-success text-white ring-success' : active ? 'bg-surface text-ink ring-line-strong' : 'bg-surface-2 text-ink-3 ring-line',
                  )}>
                    {done ? <Check className="size-4" /> : i + 1}
                  </span>
                  <span className="min-w-0">
                    <span className={cn('block whitespace-nowrap text-[14px] font-medium', active ? 'text-ink' : 'text-ink')}>{s.label}</span>
                    <span className="hidden lg:block truncate text-[12px] text-ink-3">
                      {done ? 'Tamamlandı' : s.optional ? `İsteğe bağlı · ${s.short}` : s.short}
                    </span>
                  </span>
                </button>
              )
            })}
          </div>
        </nav>

        {/* İçerik */}
        <section className="min-w-0 rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <div className="border-b border-line px-5 py-5 sm:px-7">
            <div className="flex flex-wrap items-center gap-2.5">
              <span className="grid size-10 place-items-center rounded-[var(--radius-md)] bg-surface-2 ring-1 ring-line text-ink-2"><current.icon className="size-5" /></span>
              <div className="min-w-0">
                <p className="text-[12.5px] text-ink-3 tabular">Adım {index + 1} / {STEPS.length}</p>
                <h2 className="text-[20px] font-semibold leading-tight text-ink">{current.label}</h2>
              </div>
              {o && (doneHere ? <Badge tone="success" dot className="ml-auto">Tamamlandı</Badge> : <Badge tone={current.optional ? 'neutral' : 'warning'} className="ml-auto">{current.optional ? 'İsteğe bağlı' : 'Bekliyor'}</Badge>)}
            </div>
            <p className="mt-3 flex items-start gap-2 rounded-[var(--radius-md)] bg-surface-2 px-3 py-2.5 text-[13.5px] leading-relaxed text-ink-2">
              <Info className="mt-0.5 size-4 shrink-0 text-ink-3" />
              <span><span className="font-medium text-ink">Neden gerekli? </span>{current.why}</span>
            </p>
          </div>

          <div className="px-5 py-5 sm:px-7 sm:py-6 text-[14px]">
            {!o ? (
              <Skeleton className="h-48" />
            ) : (
              <>
                {current.key === 'kurum' && <KurumStep />}
                {current.key === 'logo' && <LogoStep logoUrl={o.institution.logo_url} />}
                {current.key === 'donem' && <DonemStep />}
                {current.key === 'dersler' && <DerslerStep />}
                {current.key === 'programlar' && <ProgramlarStep overview={o} />}
                {current.key === 'derslikler' && <DersliklerStep />}
                {current.key === 'zaman' && <ZamanStep />}
                {current.key === 'siniflar' && <SiniflarStep overview={o} />}
                {current.key === 'paketler' && <PaketlerStep overview={o} />}
                {current.key === 'ogretmenler' && <OgretmenlerStep overview={o} />}
                {current.key === 'whatsapp' && <WhatsappStep overview={o} />}
                {current.key === 'ogrenciler' && <OgrencilerStep overview={o} />}
              </>
            )}

            {isLast && o && (
              <div className="mt-6 rounded-[var(--radius-md)] ring-1 ring-line p-4">
                <p className="text-[14px] font-medium text-ink">Kurulumu tamamlamadan önce</p>
                {missing.length === 0 ? (
                  <p className="mt-1 flex items-center gap-1.5 text-[13px] text-success"><CheckCircle2 className="size-4" /> Tüm zorunlu adımlar tamam.</p>
                ) : (
                  <>
                    <p className="mt-1 text-[13px] text-ink-2">Bekleyen adımlar (kurulumu tamamladıktan sonra da Ayarlar › Kurum ekranından sihirbaza dönebilirsiniz):</p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                      {missing.map((s) => (
                        <button key={s.key} type="button" onClick={() => go(STEPS.indexOf(s))} className="rounded-full ring-1 ring-warning/40 px-3 h-8 text-[12.5px] text-warning hover:bg-surface-2">{s.label}</button>
                      ))}
                    </div>
                  </>
                )}
              </div>
            )}
          </div>

          <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-line px-5 py-4 sm:px-7">
            <Button variant="ghost" icon={<ChevronLeft className="size-4" />} disabled={index === 0} onClick={() => go(index - 1)}>Geri</Button>
            <div className="flex flex-wrap gap-2">
              {!isLast && <Button variant="ghost" onClick={() => go(STEPS.length - 1)}>Son adıma git</Button>}
              {isLast ? (
                <Button variant="primary" size="lg" loading={complete.isPending} onClick={() => complete.mutate()}>Kurulumu tamamla</Button>
              ) : (
                <Button variant="primary" iconRight={<ChevronRight className="size-4" />} onClick={() => go(index + 1)}>{doneHere || current.optional ? 'İleri' : 'Şimdilik geç'}</Button>
              )}
            </div>
          </footer>
        </section>
      </div>
    </div>
  )
}

function useRefreshOverview() {
  const qc = useQueryClient()
  return () => qc.invalidateQueries({ queryKey: ['onboarding'] })
}

function ItemList({ items, empty }: { items: ReactNode[]; empty: string }) {
  if (items.length === 0) return <p className="text-[13px] text-ink-3">{empty}</p>
  return <ul className="flex flex-wrap gap-1.5">{items.map((it, i) => <li key={i}>{it}</li>)}</ul>
}

function Box({ title, children, className }: { title?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <div className={cn('rounded-[var(--radius-md)] ring-1 ring-line p-4', className)}>
      {title && <p className="mb-3 text-[14px] font-medium text-ink">{title}</p>}
      {children}
    </div>
  )
}

// ---------------------------------------------------------------- 1. kurum

function KurumStep() {
  const qc = useQueryClient()
  const refresh = useRefreshOverview()
  const { data } = useQuery({ queryKey: ['settings', 'institution'], queryFn: () => api.get<Institution>('/settings/institution') })
  const [form, setForm] = useState<Record<string, any>>({})
  useEffect(() => { if (data) setForm(data) }, [data])
  const set = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const save = useMutation({
    mutationFn: () => api.put('/settings/institution', {
      name: form.name, short_name: form.short_name || null, phone: form.phone || null, email: form.email || null, website: form.website || null,
      address: form.address || null, tax_office: form.tax_office || null, tax_number: form.tax_number || null,
      currency: form.currency ?? 'TRY', timezone: form.timezone ?? 'Europe/Istanbul',
    }),
    onSuccess: () => { toast.success('Kurum bilgileri kaydedildi.'); qc.invalidateQueries({ queryKey: ['settings', 'institution'] }); void useAuth.getState().load(); refresh() },
    onError: onError('Kaydedilemedi.'),
  })

  if (!data) return <Skeleton className="h-48" />
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <Field label="Kurum adı" required className="sm:col-span-2"><Input value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} /></Field>
      <Field label="Kısa ad" optional hint="Menü ve kısa mesajlarda"><Input value={form.short_name ?? ''} onChange={(e) => set('short_name', e.target.value)} /></Field>
      <Field label="Telefon" optional><Input type="tel" value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} placeholder="0356 xxx xx xx" /></Field>
      <Field label="E-posta" optional><Input type="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} /></Field>
      <Field label="Web sitesi" optional><Input value={form.website ?? ''} onChange={(e) => set('website', e.target.value)} /></Field>
      <Field label="Adres" optional className="sm:col-span-2"><Input value={form.address ?? ''} onChange={(e) => set('address', e.target.value)} /></Field>
      <Field label="Vergi dairesi" optional><Input value={form.tax_office ?? ''} onChange={(e) => set('tax_office', e.target.value)} /></Field>
      <Field label="Vergi numarası" optional><Input value={form.tax_number ?? ''} onChange={(e) => set('tax_number', e.target.value)} /></Field>
      <div className="sm:col-span-2 flex justify-end"><Button variant="primary" loading={save.isPending} disabled={!form.name} onClick={() => save.mutate()}>Kaydet</Button></div>
      <div className="sm:col-span-2 border-t border-line pt-4"><SchoolRegionsBlock /></div>
    </div>
  )
}

/** Kurumun öğrenci aldığı bölgeler → okul listesi (sonradan Ayarlar › Okullar'dan düzenlenir). */
function SchoolRegionsBlock() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['schools', 'admin'], queryFn: () => api.get<{ data: { id: number }[]; regions: Region[] }>('/schools') })
  const [picked, setPicked] = useState<string[]>([])
  const add = useMutation({
    mutationFn: () => api.post<{ message: string }>('/schools/import-regions', { regions: picked }),
    onSuccess: (r) => { toast.success(r.message); setPicked([]); qc.invalidateQueries({ queryKey: ['schools'] }) },
    onError: onError('Okullar eklenemedi.'),
  })
  if (!data) return <Skeleton className="h-24" />
  return (
    <div className="flex flex-col gap-3">
      <div>
        <p className="text-[14px] font-semibold">Öğrenci aldığınız bölgeler</p>
        <p className="text-[13px] text-ink-3">Seçtiğiniz bölgelerin liseleri öğrenci ve ön kayıt formlarında önerilir. Şu an listede <b className="text-ink">{data.data.length}</b> okul var; <a className="text-primary underline-offset-2 hover:underline" href="/ayarlar/okullar">Ayarlar › Okullar</a> bölümünden ekleyip çıkarabilirsiniz.</p>
      </div>
      <RegionPicker regions={data.regions} value={picked} onChange={setPicked} />
      <div className="flex justify-end"><Button disabled={!picked.length} loading={add.isPending} onClick={() => add.mutate()}>Seçili bölgelerin okullarını ekle</Button></div>
    </div>
  )
}

// ---------------------------------------------------------------- 2. logo

function LogoStep({ logoUrl }: { logoUrl: string | null }) {
  const qc = useQueryClient()
  const refresh = useRefreshOverview()
  const fileRef = useRef<HTMLInputElement>(null)
  const upload = useMutation({
    mutationFn: (file: File) => { const fd = new FormData(); fd.append('logo', file); return api.post('/settings-institution/logo', fd) },
    onSuccess: () => { toast.success('Logo yüklendi.'); qc.invalidateQueries({ queryKey: ['settings', 'institution'] }); void useAuth.getState().load(); refresh() },
    onError: onError('Yüklenemedi.'),
  })
  return (
    <div className="flex flex-wrap items-center gap-6">
      <div className="size-28 rounded-[var(--radius-lg)] ring-1 ring-line bg-surface-2 grid place-items-center overflow-hidden shrink-0">
        {logoUrl ? <img src={logoUrl} alt="Kurum logosu" className="h-full w-full object-contain" /> : <Building2 className="size-9 text-ink-3" />}
      </div>
      <div>
        <Button icon={<ImagePlus className="size-4" />} loading={upload.isPending} onClick={() => fileRef.current?.click()}>{logoUrl ? 'Logoyu değiştir' : 'Logo yükle'}</Button>
        <input ref={fileRef} type="file" accept="image/*" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f); e.target.value = '' }} />
        <p className="mt-2 text-[13px] text-ink-3">PNG ya da JPG, en fazla 2 MB. Bu adımı atlayıp sonra da yükleyebilirsiniz.</p>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------- 3. dönem

type Term = { id: number; name: string; starts_on: string; ends_on: string; is_current: boolean }

function suggestTerm() {
  const now = new Date()
  const y = now.getMonth() >= 6 ? now.getFullYear() : now.getFullYear() - 1
  return { name: `${y}-${y + 1}`, starts_on: `${y}-09-01`, ends_on: `${y + 1}-06-30` }
}

const isoDay = (v: string) => v.slice(0, 10)

function DonemStep() {
  const refresh = useRefreshOverview()
  const qc = useQueryClient()
  const terms = useQuery({ queryKey: ['settings', 'terms'], queryFn: () => api.get<Term[]>('/academic-terms') })
  const [form, setForm] = useState({ ...suggestTerm(), is_current: false })
  const [editing, setEditing] = useState<Term | null>(null)
  const after = () => { qc.invalidateQueries({ queryKey: ['settings', 'terms'] }); qc.invalidateQueries({ queryKey: ['academic'] }); refresh() }

  useEffect(() => { if (terms.data) setForm((f) => ({ ...f, is_current: !terms.data.some((t) => t.is_current) })) }, [terms.data])

  const create = useMutation({
    mutationFn: () => api.post('/academic-terms', form),
    onSuccess: () => { toast.success('Dönem oluşturuldu.'); after() },
    onError: onError('Dönem oluşturulamadı.'),
  })
  const update = useMutation({
    mutationFn: (t: Term) => api.put(`/academic-terms/${t.id}`, { name: t.name, starts_on: isoDay(t.starts_on), ends_on: isoDay(t.ends_on) }),
    onSuccess: () => { toast.success('Dönem güncellendi.'); setEditing(null); after() },
    onError: onError('Güncellenemedi.'),
  })
  const setCurrent = useMutation({
    mutationFn: (id: number) => api.post(`/academic-terms/${id}/set-current`),
    onSuccess: () => { toast.success('Geçerli dönem değişti.'); after() },
    onError: onError('Değiştirilemedi.'),
  })

  return (
    <div className="flex flex-col gap-5">
      {terms.isLoading ? <Skeleton className="h-16" /> : (terms.data ?? []).length === 0 ? (
        <Alert tone="warning">Henüz eğitim dönemi yok. Aşağıdan ilk dönemi oluşturun ve “geçerli dönem” yapın.</Alert>
      ) : (
        <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
          {terms.data!.map((t) => (
            <li key={t.id} className="px-4 py-3">
              {editing?.id === t.id ? (
                <div className="grid grid-cols-1 sm:grid-cols-[1fr_160px_160px_auto] gap-3 items-end">
                  <Field label="Dönem adı" required><Input value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} /></Field>
                  <Field label="Başlangıç tarihi" required><Input type="date" value={isoDay(editing.starts_on)} onChange={(e) => setEditing({ ...editing, starts_on: e.target.value })} /></Field>
                  <Field label="Bitiş tarihi" required><Input type="date" value={isoDay(editing.ends_on)} onChange={(e) => setEditing({ ...editing, ends_on: e.target.value })} /></Field>
                  <div className="flex gap-1.5">
                    <Button variant="primary" loading={update.isPending} onClick={() => update.mutate(editing)}>Kaydet</Button>
                    <Button variant="ghost" size="icon" aria-label="Vazgeç" onClick={() => setEditing(null)}><X className="size-4" /></Button>
                  </div>
                </div>
              ) : (
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-[15px] font-medium text-ink">{t.name}</span>
                  <span className="text-ink-3 tabular">{date(t.starts_on)} – {date(t.ends_on)}</span>
                  {t.is_current && <Badge tone="success" dot>Geçerli dönem</Badge>}
                  <span className="ml-auto flex gap-1.5">
                    {!t.is_current && <Button size="sm" icon={<Star className="size-3.5" />} loading={setCurrent.isPending} onClick={() => setCurrent.mutate(t.id)}>Geçerli yap</Button>}
                    <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(t)}>Düzenle</Button>
                  </span>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      <Box title="Yeni dönem">
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <Field label="Dönem adı" required><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="2026-2027" /></Field>
          <Field label="Başlangıç tarihi" required><Input type="date" value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })} /></Field>
          <Field label="Bitiş tarihi" required><Input type="date" value={form.ends_on} onChange={(e) => setForm({ ...form, ends_on: e.target.value })} /></Field>
        </div>
        <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
          <Checkbox checked={form.is_current} onChange={(v) => setForm({ ...form, is_current: v })} label="Geçerli dönem yap" />
          <Button variant="primary" icon={<Plus className="size-4" />} loading={create.isPending} disabled={!form.name || !form.starts_on || !form.ends_on} onClick={() => create.mutate()}>Dönemi oluştur</Button>
        </div>
      </Box>
    </div>
  )
}

// ---------------------------------------------------------------- 4. dersler

type SubjectRow = { id: number; code: string; name: string; is_active: boolean }
const SUGGESTED_SUBJECTS = ['Türkçe', 'Matematik', 'Geometri', 'Fizik', 'Kimya', 'Biyoloji', 'Edebiyat', 'Tarih', 'Coğrafya', 'Felsefe', 'Din Kültürü', 'İngilizce', 'Fen Bilimleri', 'Sosyal Bilgiler', 'İnkılap Tarihi']

function useSubjects() {
  return useQuery({ queryKey: ['subjects', 'wizard'], queryFn: () => api.get<Paginated<SubjectRow>>('/subjects', { per_page: 100 }) })
}

function DerslerStep() {
  const refresh = useRefreshOverview()
  const qc = useQueryClient()
  const subjects = useSubjects()
  const [name, setName] = useState('')
  const rows = subjects.data?.data ?? []
  const names = rows.map((s) => s.name.toLocaleLowerCase('tr'))

  const create = useMutation({
    mutationFn: (n: string) => api.post('/subjects', { name: n, code: uniqueCode(codeOf(n), rows.map((s) => s.code)) }),
    onSuccess: (_r, n) => { toast.success(`${n} eklendi.`); setName(''); qc.invalidateQueries({ queryKey: ['subjects'] }); qc.invalidateQueries({ queryKey: ['academic'] }); refresh() },
    onError: onError('Ders eklenemedi.'),
  })

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col sm:flex-row gap-2">
        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Ders adı (örn. Matematik)" aria-label="Ders adı" onKeyDown={(e) => e.key === 'Enter' && name.trim() && create.mutate(name.trim())} />
        <Button icon={<Plus className="size-4" />} loading={create.isPending} disabled={!name.trim()} onClick={() => create.mutate(name.trim())}>Ekle</Button>
      </div>
      <div>
        <p className="mb-2 text-[13px] font-medium text-ink-2">Hızlı ekle</p>
        <div className="flex flex-wrap gap-1.5">
          {SUGGESTED_SUBJECTS.filter((s) => !names.includes(s.toLocaleLowerCase('tr'))).map((s) => (
            <button key={s} type="button" disabled={create.isPending} onClick={() => create.mutate(s)} className="inline-flex items-center gap-1 rounded-full ring-1 ring-line px-3 h-8 text-[13px] text-ink-2 hover:bg-surface-2 disabled:opacity-50">
              <Plus className="size-3.5" /> {s}
            </button>
          ))}
        </div>
      </div>
      <div>
        <p className="mb-2 text-[13px] font-medium text-ink-2">Tanımlı dersler ({rows.length})</p>
        {subjects.isLoading ? <Skeleton className="h-8" /> : <ItemList empty="Henüz ders yok." items={rows.map((s) => <Badge key={s.id}>{s.name} <span className="text-ink-3">{s.code}</span></Badge>)} />}
      </div>
      <p className="text-[12.5px] text-ink-3">Konu/kazanım listeleri ve ders renkleri Akademik › Dersler ekranından düzenlenir. <ButtonLink size="xs" variant="ghost" to="/akademik?sekme=dersler">Dersler ekranı</ButtonLink></p>
    </div>
  )
}

// ---------------------------------------------------------------- 5. programlar

type ProgramDetail = { program: { id: number; name: string }; subjects: { id: number; name: string; weekly_hours: number; curriculum: string | null }[] }
const TRACKS: Record<string, string> = { LGS: 'LGS', TYT: 'TYT', AYT_SAY: 'AYT Sayısal', AYT_EA: 'AYT Eşit Ağırlık', AYT_SOZ: 'AYT Sözel', AYT_DIL: 'AYT Dil', NONE: 'Sınav hedefi yok' }

function ProgramlarStep({ overview }: { overview: Overview }) {
  const refresh = useRefreshOverview()
  const qc = useQueryClient()
  const subjects = useSubjects()
  const [form, setForm] = useState({ name: '', exam_track: 'TYT', kind: 'group' })
  const [selected, setSelected] = useState<number | null>(null)
  const programs = overview.programs

  const create = useMutation({
    mutationFn: () => api.post<{ id: number }>('/programs', { name: form.name.trim(), code: uniqueCode(codeOf(form.name, 12), programs.map((p) => p.code)), kind: form.kind, exam_track: form.exam_track, is_active: true }),
    onSuccess: (r) => { toast.success('Program oluşturuldu. Şimdi haftalık ders saatlerini girin.'); setForm({ ...form, name: '' }); setSelected(r.id); qc.invalidateQueries({ queryKey: ['programs'] }); qc.invalidateQueries({ queryKey: ['academic'] }); refresh() },
    onError: onError('Program oluşturulamadı.'),
  })

  return (
    <div className="flex flex-col gap-5">
      {(subjects.data?.data.length ?? 1) === 0 && <Alert tone="warning">Ders saatlerini girebilmek için önce “Dersler” adımında ders ekleyin.</Alert>}
      {programs.length > 0 && (
        <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
          {programs.map((p) => (
            <li key={p.id} className="flex flex-wrap items-center gap-2 px-4 py-3">
              <span className="text-[15px] font-medium text-ink">{p.name}</span>
              <span className="text-[12.5px] text-ink-3">{p.code}</span>
              {p.subjects > 0 ? <Badge tone="success">{p.subjects} ders · haftada {p.weekly_hours} saat</Badge> : <Badge tone="warning">Ders saatleri girilmedi</Badge>}
              <Button size="sm" className="ml-auto" variant={selected === p.id ? 'soft' : 'secondary'} onClick={() => setSelected(selected === p.id ? null : p.id)}>{selected === p.id ? 'Kapat' : 'Ders saatleri'}</Button>
            </li>
          ))}
        </ul>
      )}

      {selected && <ProgramHours key={selected} programId={selected} subjects={subjects.data?.data ?? []} onSaved={() => { refresh(); setSelected(null) }} />}

      <Box title="Yeni program">
        <div className="grid grid-cols-1 sm:grid-cols-[1fr_200px_160px] gap-3">
          <Field label="Program adı" required><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="örn. 12. Sınıf TYT-AYT" /></Field>
          <Field label="Sınav hedefi" required><Select value={form.exam_track} onChange={(e) => setForm({ ...form, exam_track: e.target.value })} options={Object.entries(TRACKS).map(([value, label]) => ({ value, label }))} /></Field>
          <Field label="Program türü" required><Select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })} options={[{ value: 'group', label: 'Grup dersi' }, { value: 'private', label: 'Birebir' }, { value: 'study', label: 'Etüt' }]} /></Field>
        </div>
        <div className="mt-4 flex justify-end">
          <Button variant="primary" icon={<Plus className="size-4" />} loading={create.isPending} disabled={!form.name.trim()} onClick={() => create.mutate()}>Programı oluştur</Button>
        </div>
      </Box>
    </div>
  )
}

function ProgramHours({ programId, subjects, onSaved }: { programId: number; subjects: SubjectRow[]; onSaved: () => void }) {
  const qc = useQueryClient()
  const detail = useQuery({ queryKey: ['programs', programId], queryFn: () => api.get<ProgramDetail>(`/programs/${programId}`) })
  const [hours, setHours] = useState<Record<number, string>>({})
  useEffect(() => {
    if (detail.data) setHours(Object.fromEntries(detail.data.subjects.map((s) => [s.id, String(s.weekly_hours)])))
  }, [detail.data])

  const total = Object.values(hours).reduce((s, v) => s + (Number(v) || 0), 0)
  const save = useMutation({
    mutationFn: () => api.put(`/programs/${programId}/subjects`, {
      subjects: Object.entries(hours).filter(([, v]) => Number(v) > 0).map(([id, v]) => ({
        subject_id: Number(id), weekly_hours: Number(v), curriculum: detail.data?.subjects.find((s) => s.id === Number(id))?.curriculum ?? null,
      })),
    }),
    onSuccess: () => { toast.success('Haftalık ders saatleri kaydedildi.'); qc.invalidateQueries({ queryKey: ['programs'] }); onSaved() },
    onError: onError('Kaydedilemedi.'),
  })

  if (detail.isLoading) return <Skeleton className="h-32" />
  return (
    <Box title={<>{detail.data?.program.name} · haftalık ders saatleri</>} className="ring-primary/30 bg-primary-soft/20">
      {subjects.length === 0 ? <p className="text-[13px] text-ink-3">Önce ders ekleyin.</p> : (
        <div className="grid grid-cols-1 min-[420px]:grid-cols-2 md:grid-cols-3 gap-2">
          {subjects.filter((s) => s.is_active).map((s) => (
            <label key={s.id} className="flex items-center justify-between gap-2 rounded-[var(--radius-sm)] ring-1 ring-line bg-surface px-3 py-2 text-[13.5px]">
              <span className="min-w-0 truncate">{s.name}</span>
              <input type="number" min={0} max={40} inputMode="numeric" aria-label={`${s.name} haftalık saat`} value={hours[s.id] ?? ''} placeholder="0" onChange={(e) => setHours({ ...hours, [s.id]: e.target.value })}
                className="w-16 h-8 rounded-[6px] border border-line bg-surface px-2 text-right tabular text-ink focus:border-primary focus:outline-none" />
            </label>
          ))}
        </div>
      )}
      <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
        <span className="text-[13px] text-ink-2 tabular">Toplam: haftada {total} saat</span>
        <Button variant="primary" loading={save.isPending} disabled={subjects.length === 0} onClick={() => save.mutate()}>Saatleri kaydet</Button>
      </div>
    </Box>
  )
}

// ---------------------------------------------------------------- 6. derslikler

type ClassroomRow = { id: number; name: string; capacity: number; kind: string }

function DersliklerStep() {
  const refresh = useRefreshOverview()
  const qc = useQueryClient()
  const rooms = useQuery({ queryKey: ['classrooms', 'wizard'], queryFn: () => api.get<Paginated<ClassroomRow>>('/classrooms', { per_page: 100 }) })
  const [form, setForm] = useState({ name: '', capacity: '20', kind: 'classroom' })
  const create = useMutation({
    mutationFn: () => api.post('/classrooms', { name: form.name.trim(), capacity: Number(form.capacity) || 20, kind: form.kind }),
    onSuccess: () => { toast.success('Derslik eklendi.'); setForm({ ...form, name: '' }); qc.invalidateQueries({ queryKey: ['classrooms'] }); qc.invalidateQueries({ queryKey: ['academic'] }); refresh() },
    onError: onError('Derslik eklenemedi.'),
  })
  const rows = rooms.data?.data ?? []
  return (
    <div className="flex flex-col gap-5">
      <Box title="Derslik ekle">
        <div className="grid grid-cols-1 sm:grid-cols-[1fr_110px_160px_auto] gap-3 items-end">
          <Field label="Derslik adı" required><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="örn. 101 ya da Etüt Salonu" onKeyDown={(e) => e.key === 'Enter' && form.name.trim() && create.mutate()} /></Field>
          <Field label="Kapasite" optional><Input type="number" min={1} value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} /></Field>
          <Field label="Derslik türü" required><Select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })} options={[{ value: 'classroom', label: 'Derslik' }, { value: 'study', label: 'Etüt odası' }, { value: 'hall', label: 'Salon' }, { value: 'lab', label: 'Laboratuvar' }]} /></Field>
          <Button variant="primary" icon={<Plus className="size-4" />} loading={create.isPending} disabled={!form.name.trim()} onClick={() => create.mutate()}>Ekle</Button>
        </div>
      </Box>
      <div>
        <p className="mb-2 text-[13px] font-medium text-ink-2">Tanımlı derslikler ({rows.length})</p>
        {rooms.isLoading ? <Skeleton className="h-8" /> : <ItemList empty="Henüz derslik yok." items={rows.map((r) => <Badge key={r.id}>{r.name} <span className="text-ink-3">{r.capacity} kişi</span></Badge>)} />}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------- 7. zaman şablonu

type TemplateRow = { id: number; name: string; slot_count: number; days: Record<string, [string, string][]>; classes: { id: number; name: string }[] }
const WD = ['', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz']

function ZamanStep() {
  const refresh = useRefreshOverview()
  const qc = useQueryClient()
  const templates = useQuery({ queryKey: ['time-templates'], queryFn: () => api.get<{ data: TemplateRow[] }>('/time-templates') })
  const [name, setName] = useState('Hafta içi akşam')
  const [gen, setGen] = useState<GeneratorInput>({ weekdays: [1, 2, 3, 4, 5], start: '16:30', lesson_minutes: 40, break_minutes: 10, count: 4, lunch_after: null, lunch_minutes: 40 })
  const days = useMemo(() => generatePeriods(gen), [gen])
  const periods = Object.values(days)[0] ?? []
  const num = (k: keyof GeneratorInput) => (e: React.ChangeEvent<HTMLInputElement>) => setGen({ ...gen, [k]: e.target.value === '' ? null : Number(e.target.value) })

  const create = useMutation({
    mutationFn: () => api.post('/time-templates', { name: name.trim(), generator: gen, days, is_active: true }),
    onSuccess: () => { toast.success('Zaman şablonu oluşturuldu.'); qc.invalidateQueries({ queryKey: ['time-templates'] }); refresh() },
    onError: onError('Şablon oluşturulamadı.'),
  })

  const presets: { label: string; name: string; g: Partial<GeneratorInput> }[] = [
    { label: 'Hafta içi akşam', name: 'Hafta içi akşam', g: { weekdays: [1, 2, 3, 4, 5], start: '16:30', lesson_minutes: 40, break_minutes: 10, count: 4, lunch_after: null } },
    { label: 'Hafta sonu tam gün', name: 'Hafta sonu', g: { weekdays: [6, 7], start: '09:00', lesson_minutes: 40, break_minutes: 10, count: 8, lunch_after: 4, lunch_minutes: 50 } },
    { label: 'Mezun (gündüz)', name: 'Mezun gündüz', g: { weekdays: [1, 2, 3, 4, 5], start: '09:00', lesson_minutes: 40, break_minutes: 10, count: 7, lunch_after: 4, lunch_minutes: 50 } },
  ]

  return (
    <div className="flex flex-col gap-5">
      {(templates.data?.data.length ?? 0) > 0 && (
        <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
          {templates.data!.data.map((t) => (
            <li key={t.id} className="flex flex-wrap items-center gap-2 px-4 py-3">
              <span className="text-[15px] font-medium text-ink">{t.name}</span>
              <span className="text-[13px] text-ink-3">{Object.keys(t.days).map((d) => WD[Number(d)]).join(', ')} · günde {Object.values(t.days)[0]?.length ?? 0} ders</span>
              <Badge className="ml-auto">{t.classes.length ? `${t.classes.length} sınıf` : 'Sınıf atanmadı'}</Badge>
            </li>
          ))}
        </ul>
      )}

      <Box title="Hızlı şablon oluştur">
        <div className="mb-4 flex flex-wrap items-center gap-1.5">
          <span className="mr-1 text-[13px] text-ink-2">Hazır:</span>
          {presets.map((p) => <Button key={p.label} size="sm" variant="outline" onClick={() => { setName(p.name); setGen({ ...gen, ...p.g }) }}>{p.label}</Button>)}
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <Field label="Şablon adı" required><Input value={name} onChange={(e) => setName(e.target.value)} /></Field>
          <Field label="Günler" required>
            <div className="flex flex-wrap gap-1">
              {[1, 2, 3, 4, 5, 6, 7].map((d) => {
                const on = gen.weekdays.includes(d)
                return (
                  <button key={d} type="button" aria-pressed={on} onClick={() => setGen({ ...gen, weekdays: on ? gen.weekdays.filter((x) => x !== d) : [...gen.weekdays, d].sort() })}
                    className={cn('h-9 min-w-11 px-2 rounded-[var(--radius-sm)] text-[13px] font-medium ring-1', on ? 'bg-primary text-white ring-primary' : 'ring-line text-ink-2 hover:bg-surface-2')}>{WD[d]}</button>
                )
              })}
            </div>
          </Field>
        </div>
        <div className="mt-3 grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3">
          <Field label="İlk ders saati" required><Input type="time" value={gen.start} onChange={(e) => setGen({ ...gen, start: e.target.value })} /></Field>
          <Field label="Ders süresi (dakika)" required><Input type="number" min={20} max={120} value={gen.lesson_minutes} onChange={num('lesson_minutes')} /></Field>
          <Field label="Teneffüs süresi (dakika)" required><Input type="number" min={0} max={60} value={gen.break_minutes} onChange={num('break_minutes')} /></Field>
          <Field label="Günlük ders sayısı" required><Input type="number" min={1} max={14} value={gen.count} onChange={num('count')} /></Field>
          <Field label="Öğle arası" optional hint="kaçıncı dersten sonra"><Input type="number" min={0} max={13} value={gen.lunch_after ?? ''} placeholder="yok" onChange={num('lunch_after')} /></Field>
          <Field label="Öğle arası süresi (dakika)" optional><Input type="number" min={0} max={120} value={gen.lunch_minutes ?? ''} onChange={num('lunch_minutes')} /></Field>
        </div>
        <div className="mt-4">
          <p className="mb-1.5 text-[13px] font-medium text-ink-2">Önizleme (seçili her gün)</p>
          <div className="flex flex-wrap gap-1.5">
            {periods.length === 0 ? <span className="text-[13px] text-ink-3">Gün seçin.</span> : periods.map(([s, e], i) => <Badge key={i}>{i + 1}. ders {s}–{e}</Badge>)}
          </div>
        </div>
        <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
          <ButtonLink size="sm" variant="ghost" to="/program-botu/sablonlar">Gelişmiş şablon düzenleyici</ButtonLink>
          <Button variant="primary" icon={<Plus className="size-4" />} loading={create.isPending} disabled={!name.trim() || periods.length === 0} onClick={() => create.mutate()}>Şablonu oluştur</Button>
        </div>
      </Box>
    </div>
  )
}

// ---------------------------------------------------------------- 8. sınıf yapısı

function SiniflarStep({ overview }: { overview: Overview }) {
  const can = useCan()
  return (
    <div className="flex flex-col gap-5">
      {!overview.current_term && <Alert tone="warning">Sınıflar geçerli döneme açılır. Önce “Eğitim dönemi” adımında geçerli dönem belirleyin.</Alert>}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <Summary label="Sınıf yapısı" value={overview.class_structure_saved ? 'Kaydedildi' : 'Tanımlanmadı'} tone={overview.class_structure_saved ? 'success' : 'warning'} />
        <Summary label={`Açık sınıf${overview.current_term ? ` (${overview.current_term.name})` : ''}`} value={String(overview.counts.class_groups)} tone={overview.counts.class_groups ? 'success' : 'warning'} />
        <Summary label="Zaman şablonu / derslik" value={`${overview.counts.time_templates} / ${overview.counts.classrooms}`} />
      </div>
      <ol className="flex flex-col gap-2 text-[14px] text-ink-2 list-decimal pl-5">
        <li>Sınıf yapısı ekranında seviyeleri (örn. 8, 12, Mezun) ve şubeleri (A, B…) seçin.</li>
        <li>Her şube için alan, kontenjan, program ve zaman şablonunu belirleyip kaydedin. Önerilen yapı kurumunuza uymuyorsa kaydetmeden önce düzenleyin.</li>
        <li>“Yapıyı uygula” ile sınıflar geçerli dönemde tek seferde açılır; sonra bu sihirbaza dönün.</li>
      </ol>
      {overview.class_groups.length > 0 && (
        <ItemList empty="" items={overview.class_groups.map((g) => <Badge key={g.id}>{g.name} <span className="text-ink-3">{g.capacity} kişi</span></Badge>)} />
      )}
      <div className="flex flex-wrap gap-2">
        <ButtonLink variant="primary" icon={<LayoutGrid className="size-4" />} to="/program-botu/sinif-yapisi">Sınıf yapısını aç</ButtonLink>
        {can('academic.manage') && <ButtonLink icon={<Plus className="size-4" />} to="/siniflar?yeni=1">Tek sınıf ekle</ButtonLink>}
        <ButtonLink variant="ghost" to="/siniflar">Sınıf listesi</ButtonLink>
      </div>
    </div>
  )
}

function Summary({ label, value, tone }: { label: string; value: string; tone?: 'success' | 'warning' }) {
  return (
    <div className="rounded-[var(--radius-md)] ring-1 ring-line px-4 py-3">
      <p className="text-[12.5px] text-ink-3">{label}</p>
      <p className={cn('mt-0.5 text-[17px] font-semibold text-ink', tone === 'success' && 'text-success', tone === 'warning' && 'text-warning')}>{value}</p>
    </div>
  )
}

// ---------------------------------------------------------------- 9. paketler

type PackageRow = { id: number; name: string; program: string | null; term: string | null; list_price: string; default_installments: number; is_active: boolean }

function PaketlerStep({ overview }: { overview: Overview }) {
  const refresh = useRefreshOverview()
  const qc = useQueryClient()
  const can = useCan()
  const packages = useQuery({ queryKey: ['finance', 'packages', 'wizard'], queryFn: () => api.get<{ data: PackageRow[] }>('/finance/packages'), enabled: can('finance.view') })
  const [form, setForm] = useState({ name: '', program_id: '', list_price: '', default_installments: '9' })
  const create = useMutation({
    mutationFn: () => api.post('/finance/packages', {
      name: form.name.trim(), program_id: form.program_id ? Number(form.program_id) : null, academic_term_id: overview.current_term?.id ?? null,
      list_price: form.list_price.replace(/\./g, '').replace(/\s|₺/g, '').trim(), default_installments: Number(form.default_installments) || 1, is_active: true,
    }),
    onSuccess: () => { toast.success('Paket oluşturuldu.'); setForm({ ...form, name: '', list_price: '' }); qc.invalidateQueries({ queryKey: ['finance'] }); refresh() },
    onError: onError('Paket oluşturulamadı.'),
  })

  if (!can('installments.manage')) return <Alert tone="warning">Eğitim paketi tanımlamak için ödeme planı yönetme yetkisi gerekir.</Alert>
  const rows = packages.data?.data ?? []
  return (
    <div className="flex flex-col gap-5">
      {rows.length > 0 && (
        <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
          {rows.map((p) => (
            <li key={p.id} className="flex flex-wrap items-center gap-2 px-4 py-3">
              <span className="text-[15px] font-medium text-ink">{p.name}</span>
              <span className="text-[13px] text-ink-3">{[p.program, p.term].filter(Boolean).join(' · ')}</span>
              <span className="ml-auto tabular text-ink">{money(p.list_price)} · {p.default_installments} taksit</span>
            </li>
          ))}
        </ul>
      )}
      <Box title={<>Yeni paket {overview.current_term ? <span className="font-normal text-ink-3">· {overview.current_term.name} dönemi</span> : null}</>}>
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
          <Field label="Paket adı" required><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="örn. 12. Sınıf Yıllık" /></Field>
          <Field label="Program" optional><Select value={form.program_id} onChange={(e) => setForm({ ...form, program_id: e.target.value })} placeholder="Tüm programlar" options={overview.programs.map((p) => ({ value: p.id, label: p.name }))} /></Field>
          <Field label="Liste fiyatı (₺)" required><Input inputMode="decimal" value={form.list_price} onChange={(e) => setForm({ ...form, list_price: e.target.value })} placeholder="45000" /></Field>
          <Field label="Taksit sayısı" required><Input type="number" min={1} max={36} value={form.default_installments} onChange={(e) => setForm({ ...form, default_installments: e.target.value })} /></Field>
        </div>
        <div className="mt-4 flex justify-end">
          <Button variant="primary" icon={<Plus className="size-4" />} loading={create.isPending} disabled={!form.name.trim() || !form.list_price.trim()} onClick={() => create.mutate()}>Paketi oluştur</Button>
        </div>
      </Box>
      <p className="text-[13px] text-ink-2">
        Kasa/banka hesapları: <span className="font-medium text-ink">{overview.counts.accounts}</span> tanımlı. Tahsilatların düşeceği hesapları Finans › Kasa ve banka ekranından düzenleyin. <ButtonLink size="xs" variant="ghost" to="/finans/hesaplar">Hesaplar</ButtonLink>
      </p>
    </div>
  )
}

// ---------------------------------------------------------------- 10. öğretmenler

type TeacherRow = { id: number; full_name: string; specialty: string | null }

function OgretmenlerStep({ overview }: { overview: Overview }) {
  const refresh = useRefreshOverview()
  const qc = useQueryClient()
  const can = useCan()
  const [formOpen, setFormOpen] = useState(false)
  const [importOpen, setImportOpen] = useState(false)
  const options = useQuery({ queryKey: ['teachers', 'options'], queryFn: () => api.get<TeacherOptions>('/teachers/options'), enabled: can('teachers.manage') })
  const teachers = useQuery({ queryKey: ['teachers', 'wizard'], queryFn: () => api.get<Paginated<TeacherRow>>('/teachers', { per_page: 100, status: 'active' }) })
  const rows = teachers.data?.data ?? []

  if (!can('teachers.manage')) return <Alert tone="warning">Öğretmen eklemek için öğretmen yönetme yetkisi gerekir.</Alert>
  return (
    <div className="flex flex-col gap-5">
      {overview.counts.subjects === 0 && <Alert tone="warning">Öğretmenlerin branş derslerini seçebilmek için önce “Dersler” adımını tamamlayın.</Alert>}
      <ChoiceCards
        items={[
          { icon: <UserPlus className="size-5" />, title: 'Tek tek ekle', text: 'Branş dersleri, çalışma şekli ve isteğe bağlı öğretmen paneli kullanıcısı.', onClick: () => setFormOpen(true) },
          { icon: <FileUp className="size-5" />, title: 'Excel’den içe aktar', text: 'Şablonu indirin, doldurun; satır satır kontrol edip onaylayın.', onClick: () => setImportOpen(true) },
        ]}
      />
      <div>
        <p className="mb-2 text-[13px] font-medium text-ink-2">Aktif öğretmenler ({overview.counts.teachers}) · sistem kullanıcısı olan: {overview.counts.teacher_users}</p>
        {teachers.isLoading ? <Skeleton className="h-8" /> : <ItemList empty="Henüz öğretmen yok." items={rows.map((t) => <Badge key={t.id}>{t.full_name}{t.specialty ? <span className="text-ink-3"> · {t.specialty}</span> : null}</Badge>)} />}
      </div>
      <TeacherFormDrawer open={formOpen} options={options.data} onClose={() => { setFormOpen(false); refresh() }} onSaved={() => { setFormOpen(false); qc.invalidateQueries({ queryKey: ['teachers'] }); refresh() }} />
      <ExcelImportDialog entity="teachers" open={importOpen} onClose={() => { setImportOpen(false); refresh() }} />
    </div>
  )
}

function ChoiceCards({ items }: { items: { icon: ReactNode; title: string; text: string; onClick: () => void }[] }) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
      {items.map((it) => (
        <button key={it.title} type="button" onClick={it.onClick} className="flex items-start gap-3 rounded-[var(--radius-md)] ring-1 ring-line bg-surface p-4 text-left transition-colors hover:bg-surface-2 hover:ring-line-strong">
          <span className="grid size-10 shrink-0 place-items-center rounded-[var(--radius-md)] bg-surface-2 ring-1 ring-line text-ink-2">{it.icon}</span>
          <span className="min-w-0">
            <span className="block text-[15px] font-medium text-ink">{it.title}</span>
            <span className="mt-0.5 block text-[13px] leading-relaxed text-ink-2">{it.text}</span>
          </span>
        </button>
      ))}
    </div>
  )
}

// ---------------------------------------------------------------- 11. WhatsApp

function WhatsappStep({ overview }: { overview: Overview }) {
  const can = useCan()
  const w = overview.whatsapp
  const statusText = !w ? 'Bağlı değil' : w.status === 'connected' && w.is_enabled ? 'Bağlı ve etkin' : w.status === 'connected' ? 'Bağlı, etkin değil' : w.status === 'error' ? 'Bağlantı hatası' : 'Yapılandırıldı, test edilmedi'
  return (
    <div className="flex flex-col gap-5">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Summary label="WhatsApp durumu" value={statusText} tone={overview.steps.whatsapp ? 'success' : 'warning'} />
        <Summary label="Sağlayıcı" value={w?.provider === 'meta_cloud' ? 'Meta Cloud API' : w?.provider === 'generic_http' ? 'Genel HTTP' : '—'} />
      </div>
      <ol className="flex flex-col gap-2 text-[14px] text-ink-2 list-decimal pl-5">
        <li>Entegrasyonlar ekranında WhatsApp kartını açıp sağlayıcı bilgilerini girin.</li>
        <li>“Bağlantıyı test et” ile doğrulayın ve etkinleştirin.</li>
        <li>Otomatik mesaj kuralları varsayılan olarak kapalıdır; İletişim › Otomasyonlar ekranından tek tek açılır.</li>
      </ol>
      <div className="flex flex-wrap gap-2">
        {can('integrations.manage') && <ButtonLink variant="primary" icon={<MessageCircle className="size-4" />} to="/ayarlar/entegrasyonlar">WhatsApp bağlantısını kur</ButtonLink>}
        {can('templates.manage') && <ButtonLink to="/iletisim/sablonlar">Mesaj şablonları</ButtonLink>}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------- 12. öğrenciler

type StudentRow = { id: number; full_name: string; student_no: string }

function OgrencilerStep({ overview }: { overview: Overview }) {
  const refresh = useRefreshOverview()
  const qc = useQueryClient()
  const can = useCan()
  const [formOpen, setFormOpen] = useState(false)
  const [importOpen, setImportOpen] = useState(false)
  const options = useQuery({ queryKey: ['students', 'options'], queryFn: () => api.get<StudentOptions>('/students/options'), enabled: can('students.create') })
  const recent = useQuery({ queryKey: ['students', 'wizard'], queryFn: () => api.get<Paginated<StudentRow>>('/students', { per_page: 12, sort: '-registered_on' }) })

  if (!can('students.create')) return <Alert tone="warning">Öğrenci eklemek için öğrenci ekleme yetkisi gerekir.</Alert>
  const rows = recent.data?.data ?? []
  return (
    <div className="flex flex-col gap-5">
      <ChoiceCards
        items={[
          { icon: <UserPlus className="size-5" />, title: 'Tek tek ekle', text: 'Öğrenci + veli formu; isterseniz kayıt ve ödeme planı da aynı anda.', onClick: () => setFormOpen(true) },
          { icon: <FileUp className="size-5" />, title: 'Excel’den içe aktar', text: 'Şablonu indirin, doldurun; hatalı satırları önizlemede görüp onaylayın.', onClick: () => setImportOpen(true) },
        ]}
      />
      <div>
        <p className="mb-2 text-[13px] font-medium text-ink-2">Aktif öğrenci: {overview.counts.students} · veli: {overview.counts.guardians}</p>
        {recent.isLoading ? <Skeleton className="h-8" /> : rows.length === 0 ? <EmptyState compact icon={<School />} title="Henüz öğrenci eklenmedi" /> : (
          <ItemList empty="" items={rows.map((s) => <Badge key={s.id}>{s.full_name} <span className="text-ink-3">{s.student_no}</span></Badge>)} />
        )}
      </div>
      <p className="text-[12.5px] text-ink-3">Her öğrenciye portal hesabı otomatik açılır (kullanıcı adı öğrenci no). Sınıf ataması öğrenci profilinden ya da Yerleştirme ekranından yapılır.</p>
      <StudentFormDrawer open={formOpen} options={options.data} onClose={() => setFormOpen(false)} onSaved={() => { setFormOpen(false); qc.invalidateQueries({ queryKey: ['students'] }); refresh() }} />
      <ExcelImportDialog entity="students" open={importOpen} onClose={() => { setImportOpen(false); refresh() }} />
    </div>
  )
}
