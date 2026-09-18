import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { APP_VERSION } from '@/components/app/version'
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom'
import { Bell, ChevronDown, ChevronsLeft, ChevronsRight, KeyRound, LogOut, Menu as MenuIcon, Monitor, Moon, Search, Sparkles, Sun, X } from 'lucide-react'
import { cn } from '@/lib/cn'
import { apply as applyTheme, readMode, resolve as resolveTheme, saveMode, watchSystem, type ThemeMode } from '@/lib/theme'
import { useAuth, useCan } from '@/app/auth'
import { activeGroupFor, navigation, type NavGroup, type NavItem } from '@/app/navigation'
import { BrandMark } from '@/app/AuthGate'
import { Avatar, Kbd } from '@/components/ui/feedback'
import { Menu, Tooltip } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { VendorInfo } from '@/components/app/VendorInfo'
import { CommandPalette } from './CommandPalette'
import { NotificationCenter } from './NotificationCenter'
import { SyncStatus } from './SyncStatus'

/** Sistem → Açık → Koyu sırasıyla döner; "Sistem" bilgisayarın ayarını izler ve anında yanıt verir. */
const THEME_ORDER: ThemeMode[] = ['system', 'light', 'dark']
const THEME_LABEL: Record<ThemeMode, string> = { system: 'Sistem teması', light: 'Açık tema', dark: 'Koyu tema' }

function useTheme() {
  const [mode, setMode] = useState<ThemeMode>(readMode)
  const [theme, setTheme] = useState<'light' | 'dark'>(() => resolveTheme(readMode()))

  useEffect(() => watchSystem(setTheme), [])
  useEffect(() => {
    setTheme(applyTheme(mode))
  }, [mode])

  const toggle = () => {
    const next = THEME_ORDER[(THEME_ORDER.indexOf(mode) + 1) % THEME_ORDER.length]!
    saveMode(next)
    setMode(next)
  }
  return { theme, mode, toggle }
}

/** Kullanıcının yetkili olduğu alanlar ve sayfalar */
function useVisibleNavigation(): NavGroup[] {
  const can = useCan()
  const userType = useAuth((s) => s.me?.user.user_type ?? '')
  return useMemo(
    () =>
      navigation
        .map((g) => ({ ...g, items: g.items.filter((i) => can(i.permission) && (!i.userTypes || i.userTypes.includes(userType))) }))
        .filter((g) => g.items.length > 0),
    [can, userType],
  )
}

export function AppShell({ children }: { children: ReactNode }) {
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem('ebe-sidebar') === 'collapsed'
    } catch {
      return false
    }
  })
  const [mobileOpen, setMobileOpen] = useState(false)
  const [paletteOpen, setPaletteOpen] = useState(false)
  const location = useLocation()
  const groups = useVisibleNavigation()
  const active = activeGroupFor(location.pathname, groups)

  useEffect(() => setMobileOpen(false), [location.pathname])

  useEffect(() => {
    try {
      localStorage.setItem('ebe-sidebar', collapsed ? 'collapsed' : 'expanded')
    } catch {
      /* yok say */
    }
  }, [collapsed])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setPaletteOpen((v) => !v)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  return (
    <div className="min-h-dvh bg-bg">
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-30 hidden lg:flex flex-col border-r border-line bg-surface transition-[width] duration-200',
          collapsed ? 'w-[64px]' : 'w-[232px]',
        )}
      >
        <Sidebar groups={groups} activeKey={active?.group.key} collapsed={collapsed} onToggle={() => setCollapsed((v) => !v)} />
      </aside>

      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-black/30 animate-fade-in" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-[272px] max-w-[85vw] bg-surface border-r border-line flex flex-col animate-slide-left">
            <Sidebar groups={groups} activeKey={active?.group.key} collapsed={false} onClose={() => setMobileOpen(false)} />
          </aside>
        </div>
      )}

      <div className={cn('transition-[padding] duration-200', collapsed ? 'lg:pl-[64px]' : 'lg:pl-[232px]')}>
        <Topbar onMenu={() => setMobileOpen(true)} onSearch={() => setPaletteOpen(true)} />
        <main className="mx-auto w-full max-w-[1480px] px-4 sm:px-6 lg:px-8 pt-5 pb-24">
          {/* Sayfa geçişi: yol değişince içerik yumuşak girer (hareket azaltılmışsa CSS'te durur) */}
          <div key={location.pathname} className="page-enter">
            {active?.group.key === 'settings' ? (
              <SettingsLayout group={active.group} activeTo={active.item.to}>{children}</SettingsLayout>
            ) : (
              children
            )}
          </div>
        </main>
      </div>

      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
    </div>
  )
}

/** Ayarlar: geniş ekranda başlıklara ayrılmış sol alt menü; dar ekranda tek seçim kutusu (yatay kaydırma yok) */
function SettingsLayout({ group, activeTo, children }: { group: NavGroup; activeTo: string; children: ReactNode }) {
  const navigate = useNavigate()
  const categories = useMemo(() => {
    const map = new Map<string, NavItem[]>()
    for (const item of group.items) {
      const key = item.category ?? 'Diğer'
      map.set(key, [...(map.get(key) ?? []), item])
    }
    return [...map.entries()]
  }, [group.items])

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[200px_minmax(0,1fr)] lg:gap-10">
      <aside className="lg:sticky lg:top-[76px] lg:self-start">
        <p className="mb-3 hidden text-[15px] font-semibold tracking-[-0.01em] text-ink lg:block">Ayarlar</p>
        {/* Dar ekran: yan yana kaydırmalı şerit yerine tek seçim kutusu (kaydırma gerekmesin) */}
        <label className="block lg:hidden">
          <span className="sr-only">Ayarlar sayfası</span>
          <select
            value={activeTo}
            onChange={(e) => navigate(e.target.value)}
            className="h-11 w-full rounded-[var(--radius-sm)] border border-line bg-surface px-3 text-[14px] text-ink"
          >
            {categories.map(([category, items]) => (
              <optgroup key={category} label={category}>
                {items.map((item) => (
                  <option key={item.to} value={item.to}>{item.label}</option>
                ))}
              </optgroup>
            ))}
          </select>
        </label>
        <nav aria-label="Ayarlar" className="hidden lg:block">
          <div className="flex min-w-0 flex-col gap-5">
            {categories.map(([category, items]) => (
              <div key={category} className="flex flex-col gap-0.5">
                <p className="px-2.5 pb-1 text-[11px] font-medium uppercase tracking-[0.06em] text-ink-3">{category}</p>
                {items.map((item) => {
                  const isActive = item.to === activeTo
                  return (
                    <Link
                      key={item.to}
                      to={item.to}
                      className={cn(
                        'relative inline-flex h-8 items-center rounded-[var(--radius-sm)] px-2.5 text-[13px] transition-colors',
                        isActive ? 'bg-surface-3 font-medium text-ink' : 'text-ink-2 hover:bg-surface-2 hover:text-ink',
                      )}
                    >
                      {item.label}
                    </Link>
                  )
                })}
              </div>
            ))}
          </div>
        </nav>
      </aside>
      <div className="min-w-0">{children}</div>
    </div>
  )
}

function Sidebar({
  groups, activeKey, collapsed, onToggle, onClose,
}: { groups: NavGroup[]; activeKey?: string; collapsed: boolean; onToggle?: () => void; onClose?: () => void }) {
  const me = useAuth((s) => s.me)
  const location = useLocation()
  const settingsGroup = groups.find((g) => g.key === 'settings')
  const workGroups = groups.filter((g) => g.key !== 'settings')
  const activeItemTo = activeGroupFor(location.pathname, groups)?.item.to

  // Aktif alan açık gelir; kullanıcı diğerlerini ok ile açıp kapatabilir
  const [open, setOpen] = useState<Set<string>>(() => new Set(activeKey ? [activeKey] : []))
  useEffect(() => {
    if (activeKey) setOpen((prev) => (prev.has(activeKey) ? prev : new Set(prev).add(activeKey)))
  }, [activeKey])
  const toggle = (key: string) =>
    setOpen((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })

  const renderGroup = (g: NavGroup, expandable = true) => {
    const Icon = g.icon
    const isActive = g.key === activeKey
    const hasChildren = expandable && !collapsed && g.items.length > 1
    const isOpen = hasChildren && open.has(g.key)

    if (collapsed) {
      return (
        <li key={g.key}>
          <Tooltip content={g.label} side="right">
            <NavLink
              to={g.items[0]!.to}
              end={g.items[0]!.end}
              className={cn(
                'mx-auto flex h-9 w-10 items-center justify-center rounded-[var(--radius-sm)] transition-colors',
                isActive ? 'bg-surface-3 text-ink' : 'text-ink-3 hover:bg-surface-2 hover:text-ink',
              )}
            >
              <Icon className="size-[18px]" strokeWidth={1.8} />
            </NavLink>
          </Tooltip>
        </li>
      )
    }

    return (
      <li key={g.key}>
        <div
          className={cn(
            'group/row flex h-9 items-center rounded-[var(--radius-sm)] text-[13.5px] transition-colors',
            isActive && !isOpen ? 'bg-surface-3 text-ink font-medium' : isActive ? 'text-ink font-medium' : 'text-ink-2 hover:bg-surface-2 hover:text-ink',
          )}
        >
          <NavLink to={g.items[0]!.to} end={g.items[0]!.end} onClick={() => hasChildren && setOpen((p) => new Set(p).add(g.key))} className="flex h-full min-w-0 flex-1 items-center gap-3 pl-2.5">
            <Icon className={cn('size-[18px] shrink-0', isActive ? 'text-ink' : 'text-ink-3')} strokeWidth={1.8} />
            <span className="truncate">{g.label}</span>
          </NavLink>
          {hasChildren && (
            <button
              type="button"
              onClick={() => toggle(g.key)}
              aria-label={isOpen ? `${g.label} alt menüsünü kapat` : `${g.label} alt menüsünü aç`}
              aria-expanded={isOpen}
              className="grid h-full w-8 shrink-0 place-items-center text-ink-3 opacity-60 transition-opacity hover:text-ink group-hover/row:opacity-100"
            >
              <ChevronDown className={cn('size-3.5 transition-transform', !isOpen && '-rotate-90')} />
            </button>
          )}
        </div>
        {isOpen && (
          <ul className="relative mb-1 ml-[18px] mt-0.5 flex flex-col gap-px border-l border-line pl-2.5">
            {g.items.map((item) => {
              const itemActive = item.to === activeItemTo
              return (
                <li key={item.to}>
                  <Link
                    to={item.to}
                    className={cn(
                      'relative flex h-8 items-center rounded-[var(--radius-sm)] px-2.5 text-[13px] transition-colors',
                      itemActive ? 'bg-surface-3 font-medium text-ink' : 'text-ink-3 hover:bg-surface-2 hover:text-ink',
                    )}
                  >
                    {itemActive && <span className="absolute -left-[11px] top-1.5 bottom-1.5 w-[2px] rounded-full bg-ink" />}
                    <span className="truncate">{item.label}</span>
                  </Link>
                </li>
              )
            })}
          </ul>
        )}
      </li>
    )
  }

  const SECTIONS: { label: string; keys: string[] }[] = [
    { label: 'Günlük', keys: ['main', 'crm', 'people', 'attendance', 'academic', 'exams', 'guidance', 'discipline'] },
    { label: 'Yönetim', keys: ['finance', 'communication', 'reports'] },
  ]
  const sectioned = SECTIONS.map((s) => ({ ...s, groups: workGroups.filter((g) => s.keys.includes(g.key)) }))
  const rest = workGroups.filter((g) => !SECTIONS.some((s) => s.keys.includes(g.key)))
  if (rest.length) sectioned[sectioned.length - 1]!.groups.push(...rest)

  return (
    <>
      <div className={cn('app-topbar flex h-14 items-center gap-2.5 shrink-0 border-b border-line', collapsed ? 'justify-center px-2' : 'px-4')}>
        <BrandMark size={26} />
        {!collapsed && (
          <div className="min-w-0 leading-tight">
            <p className="text-[13.5px] font-semibold tracking-[-0.01em] truncate">{me?.institution.name ?? 'Erbaa Bilgi Eğitim'}</p>
            <p className="text-[11px] text-ink-3 truncate">{me?.branch?.name}</p>
          </div>
        )}
        {onClose && (
          <Button variant="ghost" size="icon-sm" className="ml-auto" onClick={onClose} aria-label="Menüyü kapat">
            <X className="size-4" />
          </Button>
        )}
      </div>

      <nav className="flex-1 overflow-y-auto scroll-thin px-2 py-3">
        {sectioned.filter((s) => s.groups.length > 0).map((s, i) => (
          <div key={s.label} className={cn(i > 0 && 'mt-4')}>
            {collapsed ? (
              i > 0 && <div className="mx-3 mb-3 border-t border-line" />
            ) : (
              <p className="px-2.5 pb-1.5 text-[11px] font-medium uppercase tracking-[0.06em] text-ink-3">{s.label}</p>
            )}
            <ul className="flex flex-col gap-0.5">{s.groups.map((g) => renderGroup(g))}</ul>
          </div>
        ))}
      </nav>

      <div className="shrink-0 border-t border-line px-2 py-2">
        {settingsGroup && <ul className="mb-1">{renderGroup(settingsGroup, false)}</ul>}
        <Link to="/yenilikler" title={`Sürüm ${APP_VERSION} · yenilikler`}
          className={cn('mb-1 flex items-center gap-2 rounded-[var(--radius-sm)] px-2.5 py-1.5 text-[12px] text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink', collapsed && 'justify-center px-0')}>
          <Sparkles className="size-3.5 shrink-0" />
          {!collapsed && <span>Sürüm <b className="font-semibold tabular text-ink-2">v{APP_VERSION}</b> · Yenilikler</span>}
        </Link>
        {onToggle && (
          <Button variant="ghost" size={collapsed ? 'icon' : 'sm'} className={cn(collapsed ? 'mx-auto flex' : 'w-full justify-start text-ink-3')} onClick={onToggle}>
            {collapsed ? <ChevronsRight className="size-4" /> : <ChevronsLeft className="size-4" />}
            {!collapsed && 'Daralt'}
          </Button>
        )}
        {!collapsed && <VendorInfo compact className="mt-1" />}
      </div>
    </>
  )
}

function Topbar({ onMenu, onSearch }: { onMenu: () => void; onSearch: () => void }) {
  const me = useAuth((s) => s.me)
  const logout = useAuth((s) => s.logout)
  const navigate = useNavigate()
  const { mode, toggle } = useTheme()
  const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)

  return (
    <header className="app-topbar sticky top-0 z-20 flex h-14 items-center gap-2 border-b border-line backdrop-blur-md px-3 sm:px-6 lg:px-8">
      <Button variant="ghost" size="icon" className="lg:hidden" onClick={onMenu} aria-label="Menü">
        <MenuIcon className="size-5" />
      </Button>

      <button
        type="button"
        onClick={onSearch}
        className="flex h-9 w-9 sm:w-auto sm:flex-1 justify-center sm:justify-start max-w-[440px] items-center gap-2 rounded-[var(--radius-sm)] border border-line bg-surface px-3 text-[13.5px] text-ink-3 hover:border-line-strong transition-colors"
        aria-label="Ara"
      >
        <Search className="size-4 shrink-0" />
        <span className="hidden sm:block flex-1 text-left truncate">Öğrenci, veli, öğretmen ara ya da işlem yap…</span>
        <span className="hidden sm:flex items-center gap-0.5">
          <Kbd>{isMac ? '⌘' : 'Ctrl'}</Kbd>
          <Kbd>K</Kbd>
        </span>
      </button>

      <div className="ml-auto flex items-center gap-1">
        <SyncStatus />
        <Tooltip content={`${THEME_LABEL[mode]} · değiştirmek için tıklayın`} side="bottom">
          <Button variant="ghost" size="icon" onClick={toggle} aria-label={THEME_LABEL[mode]}>
            {mode === 'system' ? <Monitor className="size-[18px]" /> : mode === 'dark' ? <Moon className="size-[18px]" /> : <Sun className="size-[18px]" />}
          </Button>
        </Tooltip>
        <NotificationCenter trigger={<Bell className="size-[18px]" />} />
        <Menu
          width={230}
          trigger={
            <button type="button" className="ml-1 flex items-center gap-2 rounded-full p-0.5 pr-2 hover:bg-surface-2 transition-colors">
              <Avatar name={me?.user.name} src={me?.user.avatar_url} size={30} />
              <span className="hidden md:block text-[13px] font-medium max-w-[140px] truncate">{me?.user.name}</span>
            </button>
          }
          items={[
            { label: <span className="flex flex-col leading-tight"><span className="font-medium">{me?.user.name}</span><span className="text-[11.5px] text-ink-3">@{me?.user.username}</span></span>, disabled: true },
            'divider',
            { label: 'Hesabım ve oturumlar', icon: <KeyRound />, onClick: () => navigate('/hesabim') },
            'divider',
            {
              label: 'Çıkış yap',
              icon: <LogOut />,
              danger: true,
              onClick: async () => {
                await logout()
                navigate('/giris', { replace: true })
              },
            },
          ]}
        />
      </div>
    </header>
  )
}
