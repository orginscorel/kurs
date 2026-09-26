import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Laptop, ArrowLeft, CloudCog, Download, HardDrive, MonitorDown, MonitorSmartphone, ShieldCheck, Smartphone } from 'lucide-react'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { ChangelogList } from '@/components/app/ChangelogList'
import type { ChangelogEntry } from '@/components/app/version'
import { useCan } from '@/app/auth'
import { cn } from '@/lib/cn'
import { date } from '@/lib/format'

type Installer = { version: string; name: string; url: string; size: number; sha256: string; pub_date: string | null }

type ReleaseInfo = {
  version: string
  pub_date: string | null
  min_os: string | null
  arch: string
  dmg: { name: string; url: string; size: number; sha256: string } | null
  windows: Installer | null
  android: Installer | null
  ios: { url: string } | null
  notes: ChangelogEntry[] | null
}

/** Masaüstü uygulamasının içinden açıldıysa kabuğun eklediği işaret (desktop/src-tauri/src/bridge.js). */
type DesktopMarker = { version: string; mode: 'local' | 'remote' }
const desktopMarker = (): DesktopMarker | null =>
  (window as unknown as { __KURS_DESKTOP__?: DesktopMarker }).__KURS_DESKTOP__ ?? null

async function fetchRelease(): Promise<ReleaseInfo | null> {
  const r = await fetch(`/desktop/release.json?t=${Date.now()}`, { cache: 'no-store', credentials: 'same-origin' })
  if (!r.ok) return null
  const type = r.headers.get('content-type') ?? ''
  if (!type.includes('json')) return null // yayın yokken SPA sayfası dönebilir
  return (await r.json()) as ReleaseInfo
}

const mb = (bytes: number) => `${(bytes / 1024 / 1024).toLocaleString('tr-TR', { maximumFractionDigits: 0 })} MB`

/** Diğer platform kartı: sürüm yayınlanmışsa indirme bağlantısı, yoksa "Hazırlanıyor". */
function PlatformCard({ icon, name, href, label, meta, hint, download, external, loading }: {
  icon: ReactNode; name: string; href?: string | null; label: string
  meta?: string; hint?: string; download?: boolean; external?: boolean; loading?: boolean
}) {
  return (
    <div className="flex flex-col gap-2 rounded-[var(--radius-sm)] px-3 py-2.5 ring-1 ring-line">
      <div className="flex items-center justify-between gap-2">
        <span className="inline-flex items-center gap-2 text-[13.5px] font-medium text-ink">{icon}{name}</span>
        {loading ? <Skeleton className="h-5 w-16" /> : href ? null : <Badge tone="neutral">Hazırlanıyor</Badge>}
      </div>
      {href && (
        <a
          href={href}
          {...(download ? { download: true } : {})}
          {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
          className="inline-flex h-9 items-center justify-center gap-2 rounded-[var(--radius-sm)] bg-primary px-3 text-[13.5px] font-medium text-white hover:bg-primary-hover"
        >
          <Download className="size-4" /> {label}
        </a>
      )}
      {href && meta && <span className="text-[12px] text-ink-3">{meta}</span>}
      {href && hint && <span className="text-[12px] text-ink-3">{hint}</span>}
    </div>
  )
}

function Requirement({ icon, label, value }: { icon: ReactNode; label: string; value: string }) {
  return (
    <div className="flex items-start gap-2.5">
      <span className="mt-0.5 text-ink-3 [&_svg]:size-4">{icon}</span>
      <div className="min-w-0">
        <div className="text-[12.5px] text-ink-3">{label}</div>
        <div className="text-[13.5px] text-ink">{value}</div>
      </div>
    </div>
  )
}

/**
 * Uygulamalar: macOS masaüstü uygulaması indirme bağlantısı, sürüm, sistem gereksinimi, kullanım biçimleri.
 * `standalone`: öğretmen portalı hesapları için kabuksuz görünüm (/ogretmen/uygulamalar).
 */
export default function AppsPage({ standalone = false }: { standalone?: boolean }) {
  const can = useCan()
  const { data, isLoading, isError } = useQuery({ queryKey: ['desktop-apps', 'release'], queryFn: fetchRelease, staleTime: 5 * 60_000 })
  const marker = desktopMarker()

  return (
    <div className={cn('mx-auto max-w-[960px] animate-fade-in', standalone && 'px-4 py-6')}>
      {standalone && (
        <Link to="/ogretmen" className="mb-4 inline-flex items-center gap-1.5 text-[13px] text-ink-2 hover:text-ink">
          <ArrowLeft className="size-4" /> Portala dön
        </Link>
      )}
      <PageHeader
        title="Uygulamalar"
        description="Kurum sistemini bilgisayarınıza kurulan uygulamayla kullanın. Web ile aynı hesap ve aynı ekranlar."
      />

      {marker && (
        <Alert tone="success" className="mb-4" title="Masaüstü uygulamasını kullanıyorsunuz">
          Sürüm {marker.version} · {marker.mode === 'local' ? 'Kurum bilgisayarı (yerel kurulum)' : 'Çevrimiçi kullanım'}. Güncellemeler kendiliğinden gelir.
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
        <Panel
          title={<span className="inline-flex items-center gap-2"><Laptop className="size-4" /> macOS</span>}
          description="Apple Silicon (M serisi) ve Intel işlemcili Mac'ler için tek paket"
          actions={data ? <Badge tone="info">v{data.version}</Badge> : null}
        >
          {isLoading ? (
            <Skeleton className="h-40" />
          ) : !data || !data.dmg || isError ? (
            <EmptyState compact icon={<MonitorDown />} title="Henüz yayımlanmış sürüm yok"
              description="İlk sürüm hazırlandığında indirme bağlantısı burada görünecek." />
          ) : (
            <div className="flex flex-col gap-4">
              <div className="flex flex-wrap items-center gap-3">
                <a
                  href={data.dmg.url}
                  className="inline-flex h-11 items-center gap-2 rounded-[var(--radius-md)] bg-primary px-5 text-[15px] font-medium text-white hover:bg-primary-hover"
                  download
                >
                  <Download className="size-4" /> macOS için indir
                </a>
                <span className="text-[13px] text-ink-2">
                  {mb(data.dmg.size)}{data.pub_date ? ` · ${date(data.pub_date, 'long')}` : ''}
                </span>
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <Requirement icon={<Laptop />} label="İşletim sistemi" value={`macOS ${data.min_os ?? '12'} ya da üzeri`} />
                <Requirement icon={<HardDrive />} label="Disk alanı" value="En az 1 GB boş alan (yerel kurulumda veriyle birlikte)" />
                <Requirement icon={<MonitorSmartphone />} label="Bellek" value="4 GB RAM (yerel kurulumda 8 GB önerilir)" />
                <Requirement icon={<ShieldCheck />} label="Güvenlik" value="Apple tarafından onaylı (imzalı ve noter onaylı)" />
              </div>
              <details className="text-[12.5px] text-ink-3">
                <summary className="cursor-pointer select-none">Dosya doğrulama (SHA-256)</summary>
                <code className="mt-1 block break-all rounded-[var(--radius-xs)] bg-surface-2 px-2 py-1 font-mono text-[12px] text-ink-2">{data.dmg.sha256}</code>
              </details>
              <ol className="list-decimal space-y-1 pl-5 text-[13.5px] text-ink-2">
                <li>İndirilen <strong>.dmg</strong> dosyasını açın ve uygulamayı <strong>Uygulamalar</strong> klasörüne sürükleyin.</li>
                <li>Uygulamayı açın ve kullanım biçimini seçin.</li>
                <li>
                  Kurum bilgisayarı için eşleştirme kodu gerekir
                  {can('sync.manage') && !standalone ? <> (<Link className="text-primary underline underline-offset-2" to="/ayarlar/bagli-cihazlar">Ayarlar › Bağlı cihazlar</Link>)</> : ' (kurum yöneticinizden alın)'}.
                </li>
              </ol>
            </div>
          )}
        </Panel>

        <Panel title="Kullanım biçimleri">
          <div className="flex flex-col gap-4">
            <div className="flex gap-3">
              <span className="grid size-9 shrink-0 place-items-center rounded-[var(--radius-sm)] bg-primary-soft text-primary"><HardDrive className="size-4" /></span>
              <div>
                <div className="text-[13.5px] font-semibold">Kurum bilgisayarı (yerel kurulum)</div>
                <p className="text-[13px] text-ink-2">Veri bilgisayarda da tutulur, internet kesilse de kayıt, yoklama ve tahsilat yapılır; bağlantı gelince web ile eşitlenir. Personel içindir.</p>
              </div>
            </div>
            <div className="flex gap-3">
              <span className="grid size-9 shrink-0 place-items-center rounded-[var(--radius-sm)] bg-primary-soft text-primary"><CloudCog className="size-4" /></span>
              <div>
                <div className="text-[13.5px] font-semibold">Çevrimiçi kullanım</div>
                <p className="text-[13px] text-ink-2">Öğretmen portalı ve yönetim ekranları uygulama penceresinde açılır, bildirimler masaüstünde gösterilir. Bilgisayarda veri tutulmaz.</p>
              </div>
            </div>
          </div>
        </Panel>
      </div>

      <Panel className="mt-4" title="Diğer platformlar">
        <div className="grid gap-3 sm:grid-cols-3">
          <PlatformCard
            icon={<MonitorDown className="size-4" />} name="Windows"
            href={data?.windows?.url} label="Windows için indir"
            meta={data?.windows ? `${mb(data.windows.size)}${data.windows.pub_date ? ` · ${date(data.windows.pub_date, 'long')}` : ''}` : undefined}
            loading={isLoading}
          />
          <PlatformCard
            icon={<Smartphone className="size-4" />} name="Android"
            href={data?.android?.url} label="Android APK indir" download
            meta={data?.android ? `${mb(data.android.size)}${data.android.pub_date ? ` · ${date(data.android.pub_date, 'long')}` : ''}` : undefined}
            hint={data?.android ? 'Kurulumda "bilinmeyen kaynak" iznini verin.' : undefined}
            loading={isLoading}
          />
          <PlatformCard
            icon={<Smartphone className="size-4" />} name="iPhone ve iPad"
            href={data?.ios?.url} label="App Store / TestFlight" external
            loading={isLoading}
          />
        </div>
      </Panel>

      {data?.notes && data.notes.length > 0 && (
        <Panel className="mt-4" title="Masaüstü sürüm notları">
          <ChangelogList entries={data.notes} compact />
        </Panel>
      )}
    </div>
  )
}
