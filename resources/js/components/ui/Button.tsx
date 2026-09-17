import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { Link, type LinkProps } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { cn } from '@/lib/cn'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'soft' | 'outline' | 'success' | 'info' | 'warning' | 'danger-soft'
type Size = 'xs' | 'sm' | 'md' | 'lg' | 'icon' | 'icon-sm'

const variants: Record<Variant, string> = {
  primary: 'bg-primary text-white hover:bg-primary-hover shadow-[inset_0_1px_0_rgb(255_255_255/0.12)]',
  secondary: 'bg-surface text-ink border border-line hover:bg-primary-soft/50 hover:border-primary/30 shadow-[var(--shadow-soft)] [&>svg]:text-primary',
  outline: 'bg-transparent text-ink border border-line hover:bg-surface-2',
  ghost: 'text-ink-2 hover:text-ink hover:bg-surface-2',
  soft: 'bg-primary-soft text-primary-ink hover:brightness-[0.97]',
  danger: 'bg-danger text-white hover:brightness-95',
  success: 'bg-success-soft text-success border border-success/25 hover:bg-success/15',
  info: 'bg-info-soft text-info border border-info/25 hover:bg-info/15',
  warning: 'bg-warning-soft text-warning border border-warning/30 hover:bg-warning/15',
  'danger-soft': 'bg-danger-soft text-danger border border-danger/25 hover:bg-danger/15',
}

const sizes: Record<Size, string> = {
  xs: 'h-7 px-2 text-xs gap-1 rounded-[var(--radius-xs)]',
  sm: 'h-8 px-3 text-[13px] gap-1.5 rounded-[var(--radius-sm)]',
  md: 'h-9 px-3.5 text-[13.5px] gap-2 rounded-[var(--radius-sm)]',
  lg: 'h-11 px-5 text-[15px] gap-2 rounded-[var(--radius-md)]',
  icon: 'h-9 w-9 rounded-[var(--radius-sm)]',
  'icon-sm': 'h-7 w-7 rounded-[var(--radius-xs)]',
}

const base =
  'inline-flex items-center justify-center font-medium whitespace-nowrap select-none transition-[background,border,color,box-shadow,transform] duration-150 active:scale-[0.985] disabled:opacity-50 disabled:pointer-events-none'

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant
  size?: Size
  loading?: boolean
  icon?: ReactNode
  iconRight?: ReactNode
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'secondary', size = 'md', loading, icon, iconRight, className, children, disabled, type = 'button', ...props },
  ref,
) {
  return (
    <button ref={ref} type={type} disabled={disabled || loading} className={cn(base, variants[variant], sizes[size], className)} {...props}>
      {loading ? <Loader2 className="size-4 animate-spin" /> : icon}
      {children}
      {iconRight}
    </button>
  )
})

export function ButtonLink({
  variant = 'secondary',
  size = 'md',
  icon,
  className,
  children,
  ...props
}: LinkProps & { variant?: Variant; size?: Size; icon?: ReactNode }) {
  return (
    <Link className={cn(base, variants[variant], sizes[size], className)} {...props}>
      {icon}
      {children}
    </Link>
  )
}
