import type { ReactNode } from 'react'
import { Pin, Users } from 'lucide-react'
import { cn } from '@/lib/cn'
import { num } from '@/lib/format'
import { Avatar, Badge } from '@/components/ui/feedback'
import { genderShort, type StudentCard } from './types'

/** Öğrenci kartı: sade, tek çizgi çerçeve; renk yok (risk nötr rozet). */
export function StudentTile({ s, actions, className, muted }: { s: StudentCard; actions?: ReactNode; className?: string; muted?: boolean }) {
  return (
    <div className={cn('flex items-center gap-2.5 rounded-[var(--radius-sm)] bg-surface px-2.5 py-2 ring-1 ring-line transition-shadow hover:ring-line-strong', muted && 'opacity-70', className)}>
      <Avatar name={s.full_name} src={s.photo_url} size={28} />
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-1.5">
          <span className="truncate text-[13px] font-medium text-ink">{s.full_name}</span>
          {s.pinned && <Pin className="size-3 shrink-0 text-ink-3" aria-label="Şubesine sabitlendi" />}
          {s.sibling && <Users className="size-3 shrink-0 text-ink-3" aria-label="Kurumda kardeşi var" />}
        </div>
        <div className="truncate text-[12px] text-ink-3 tabular">
          {s.gender ? `${genderShort(s.gender)} · ` : ''}{s.avg_net === null ? 'Deneme sonucu yok' : `Deneme ort.: ${num(s.avg_net, 1)} net`}
          {s.other_class ? ` · Önceki sınıf: ${s.other_class.name}` : ''}
        </div>
      </div>
      {s.risk === 'high' && <Badge dot>Yüksek risk</Badge>}
      {s.status !== 'active' && <Badge>{s.status_label}</Badge>}
      {actions}
    </div>
  )
}

/** Doluluk: nötr çubuk; yalnız dolu (uyarı) ve aşım (hata) renkli. */
export function FillMeter({ count, capacity }: { count: number; capacity: number }) {
  const pct = capacity > 0 ? Math.min(100, Math.round((count / capacity) * 100)) : 0
  const over = count > capacity
  const full = count === capacity
  return (
    <div className="flex items-center gap-2">
      <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-3">
        <div className={cn('h-full rounded-full', over ? 'bg-danger/85' : full ? 'bg-warning/85' : 'bg-ink-3')} style={{ width: `${pct}%` }} />
      </div>
      <span className={cn('text-[12px] font-medium tabular', over ? 'text-danger' : full ? 'text-warning' : 'text-ink-2')}>
        {count}/{capacity} öğrenci
      </span>
    </div>
  )
}

export function MetricLine({ female, male, avg, highRisk }: { female: number; male: number; avg: number | null; highRisk: number }) {
  return (
    <p className="text-[12px] text-ink-3 tabular">
      {female} kız · {male} erkek · {avg === null ? 'Deneme sonucu yok' : `Deneme ort.: ${num(avg, 1)} net`}
      {highRisk > 0 ? ` · ${highRisk} yüksek risk` : ''}
    </p>
  )
}
