import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'
import { usePortalPageTitle } from './PortalLayout'

/**
 * Portal sayfa başlığı. Başlık kabuğun yapışkan üst çubuğunda gösterilir; buradaki h1 yalnız
 * ekran okuyuculara kalır (çift başlık olmasın). Açıklama ve eylemler içerikte görünür.
 */
export function PortalTitle({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
  usePortalPageTitle(title)
  return (
    <header className={cn('flex flex-wrap items-center justify-between gap-x-3 gap-y-2', description || actions ? 'mb-4 md:mb-5' : 'mb-0')}>
      <h1 className="sr-only">{title}</h1>
      <div className="min-w-0 flex-1 basis-[16rem]">
        {description && <p className="text-[14px] leading-snug text-ink-2">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </header>
  )
}

/** Küçük sayı kutusu. */
export function MiniStat({ label, value, sub, tone, className }: { label: string; value: ReactNode; sub?: ReactNode; tone?: 'danger' | 'warning' | 'success'; className?: string }) {
  const toneClass = tone === 'danger' ? 'text-danger' : tone === 'warning' ? 'text-warning' : tone === 'success' ? 'text-success' : ''
  return (
    <div className={cn('min-w-0 rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line', className)}>
      <p className="text-[12.5px] leading-snug text-ink-2">{label}</p>
      <p className={cn('mt-0.5 truncate text-[20px] font-semibold tracking-tight tabular', toneClass)}>{value}</p>
      {sub && <p className="mt-0.5 text-[12.5px] leading-snug text-ink-3">{sub}</p>}
    </div>
  )
}

export function ListCard({ children, className }: { children: ReactNode; className?: string }) {
  return <ul className={cn('overflow-hidden rounded-[var(--radius-lg)] bg-surface ring-1 ring-line [&>li+li]:border-t [&>li+li]:border-line', className)}>{children}</ul>
}
