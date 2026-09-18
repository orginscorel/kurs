import { useEffect, useState } from 'react'
import { cn } from '@/lib/cn'

/**
 * Sayı girişi (Türkçe ondalık virgül). Değer Enter / odak kaybında uygulanır; Esc geri alır.
 * `scale`: gösterim çarpanı (ör. metre → cm için 100).
 */
export function NumberField({
  label, value, onCommit, unit, step = 1, min, max, scale = 1, digits = 0, disabled, className, testId,
}: {
  label: string
  value: number
  onCommit: (v: number) => void
  unit?: string
  step?: number
  min?: number
  max?: number
  scale?: number
  digits?: number
  disabled?: boolean
  className?: string
  testId?: string
}) {
  const fmt = (v: number) => (v * scale).toFixed(digits).replace('.', ',')
  const [text, setText] = useState(fmt(value))
  const [focus, setFocus] = useState(false)
  useEffect(() => {
    if (!focus) setText(fmt(value))
  }, [value, focus]) // eslint-disable-line react-hooks/exhaustive-deps

  const commit = () => {
    const n = Number(text.trim().replace(',', '.'))
    if (!Number.isFinite(n)) {
      setText(fmt(value))
      return
    }
    let v = n / scale
    if (min !== undefined) v = Math.max(min, v)
    if (max !== undefined) v = Math.min(max, v)
    if (Math.abs(v - value) > 1e-9) onCommit(v)
    setText(fmt(v))
  }

  return (
    <label className={cn('flex flex-col gap-1', className)}>
      <span className="text-[11.5px] font-medium text-ink-3">{label}</span>
      <span className="relative flex items-center">
        <input
          inputMode="decimal"
          disabled={disabled}
          value={text}
          data-testid={testId}
          onFocus={() => setFocus(true)}
          onBlur={() => {
            setFocus(false)
            commit()
          }}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') (e.target as HTMLInputElement).blur()
            if (e.key === 'Escape') {
              setText(fmt(value))
              ;(e.target as HTMLInputElement).blur()
            }
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
              e.preventDefault()
              const n = Number(text.replace(',', '.')) || 0
              const next = n + (e.key === 'ArrowUp' ? step : -step) * (e.shiftKey ? 10 : 1)
              setText(next.toFixed(digits).replace('.', ','))
            }
          }}
          className="h-8 w-full rounded-[var(--radius-xs)] border border-line bg-surface pl-2 pr-8 text-[13px] tabular-nums text-ink hover:border-line-strong focus:border-primary focus:outline-none focus:ring-[3px] focus:ring-primary/15 disabled:bg-surface-2 disabled:text-ink-3"
        />
        {unit && <span className="pointer-events-none absolute right-2 text-[11.5px] text-ink-3">{unit}</span>}
      </span>
    </label>
  )
}
