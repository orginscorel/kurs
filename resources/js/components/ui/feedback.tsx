import type { ReactNode } from 'react'
import { CircleCheck, Info, OctagonAlert, TriangleAlert } from 'lucide-react'
import { cn } from '@/lib/cn'
import { initials } from '@/lib/format'

export type Tone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'accent'

/*
 * Kurumsal renk dili: rozetler düz köşeli, ince çerçeveli, çok hafif zeminli; renk yalnız anlam taşır.
 */
const toneClass: Record<Tone, string> = {
  neutral: 'bg-surface-2 text-ink-2 border-line-strong',
  primary: 'bg-primary-soft text-primary-ink border-primary/25',
  success: 'bg-success-soft text-success border-success/25',
  warning: 'bg-warning-soft text-warning border-warning/30',
  danger: 'bg-danger-soft text-danger border-danger/25',
  info: 'bg-info-soft text-info border-info/25',
  accent: 'bg-accent-soft text-accent border-accent/25',
}

const dotClass: Record<Tone, string> = {
  neutral: 'bg-ink-3',
  primary: 'bg-primary',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  info: 'bg-info',
  accent: 'bg-accent',
}

const barClass: Record<Tone, string> = {
  neutral: 'bg-ink-3',
  primary: 'bg-primary',
  success: 'bg-success/85',
  warning: 'bg-warning/85',
  danger: 'bg-danger/85',
  info: 'bg-info',
  accent: 'bg-accent',
}

export function Badge({ tone = 'neutral', dot, children, className }: { tone?: Tone; dot?: boolean; children: ReactNode; className?: string }) {
  return (
    <span className={cn('inline-flex items-center gap-1.5 h-[22px] px-1.5 rounded-[3px] border text-[12px] font-medium leading-none tracking-[0.01em] whitespace-nowrap', toneClass[tone], className)}>
      {dot && <span className={cn('h-2.5 w-[3px] rounded-[1px]', dotClass[tone])} />}
      {children}
    </span>
  )
}

export function StatusDot({ tone = 'neutral', pulse }: { tone?: Tone; pulse?: boolean }) {
  return <span className={cn('inline-block size-2 rounded-full', dotClass[tone], pulse && 'animate-pulse-dot')} />
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('skeleton h-4', className)} />
}

export function Spinner({ className }: { className?: string }) {
  return <span className={cn('inline-block size-4 rounded-full border-2 border-line-strong border-t-primary animate-spin', className)} />
}

export function EmptyState({
  icon,
  title,
  description,
  action,
  className,
  compact,
}: {
  icon?: ReactNode
  title: string
  description?: ReactNode
  action?: ReactNode
  className?: string
  compact?: boolean
}) {
  return (
    <div className={cn('flex flex-col items-center justify-center text-center', compact ? 'py-8 px-4' : 'py-16 px-6', className)}>
      {icon && (
        <div className="mb-4 grid size-11 place-items-center rounded-[var(--radius-md)] bg-surface-2 text-ink-3 ring-1 ring-line [&_svg]:size-5">
          {icon}
        </div>
      )}
      <p className="text-[14.5px] font-semibold text-ink">{title}</p>
      {description && <p className="mt-1 max-w-sm text-[13px] text-ink-2">{description}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}

/** Tek nötr ton: rengârenk baş harf daireleri ekranı kalabalıklaştırıyordu. */
export function Avatar({ name, src, size = 32, className }: { name?: string | null; src?: string | null; size?: number; className?: string }) {
  if (src) {
    return <img src={src} alt={name ?? ''} width={size} height={size} loading="lazy" className={cn('rounded-full object-cover shrink-0 bg-surface-2', className)} style={{ width: size, height: size }} />
  }
  return (
    <span
      className={cn('grid place-items-center rounded-full shrink-0 font-semibold bg-surface-3 text-ink-2 ring-1 ring-inset ring-line', className)}
      style={{ width: size, height: size, fontSize: Math.max(10, size * 0.36) }}
      aria-hidden
    >
      {initials(name)}
    </span>
  )
}

const alertStyle: Record<Tone, { box: string; icon: string; title: string; Icon: typeof Info }> = {
  neutral: { box: 'bg-surface-2 border-line-strong', icon: 'text-ink-2', title: 'text-ink', Icon: Info },
  primary: { box: 'bg-primary-soft/70 border-primary', icon: 'text-primary', title: 'text-primary-ink', Icon: Info },
  info: { box: 'bg-info-soft border-info', icon: 'text-info', title: 'text-info', Icon: Info },
  accent: { box: 'bg-accent-soft border-accent', icon: 'text-accent', title: 'text-accent', Icon: Info },
  success: { box: 'bg-success-soft border-success', icon: 'text-success', title: 'text-success', Icon: CircleCheck },
  warning: { box: 'bg-warning-soft border-warning', icon: 'text-warning', title: 'text-warning', Icon: TriangleAlert },
  danger: { box: 'bg-danger-soft border-danger', icon: 'text-danger', title: 'text-danger', Icon: OctagonAlert },
}

/**
 * Uyarı kutusu: tona göre sol renk şeridi + dolu ikon rozeti; bilgi / başarı / uyarı / hata ilk bakışta ayırt edilir.
 * Metin ink tonunda kalır (okunabilirlik), başlık tonun renginde.
 */
export function Alert({ tone = 'info', title, children, action, className, icon }: { tone?: Tone; title?: ReactNode; children?: ReactNode; action?: ReactNode; className?: string; icon?: ReactNode }) {
  const st = alertStyle[tone]
  return (
    <div role={tone === 'danger' || tone === 'warning' ? 'alert' : 'status'}
      className={cn('flex flex-wrap items-start gap-3 rounded-[var(--radius-sm)] border border-l-[3px] border-y-transparent border-r-transparent px-3.5 py-3', st.box, className)}>
      <span className={cn('mt-px grid size-5 shrink-0 place-items-center [&>svg]:size-[18px]', st.icon)}>{icon ?? <st.Icon strokeWidth={2.2} />}</span>
      <div className="min-w-[200px] flex-1">
        {title && <p className={cn('text-[13.5px] font-semibold leading-snug', st.title)}>{title}</p>}
        {children && <div className={cn('text-[13px] leading-relaxed text-ink-2', title && 'mt-0.5')}>{children}</div>}
      </div>
      {action && <div className="flex shrink-0 items-center gap-2 self-center">{action}</div>}
    </div>
  )
}

export function ProgressBar({ value, tone = 'primary', className }: { value: number; tone?: Tone; className?: string }) {
  const pct = Math.max(0, Math.min(100, value))
  return (
    <div className={cn('h-1.5 w-full overflow-hidden rounded-full bg-surface-3', className)}>
      <div className={cn('h-full rounded-full transition-[width] duration-500', barClass[tone])} style={{ width: `${pct}%` }} />
    </div>
  )
}

export function Kbd({ children }: { children: ReactNode }) {
  return <kbd className="inline-flex h-5 min-w-5 items-center justify-center rounded border border-line bg-surface px-1 font-mono text-[10.5px] text-ink-3">{children}</kbd>
}
