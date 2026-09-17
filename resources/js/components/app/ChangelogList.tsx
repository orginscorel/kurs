import { Badge } from '@/components/ui/feedback'
import { date } from '@/lib/format'
import { CHANGE_LABEL, CHANGE_TONE, type ChangelogEntry } from './version'

export function ChangelogList({ entries, compact }: { entries: ChangelogEntry[]; compact?: boolean }) {
  return (
    <ol className="flex flex-col gap-5">
      {entries.map((e) => (
        <li key={e.version}>
          <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
            <span className="rounded-[3px] border border-primary/25 bg-primary-soft px-1.5 py-0.5 text-[12px] font-semibold tabular text-primary-ink">v{e.version}</span>
            <span className={compact ? 'text-[14px] font-semibold' : 'text-[15px] font-semibold'}>{e.title}</span>
            <span className="text-[12.5px] text-ink-3">{date(e.date, 'long')}</span>
          </div>
          <ul className="mt-2 flex flex-col gap-1.5">
            {e.items.map((it, i) => (
              <li key={i} className="flex items-start gap-2 text-[14px] leading-snug text-ink-2">
                <Badge tone={CHANGE_TONE[it.type]} className="mt-px shrink-0">{CHANGE_LABEL[it.type]}</Badge>
                <span>{it.text}</span>
              </li>
            ))}
          </ul>
        </li>
      ))}
    </ol>
  )
}
