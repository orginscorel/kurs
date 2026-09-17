import { useQuery } from '@tanstack/react-query'
import { api, type Paginated } from '@/lib/api'
import { date } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Badge } from '@/components/ui/feedback'
import { Select } from '@/components/ui/form'
import { rateTone, statusLabel, statusTone, type ExamOptions, type ExamRow, type ExamStatus } from './types'

export function ExamStatusBadge({ status }: { status: ExamStatus }) {
  return <Badge tone={statusTone[status]} dot>{statusLabel[status]}</Badge>
}

export function useExamOptions(enabled = true) {
  return useQuery({ queryKey: ['exams', 'options'], queryFn: () => api.get<ExamOptions>('/exams/options'), staleTime: 5 * 60_000, enabled })
}

/** Sınav seçici: yayımlanmış / anahtarı hazır sınavlar arasından seçim. */
export function ExamPicker({ value, onChange, status, className, placeholder = 'Sınav seçin' }: { value: string; onChange: (id: string) => void; status?: string; className?: string; placeholder?: string }) {
  const { data } = useQuery({ queryKey: ['exams', 'picker', status], queryFn: () => api.get<Paginated<ExamRow>>('/exams', { per_page: 200, sort: '-exam_date', status }), staleTime: 60_000 })
  return (
    <Select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      className={className}
      options={(data?.data ?? []).map((e) => ({ value: e.id, label: `${e.name} · ${date(e.exam_date)}${e.status !== 'results_published' ? ` (${statusLabel[e.status] ?? e.status})` : ''}` }))}
    />
  )
}

/** Yüzde çubuğu + etiket (konu / soru başarı oranı) */
export function RateBar({ rate, className, showLabel = true }: { rate: number | null | undefined; className?: string; showLabel?: boolean }) {
  const tone = rateTone(rate)
  const fill = { danger: 'bg-danger', warning: 'bg-warning', success: 'bg-success', neutral: 'bg-ink-3', primary: 'bg-primary', info: 'bg-info', accent: 'bg-accent' }[tone]
  return (
    <div className={cn('flex items-center gap-2 min-w-[120px]', className)}>
      <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-3">
        <div className={cn('h-full rounded-full transition-[width]', fill)} style={{ width: `${Math.max(0, Math.min(100, rate ?? 0))}%` }} />
      </div>
      {showLabel && <span className="w-10 text-right text-[12px] tabular text-ink-2">{rate === null || rate === undefined ? '—' : `%${rate}`}</span>}
    </div>
  )
}

/** D/Y/B üçlüsü */
export function Dyb({ correct, wrong, blank }: { correct: number; wrong: number; blank: number }) {
  return (
    <span className="tabular text-[12.5px] whitespace-nowrap" title={`${correct} doğru · ${wrong} yanlış · ${blank} boş`}>
      <span className="text-success">{correct}</span>
      <span className="text-ink-3"> / </span>
      <span className="text-danger">{wrong}</span>
      <span className="text-ink-3"> / </span>
      <span className="text-ink-3">{blank}</span>
    </span>
  )
}

/** Cevap kutucuğu (doğru / yanlış / boş / iptal) */
export function AnswerCell({ number, given, state, title }: { number: number; given: string; state: 'c' | 'w' | 'b' | 'x'; title?: string }) {
  const cls = { c: 'bg-success-soft text-success ring-success/20', w: 'bg-danger-soft text-danger ring-danger/20', b: 'bg-surface-2 text-ink-3 ring-line', x: 'bg-primary-soft text-primary-ink ring-primary/20' }[state]
  return (
    <span title={title} className={cn('inline-flex h-9 w-8 flex-col items-center justify-center rounded-[6px] ring-1 ring-inset text-[11px] leading-none', cls)}>
      <span className="text-[9.5px] opacity-70">{number}</span>
      <span className="mt-0.5 font-semibold">{given.trim() || '·'}</span>
    </span>
  )
}
