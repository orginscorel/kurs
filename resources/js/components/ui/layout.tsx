import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ArrowDownRight, ArrowUpRight, ChevronRight } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Skeleton, type Tone } from './feedback'

export function PageHeader({
  title,
  description,
  actions,
  breadcrumbs,
  className,
}: {
  title: ReactNode
  description?: ReactNode
  actions?: ReactNode
  breadcrumbs?: { label: string; to?: string }[]
  className?: string
}) {
  return (
    <div className={cn('mb-6', className)}>
      {breadcrumbs && breadcrumbs.length > 0 && (
        <nav className="mb-2 flex items-center gap-1 text-[12.5px] text-ink-3">
          {breadcrumbs.map((b, i) => (
            <span key={i} className="flex items-center gap-1">
              {i > 0 && <ChevronRight className="size-3.5" />}
              {b.to ? (
                <Link to={b.to} className="hover:text-ink transition-colors">
                  {b.label}
                </Link>
              ) : (
                <span>{b.label}</span>
              )}
            </span>
          ))}
        </nav>
      )}
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-[22px] font-semibold tracking-[-0.015em] text-ink leading-tight">{title}</h1>
          {description && <p className="mt-1 text-[13.5px] text-ink-2">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  )
}

/** Hafif bölüm: kart yerine ince çerçeveli yüzey. İçerik yoğunluğuna göre padding. */
export function Panel({
  title,
  description,
  actions,
  children,
  className,
  bodyClassName,
  flush,
}: {
  title?: ReactNode
  description?: ReactNode
  actions?: ReactNode
  children?: ReactNode
  className?: string
  bodyClassName?: string
  flush?: boolean
}) {
  return (
    <section className={cn('rounded-[var(--radius-lg)] bg-surface ring-1 ring-line', className)}>
      {(title || actions) && (
        <header className="flex items-center justify-between gap-3 px-4 pt-3.5 pb-2.5">
          <div className="min-w-0">
            {title && <h3 className="text-[13.5px] font-semibold text-ink">{title}</h3>}
            {description && <p className="text-[12px] text-ink-3 mt-0.5">{description}</p>}
          </div>
          {actions && <div className="flex items-center gap-1.5 shrink-0">{actions}</div>}
        </header>
      )}
      <div className={cn(flush ? '' : 'px-4 pb-4', !title && !actions && !flush && 'pt-4', bodyClassName)}>{children}</div>
    </section>
  )
}

export function Stat({
  label,
  value,
  sub,
  delta,
  deltaLabel,
  tone,
  icon,
  to,
  loading,
  className,
}: {
  label: string
  value: ReactNode
  sub?: ReactNode
  delta?: number | null
  deltaLabel?: string
  tone?: Tone
  icon?: ReactNode
  to?: string
  loading?: boolean
  className?: string
}) {
  // Büyük sayılar nötr kalır; renk yalnız sorun (hata/uyarı) anlamında
  const toneText: Partial<Record<Tone, string>> = { danger: 'text-danger', warning: 'text-warning' }
  const body = (
    <div className={cn('group relative flex flex-col gap-1 rounded-[var(--radius-lg)] bg-surface px-4 py-3.5 ring-1 ring-line transition-shadow', to && 'hover:ring-line-strong hover:shadow-[var(--shadow-soft)]', className)}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-[12.5px] font-medium text-ink-2 truncate">{label}</span>
        {icon && <span className={cn('text-ink-3 [&_svg]:size-4', tone && toneText[tone])}>{icon}</span>}
      </div>
      {loading ? (
        <Skeleton className="h-7 w-24 mt-0.5" />
      ) : (
        <span className={cn('text-[24px] font-semibold tracking-[-0.02em] leading-tight tabular', tone && toneText[tone])}>{value}</span>
      )}
      <div className="flex items-center gap-2 min-h-[18px]">
        {delta !== undefined && delta !== null && !loading && (
          <span className={cn('inline-flex items-center gap-0.5 text-[12px] font-medium tabular', delta >= 0 ? 'text-success' : 'text-danger')}>
            {delta >= 0 ? <ArrowUpRight className="size-3.5" /> : <ArrowDownRight className="size-3.5" />}
            {Math.abs(delta).toLocaleString('tr-TR', { maximumFractionDigits: 1 })}%
          </span>
        )}
        {(sub || deltaLabel) && <span className="text-[12px] text-ink-3 truncate">{sub ?? deltaLabel}</span>}
      </div>
    </div>
  )
  return to ? <Link to={to}>{body}</Link> : body
}

export function Tabs<T extends string>({
  value,
  onChange,
  tabs,
  className,
}: {
  value: T
  onChange: (v: T) => void
  tabs: { value: T; label: ReactNode; count?: number | null; hidden?: boolean }[]
  className?: string
}) {
  return (
    <div className={cn('flex items-center gap-0.5 overflow-x-auto scroll-thin border-b border-line', className)}>
      {tabs
        .filter((t) => !t.hidden)
        .map((t) => (
          <button
            key={t.value}
            type="button"
            onClick={() => onChange(t.value)}
            className={cn(
              'relative h-10 px-3 text-[13px] font-medium whitespace-nowrap transition-colors',
              value === t.value ? 'text-ink' : 'text-ink-3 hover:text-ink-2',
            )}
          >
            <span className="flex items-center gap-1.5">
              {t.label}
              {t.count !== undefined && t.count !== null && (
                <span className={cn('rounded-[5px] px-1.5 text-[12px] tabular', value === t.value ? 'bg-surface-3 text-ink' : 'bg-surface-2 text-ink-3')}>{t.count}</span>
              )}
            </span>
            {value === t.value && <span className="absolute inset-x-2 -bottom-px h-[2px] rounded-full bg-ink" />}
          </button>
        ))}
    </div>
  )
}

/** Tanım listesi: etiket–değer satırları (profil bilgileri) */
export function DescriptionList({ items, columns = 2 }: { items: { label: string; value: ReactNode; hidden?: boolean }[]; columns?: 1 | 2 | 3 }) {
  return (
    <dl className={cn('grid gap-x-6 gap-y-3.5', columns === 1 ? 'grid-cols-1' : columns === 2 ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3')}>
      {items
        .filter((i) => !i.hidden)
        .map((i) => (
          <div key={i.label} className="min-w-0">
            <dt className="text-[12px] text-ink-3">{i.label}</dt>
            <dd className="mt-0.5 text-[13.5px] text-ink break-words">{i.value ?? '—'}</dd>
          </div>
        ))}
    </dl>
  )
}
