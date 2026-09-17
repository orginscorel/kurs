import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { Link, NavLink, useLocation } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { create } from 'zustand'
import { toast } from 'sonner'
import {
  Bell, CalendarDays, Check, ChevronDown, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Eye, LogOut, Menu as MenuIcon, X, type LucideIcon,
} from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useAuth, type Me } from '@/app/auth'
import { BrandMark } from '@/app/AuthGate'
import { Avatar } from '@/components/ui/feedback'
import { NotificationCenter } from '@/components/layout/NotificationCenter'

/**
 * ÖĞRENCİ/VELİ ve ÖĞRETMEN PORTALLARININ ORTAK KABUĞU.
 * - Masaüstü (≥1024): solda gruplu, daraltılabilir kenar çubuğu (tercih localStorage'da).
 * - Tablet (768–1023): ikonlu dar ray + ipucu; "Menüyü aç" tam etiketli çekmeceyi açar.
 * - Mobil (<768): üstte başlık çubuğu, altta 4 sekme + "Menü" (alt sayfa, gruplu liste).
 * Her portal yalnız kendi öğe listesini, kullanıcı kartını ve (veli) öğrenci seçicisini verir.
 */

export type PortalNavItem = {
  to: string
  label: string
  icon: LucideIcon
  end?: boolean
  /** Okunmamış/bekleyen sayısı (0 → gösterilmez) */
  badge?: number
  /** Bu öğeye ait sayılacak başka yol önekleri (ör. ayrıntı sayfaları) */
  match?: string[]
}
export type PortalNavGroup = { label: string; items: PortalNavItem[] }
export type PortalSwitcherStudent = { id: number; name: string; firstName: string; subtitle?: string | null; photo?: string | null }
export type PortalSwitcher = { students: PortalSwitcherStudent[]; current: number | null; onPick: (id: number) => void }

type Props = {
  homeTo: string
  /** "Öğrenci portalı", "Veli portalı", "Öğretmen portalı" */
  portalLabel: string
  groups: PortalNavGroup[]
  /** Mobil alt çubukta duracak öğelerin yolları (en fazla 4) */
  tabs: string[]
  user: { name: string; avatar?: string | null; subtitle: string; profileTo: string }
  switcher?: PortalSwitcher | null
  /** Önizleme bandında hedef adın yanına eklenecek metin: "(veli)" */
  impersonationSuffix?: string
  /** Üst çubuktaki "bugün" bilgisine eklenecek kısa metin (ör. "3 ders") */
  todayNote?: ReactNode
  /** Üst çubuk hızlı eylemleri (masaüstü/tablet) */
  actions?: ReactNode
  /** Hesap grubunun altına eklenecek bağlantılar (ör. Yönetim paneli) */
  accountExtra?: { to: string; label: string; icon: LucideIcon }[]
  onSignOut: () => void | Promise<void>
  children: ReactNode
}

/* ---------------- sayfa başlığı (PortalTitle → üst çubuk) ---------------- */

const usePageTitleStore = create<{ path: string; title: string; set: (path: string, title: string) => void }>((set) => ({
  path: '',
  title: '',
  set: (path, title) => set({ path, title }),
}))

/** Sayfa, üst çubukta görünecek başlığını bildirir (yalnız o yol için geçerlidir). */
export function usePortalPageTitle(title: string | undefined) {
  const { pathname } = useLocation()
  const set = usePageTitleStore((s) => s.set)
  useEffect(() => {
    if (title) set(pathname, title)
  }, [title, pathname, set])
}

/* ---------------- yardımcılar ---------------- */

/** Bildirim bağlantısını bu portalda açılabilecek adrese çevirir (yönetim adresleri portalda açılmaz). */
function portalNotificationUrl(homeTo: string) {
  return (url: string): string | null => {
    if (!url.startsWith('/')) return null
    if (url === homeTo || url.startsWith(`${homeTo}/`)) return url
    // Öğretmene yönetim adresiyle yazılmış bildirimler (ör. /odevler/12) → öğretmen portalındaki karşılığı
    if (homeTo === '/ogretmen' && /^\/(odevler|yoklama|siniflar|duyurular|sinavlar)(\/|$)/.test(url)) return `${homeTo}${url}`
    return null
  }
}


function useMedia(query: string) {
  const get = () => (typeof window !== 'undefined' ? window.matchMedia(query).matches : false)
  const [match, setMatch] = useState(get)
  useEffect(() => {
    const mq = window.matchMedia(query)
    const on = () => setMatch(mq.matches)
    on()
    mq.addEventListener('change', on)
    return () => mq.removeEventListener('change', on)
  }, [query])
  return match
}

const COLLAPSE_KEY = 'ebe-portal-sidebar'

function readCollapsed() {
  try {
    return localStorage.getItem(COLLAPSE_KEY) === 'collapsed'
  } catch {
    return false
  }
}

function matches(item: PortalNavItem, pathname: string) {
  if (item.end) return pathname === item.to || pathname === `${item.to}/`
  return [item.to, ...(item.match ?? [])].some((p) => pathname === p || pathname.startsWith(`${p}/`))
}

function findActive(groups: PortalNavGroup[], pathname: string) {
  let best: { item: PortalNavItem; group: PortalNavGroup } | null = null
  for (const group of groups) {
    for (const item of group.items) {
      if (matches(item, pathname) && (!best || item.to.length > best.item.to.length)) best = { item, group }
    }
  }
  return best
}

function BadgeCount({ n, className, small }: { n?: number; className?: string; small?: boolean }) {
  if (!n) return null
  return (
    <span className={cn('inline-flex items-center justify-center rounded-full bg-danger font-semibold leading-none text-white tabular', small ? 'h-[18px] min-w-[18px] px-1 text-[12px]' : 'h-5 min-w-5 px-1.5 text-[12px]', className)}>
      {n > 99 ? '99+' : n}
      <span className="sr-only"> yeni</span>
    </span>
  )
}

function InstitutionLogo({ size }: { size: number }) {
  const logo = useAuth((s) => s.me?.institution.logo_url)
  if (logo) return <img src={logo} alt="" width={size} height={size} className="shrink-0 rounded-[8px] object-contain" style={{ width: size, height: size }} />
  return <BrandMark size={size} className="shrink-0" />
}

/** Açıkken arka planı kilitler, Escape ile kapanır, odağı içeri alıp kapanınca geri verir. */
function useOverlay(open: boolean, onClose: () => void, panel: React.RefObject<HTMLElement | null>) {
  useEffect(() => {
    if (!open) return
    const prevFocus = document.activeElement as HTMLElement | null
    const prevOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const t = window.setTimeout(() => panel.current?.focus(), 10)
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onClose()
      } else if (e.key === 'Tab' && panel.current) {
        const f = panel.current.querySelectorAll<HTMLElement>('a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])')
        if (!f.length) return
        const first = f[0]!
        const last = f[f.length - 1]!
        if (e.shiftKey && (document.activeElement === first || document.activeElement === panel.current)) {
          e.preventDefault()
          last.focus()
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault()
          first.focus()
        }
      }
    }
    document.addEventListener('keydown', onKey)
    return () => {
      window.clearTimeout(t)
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prevOverflow
      prevFocus?.focus?.()
    }
  }, [open, onClose, panel])
}

/** Mobil alt sayfa (bottom sheet). */
function Sheet({ open, onClose, title, children, footer }: { open: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null)
  const titleId = useId()
  useOverlay(open, onClose, ref)
  if (!open) return null
  return (
    <div className="fixed inset-0 z-50" role="presentation">
      <div className="absolute inset-0 bg-black/45 animate-fade-in" onClick={onClose} aria-hidden />
      <div
        ref={ref}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        className="absolute inset-x-0 bottom-0 flex max-h-[min(88dvh,760px)] flex-col rounded-t-[var(--radius-xl)] bg-surface shadow-[var(--shadow-pop)] outline-none animate-slide-up"
      >
        <div className="mx-auto mt-2 h-1 w-10 shrink-0 rounded-full bg-line-strong" aria-hidden />
        <div className="flex shrink-0 items-center justify-between gap-3 px-4 pb-2 pt-2">
          <h2 id={titleId} className="text-[16px] font-semibold tracking-[-0.01em]">{title}</h2>
          <button type="button" onClick={onClose} aria-label="Kapat" className="-mr-2 grid size-11 place-items-center rounded-full text-ink-2 hover:bg-surface-2">
            <X className="size-5" />
          </button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-4">{children}</div>
        {footer && <div className="shrink-0 border-t border-line px-4 pt-3 pb-[calc(env(safe-area-inset-bottom)+12px)]">{footer}</div>}
        {!footer && <div className="h-[env(safe-area-inset-bottom)] shrink-0" />}
      </div>
    </div>
  )
}

/* ---------------- ana bileşen ---------------- */

export function PortalLayout(props: Props) {
  const { groups, tabs, switcher, children } = props
  const me = useAuth((s) => s.me)
  const imp = me?.impersonation ?? null
  const location = useLocation()
  const isDesktop = useMedia('(min-width: 1024px)')
  const [collapsed, setCollapsed] = useState(readCollapsed)
  const [drawerOpen, setDrawerOpen] = useState(false) // tablet: tam etiketli çekmece
  const [menuOpen, setMenuOpen] = useState(false) // mobil: menü alt sayfası
  const [pickerOpen, setPickerOpen] = useState(false) // mobil: öğrenci seçici alt sayfası

  const rail = !isDesktop || collapsed
  const active = findActive(groups, location.pathname)
  const pageTitle = usePageTitleStore((s) => (s.path === location.pathname ? s.title : ''))
  const title = pageTitle || active?.item.label || props.portalLabel
  const isDetail = !!active && !active.item.end && location.pathname.replace(/\/$/, '') !== active.item.to

  useEffect(() => {
    try {
      localStorage.setItem(COLLAPSE_KEY, collapsed ? 'collapsed' : 'expanded')
    } catch {
      /* depolama kapalı olabilir */
    }
  }, [collapsed])

  useEffect(() => {
    setDrawerOpen(false)
    setMenuOpen(false)
    setPickerOpen(false)
  }, [location.pathname, switcher?.current])

  useEffect(() => {
    if (isDesktop) setDrawerOpen(false)
  }, [isDesktop])

  useEffect(() => {
    window.scrollTo({ top: 0 })
  }, [location.pathname, switcher?.current])

  useEffect(() => {
    document.title = `${title} · ${me?.institution.short_name || me?.institution.name || 'Erbaa Bilgi Eğitim'}`
  }, [title, me?.institution.short_name, me?.institution.name])

  const tabItems = tabs.map((to) => groups.flatMap((g) => g.items).find((i) => i.to === to)).filter((i): i is PortalNavItem => !!i)
  const menuActive = !tabItems.some((i) => matches(i, location.pathname))
  const menuBadge = groups.flatMap((g) => g.items).filter((i) => !tabs.includes(i.to)).reduce((a, i) => a + (i.badge ?? 0), 0)
  const current = switcher?.students.find((s) => s.id === switcher.current) ?? null

  const closeDrawer = useCallback(() => setDrawerOpen(false), [])
  const closeMenu = useCallback(() => setMenuOpen(false), [])
  const closePicker = useCallback(() => setPickerOpen(false), [])

  return (
    <div className="min-h-dvh bg-bg" style={{ '--portal-sb': rail ? '76px' : '264px', '--portal-tabbar': '62px' } as React.CSSProperties}>
      <a href="#portal-main" className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[60] focus:rounded-[var(--radius-sm)] focus:bg-surface focus:px-3 focus:py-2 focus:text-[14px] focus:font-medium focus:shadow-[var(--shadow-pop)]">
        İçeriğe geç
      </a>

      {/* Masaüstü / tablet kenar çubuğu */}
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-30 hidden flex-col border-r border-line bg-surface transition-[width] duration-200 md:flex',
          rail ? 'w-[76px]' : 'w-[264px]',
        )}
        aria-label={`${props.portalLabel} menüsü`}
      >
        <SidebarBody
          {...props}
          rail={rail}
          toggleLabel={isDesktop ? (collapsed ? 'Menüyü genişlet' : 'Menüyü daralt') : 'Menüyü aç'}
          onToggle={() => (isDesktop ? setCollapsed((v) => !v) : setDrawerOpen(true))}
          current={current}
        />
      </aside>

      {/* Tablet: tam etiketli çekmece */}
      {drawerOpen && !isDesktop && (
        <TabletDrawer onClose={closeDrawer}>
          <SidebarBody {...props} rail={false} toggleLabel="Menüyü kapat" onToggle={closeDrawer} current={current} closeIcon />
        </TabletDrawer>
      )}

      <div className={cn('flex min-h-dvh min-w-0 flex-col transition-[padding] duration-200', rail ? 'md:pl-[76px]' : 'md:pl-[264px]')}>
        <div className="sticky top-0 z-20">
          {imp && <ImpersonationBand suffix={props.impersonationSuffix} />}
          <header className="border-b border-line bg-surface/92 backdrop-blur-md">
            {/* Mobil üst çubuk */}
            <div className="flex h-14 items-center gap-1 px-2 md:hidden">
              {isDetail && active ? (
                <Link to={active.item.to} aria-label={`${active.item.label} sayfasına dön`} className="grid size-11 shrink-0 place-items-center rounded-full text-ink-2 hover:bg-surface-2">
                  <ChevronLeft className="size-6" />
                </Link>
              ) : (
                <Link to={props.homeTo} aria-label="Özet sayfası" className="grid size-11 shrink-0 place-items-center">
                  <InstitutionLogo size={30} />
                </Link>
              )}
              <div className="min-w-0 flex-1 px-1">
                <p className="truncate text-[16.5px] font-semibold leading-tight tracking-[-0.01em]" aria-live="polite">{title}</p>
                {!isDetail && <p className="truncate text-[12.5px] leading-tight text-ink-3">{props.portalLabel}</p>}
              </div>
              <NotificationCenter trigger={<Bell className="size-5" />} mapUrl={portalNotificationUrl(props.homeTo)} />
              {switcher && switcher.students.length > 0 ? (
                <button
                  type="button"
                  onClick={() => switcher.students.length > 1 && setPickerOpen(true)}
                  aria-haspopup={switcher.students.length > 1 ? 'dialog' : undefined}
                  aria-label={current ? `Seçili öğrenci: ${current.name}${switcher.students.length > 1 ? '. Öğrenci değiştir' : ''}` : 'Öğrenci seç'}
                  className={cn(
                    'flex h-11 max-w-[46%] shrink-0 items-center gap-1.5 rounded-full py-1 pl-1 pr-2.5',
                    switcher.students.length > 1 ? 'bg-surface ring-1 ring-line hover:bg-surface-2' : 'bg-surface-2',
                  )}
                >
                  <Avatar name={current?.name} src={current?.photo} size={32} />
                  <span className="min-w-0 truncate text-[14px] font-medium">{current?.firstName ?? 'Seç'}</span>
                  {switcher.students.length > 1 && <ChevronDown className="size-4 shrink-0 text-ink-3" />}
                </button>
              ) : (
                <Link to={props.user.profileTo} aria-label="Profilim" className="grid size-11 shrink-0 place-items-center rounded-full hover:bg-surface-2">
                  <Avatar name={props.user.name} src={props.user.avatar} size={34} />
                </Link>
              )}
            </div>

            {/* Tablet / masaüstü üst çubuk */}
            <div className="mx-auto hidden h-16 w-full max-w-[1200px] items-center gap-3 px-6 md:flex lg:px-8">
              <div className="min-w-0 flex-1">
                <nav aria-label="Konum" className="flex min-w-0 items-center gap-1 text-[12.5px] text-ink-3">
                  <Link to={props.homeTo} className="shrink-0 hover:text-ink hover:underline">{props.portalLabel}</Link>
                  {active && active.item.to !== props.homeTo && (
                    <>
                      <ChevronRight className="size-3.5 shrink-0" aria-hidden />
                      <span className="shrink-0">{active.group.label}</span>
                      {isDetail && (
                        <>
                          <ChevronRight className="size-3.5 shrink-0" aria-hidden />
                          <Link to={active.item.to} className="truncate hover:text-ink hover:underline">{active.item.label}</Link>
                        </>
                      )}
                    </>
                  )}
                </nav>
                <p className="truncate text-[17px] font-semibold leading-snug tracking-[-0.015em] text-ink">{title}</p>
              </div>
              <TodayChip note={props.todayNote} />
              {props.actions && <div className="hidden shrink-0 items-center gap-2 lg:flex">{props.actions}</div>}
              <NotificationCenter trigger={<Bell className="size-[18px]" />} mapUrl={portalNotificationUrl(props.homeTo)} />
            </div>
          </header>
        </div>

        <main id="portal-main" tabIndex={-1} className="mx-auto w-full min-w-0 max-w-[1200px] flex-1 px-4 pt-4 pb-[calc(env(safe-area-inset-bottom)+96px)] outline-none sm:px-6 md:pt-6 md:pb-14 lg:px-8">
          {children}
        </main>
      </div>

      {/* Mobil alt sekme çubuğu */}
      <nav className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md md:hidden" aria-label={`${props.portalLabel} alt menüsü`}>
        <ul className="grid" style={{ gridTemplateColumns: `repeat(${tabItems.length + 1}, minmax(0, 1fr))` }}>
          {tabItems.map((i) => {
            const on = matches(i, location.pathname)
            return (
              <li key={i.to}>
                <Link
                  to={i.to}
                  aria-current={on ? 'page' : undefined}
                  aria-label={i.badge ? `${i.label}, ${i.badge} yeni` : undefined}
                  className={cn('relative flex h-[62px] flex-col items-center justify-center gap-1 px-0.5 text-[12px] font-medium text-ink-2', on && 'text-primary')}
                >
                  {on && <span className="absolute inset-x-4 top-0 h-[3px] rounded-b-full bg-primary" aria-hidden />}
                  <span className={cn('relative grid h-7 w-12 place-items-center rounded-full', on && 'bg-primary-soft')}>
                    <i.icon className="size-[21px]" strokeWidth={on ? 2.2 : 1.9} />
                    {!!i.badge && <BadgeCount n={i.badge} small className="absolute -right-1.5 -top-1.5" />}
                  </span>
                  <span className="max-w-full truncate">{i.label}</span>
                </Link>
              </li>
            )
          })}
          <li>
            <button
              type="button"
              onClick={() => setMenuOpen(true)}
              aria-haspopup="dialog"
              aria-expanded={menuOpen}
              aria-label={menuBadge ? `Menü, ${menuBadge} yeni` : 'Menü'}
              className={cn('relative flex h-[62px] w-full flex-col items-center justify-center gap-1 text-[12px] font-medium text-ink-2', menuActive && 'text-primary')}
            >
              {menuActive && <span className="absolute inset-x-4 top-0 h-[3px] rounded-b-full bg-primary" aria-hidden />}
              <span className={cn('relative grid h-7 w-12 place-items-center rounded-full', menuActive && 'bg-primary-soft')}>
                <MenuIcon className="size-[21px]" />
                {menuBadge > 0 && <span className="absolute right-2 top-0 size-2.5 rounded-full bg-danger ring-2 ring-surface" aria-hidden />}
              </span>
              Menü
            </button>
          </li>
        </ul>
      </nav>

      {/* Mobil menü */}
      <Sheet
        open={menuOpen}
        onClose={closeMenu}
        title="Menü"
        footer={!imp ? (
          <button type="button" onClick={() => void props.onSignOut()} className="flex h-12 w-full items-center justify-center gap-2 rounded-[var(--radius-md)] border border-line text-[15px] font-medium text-danger hover:bg-danger-soft">
            <LogOut className="size-5" /> Çıkış yap
          </button>
        ) : undefined}
      >
        <Link to={props.user.profileTo} className="mb-4 flex items-center gap-3 rounded-[var(--radius-lg)] bg-surface-2 p-3 hover:bg-surface-3">
          <Avatar name={props.user.name} src={props.user.avatar} size={44} />
          <span className="min-w-0 flex-1">
            <span className="block truncate text-[15px] font-semibold">{props.user.name}</span>
            <span className="block truncate text-[13px] text-ink-3">{props.user.subtitle}</span>
          </span>
          <ChevronRight className="size-5 shrink-0 text-ink-3" />
        </Link>
        {switcher && switcher.students.length > 1 && current && (
          <button type="button" onClick={() => { setMenuOpen(false); setPickerOpen(true) }} className="mb-4 flex min-h-12 w-full items-center gap-3 rounded-[var(--radius-lg)] p-2.5 text-left ring-1 ring-line hover:bg-surface-2">
            <Avatar name={current.name} src={current.photo} size={36} />
            <span className="min-w-0 flex-1">
              <span className="block text-[12.5px] text-ink-3">Görüntülenen öğrenci</span>
              <span className="block truncate text-[14.5px] font-medium">{current.name}</span>
            </span>
            <span className="shrink-0 text-[13px] font-medium text-primary">Değiştir</span>
          </button>
        )}
        <div className="flex flex-col gap-4">
          {groups.map((g) => (
            <section key={g.label} aria-label={g.label}>
              <h3 className="mb-1.5 px-1 text-[12.5px] font-semibold text-ink-3">{g.label}</h3>
              <ul className="grid grid-cols-1 gap-1.5 min-[360px]:grid-cols-2">
                {g.items.map((i) => (
                  <li key={i.to}>
                    <Link
                      to={i.to}
                      aria-current={matches(i, location.pathname) ? 'page' : undefined}
                      className={cn(
                        'flex min-h-12 items-center gap-2.5 rounded-[var(--radius-md)] bg-surface-2 px-3 py-2 text-[14.5px] font-medium leading-tight text-ink hover:bg-surface-3',
                        matches(i, location.pathname) && 'bg-primary-soft text-primary-ink ring-1 ring-primary/25',
                      )}
                    >
                      <i.icon className="size-5 shrink-0" strokeWidth={1.9} />
                      <span className="min-w-0 flex-1 break-words">{i.label}</span>
                      <BadgeCount n={i.badge} />
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          ))}
          {props.accountExtra?.length ? (
            <ul className="flex flex-col gap-1.5">
              {props.accountExtra.map((x) => (
                <li key={x.to}>
                  <Link to={x.to} className="flex min-h-12 items-center gap-2.5 rounded-[var(--radius-md)] px-3 text-[14.5px] font-medium ring-1 ring-line hover:bg-surface-2">
                    <x.icon className="size-5" /> {x.label}
                  </Link>
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      </Sheet>

      {/* Mobil öğrenci seçici */}
      {switcher && (
        <Sheet open={pickerOpen} onClose={closePicker} title="Öğrenci seçin">
          <StudentOptions switcher={switcher} onDone={closePicker} large />
        </Sheet>
      )}
    </div>
  )
}

/* ---------------- parçalar ---------------- */

function ImpersonationBand({ suffix }: { suffix?: string }) {
  const imp = useAuth((s) => s.me?.impersonation ?? null)
  const qc = useQueryClient()
  const [leaving, setLeaving] = useState(false)
  if (!imp) return null

  const leave = async () => {
    setLeaving(true)
    try {
      const res = await api.post<Me & { return_to?: string }>('/auth/impersonation/leave')
      qc.clear()
      // Tam yenileme: portal verisi bellekte kalmasın
      window.location.assign(res.return_to ?? '/')
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Yönetime dönülemedi.')
      if (e instanceof ApiError && e.status === 401) window.location.assign('/giris')
      setLeaving(false)
    }
  }

  return (
    <div className="bg-warning text-white dark:text-bg" role="status">
      <div className="mx-auto flex w-full max-w-[1200px] items-center justify-between gap-3 px-4 py-1.5 sm:px-6 lg:px-8">
        <span className="flex min-w-0 items-center gap-2 text-[13.5px] font-medium leading-snug">
          <Eye className="size-4 shrink-0" aria-hidden />
          <span className="min-w-0">
            Önizleme: <b>{imp.target_name ?? imp.student_name}</b>{suffix ? ` ${suffix}` : ''}
            <span className="hidden sm:inline"> olarak görüntülüyorsunuz</span>
            <span className="hidden font-normal opacity-90 lg:inline"> · değişiklik yapılamaz</span>
          </span>
        </span>
        <button
          type="button"
          onClick={leave}
          disabled={leaving}
          className="inline-flex h-9 shrink-0 items-center rounded-[var(--radius-sm)] bg-white px-3 text-[13.5px] font-semibold text-ink shadow-sm transition hover:bg-white/90 disabled:opacity-60"
        >
          {leaving ? 'Dönülüyor…' : 'Yönetime dön'}
        </button>
      </div>
    </div>
  )
}

function TodayChip({ note }: { note?: ReactNode }) {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const t = window.setInterval(() => setNow(new Date()), 60_000)
    return () => window.clearInterval(t)
  }, [])
  const day = new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'long' }).format(now)
  const weekday = new Intl.DateTimeFormat('tr-TR', { weekday: 'long' }).format(now)
  return (
    <div className="hidden shrink-0 items-center gap-2.5 rounded-[var(--radius-md)] bg-surface-2 px-3 py-1.5 ring-1 ring-line md:flex" aria-label={`Bugün ${day} ${weekday}`}>
      <CalendarDays className="size-[18px] shrink-0 text-primary" aria-hidden />
      <span className="leading-tight">
        <span className="block text-[13.5px] font-semibold tabular">{day}</span>
        <span className="block text-[12.5px] text-ink-3">
          {weekday}
          {note ? <> · {note}</> : null}
        </span>
      </span>
    </div>
  )
}

function TabletDrawer({ onClose, children }: { onClose: () => void; children: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null)
  useOverlay(true, onClose, ref)
  return (
    <div className="fixed inset-0 z-40 hidden md:block lg:hidden">
      <div className="absolute inset-0 bg-black/35 animate-fade-in" onClick={onClose} aria-hidden />
      <div ref={ref} role="dialog" aria-modal="true" aria-label="Menü" tabIndex={-1} className="absolute inset-y-0 left-0 flex w-[288px] max-w-[85vw] flex-col border-r border-line bg-surface shadow-[var(--shadow-pop)] outline-none animate-fade-in">
        {children}
      </div>
    </div>
  )
}

type SidebarProps = Props & { rail: boolean; toggleLabel: string; onToggle: () => void; current: PortalSwitcherStudent | null; closeIcon?: boolean }

function SidebarBody({ rail, toggleLabel, onToggle, current, closeIcon, ...p }: SidebarProps) {
  const me = useAuth((s) => s.me)
  const imp = me?.impersonation ?? null
  const location = useLocation()
  const instName = me?.institution.short_name || me?.institution.name || 'Erbaa Bilgi Eğitim'
  const [tip, setTip] = useState<{ label: string; top: number } | null>(null)
  const showTip = (label: string) => (e: React.SyntheticEvent<HTMLElement>) => {
    if (!rail) return
    const r = e.currentTarget.getBoundingClientRect()
    setTip({ label, top: r.top + r.height / 2 })
  }
  const hideTip = () => setTip(null)
  const tipProps = (label: string) => ({ onMouseEnter: showTip(label), onMouseLeave: hideTip, onFocus: showTip(label), onBlur: hideTip })
  useEffect(() => setTip(null), [location.pathname, rail])

  return (
    <>
      {/* Kurum */}
      <div className={cn('flex h-16 shrink-0 items-center gap-2.5 border-b border-line', rail ? 'justify-center px-2' : 'px-4')}>
        <Link to={p.homeTo} className="flex min-w-0 flex-1 items-center gap-2.5" aria-label={`${instName} · ${p.portalLabel}`} {...tipProps(instName)}>
          <span className={cn('flex min-w-0 items-center gap-2.5', rail && 'mx-auto')}>
            <InstitutionLogo size={34} />
            {!rail && (
              <span className="min-w-0 leading-tight">
                <span className="block truncate text-[14.5px] font-semibold tracking-[-0.01em]">{instName}</span>
                <span className="block truncate text-[12.5px] text-ink-3">{p.portalLabel}</span>
              </span>
            )}
          </span>
        </Link>
        {closeIcon && (
          <button type="button" onClick={onToggle} aria-label="Menüyü kapat" className="grid size-10 shrink-0 place-items-center rounded-full text-ink-2 hover:bg-surface-2">
            <X className="size-5" />
          </button>
        )}
      </div>

      {/* Kullanıcı + öğrenci seçici */}
      <div className={cn('shrink-0 border-b border-line', rail ? 'flex flex-col items-center gap-2 px-2 py-3' : 'px-3 py-3')}>
        <NavLink
          to={p.user.profileTo}
          aria-label={rail ? `${p.user.name} · Profilim` : undefined}
          {...tipProps(`${p.user.name} · ${p.user.subtitle}`)}
          className={({ isActive }) =>
            cn(
              'flex items-center gap-3 rounded-[var(--radius-md)] transition-colors hover:bg-surface-2',
              rail ? 'size-11 justify-center' : 'p-2',
              isActive && 'bg-surface-2',
            )
          }
        >
          <Avatar name={p.user.name} src={p.user.avatar} size={rail ? 36 : 40} />
          {!rail && (
            <span className="min-w-0 flex-1 leading-tight">
              <span className="block truncate text-[14px] font-semibold">{p.user.name}</span>
              <span className="mt-0.5 block truncate text-[12.5px] text-ink-3">{p.user.subtitle}</span>
            </span>
          )}
        </NavLink>
        {p.switcher && p.switcher.students.length > 0 && (
          <div className={cn(!rail && 'mt-2')}>
            <DesktopSwitcher switcher={p.switcher} current={current} rail={rail} />
          </div>
        )}
      </div>

      {/* Menü */}
      <nav className={cn('min-h-0 flex-1 overflow-y-auto overscroll-contain scroll-thin py-3', rail ? 'px-2' : 'px-3')} aria-label="Sayfalar" onScroll={hideTip}>
        {p.groups.map((g, gi) => (
          <div key={g.label} className={cn(gi > 0 && (rail ? 'mt-2 border-t border-line pt-2' : 'mt-4'))}>
            {!rail && <h2 className="px-2.5 pb-1 text-[12.5px] font-semibold text-ink-3">{g.label}</h2>}
            <ul className={cn('flex flex-col', rail ? 'items-center gap-1' : 'gap-0.5')} aria-label={rail ? g.label : undefined}>
              {g.items.map((i) => {
                const on = matches(i, location.pathname)
                return (
                  <li key={i.to} className={cn(rail && 'w-full')}>
                    <Link
                      to={i.to}
                      aria-current={on ? 'page' : undefined}
                      aria-label={rail ? (i.badge ? `${i.label}, ${i.badge} yeni` : i.label) : undefined}
                      {...tipProps(i.label)}
                      className={cn(
                        'group relative flex items-center rounded-[var(--radius-sm)] transition-colors',
                        rail ? 'mx-auto h-11 w-11 justify-center' : 'h-10 gap-3 pl-3 pr-2 text-[14px] font-medium',
                        on ? 'bg-primary-soft text-primary-ink' : 'text-ink-2 hover:bg-surface-2 hover:text-ink',
                      )}
                    >
                      {on && !rail && <span className="absolute -left-3 top-2 bottom-2 w-[3px] rounded-r-full bg-primary" aria-hidden />}
                      <i.icon className={cn('size-[19px] shrink-0', on ? 'text-primary' : 'text-ink-3 group-hover:text-ink-2')} strokeWidth={on ? 2.1 : 1.8} />
                      {!rail && <span className="min-w-0 flex-1 truncate">{i.label}</span>}
                      {rail ? (
                        !!i.badge && <BadgeCount n={i.badge} small className="absolute -right-1 -top-1 ring-2 ring-surface" />
                      ) : (
                        <BadgeCount n={i.badge} />
                      )}
                    </Link>
                  </li>
                )
              })}
              {gi === p.groups.length - 1 && (
                <>
                  {p.accountExtra?.map((x) => (
                    <li key={x.to} className={cn(rail && 'w-full')}>
                      <Link
                        to={x.to}
                        aria-label={rail ? x.label : undefined}
                        {...tipProps(x.label)}
                        className={cn('flex items-center rounded-[var(--radius-sm)] text-ink-2 transition-colors hover:bg-surface-2 hover:text-ink', rail ? 'mx-auto h-11 w-11 justify-center' : 'h-10 gap-3 pl-3 pr-2 text-[14px] font-medium')}
                      >
                        <x.icon className="size-[19px] shrink-0 text-ink-3" strokeWidth={1.8} />
                        {!rail && <span className="truncate">{x.label}</span>}
                      </Link>
                    </li>
                  ))}
                  {!imp && (
                    <li className={cn(rail && 'w-full')}>
                      <button
                        type="button"
                        onClick={() => void p.onSignOut()}
                        aria-label={rail ? 'Çıkış yap' : undefined}
                        {...tipProps('Çıkış yap')}
                        className={cn('flex w-full items-center rounded-[var(--radius-sm)] text-ink-2 transition-colors hover:bg-danger-soft hover:text-danger', rail ? 'mx-auto h-11 w-11 justify-center' : 'h-10 gap-3 pl-3 pr-2 text-[14px] font-medium')}
                      >
                        <LogOut className="size-[19px] shrink-0" strokeWidth={1.8} />
                        {!rail && 'Çıkış yap'}
                      </button>
                    </li>
                  )}
                </>
              )}
            </ul>
          </div>
        ))}
      </nav>

      {!closeIcon && (
        <div className={cn('shrink-0 border-t border-line py-2', rail ? 'px-2' : 'px-3')}>
          <button
            type="button"
            onClick={onToggle}
            aria-label={toggleLabel}
            {...tipProps(toggleLabel)}
            className={cn('flex h-10 items-center gap-2 rounded-[var(--radius-sm)] text-[13.5px] font-medium text-ink-2 hover:bg-surface-2 hover:text-ink', rail ? 'mx-auto w-11 justify-center' : 'w-full px-3')}
          >
            {rail ? <ChevronsRight className="size-[18px]" /> : <ChevronsLeft className="size-[18px]" />}
            {!rail && <span>Daralt</span>}
          </button>
        </div>
      )}

      {tip && rail && (
        <div role="tooltip" className="pointer-events-none fixed left-[84px] z-50 -translate-y-1/2 whitespace-nowrap rounded-[6px] bg-ink px-2.5 py-1.5 text-[13px] font-medium text-bg shadow-[var(--shadow-pop)]" style={{ top: tip.top }}>
          {tip.label}
        </div>
      )}
    </>
  )
}

/** Masaüstü/tablet: avatarlı açılır öğrenci listesi. */
function DesktopSwitcher({ switcher, current, rail }: { switcher: PortalSwitcher; current: PortalSwitcherStudent | null; rail: boolean }) {
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const listId = useId()
  const many = switcher.students.length > 1

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false)
        box.current?.querySelector<HTMLButtonElement>('button')?.focus()
      }
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    window.setTimeout(() => box.current?.querySelector<HTMLButtonElement>('[aria-selected="true"], [role="option"]')?.focus(), 10)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  return (
    <div ref={box} className="relative">
      <button
        type="button"
        onClick={() => many && setOpen((v) => !v)}
        aria-haspopup={many ? 'listbox' : undefined}
        aria-expanded={many ? open : undefined}
        aria-controls={many && open ? listId : undefined}
        aria-label={`Görüntülenen öğrenci: ${current?.name ?? '—'}${many ? '. Değiştirmek için açın' : ''}`}
        title={rail ? current?.name : undefined}
        className={cn(
          'flex items-center rounded-[var(--radius-md)] text-left transition-colors',
          rail ? 'size-11 justify-center ring-1 ring-line hover:bg-surface-2' : 'w-full gap-2.5 bg-surface-2 p-2 ring-1 ring-line',
          many && !rail && 'hover:bg-surface-3',
          !many && 'cursor-default',
        )}
      >
        <Avatar name={current?.name} src={current?.photo} size={rail ? 32 : 34} />
        {!rail && (
          <>
            <span className="min-w-0 flex-1 leading-tight">
              <span className="block text-[12.5px] text-ink-3">{many ? `Öğrenci (${switcher.students.length})` : 'Öğrenci'}</span>
              <span className="block truncate text-[14px] font-medium">{current?.name ?? '—'}</span>
            </span>
            {many && <ChevronDown className={cn('size-4 shrink-0 text-ink-3 transition-transform', open && 'rotate-180')} />}
          </>
        )}
      </button>
      {open && (
        <div
          className={cn(
            'absolute z-50 w-[272px] rounded-[var(--radius-lg)] border border-line bg-surface p-1.5 shadow-[var(--shadow-pop)] animate-fade-in',
            rail ? 'left-full top-0 ml-3' : 'left-0 right-0 top-full mt-1.5 w-auto',
          )}
        >
          <p className="px-2.5 pb-1 pt-1.5 text-[12.5px] font-semibold text-ink-3">Öğrenci seçin</p>
          <StudentOptions switcher={switcher} onDone={() => setOpen(false)} id={listId} />
        </div>
      )}
    </div>
  )
}

function StudentOptions({ switcher, onDone, large, id }: { switcher: PortalSwitcher; onDone: () => void; large?: boolean; id?: string }) {
  const onKeyDown = (e: React.KeyboardEvent<HTMLUListElement>) => {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Home' && e.key !== 'End') return
    e.preventDefault()
    const opts = Array.from(e.currentTarget.querySelectorAll<HTMLButtonElement>('[role="option"]'))
    const i = opts.indexOf(document.activeElement as HTMLButtonElement)
    const next = e.key === 'Home' ? 0 : e.key === 'End' ? opts.length - 1 : (i + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length
    opts[next]?.focus()
  }
  return (
    <ul id={id} role="listbox" aria-label="Öğrenciler" className="flex flex-col gap-1" onKeyDown={onKeyDown}>
      {switcher.students.map((s) => {
        const on = s.id === switcher.current
        return (
          <li key={s.id} role="presentation">
            <button
              type="button"
              role="option"
              aria-selected={on}
              onClick={() => {
                switcher.onPick(s.id)
                onDone()
              }}
              className={cn(
                'flex w-full items-center gap-3 rounded-[var(--radius-md)] text-left transition-colors',
                large ? 'min-h-14 p-2.5' : 'min-h-11 p-2',
                on ? 'bg-primary-soft text-primary-ink' : 'hover:bg-surface-2',
              )}
            >
              <Avatar name={s.name} src={s.photo} size={large ? 40 : 32} />
              <span className="min-w-0 flex-1 leading-tight">
                <span className={cn('block truncate font-medium', large ? 'text-[15px]' : 'text-[14px]')}>{s.name}</span>
                {s.subtitle && <span className="mt-0.5 block truncate text-[12.5px] text-ink-3">{s.subtitle}</span>}
              </span>
              {on && <Check className="size-5 shrink-0 text-primary" aria-hidden />}
            </button>
          </li>
        )
      })}
    </ul>
  )
}
