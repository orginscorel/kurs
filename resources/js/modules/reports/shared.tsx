import { useState, type ComponentType, type ReactNode } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import type { LucideIcon } from 'lucide-react'
import { BarChart3, ClipboardCheck, Landmark, FileSpreadsheet, FileText, Gavel, GraduationCap, Lock, Send, UserPlus, Users, Wallet, Presentation } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/feedback'
import { Input, Segmented, Select } from '@/components/ui/form'

type Query = Parameters<typeof api.download>[1]

export type ReportDef = {
  key: string
  to: string
  title: string
  description: string
  icon: LucideIcon
  /** Hepsi gerekli (VE) */
  permissions: string[]
}

/** Rapor kataloğu: yetkiler sunucudaki routes/api/reports.php ile aynı. */
export const REPORTS: ReportDef[] = [
  { key: 'students', to: '/raporlar/ogrenciler', title: 'Öğrenci listesi', icon: Users, permissions: ['reports.view', 'students.view'],
    description: 'Durum, program, sınıf, sınıf seviyesi ve kayıt tarihine göre filtreli öğrenci listesi.' },
  { key: 'attendance', to: '/raporlar/devamsizlik', title: 'Yoklama ve devamsızlık', icon: ClipboardCheck, permissions: ['reports.view', 'attendance.view'],
    description: 'Tarih aralığı ve sınıfa göre katılım oranı, gelmeyen öğrenciler ve eksik yoklamalar.' },
  { key: 'finance', to: '/raporlar/tahsilat', title: 'Tahsilat ve alacak', icon: Wallet, permissions: ['reports.view', 'reports.finance'],
    description: 'Dönemdeki tahsilat, tahsilat oranı, ödeme yöntemleri ve alacak yaşlandırması.' },
  { key: 'finance-analytics', to: '/raporlar/finans-analiz', title: 'Finans ve muhasebe analizleri', icon: Landmark, permissions: ['reports.view', 'reports.finance'],
    description: 'Gelir tablosu, nakit akışı, tahsilat performansı, yaşlandırma, fatura/KDV özeti, program kârlılığı ve mizan.' },
  { key: 'exams', to: '/raporlar/sinavlar', title: 'Sınav sonuçları özeti', icon: GraduationCap, permissions: ['reports.view', 'exams.view'],
    description: 'Seçilen sınavda sınıf ortalamaları ve öğrenci sıralaması.' },
  { key: 'leads', to: '/raporlar/on-kayit', title: 'Ön kayıt dönüşümü', icon: UserPlus, permissions: ['reports.view', 'crm.view'],
    description: 'Kaynak ve aya göre ön kayıt sayısı, kayda dönüşüm oranı, sorumlu performansı.' },
  { key: 'teachers', to: '/raporlar/ogretmen-yuku', title: 'Öğretmen ders yükü', icon: Presentation, permissions: ['reports.view', 'teachers.view'],
    description: 'Haftalık program saati, dönemde verilen ders, iptaller, eksik yoklama ve etütler.' },
  { key: 'discipline', to: '/raporlar/disiplin', title: 'Disiplin', icon: Gavel, permissions: ['reports.view', 'discipline.view'],
    description: 'Dönem, sınıf, davranış ve öğretmen kırılımı; en sık davranışlar, tekrar eden öğrenciler, yaptırım dağılımı ve eğilim.' },
  { key: 'communication', to: '/raporlar/iletisim', title: 'İletişim ve toplu gönderim', icon: Send, permissions: ['reports.view', 'messages.view'],
    description: 'Aylık SMS / e-posta / WhatsApp gönderimi, başarı oranı, harcanan SMS ve maliyet tahmini, kampanya sonuçları.' },
]

export function useAllowed() {
  const can = useCan()
  return (def: ReportDef) => def.permissions.every((p) => can(p))
}

/** Rapor sayfası çerçevesi: başlık, rapor değiştirici, yetki koruması. */
export function ReportFrame({ reportKey, actions, filters, children }: { reportKey: string; actions?: ReactNode; filters?: ReactNode; children: ReactNode }) {
  const allowed = useAllowed()
  const navigate = useNavigate()
  const location = useLocation()
  const def = REPORTS.find((r) => r.key === reportKey)!
  const visible = REPORTS.filter(allowed)

  if (!allowed(def)) {
    return (
      <div className="animate-fade-in">
        <PageHeader title={def.title} breadcrumbs={[{ label: 'Raporlar', to: '/raporlar' }, { label: def.title }]} />
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<Lock />} title="Bu raporu görüntüleme yetkiniz yok" description="Yetki için kurum yöneticinizle görüşün."
            action={<Button onClick={() => navigate('/raporlar')}>Raporlara dön</Button>} />
        </div>
      </div>
    )
  }

  return (
    <div className="animate-fade-in">
      <PageHeader title={def.title} description={def.description} breadcrumbs={[{ label: 'Raporlar', to: '/raporlar' }, { label: def.title }]} actions={actions} />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[210px_minmax(0,1fr)]">
        <nav aria-label="Raporlar" className="hidden lg:block">
          <ul className="sticky top-4 flex flex-col gap-0.5">
            {visible.map((r) => (
              <li key={r.key}>
                <Link to={r.to} className={cn('flex items-center gap-2 rounded-[var(--radius-sm)] px-2.5 py-2 text-[13px] transition-colors',
                  location.pathname === r.to ? 'bg-surface-2 font-medium text-ink ring-1 ring-line' : 'text-ink-2 hover:bg-surface-2 hover:text-ink')}>
                  <r.icon className="size-4 shrink-0 text-ink-3" />
                  <span className="truncate">{r.title}</span>
                </Link>
              </li>
            ))}
          </ul>
        </nav>
        <div className="min-w-0">
          <div className="mb-3 lg:hidden">
            <Select aria-label="Rapor seç" value={def.to} onChange={(e) => navigate(e.target.value)} options={visible.map((r) => ({ value: r.to, label: r.title }))} />
          </div>
          {filters && <div className="mb-4 flex flex-wrap items-center gap-2 rounded-[var(--radius-lg)] bg-surface p-3 ring-1 ring-line">{filters}</div>}
          {children}
        </div>
      </div>
    </div>
  )
}

/**
 * Yetki kalkanı: yetkisiz kullanıcıda raporun sorguları hiç çalışmaz (403 üretmez),
 * yalnız "yetkiniz yok" ekranı gösterilir.
 */
export function reportPage(reportKey: string, Inner: ComponentType) {
  return function GuardedReport() {
    const allowed = useAllowed()
    const def = REPORTS.find((r) => r.key === reportKey)!
    return allowed(def) ? <Inner /> : <ReportFrame reportKey={reportKey}>{null}</ReportFrame>
  }
}

/** URL'de tutulan filtreler (bağlantı paylaşılabilir). */
export function useUrlFilters<T extends Record<string, string>>(defaults: T) {
  const [params, setParams] = useSearchParams()
  const values = Object.fromEntries(Object.keys(defaults).map((k) => [k, params.get(k) ?? defaults[k]])) as T
  const set = (patch: Partial<Record<keyof T, string | null>>) =>
    setParams((prev) => {
      const next = new URLSearchParams(prev)
      Object.entries(patch).forEach(([k, v]) => (v === null || v === undefined || v === '' || v === defaults[k] ? next.delete(k) : next.set(k, String(v))))
      if (!('page' in patch)) next.delete('page')
      return next
    }, { replace: true })
  return [values, set] as const
}

/* ------------------------------------------------------------------ Tarih aralığı */

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

export function addDaysISO(value: string, n: number) {
  const [y, m, d] = value.split('-').map(Number)
  return iso(new Date(y!, m! - 1, d! + n))
}

export type RangePreset = 'last30' | 'month' | 'last_month' | 'year' | 'last12' | 'custom'

export function presetRange(key: RangePreset): { from: string; to: string } {
  const now = new Date()
  const t = todayISO()
  switch (key) {
    case 'month':
      return { from: iso(new Date(now.getFullYear(), now.getMonth(), 1)), to: iso(new Date(now.getFullYear(), now.getMonth() + 1, 0)) }
    case 'last_month':
      return { from: iso(new Date(now.getFullYear(), now.getMonth() - 1, 1)), to: iso(new Date(now.getFullYear(), now.getMonth(), 0)) }
    case 'year':
      return { from: `${now.getFullYear()}-01-01`, to: t }
    case 'last12':
      return { from: iso(new Date(now.getFullYear(), now.getMonth() - 11, 1)), to: t }
    default:
      return { from: addDaysISO(t, -29), to: t }
  }
}

const PRESET_LABELS: Record<Exclude<RangePreset, 'custom'>, string> = { last30: 'Son 30 gün', month: 'Bu ay', last_month: 'Geçen ay', year: 'Bu yıl', last12: 'Son 12 ay' }

export function DateRange({ from, to, presets, onChange }: { from: string; to: string; presets: Exclude<RangePreset, 'custom'>[]; onChange: (r: { from: string; to: string }) => void }) {
  const active = presets.find((p) => {
    const r = presetRange(p)
    return r.from === from && r.to === to
  }) ?? 'custom'
  return (
    <>
      <div className="max-w-full overflow-x-auto scroll-thin">
        <Segmented<RangePreset> size="sm" value={active} onChange={(v) => v !== 'custom' && onChange(presetRange(v))}
          options={[...presets.map((p) => ({ value: p as RangePreset, label: PRESET_LABELS[p] })), { value: 'custom', label: 'Özel' }]} />
      </div>
      <div className="flex w-full items-center gap-1.5 sm:w-auto">
        <Input type="date" aria-label="Başlangıç" value={from} max={to} onChange={(e) => e.target.value && onChange({ from: e.target.value, to })} className="min-w-0 flex-1 sm:w-[150px] sm:flex-none" />
        <span className="text-ink-3">–</span>
        <Input type="date" aria-label="Bitiş" value={to} min={from} onChange={(e) => e.target.value && onChange({ from, to: e.target.value })} className="min-w-0 flex-1 sm:w-[150px] sm:flex-none" />
      </div>
    </>
  )
}

/* ------------------------------------------------------------------ Dışa aktarma */

export function ExportButton({ path, query, name, kind = 'excel', label, permission = 'reports.export', disabled }: {
  path: string; query?: Query; name: string; kind?: 'excel' | 'pdf'; label?: string; permission?: string | string[]; disabled?: boolean
}) {
  const can = useCan()
  const [busy, setBusy] = useState(false)
  const perms = Array.isArray(permission) ? permission : [permission]
  if (!perms.every((p) => can(p))) return null
  const run = async () => {
    setBusy(true)
    try {
      await api.download(path, query, name)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.firstError() : 'Dosya hazırlanamadı.')
    } finally {
      setBusy(false)
    }
  }
  return (
    <Button icon={kind === 'pdf' ? <FileText className="size-4" /> : <FileSpreadsheet className="size-4" />} loading={busy} disabled={disabled} onClick={run}>
      {label ?? (kind === 'pdf' ? 'PDF' : 'Excel')}
    </Button>
  )
}

/** Oransal yatay çubuk listesi (tek renk; grafik yerine sade dağılım). */
export function BarList({ items, format = (n) => n.toLocaleString('tr-TR') }: { items: { label: string; value: number; hint?: string }[]; format?: (n: number) => string }) {
  const max = Math.max(1, ...items.map((i) => i.value))
  return (
    <ul className="flex flex-col gap-2">
      {items.map((i) => (
        <li key={i.label} className="flex items-center gap-3 text-[12.5px]">
          <span className="w-32 shrink-0 truncate text-ink-2" title={i.label}>{i.label}</span>
          <span className="relative h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-2">
            <span className="absolute inset-y-0 left-0 rounded-full bg-[var(--series-1)]" style={{ width: `${(i.value / max) * 100}%` }} />
          </span>
          <span className="w-16 shrink-0 text-right tabular text-ink">{format(i.value)}</span>
          {i.hint !== undefined && <span className="hidden w-14 shrink-0 text-right tabular text-ink-3 sm:inline">{i.hint}</span>}
        </li>
      ))}
    </ul>
  )
}

/**
 * Basit tablo kabı: mobilde yatay kaydırma kabın içinde kalır.
 * Hizalama kuralı: ilk sütun (ya da `leftCols={2}` ile ilk iki sütun) sola, diğerleri ortaya.
 */
export function SimpleTable({ head, children, className, leftCols = 1 }: { head: ReactNode; children: ReactNode; className?: string; leftCols?: 1 | 2 }) {
  return (
    <div className={cn('overflow-x-auto scroll-thin', className)}>
      <table className={cn('tbl w-full min-w-[560px] text-[13px] [&_td]:text-center [&_th]:text-center [&_td:first-child]:text-left [&_th:first-child]:text-left',
        leftCols === 2 && '[&_td:nth-child(2)]:text-left [&_th:nth-child(2)]:text-left')}>
        <thead className="text-[12px] text-ink-3">{head}</thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  )
}

/** `right` geriye uyumluluk için duruyor; hizayı SimpleTable belirler (ilk sütun sola, diğerleri ortaya). */
// eslint-disable-next-line @typescript-eslint/no-unused-vars
export const Th = ({ children }: { children?: ReactNode; right?: boolean }) => <th>{children}</th>

export { BarChart3 as ReportsIcon }
