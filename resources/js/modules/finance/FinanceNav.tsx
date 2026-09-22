import { useMemo } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'

/**
 * Finans bölüm gezinmesi (ikinci düzey sekmeler).
 *
 * Kenar menüsü yalnız 3 üst girdiye indirildi (Finans / Kayıt ve sözleşmeler / Muhasebe ve raporlar).
 * Aynı gruptaki sayfalar arasında geçiş bu sekme şeridiyle yapılır. Rotalar korunur; her sekme
 * kendi yetkisiyle görünür, yetkisiz sekme gizlenir. Sayfaların kendi başlığı/`?sekme=` iç sekmeleri
 * olduğu gibi kalır — bu şerit yalnız üstte, bölümler arası geçişi sağlar.
 */
type Tab = { to: string; label: string; permission?: string | string[]; end?: boolean }

const GROUPS: { key: string; tabs: Tab[] }[] = [
  {
    key: 'finans',
    tabs: [
      { to: '/finans', label: 'Genel bakış', end: true, permission: 'finance.view' },
      { to: '/finans/tahsilat', label: 'Tahsilat al', permission: 'payments.create' },
      { to: '/finans/tahsilatlar', label: 'Tahsilatlar', permission: 'finance.view' },
      { to: '/finans/alacaklar', label: 'Taksit ve alacaklar', permission: 'finance.view' },
      { to: '/finans/takip', label: 'Gecikme takibi', permission: 'finance.view' },
      { to: '/finans/iadeler', label: 'İadeler', permission: 'finance.view' },
    ],
  },
  {
    key: 'kayitlar',
    tabs: [
      { to: '/finans/kayitlar', label: 'Kayıtlar', permission: 'finance.view' },
      { to: '/finans/faturalar', label: 'Faturalar', permission: 'finance.view' },
      { to: '/finans/senetler', label: 'Senetler', permission: ['installments.manage', 'enrollments.create', 'finance.invoice'] },
      { to: '/finans/paketler', label: 'Eğitim paketleri', permission: 'finance.view' },
      { to: '/finans/paket-talepleri', label: 'Paket talepleri', permission: 'package_requests.manage' },
    ],
  },
  {
    key: 'defter',
    tabs: [
      { to: '/finans/gelir-gider', label: 'Gelir ve gider', permission: 'finance.view' },
      { to: '/finans/hesaplar', label: 'Kasa ve banka', permission: 'finance.view' },
      { to: '/finans/mutabakat', label: 'Mutabakat', permission: 'finance.view' },
      { to: '/finans/muhasebe', label: 'Muhasebe', permission: 'finance.accounting' },
      { to: '/finans/raporlar', label: 'Finans raporları', permission: 'reports.finance' },
      { to: '/finans/envanter', label: 'Kitap ve materyal', permission: ['finance.view', 'inventory.manage'] },
    ],
  },
]

/** Yol ile sekme eşleşme puanı (en uzun ön ek kazanır; "/finans" yalnız tam eşleşir). */
function scoreFor(pathname: string, to: string, end?: boolean): number {
  if (end || to === '/finans') return pathname === to ? to.length + 1 : 0
  if (pathname === to) return to.length + 1
  return pathname.startsWith(to + '/') ? to.length : 0
}

export function FinanceNav() {
  const { pathname } = useLocation()
  const can = useCan()

  const active = useMemo(() => {
    let best: { groupKey: string; to: string; score: number } | null = null
    for (const g of GROUPS)
      for (const t of g.tabs) {
        const s = scoreFor(pathname, t.to, t.end)
        if (s > 0 && (!best || s > best.score)) best = { groupKey: g.key, to: t.to, score: s }
      }
    return best
  }, [pathname])

  if (!active) return null
  const group = GROUPS.find((g) => g.key === active.groupKey)
  if (!group) return null
  const tabs = group.tabs.filter((t) => can(t.permission))
  if (tabs.length <= 1) return null

  return (
    <div className="mb-5 flex items-center gap-0.5 overflow-x-auto scroll-thin border-b border-line">
      {tabs.map((t) => {
        const isActive = t.to === active.to
        return (
          <Link
            key={t.to}
            to={t.to}
            aria-current={isActive ? 'page' : undefined}
            className={cn(
              'relative flex h-10 shrink-0 items-center px-3 text-[13px] font-medium whitespace-nowrap transition-colors',
              isActive ? 'text-ink' : 'text-ink-3 hover:text-ink-2',
            )}
          >
            {t.label}
            {isActive && <span className="absolute inset-x-2 -bottom-px h-[2px] rounded-full bg-ink" />}
          </Link>
        )
      })}
    </div>
  )
}
