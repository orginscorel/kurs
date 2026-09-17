import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, CheckCheck, Fingerprint, MessageSquarePlus, Save } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, time } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Alert, Avatar, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/form'
import { TP, useTeacherCan, useTeacherQuery } from '../api'
import { usePortalPageTitle } from '@/modules/portal/PortalLayout'

type Row = { student_id: number; full_name: string; student_no: string; photo_url: string | null; status: string | null; late_minutes: number | null; note: string | null; method: string | null; first_entry_at: string | null; discipline?: string | null }
type Data = {
  session: { id: number; date: string; starts_at: string; ends_at: string; subject: string; class_group: string; classroom: string | null; attendance_taken_at: string | null; status: string }
  students: Row[]
  can_edit: boolean
  edit_reason: string | null
  statuses: Record<string, string>
}
type Draft = { status: string | null; late_minutes: string; note: string; showNote: boolean }

const OPTIONS: { value: string; label: string; on: string }[] = [
  { value: 'present', label: 'Var', on: 'bg-success text-white ring-success' },
  { value: 'late', label: 'Geç', on: 'bg-warning text-white ring-warning' },
  { value: 'absent', label: 'Yok', on: 'bg-danger text-white ring-danger' },
  { value: 'excused', label: 'İzinli', on: 'bg-info text-white ring-info' },
  { value: 'medical', label: 'Raporlu', on: 'bg-info text-white ring-info' },
]

export default function TeacherAttendanceTake() {
  const { id } = useParams()
  const qc = useQueryClient()
  const can = useTeacherCan()
  const { data, isLoading, error } = useTeacherQuery<Data>(['attendance', 'session', id], `/attendance/${id}`)
  const [draft, setDraft] = useState<Record<number, Draft>>({})

  useEffect(() => {
    if (!data) return
    setDraft(Object.fromEntries(data.students.map((s) => [s.student_id, { status: s.status ?? (s.discipline ? 'excused' : null), late_minutes: s.late_minutes ? String(s.late_minutes) : '', note: s.note ?? (s.discipline && !s.status ? s.discipline : ''), showNote: !!s.note || (!!s.discipline && !s.status) }])))
  }, [data])

  const editable = !!data?.can_edit && can('attendance')
  const counts = useMemo(() => {
    const c: Record<string, number> = { present: 0, late: 0, absent: 0, excused: 0, medical: 0, empty: 0 }
    Object.values(draft).forEach((d) => { c[d.status ?? 'empty'] = (c[d.status ?? 'empty'] ?? 0) + 1 })
    return c
  }, [draft])

  const save = useMutation({
    mutationFn: () => api.post<{ message: string }>(`${TP}/attendance/${id}`, {
      rows: Object.entries(draft).filter(([, d]) => d.status).map(([sid, d]) => ({
        student_id: Number(sid), status: d.status, late_minutes: d.status === 'late' && d.late_minutes ? Number(d.late_minutes) : null, note: d.note.trim() || null,
      })),
    }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['teacher-portal'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Yoklama kaydedilemedi.'),
  })

  const set = (sid: number, patch: Partial<Draft>) => setDraft((d) => ({ ...d, [sid]: { ...d[sid]!, ...patch } }))
  const allPresent = () => setDraft((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v.status ? v : { ...v, status: 'present' }])))

  usePortalPageTitle(data?.session ? `Yoklama · ${data.session.class_group} · ${data.session.subject}` : undefined)
  if (error) {
    return <EmptyState title="Ders bulunamadı" description={error instanceof ApiError ? error.message : undefined} action={<Link className="text-[14px] font-medium text-primary" to="/ogretmen/yoklama">Yoklama listesine dön</Link>} />
  }
  if (isLoading || !data) return <div className="flex flex-col gap-3"><Skeleton className="h-16" /><Skeleton className="h-96" /></div>

  const s = data.session
  const empty = counts.empty ?? 0

  return (
    <div className="animate-fade-in flex flex-col gap-4 pb-20">
      <div>
        <Link to="/ogretmen/yoklama" className="hidden items-center gap-1 text-[12.5px] font-medium text-ink-3 hover:text-ink md:inline-flex"><ArrowLeft className="size-3.5" /> Yoklama</Link>
        <h1 className="mt-1 text-[20px] font-semibold tracking-[-0.02em] sm:text-[22px]">{s.class_group} · {s.subject}</h1>
        <p className="text-[14px] text-ink-3 capitalize">{date(s.date, 'day')} · {time(s.starts_at)}–{time(s.ends_at)}{s.classroom ? ` · ${s.classroom}` : ''}</p>
      </div>

      {!data.can_edit && <Alert tone="info">{data.edit_reason}</Alert>}
      {data.can_edit && !can('attendance') && <Alert tone="info">Önizleme modunda yoklama kaydedilemez.</Alert>}
      {s.attendance_taken_at && <p className="text-[12.5px] text-ink-3">Son kayıt: {date(s.attendance_taken_at)} {time(s.attendance_taken_at)}</p>}

      <div className="flex flex-wrap items-center gap-2 text-[12.5px]">
        <span className="rounded-full bg-success-soft px-2.5 py-1 font-medium text-success">{counts.present} var</span>
        <span className="rounded-full bg-warning-soft px-2.5 py-1 font-medium text-warning">{counts.late} geç</span>
        <span className="rounded-full bg-danger-soft px-2.5 py-1 font-medium text-danger">{counts.absent} yok</span>
        <span className="rounded-full bg-info-soft px-2.5 py-1 font-medium text-info">{(counts.excused ?? 0) + (counts.medical ?? 0)} izinli/raporlu</span>
        {empty > 0 && <span className="rounded-full bg-surface-2 px-2.5 py-1 font-medium text-ink-2">{empty} işaretlenmedi</span>}
        {editable && empty > 0 && (
          <Button size="sm" variant="soft" className="ml-auto" icon={<CheckCheck className="size-4" />} onClick={allPresent}>Kalanları “Var” işaretle</Button>
        )}
      </div>

      {data.students.length === 0 ? (
        <EmptyState title="Bu sınıfta kayıtlı öğrenci yok" />
      ) : (
        <ul className="overflow-hidden rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          {data.students.map((st, i) => {
            const d = draft[st.student_id]
            if (!d) return null
            return (
              <li key={st.student_id} className={cn('flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:gap-3 sm:px-4', i > 0 && 'border-t border-line')}>
                <Link to={`/ogretmen/ogrenci/${st.student_id}`} className="flex min-w-0 flex-1 items-center gap-2.5">
                  <Avatar name={st.full_name} src={st.photo_url} size={34} />
                  <span className="min-w-0">
                    <span className="block break-words text-[14.5px] font-medium">{st.full_name}</span>
                    <span className="flex items-center gap-1.5 text-[12.5px] text-ink-3">
                      <span className="tabular">{st.student_no}</span>
                      {st.first_entry_at && <span className="inline-flex items-center gap-0.5 text-success" title="Kurum girişi (cihaz)"><Fingerprint className="size-3" /> {time(st.first_entry_at)}</span>}
                      {st.discipline && <span title={st.discipline} className="rounded-[3px] border border-danger/25 bg-danger-soft px-1 font-medium text-danger">Uzaklaştırmada</span>}
                    </span>
                  </span>
                </Link>
                <div className="flex w-full flex-wrap items-center gap-1.5 sm:w-auto">
                  <div className="grid min-w-0 flex-1 grid-cols-3 gap-1 min-[380px]:grid-cols-5 sm:flex-none" role="radiogroup" aria-label={`${st.full_name} yoklama durumu`}>
                    {OPTIONS.map((o) => (
                      <button
                        key={o.value}
                        type="button"
                        role="radio"
                        aria-checked={d.status === o.value}
                        disabled={!editable}
                        onClick={() => set(st.student_id, { status: o.value })}
                        className={cn('h-11 min-w-0 rounded-[var(--radius-sm)] px-1 text-[13px] font-medium ring-1 transition-colors disabled:opacity-60 sm:h-9 sm:min-w-[56px] sm:px-1.5',
                          d.status === o.value ? o.on : 'bg-surface text-ink-2 ring-line hover:bg-surface-2')}
                      >
                        {o.label}
                      </button>
                    ))}
                  </div>
                  {d.status === 'late' && (
                    <Input type="number" min={1} max={300} inputMode="numeric" placeholder="dk" aria-label="Geç kalma (dakika)" className="w-[72px]" value={d.late_minutes} disabled={!editable} onChange={(e) => set(st.student_id, { late_minutes: e.target.value })} />
                  )}
                  {editable && !d.showNote && (
                    <Button size="icon-sm" variant="ghost" aria-label="Not ekle" title="Not ekle" onClick={() => set(st.student_id, { showNote: true })}><MessageSquarePlus className="size-4" /></Button>
                  )}
                </div>
                {d.showNote && (
                  <Input className="sm:w-56" maxLength={300} placeholder="Kısa not (isteğe bağlı)" value={d.note} disabled={!editable} onChange={(e) => set(st.student_id, { note: e.target.value })} />
                )}
              </li>
            )
          })}
        </ul>
      )}

      {editable && data.students.length > 0 && (
        <div className="fixed inset-x-0 bottom-[calc(var(--portal-tabbar,62px)+env(safe-area-inset-bottom))] z-20 border-t border-line bg-surface/95 px-4 py-2.5 backdrop-blur-md md:bottom-0 md:left-[var(--portal-sb,0px)] md:px-6 md:pb-[calc(env(safe-area-inset-bottom)+10px)] lg:px-8">
          <div className="mx-auto flex max-w-[1136px] items-center justify-between gap-3">
            <p className="min-w-0 break-words text-[12.5px] text-ink-2">{empty > 0 ? `${empty} öğrenci işaretlenmedi` : 'Tüm öğrenciler işaretlendi'}</p>
            <Button variant="primary" icon={<Save className="size-4" />} loading={save.isPending} disabled={empty === data.students.length} onClick={() => save.mutate()}>
              Yoklamayı kaydet
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
