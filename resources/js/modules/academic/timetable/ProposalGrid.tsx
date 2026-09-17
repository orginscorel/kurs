import { useMemo } from 'react'
import { Lock } from 'lucide-react'
import { cn } from '@/lib/cn'
import { WEEKDAY_SHORT } from '../types'
import type { ProposalItem } from './types'

const diffLine: Record<string, string> = {
  added: 'var(--primary)',
  changed: 'var(--warning)',
  same: 'var(--line-strong)',
}

/**
 * Önerilen haftalık program: satır = ders saati dilimi, sütun = gün.
 * Renk yalnız anlam taşır: ince sol çizgi yeni (birincil) / değişen (uyarı) / aynı (nötr).
 */
export function ProposalGrid({ items, locked, showDiff = true, mode = 'class' }: { items: ProposalItem[]; locked: ProposalItem[]; showDiff?: boolean; mode?: 'class' | 'teacher' }) {
  const { periods, days, cell } = useMemo(() => {
    const all = [...items.map((i) => ({ ...i, locked: false })), ...locked.map((i) => ({ ...i, locked: true }))]
    const periods = [...new Set(all.map((i) => `${i.start}–${i.end}`))].sort()
    const days = [...new Set(all.map((i) => i.weekday))].sort((a, b) => a - b)
    const cell = new Map<string, (ProposalItem & { locked: boolean })[]>()
    all.forEach((i) => {
      const key = `${i.start}–${i.end}|${i.weekday}`
      cell.set(key, [...(cell.get(key) ?? []), i])
    })
    return { periods, days, cell }
  }, [items, locked])

  if (periods.length === 0) return <p className="py-10 text-center text-[13px] text-ink-3">{mode === 'teacher' ? 'Bu öğretmene ders yerleşmedi.' : 'Bu sınıfa ders yerleşmedi.'}</p>

  return (
    <div className="overflow-x-auto scroll-thin rounded-[var(--radius-lg)] ring-1 ring-line bg-surface">
      <table className="w-full min-w-[640px] border-collapse text-left">
        <thead>
          <tr className="border-b border-line">
            <th className="w-[92px] px-3 py-2 text-[11.5px] font-medium uppercase tracking-[0.04em] text-ink-3">Saat</th>
            {days.map((d) => <th key={d} className="px-2 py-2 text-[11.5px] font-medium uppercase tracking-[0.04em] text-ink-3">{WEEKDAY_SHORT[d]}</th>)}
          </tr>
        </thead>
        <tbody>
          {periods.map((p) => (
            <tr key={p} className="border-b border-line last:border-0 align-top">
              <td className="px-3 py-2 text-[12px] tabular text-ink-2 whitespace-nowrap">{p}</td>
              {days.map((d) => (
                <td key={d} className="px-1.5 py-1.5">
                  {(cell.get(`${p}|${d}`) ?? []).map((i, k) => (
                    <div
                      key={k}
                      className={cn('mb-1 last:mb-0 rounded-[6px] border border-line border-l-2 bg-surface-2/60 px-2 py-1')}
                      style={{ borderLeftColor: i.locked ? 'var(--ink-3)' : showDiff ? diffLine[i.diff ?? 'same'] : 'var(--line-strong)', opacity: i.external ? 0.7 : undefined }}
                      title={i.before ? `Önce: ${i.before.subject.name} · ${i.before.teacher.name} · ${i.before.room.name}` : undefined}
                    >
                      <p className="flex items-center gap-1 truncate text-[12.5px] font-medium text-ink">
                        {i.locked && <Lock className="size-3 shrink-0 text-ink-3" />}
                        {mode === 'teacher' ? (i.class_name ?? '—') : i.subject.name}
                      </p>
                      <p className="truncate text-[11.5px] text-ink-3">{mode === 'teacher' ? `${i.subject.name ?? ''} · ${i.room.name ?? ''}${i.external ? ' · mevcut' : ''}` : `${i.teacher.name} · ${i.room.name}`}</p>
                      {showDiff && i.diff === 'changed' && i.before && <p className="truncate text-[11px] text-ink-3 line-through">{i.before.subject.name} · {i.before.teacher.name}</p>}
                    </div>
                  ))}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function DiffLegend() {
  return (
    <div className="flex flex-wrap items-center gap-4 text-[12px] text-ink-3">
      {[['added', 'Yeni'], ['changed', 'Değişti'], ['same', 'Aynı']].map(([k, l]) => (
        <span key={k} className="inline-flex items-center gap-1.5"><span className="h-3 w-[2px] rounded-full" style={{ background: diffLine[k!] }} />{l}</span>
      ))}
      <span className="inline-flex items-center gap-1.5"><Lock className="size-3" />Kilitli (korunur)</span>
    </div>
  )
}
