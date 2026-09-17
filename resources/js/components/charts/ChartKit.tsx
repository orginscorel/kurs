import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

/** Kategorik seri renkleri — sabit sırada, asla döngüye alınmaz. */
export const series = ['var(--series-1)', 'var(--series-2)', 'var(--series-3)', 'var(--series-4)', 'var(--series-5)', 'var(--series-6)', 'var(--series-7)', 'var(--series-8)']

export const axisProps = {
  tick: { fill: 'var(--chart-axis)', fontSize: 11.5 },
  axisLine: false,
  tickLine: false,
} as const

export const gridProps = { stroke: 'var(--chart-grid)', vertical: false } as const

type TooltipRow = { name?: string | number; value?: number | string; color?: string; dataKey?: string | number; payload?: any }

/** Recharts için tema uyumlu ipucu kutusu */
export function ChartTooltip({
  active,
  payload,
  label,
  formatLabel,
  formatValue,
}: {
  active?: boolean
  payload?: TooltipRow[]
  label?: string | number
  formatLabel?: (label: any) => ReactNode
  formatValue?: (value: number, row: TooltipRow) => ReactNode
}) {
  if (!active || !payload?.length) return null
  return (
    <div className="min-w-[160px] rounded-[var(--radius-sm)] bg-surface px-3 py-2 ring-1 ring-line shadow-[var(--shadow-pop)] text-[12.5px]">
      {label !== undefined && <p className="mb-1.5 font-medium text-ink">{formatLabel ? formatLabel(label) : label}</p>}
      <div className="flex flex-col gap-1">
        {payload.map((row, i) => (
          <div key={i} className="flex items-center gap-2">
            <span className="size-2 rounded-full" style={{ background: row.color }} />
            <span className="text-ink-2">{row.name}</span>
            <span className="ml-auto pl-3 font-medium text-ink tabular">{formatValue ? formatValue(Number(row.value), row) : row.value}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

export function Legend({ items, className }: { items: { label: string; color: string }[]; className?: string }) {
  return (
    <div className={cn('flex flex-wrap items-center gap-x-3.5 gap-y-1 text-[12px] text-ink-2', className)}>
      {items.map((i) => (
        <span key={i.label} className="inline-flex items-center gap-1.5">
          <span className="size-2 rounded-[3px]" style={{ background: i.color }} />
          {i.label}
        </span>
      ))}
    </div>
  )
}

export const monthLabel = (ym: string) => {
  const [y, m] = ym.split('-').map(Number)
  return new Date(y!, m! - 1, 1).toLocaleDateString('tr-TR', { month: 'short' })
}
