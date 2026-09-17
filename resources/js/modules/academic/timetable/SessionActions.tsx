import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarClock, ChevronDown, UserRoundCheck } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date as fmtDate, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/form'
import { Alert, EmptyState, Skeleton } from '@/components/ui/feedback'
import { addDays, WEEKDAYS, type AcademicOptions } from '../types'
import type { FreeSlot, SubstituteCandidate, SubstituteData } from './types'

const fail = (fallback: string) => (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : fallback)

/** Yedek öğretmen önerileri: puanlı liste, tek tıkla atama. */
export function SubstituteList({ sessionId, canManage, onDone, limit }: { sessionId: number; canManage: boolean; onDone: () => void; limit?: number }) {
  const [showOthers, setShowOthers] = useState(false)
  const q = useQuery({ queryKey: ['timetable', 'substitutes', sessionId], queryFn: () => api.get<SubstituteData>(`/schedule/sessions/${sessionId}/substitutes`) })
  const assign = useMutation({
    mutationFn: (teacherId: number) => api.post<{ message: string }>(`/schedule/sessions/${sessionId}/substitute`, { teacher_id: teacherId }),
    onSuccess: (r) => {
      toast.success(r.message)
      onDone()
    },
    onError: fail('Yedek öğretmen atanamadı.'),
  })

  if (q.isLoading) return <div className="flex flex-col gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-14" />)}</div>
  if (q.isError || !q.data) return <Alert tone="danger">Öneriler yüklenemedi.</Alert>

  const { candidates, others, excluded } = q.data
  const list = limit ? candidates.slice(0, limit) : candidates
  const excludedText = [
    excluded.busy && `${excluded.busy} öğretmen o saatte derste`,
    excluded.leave && `${excluded.leave} izinli`,
    excluded.unavailable && `${excluded.unavailable} uygunluk dışında`,
    excluded.max && `${excluded.max} haftalık üst sınırda`,
  ].filter(Boolean).join(' · ')

  return (
    <div className="flex flex-col gap-2">
      {list.length === 0 ? (
        <EmptyState compact icon={<UserRoundCheck />} title="Branşı uyan boş öğretmen yok" description={excludedText || 'Bu derse bağlı başka öğretmen tanımlı değil.'} />
      ) : (
        <ul className="flex flex-col divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
          {list.map((c) => <CandidateRow key={c.id} c={c} canManage={canManage} loading={assign.isPending && assign.variables === c.id} onAssign={() => assign.mutate(c.id)} />)}
        </ul>
      )}
      {list.length > 0 && excludedText && <p className="text-[12px] text-ink-3">Elenenler: {excludedText}</p>}
      {others.length > 0 && (
        <div>
          <button type="button" onClick={() => setShowOthers((v) => !v)} className="inline-flex items-center gap-1 text-[12.5px] text-ink-2 hover:text-ink">
            <ChevronDown className={cn('size-3.5 transition-transform', showOthers && 'rotate-180')} /> Branş dışı ama o saatte boş ({others.length})
          </button>
          {showOthers && (
            <ul className="mt-2 flex flex-col divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
              {others.map((c) => <CandidateRow key={c.id} c={c} canManage={canManage} loading={assign.isPending && assign.variables === c.id} onAssign={() => assign.mutate(c.id)} />)}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}

function CandidateRow({ c, canManage, loading, onAssign }: { c: SubstituteCandidate; canManage: boolean; loading: boolean; onAssign: () => void }) {
  return (
    <li className="flex items-center gap-3 px-3 py-2.5">
      <div className="grid size-9 shrink-0 place-items-center rounded-full bg-surface-2 text-[12.5px] font-semibold tabular text-ink-2 ring-1 ring-line" title="Uygunluk puanı">{c.score}</div>
      <div className="min-w-0 flex-1">
        <p className="truncate text-[13.5px] font-medium">{c.name}</p>
        <p className="truncate text-[12px] text-ink-3">{c.reasons.join(' · ')} · hafta {c.week_load}/{c.max_week}</p>
      </div>
      {canManage && <Button size="xs" variant="secondary" loading={loading} onClick={onAssign}>Ata</Button>}
    </li>
  )
}

/** Telafi / taşıma: boş dilim önerileri + elle seçim. */
export function MoveForm({ sessionId, duration, options, onDone }: { sessionId: number; duration: number; options?: AcademicOptions; onDone: () => void }) {
  const today = todayISO()
  const [from, setFrom] = useState(today)
  const [pick, setPick] = useState<FreeSlot | null>(null)
  const [manual, setManual] = useState(false)
  const [form, setForm] = useState({ date: addDays(today, 1), starts_at: '17:00', classroom_id: '' })
  const [reason, setReason] = useState('')

  const q = useQuery({
    queryKey: ['timetable', 'free-slots', sessionId, from],
    queryFn: () => api.get<{ data: FreeSlot[] }>(`/schedule/sessions/${sessionId}/free-slots`, { from, to: addDays(from, 14) }),
    enabled: !manual,
  })
  const grouped = useMemo(() => {
    const m = new Map<string, FreeSlot[]>()
    q.data?.data.forEach((s) => m.set(s.date, [...(m.get(s.date) ?? []), s]))
    return [...m.entries()]
  }, [q.data])

  const endOf = (start: string) => {
    const [h, m] = start.split(':').map(Number)
    const t = (h ?? 0) * 60 + (m ?? 0) + duration
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`
  }

  const move = useMutation({
    mutationFn: () => {
      const body = manual
        ? { date: form.date, starts_at: form.starts_at, ends_at: endOf(form.starts_at), classroom_id: form.classroom_id ? Number(form.classroom_id) : undefined, reason: reason || undefined }
        : { date: pick!.date, starts_at: pick!.starts_at, ends_at: pick!.ends_at, classroom_id: pick!.classroom_id, reason: reason || undefined }
      return api.post<{ message: string }>(`/schedule/sessions/${sessionId}/move`, body)
    },
    onSuccess: (r) => {
      toast.success(r.message)
      onDone()
    },
    onError: fail('Ders taşınamadı.'),
  })

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-end gap-2">
        {!manual && (
          <Field label="Şu tarihten itibaren" className="w-[170px]">
            <Input type="date" value={from} min={today} onChange={(e) => { setFrom(e.target.value || today); setPick(null) }} />
          </Field>
        )}
        <Button size="sm" variant="ghost" className="ml-auto" onClick={() => { setManual((v) => !v); setPick(null) }}>{manual ? 'Önerilere dön' : 'Elle tarih/saat seç'}</Button>
      </div>

      {manual ? (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Field label="Tarih"><Input type="date" min={today} value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} /></Field>
          <Field label="Başlangıç" hint={`Bitiş ${endOf(form.starts_at)} (${duration} dk)`}><Input type="time" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} /></Field>
          <Field label="Derslik">
            <Select value={form.classroom_id} onChange={(e) => setForm({ ...form, classroom_id: e.target.value })} placeholder="Aynı derslik" options={(options?.classrooms ?? []).filter((c) => c.is_active && c.kind !== 'study').map((c) => ({ value: c.id, label: `${c.name} · ${c.capacity} kişi` }))} />
          </Field>
        </div>
      ) : q.isLoading ? (
        <div className="flex flex-col gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-10" />)}</div>
      ) : grouped.length === 0 ? (
        <EmptyState compact icon={<CalendarClock />} title="Önümüzdeki 14 günde ortak boşluk yok" description="Sınıf, öğretmen ve uygun derslik aynı anda boş olmalı. Elle seçmeyi deneyin." />
      ) : (
        <div className="flex max-h-[300px] flex-col gap-3 overflow-y-auto scroll-thin pr-1">
          {grouped.map(([day, slots]) => (
            <div key={day}>
              <p className="mb-1.5 text-[12px] font-medium text-ink-3">{fmtDate(day, 'day')}</p>
              <div className="flex flex-wrap gap-1.5">
                {slots.map((s) => {
                  const active = pick?.date === s.date && pick.starts_at === s.starts_at
                  return (
                    <button
                      key={`${s.date}${s.starts_at}`}
                      type="button"
                      onClick={() => setPick(s)}
                      title={s.note ?? undefined}
                      className={cn('rounded-[var(--radius-sm)] border px-2.5 py-1.5 text-left text-[12.5px] transition-colors', active ? 'border-ink bg-surface-2' : 'border-line hover:border-line-strong')}
                    >
                      <span className="block font-medium tabular">{s.starts_at}–{s.ends_at}</span>
                      <span className="block text-[12px] text-ink-3">{s.classroom}{s.same_room ? '' : ' · farklı derslik'}</span>
                    </button>
                  )
                })}
              </div>
            </div>
          ))}
        </div>
      )}

      {(manual || pick) && (
        <>
          {pick && !manual && <p className="text-[13px]">Yeni zaman: <span className="font-medium">{fmtDate(pick.date, 'day')} {pick.starts_at}–{pick.ends_at}</span> · {pick.classroom} <span className="text-ink-3">({WEEKDAYS[pick.weekday]})</span></p>}
          <Field label="Not" optional hint="Öğrenci bildirimine eklenir">
            <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Örn. Öğretmen raporlu olduğu için telafi" />
          </Field>
          <div className="flex justify-end">
            <Button variant="primary" loading={move.isPending} onClick={() => move.mutate()}>Dersi taşı ve bildir</Button>
          </div>
        </>
      )}
    </div>
  )
}

/** İptal: gerekçe zorunlu; öğrencilere bildirim gider. */
export function CancelForm({ sessionId, onDone, onSuggestSubstitute }: { sessionId: number; onDone: () => void; onSuggestSubstitute?: () => void }) {
  const [reason, setReason] = useState('')
  const cancel = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/schedule/sessions/${sessionId}/cancel`, { reason }),
    onSuccess: (r) => {
      toast.success(r.message)
      onDone()
    },
    onError: fail('Ders iptal edilemedi.'),
  })
  return (
    <div className="flex flex-col gap-3">
      {onSuggestSubstitute && (
        <Alert tone="neutral" action={<Button size="xs" onClick={onSuggestSubstitute}>Yedek öner</Button>}>
          İptal etmeden önce boş bir yedek öğretmen atayabilir ya da dersi telafiye taşıyabilirsiniz.
        </Alert>
      )}
      <Field label="İptal gerekçesi" required hint="Öğrenci ve velilere gönderilen bildirimde yer alır">
        <Textarea autoFocus rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Örn. Öğretmen raporlu; telafi Cumartesi 10:00" />
      </Field>
      <div className="flex justify-end">
        <Button variant="danger" disabled={reason.trim().length < 3} loading={cancel.isPending} onClick={() => cancel.mutate()}>Dersi iptal et</Button>
      </div>
    </div>
  )
}
