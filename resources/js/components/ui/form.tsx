import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { Check, ChevronDown } from 'lucide-react'
import { cn } from '@/lib/cn'

const control =
  'w-full h-9 rounded-[var(--radius-sm)] border border-line bg-surface px-3 text-[13.5px] text-ink placeholder:text-ink-3 transition-[border,box-shadow] hover:border-line-strong focus:border-primary focus:outline-none focus:ring-[3px] focus:ring-primary/15 disabled:bg-surface-2 disabled:text-ink-3'

export function Field({
  label,
  hint,
  error,
  required,
  optional,
  children,
  className,
  htmlFor,
}: {
  label?: ReactNode
  hint?: ReactNode
  error?: string | null
  required?: boolean
  /** Etiketin yanına soluk "(isteğe bağlı)" yazar */
  optional?: boolean
  children: ReactNode
  className?: string
  htmlFor?: string
}) {
  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      {label && (
        <label htmlFor={htmlFor} className="text-[12.5px] font-medium text-ink-2">
          {label}
          {required && <span className="text-danger ml-0.5">*</span>}
          {optional && !required && <span className="font-normal text-ink-3"> (isteğe bağlı)</span>}
        </label>
      )}
      {children}
      {error ? <p className="text-xs text-danger">{error}</p> : hint ? <p className="text-xs text-ink-3">{hint}</p> : null}
    </div>
  )
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean; leading?: ReactNode; trailing?: ReactNode }

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input({ className, invalid, leading, trailing, ...props }, ref) {
  if (leading || trailing) {
    return (
      <div className={cn('relative flex items-center', className)}>
        {leading && <span className="pointer-events-none absolute left-2.5 text-ink-3 [&_svg]:size-4">{leading}</span>}
        <input
          ref={ref}
          className={cn(control, leading && 'pl-8', trailing && 'pr-9', invalid && 'border-danger focus:border-danger focus:ring-danger/15')}
          {...props}
        />
        {trailing && <span className="absolute right-2 text-ink-3">{trailing}</span>}
      </div>
    )
  }
  return <input ref={ref} className={cn(control, invalid && 'border-danger focus:border-danger focus:ring-danger/15', className)} {...props} />
})

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }>(function Textarea(
  { className, invalid, rows = 3, ...props },
  ref,
) {
  return <textarea ref={ref} rows={rows} className={cn(control, 'h-auto py-2 leading-relaxed resize-y', invalid && 'border-danger', className)} {...props} />
})

export type Option = { value: string | number; label: string; disabled?: boolean }

export const Select = forwardRef<
  HTMLSelectElement,
  SelectHTMLAttributes<HTMLSelectElement> & { options: Option[]; placeholder?: string; invalid?: boolean }
>(function Select({ className, options, placeholder, invalid, ...props }, ref) {
  return (
    <div className={cn('relative', className)}>
      <select ref={ref} className={cn(control, 'appearance-none pr-8 cursor-pointer', invalid && 'border-danger')} {...props}>
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value} disabled={o.disabled}>
            {o.label}
          </option>
        ))}
      </select>
      <ChevronDown className="pointer-events-none absolute right-2.5 top-1/2 size-4 -translate-y-1/2 text-ink-3" />
    </div>
  )
})

export function Checkbox({
  checked,
  indeterminate,
  onChange,
  label,
  className,
  disabled,
}: {
  checked: boolean
  indeterminate?: boolean
  onChange: (checked: boolean) => void
  label?: ReactNode
  className?: string
  disabled?: boolean
}) {
  const id = useId()
  const on = checked || indeterminate
  return (
    <label htmlFor={id} className={cn('inline-flex items-center gap-2 cursor-pointer select-none', disabled && 'opacity-50 cursor-not-allowed', className)}>
      <span
        className={cn(
          'relative grid place-items-center size-4 rounded-[5px] border transition-colors',
          on ? 'bg-primary border-primary text-white' : 'bg-surface border-line-strong',
        )}
      >
        <input id={id} type="checkbox" className="sr-only" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
        {indeterminate ? <span className="h-0.5 w-2 bg-white rounded" /> : checked ? <Check className="size-3" strokeWidth={3} /> : null}
      </span>
      {label && <span className="text-[13.5px] text-ink">{label}</span>}
    </label>
  )
}

export function Switch({ checked, onChange, label, disabled }: { checked: boolean; onChange: (v: boolean) => void; label?: ReactNode; disabled?: boolean }) {
  return (
    <label className={cn('inline-flex items-center gap-2.5 cursor-pointer select-none', disabled && 'opacity-50 cursor-not-allowed')}>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={cn('relative h-5 w-9 rounded-full transition-colors', checked ? 'bg-success shadow-[inset_0_0_0_1px_rgb(0_0_0/0.06),0_0_0_3px_var(--success-soft)]' : 'bg-surface-3 border border-line-strong')}
      >
        <span className={cn('absolute top-1/2 size-3.5 -translate-y-1/2 rounded-full bg-white shadow transition-[left]', checked ? 'left-[18px]' : 'left-[3px]')} />
      </button>
      {label && <span className="text-[13.5px]">{label}</span>}
    </label>
  )
}

/** Segment seçici: VAR / YOK / GEÇ gibi hızlı seçimler */
export function Segmented<T extends string>({
  value,
  onChange,
  options,
  size = 'md',
  className,
}: {
  value: T
  onChange: (v: T) => void
  options: { value: T; label: ReactNode; tone?: string }[]
  size?: 'sm' | 'md'
  className?: string
}) {
  return (
    <div className={cn('inline-flex max-w-full overflow-x-auto scroll-thin rounded-[var(--radius-sm)] bg-surface-2 p-0.5 border border-line max-sm:flex-wrap max-sm:gap-0.5 max-sm:overflow-x-visible', className)}>
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          onClick={() => onChange(o.value)}
          className={cn(
            'shrink-0 whitespace-nowrap rounded-[6px] font-medium transition-all',
            size === 'sm' ? 'h-6 px-2 text-xs' : 'h-7 px-3 text-[13px]',
            value === o.value ? cn('bg-surface text-ink shadow-[var(--shadow-soft)]', o.tone) : 'text-ink-2 hover:text-ink',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  )
}
