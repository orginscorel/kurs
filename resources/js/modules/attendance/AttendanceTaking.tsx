import { useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { BookOpen, CheckCheck, ChevronLeft, ChevronRight, Clock, Save } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, time, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Avatar, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Input, Segmented } from '@/components/ui/form'
import { STATUS_TONE, type AttendanceStatus, type RosterResponse, type SessionRow } from './types'

const STATUS_OPTIONS: { value: AttendanceStatus; label: string }[] = [
  { value: 'present', label: 'Var' },
  { value: 'absent', label: 'Yok' },
  { value: 'late', label: 'Geç' },
  { value: 'excused', label: 'İzinli' },
  { value: 'medical', label: 'Raporlu' },
]

const KEY_TO_STATUS: Record<string, AttendanceStatus> = { v: 'present', y: 'absent', g: 'late', i: 'excused', İ: 'excused', ı: 'excused', r: 'medical' }

type LocalRow = { student_id: number; full_name: string; student_no: string; first_entry_at: string | null; status: AttendanceStatus; late_minutes: number | null; note: string; discipline?: string | null }

function SessionList({ sessions, isLoading, onSelect }: { sessions: SessionRow[] | undefined; isLoading: boolean; onSelect: (id: number) => void }) {
  if (isLoading) {
    return (
      <div className="space-y-2">
        {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-16 rounded-[var(--radius-md)]" />)}
      </div>
    )
  }
  if (!sessions?.length) {
    return <EmptyState icon={<BookOpen />} title="Bu gün için planlanmış ders yok" description="Yoklama, ders programındaki derslerden oluşur." action={<ButtonLink to="/ders-programi">Ders programına git</ButtonLink>} />
  }

  return (
    <ul className="space-y-2">
      {sessions.map((s) => {
        const done = s.taken >= s.roster && s.roster > 0
        return (
          <li key={s.id}>
            <button
              type="button"
              onClick={() => onSelect(s.id)}
              className="flex w-full flex-wrap items-center gap-x-3 gap-y-2 rounded-[var(--radius-md)] bg-surface ring-1 ring-line px-4 py-3 text-left transition-colors hover:ring-line-strong"
            >
              <div className={cn('grid size-10 shrink-0 place-items-center rounded-[var(--radius-sm)]', done ? 'bg-success-soft text-success' : s.taken > 0 ? 'bg-warning-soft text-warning' : 'bg-primary-soft text-primary')}>
                {done ? <CheckCheck className="size-4" /> : <Clock className="size-4" />}
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-[13.5px] font-medium">{s.subject} <span className="font-normal text-ink-3">· {s.class_group}</span></p>
                <p className="text-[12.5px] text-ink-3 tabular">{time(s.starts_at)}–{time(s.ends_at)} · Derslik: {s.classroom || '—'} · Öğretmen: {s.teacher || '—'}</p>
              </div>
              {done ? (
                <Badge tone="success" dot>Yoklama alındı · {s.roster} öğrenci</Badge>
              ) : s.taken > 0 ? (
                <Badge tone="warning" dot>Yarım · {s.taken}/{s.roster} öğrenci</Badge>
              ) : (
                <Badge tone="neutral">Yoklama alınmadı · {s.roster} öğrenci</Badge>
              )}
              <span className={cn('hidden shrink-0 items-center gap-1 text-[12.5px] font-medium sm:inline-flex', done ? 'text-ink-3' : 'text-primary')}>
                {done ? 'Düzenle' : 'Yoklama al'}
                <ChevronRight className="size-4" />
              </span>
            </button>
          </li>
        )
      })}
    </ul>
  )
}

function RosterScreen({ sessionId, onBack }: { sessionId: number; onBack: () => void }) {
  const qc = useQueryClient()
  const [rows, setRows] = useState<LocalRow[] | null>(null)
  const [focused, setFocused] = useState(0)
  const [openNote, setOpenNote] = useState<number | null>(null)
  const containerRef = useRef<HTMLDivElement>(null)

  const roster = useQuery({ queryKey: ['attendance', 'roster', sessionId], queryFn: () => api.get<RosterResponse>(`/attendance/sessions/${sessionId}`) })

  useEffect(() => {
    if (!roster.data) return
    setRows(
      roster.data.students.map((s) => ({
        student_id: s.student_id,
        full_name: s.full_name,
        student_no: s.student_no,
        first_entry_at: s.first_entry_at,
        // Uzaklaştırmadaki öğrenci: kayıt yoksa "izinli" + disiplin notu önerilir
        status: s.status ?? (s.discipline ? 'excused' : s.first_entry_at ? 'present' : 'absent'),
        late_minutes: s.late_minutes,
        note: s.note ?? (s.discipline && !s.status ? s.discipline : ''),
        discipline: s.discipline ?? null,
      })),
    )
  }, [roster.data])

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      const tag = (document.activeElement?.tagName ?? '').toLowerCase()
      if (tag === 'input' || tag === 'textarea' || tag === 'select' || !rows) return

      if (e.key === 'ArrowDown') { e.preventDefault(); setFocused((f) => Math.min(rows.length - 1, f + 1)) }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setFocused((f) => Math.max(0, f - 1)) }
      else {
        const status = KEY_TO_STATUS[e.key.toLowerCase()] ?? KEY_TO_STATUS[e.key]
        if (status) setRows((prev) => prev!.map((r, i) => (i === focused ? { ...r, status } : r)))
      }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [rows, focused])

  const saveMutation = useMutation({
    mutationFn: () =>
      api.post(`/attendance/sessions/${sessionId}`, {
        rows: rows!.map((r) => ({ student_id: r.student_id, status: r.status, late_minutes: r.status === 'late' ? r.late_minutes ?? 5 : null, note: r.note || null })),
      }),
    onSuccess: (res: any) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['attendance', 'sessions'] })
      qc.invalidateQueries({ queryKey: ['attendance', 'roster', sessionId] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const markAllPresent = () => setRows((prev) => prev?.map((r) => ({ ...r, status: 'present' })) ?? null)

  const counts = useMemo(() => {
    const c: Record<AttendanceStatus, number> = { present: 0, absent: 0, late: 0, excused: 0, medical: 0 }
    rows?.forEach((r) => c[r.status]++)
    return c
  }, [rows])

  return (
    <div>
      <div className="flex items-center gap-2 mb-3">
        <Button size="sm" variant="ghost" icon={<ChevronLeft className="size-4" />} onClick={onBack}>Derslere dön</Button>
      </div>

      {roster.isLoading || !rows ? (
        <Skeleton className="h-96 rounded-[var(--radius-lg)]" />
      ) : (
        <>
          <Panel className="mb-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p className="text-[14.5px] font-semibold">{roster.data!.session.subject} · {roster.data!.session.class_group}</p>
                <p className="text-[12.5px] text-ink-3">{time(roster.data!.session.starts_at)}–{time(roster.data!.session.ends_at)} · Derslik: {roster.data!.session.classroom || '—'} · Öğretmen: {roster.data!.session.teacher || '—'}</p>
              </div>
              <div className="flex items-center gap-1.5 flex-wrap">
                {STATUS_OPTIONS.map((o) => (
                  <Badge key={o.value} tone={STATUS_TONE[o.value]}>{counts[o.value]} {o.label}</Badge>
                ))}
              </div>
            </div>
          </Panel>

          <div className="flex items-center justify-between gap-2 mb-2 px-0.5">
            <p className="hidden text-[12.5px] text-ink-3 sm:block">Klavye: ↑↓ öğrenci seç · V var, Y yok, G geç, İ izinli, R raporlu</p>
            <Button size="sm" variant="soft" icon={<CheckCheck className="size-3.5" />} onClick={markAllPresent} className="ml-auto">Tümünü “var” işaretle</Button>
          </div>

          <div ref={containerRef} className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line overflow-hidden">
            {rows.map((r, i) => (
              <div key={r.student_id}>
                <div
                  onClick={() => setFocused(i)}
                  className={cn('flex flex-col sm:flex-row sm:items-center gap-2.5 px-4 py-3 border-b border-line last:border-0 cursor-pointer transition-colors', focused === i ? 'bg-primary-soft/40' : 'hover:bg-surface-2/60')}
                >
                  <div className="flex items-center gap-3 min-w-0 sm:flex-1">
                    <Avatar name={r.full_name} size={32} />
                    <div className="min-w-0">
                      <p className="truncate text-[13.5px] font-medium">
                        {r.full_name}
                        {r.discipline && <Badge tone="danger" className="ml-1.5 align-middle"><span title={r.discipline}>Uzaklaştırmada</span></Badge>}
                      </p>
                      <p className="text-[12.5px] text-ink-3 tabular">
                        Öğrenci no: {r.student_no}{r.first_entry_at ? ` · Kurum girişi: ${time(r.first_entry_at)}` : ' · Cihazdan giriş kaydı yok'}
                      </p>
                    </div>
                  </div>
                  <div className="flex flex-wrap items-center gap-2 sm:shrink-0">
                    <Segmented
                      size="sm"
                      value={r.status}
                      onChange={(status) => setRows((prev) => prev!.map((x, xi) => (xi === i ? { ...x, status } : x)))}
                      options={STATUS_OPTIONS.map((o) => ({ value: o.value, label: o.label }))}
                    />
                    {r.status === 'late' && (
                      <Input
                        type="number"
                        min={1}
                        max={120}
                        value={r.late_minutes ?? 5}
                        onChange={(e) => setRows((prev) => prev!.map((x, xi) => (xi === i ? { ...x, late_minutes: Number(e.target.value) } : x)))}
                        className="w-24"
                        aria-label="Kaç dakika geç kaldı"
                        title="Kaç dakika geç kaldı"
                        trailing={<span className="text-[12px]">dk</span>}
                      />
                    )}
                    <Button size="xs" variant="ghost" onClick={() => setOpenNote(openNote === i ? null : i)}>{r.note ? 'Notu düzenle' : 'Not ekle'}</Button>
                  </div>
                </div>
                {openNote === i && (
                  <div className="px-4 pb-3 -mt-1">
                    <Input
                      value={r.note}
                      onChange={(e) => setRows((prev) => prev!.map((x, xi) => (xi === i ? { ...x, note: e.target.value } : x)))}
                      placeholder="Not (ör. izin/rapor belge no)"
                      aria-label="Yoklama notu"
                    />
                  </div>
                )}
              </div>
            ))}
          </div>

          <div className="sticky bottom-3 mt-4 flex justify-end">
            <Button variant="primary" size="lg" icon={<Save className="size-4" />} loading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>
              Yoklamayı kaydet
            </Button>
          </div>
        </>
      )}
    </div>
  )
}

export default function AttendanceTaking() {
  const [params, setParams] = useSearchParams()
  const sessionId = params.get('ders') ? Number(params.get('ders')) : null

  const sessions = useQuery({
    queryKey: ['attendance', 'sessions', todayISO()],
    queryFn: () => api.get<{ date: string; data: SessionRow[] }>('/attendance/sessions'),
  })

  const select = (id: number) => setParams((p) => { p.set('ders', String(id)); return p })
  const back = () => setParams((p) => { p.delete('ders'); return p })

  return (
    <div className="animate-fade-in">
      <PageHeader title="Yoklama" description={date(new Date(), 'day')} />
      {sessionId ? <RosterScreen sessionId={sessionId} onBack={back} /> : <SessionList sessions={sessions.data?.data} isLoading={sessions.isLoading} onSelect={select} />}
    </div>
  )
}
