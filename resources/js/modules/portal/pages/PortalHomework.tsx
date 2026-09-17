import { useState } from 'react'
import { Link } from 'react-router-dom'
import { BookOpenCheck, ChevronRight, Paperclip } from 'lucide-react'
import { dateTime, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Segmented } from '@/components/ui/form'
import { homeworkTone, usePortal, useVoice } from '../api'
import { MiniStat, PortalTitle } from '../ui'

type Row = {
  id: number; title: string; description: string | null; assigned_at: string; due_at: string; status: string; status_label: string
  submitted_at: string | null; score: number | null; teacher_note: string | null; subject: string; teacher: string | null; is_open: boolean
  has_submission: boolean; is_graded: boolean
}
type Data = { data: Row[]; summary: { total: number; open: number; done: number; missed: number } }

export default function PortalHomework() {
  const v = useVoice()
  const { data, isLoading } = usePortal<Data>('homework', '/portal/homework')
  const [filter, setFilter] = useState<'open' | 'all'>('open')
  const rows = (data?.data ?? []).filter((r) => filter === 'all' || r.is_open)

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title={v('Ödevlerim', 'Ödevler')} description={v('Öğretmenlerinin verdiği ödevler ve değerlendirmeler', 'Öğretmenlerin verdiği ödevler ve değerlendirmeler')} />
      <div className="grid grid-cols-3 gap-3">
        <MiniStat label="Bekleyen" value={data?.summary.open ?? '—'} />
        <MiniStat label="Teslim edilen" value={data?.summary.done ?? '—'} tone="success" />
        <MiniStat label="Teslim edilmeyen" value={data?.summary.missed ?? '—'} tone={data && data.summary.missed > 0 ? 'danger' : undefined} />
      </div>
      <Segmented className="self-start" value={filter} onChange={setFilter} options={[{ value: 'open', label: 'Bekleyenler' }, { value: 'all', label: 'Tümü' }]} />
      {isLoading ? (
        <Skeleton className="h-64" />
      ) : rows.length === 0 ? (
        <EmptyState compact icon={<BookOpenCheck />} title={filter === 'open' ? v('Bekleyen ödevin yok', 'Bekleyen ödevi yok') : 'Henüz ödev yok'} description={filter === 'open' ? v('Tüm ödevlerini görmek için “Tümü”ne geç.', 'Tüm ödevleri görmek için “Tümü”ne geçin.') : undefined} />
      ) : (
        <ul className="flex flex-col gap-2">
          {rows.map((r) => {
            const soon = r.is_open && new Date(r.due_at).getTime() - Date.now() < 36 * 3600_000
            return (
              <li key={r.id}>
                <Link to={`/portal/odevler/${r.id}`} className={cn('block rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line transition hover:ring-line-strong', soon && 'ring-warning')}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-[14px] font-medium">{r.title}</p>
                    <p className="text-[12.5px] text-ink-3">{r.subject}{r.teacher ? ` · ${r.teacher}` : ''}</p>
                  </div>
                  <Badge tone={homeworkTone[r.status] ?? 'neutral'} dot>{r.status_label}</Badge>
                </div>
                {r.description && <p className="mt-2 line-clamp-2 whitespace-pre-line text-[14px] text-ink-2">{r.description}</p>}
                <p className={cn('mt-2 text-[12.5px]', soon ? 'font-medium text-warning' : 'text-ink-3')}>
                  Teslim: {dateTime(r.due_at)}{r.is_open ? ` (${relative(r.due_at)})` : ''}
                  {r.submitted_at ? ` · teslim edildi ${dateTime(r.submitted_at)}` : ''}
                </p>
                {(r.score !== null || r.teacher_note) && (
                  <div className="mt-2 rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 text-[12.5px]">
                    {r.score !== null && <p><span className="text-ink-3">Puan:</span> <b className="tabular">{r.score}</b></p>}
                    {r.teacher_note && <p className="text-ink-2"><span className="text-ink-3">Öğretmen notu:</span> {r.teacher_note}</p>}
                  </div>
                )}
                <p className="mt-2 flex items-center justify-between gap-2 text-[12.5px] font-medium text-primary">
                  <span className="inline-flex items-center gap-1">
                    {r.has_submission && <Paperclip className="size-3.5" />}
                    {r.is_graded ? v('Değerlendirmeyi gör', 'Değerlendirmeyi görün') : r.is_open ? v(r.has_submission ? 'Teslimini düzenle' : 'Ödevi aç ve teslim et', 'Ayrıntıyı görün') : 'Ayrıntı'}
                  </span>
                  <ChevronRight className="size-4" />
                </p>
                </Link>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
