import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, CalendarClock, CheckCircle2, DoorOpen, Lock, LockOpen, NotebookPen, Pencil, RotateCcw, Trash2, UserRound, UserRoundCheck } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date } from '@/lib/format'
import { useCan } from '@/app/auth'
import { Modal, ConfirmDialog } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Select, Textarea } from '@/components/ui/form'
import { Alert, Badge } from '@/components/ui/feedback'
import { DescriptionList } from '@/components/ui/layout'
import { ColorChip } from '../ui'
import { teacherOptions } from '../hooks'
import { sessionStatusLabel, toMinutes, WEEKDAYS, type AcademicOptions, type Conflict, type SubjectDetailData } from '../types'
import { MoveForm, SubstituteList } from '../timetable/SessionActions'

/** Ders kartı: şablon bilgisi + seçili haftanın oturumu için tek seferlik işlemler. */
export type SheetTarget = {
  scheduleId: number | null
  session: { id: number; date: string; status: string; cancel_reason: string | null; topic_note: string | null; topic_id: number | null; attendance_taken: boolean; classroom_override?: { id: number; name: string } | null; teacher_override?: { id: number; name: string } | null } | null
  weekday: number; starts_at: string; ends_at: string
  subject: { id: number; name: string; color: string }; teacher: { id: number; name: string }; classroom: { id: number; name: string }; class_group: { id: number; name: string }
  valid_from?: string; valid_until?: string | null
  is_locked?: boolean
}

type Props = {
  target: SheetTarget | null
  onClose: () => void
  onChanged: () => void
  onEdit?: () => void
  options?: AcademicOptions
}

export function LessonSheet({ target, onClose, onChanged, onEdit, options }: Props) {
  const can = useCan()
  const manage = can('schedule.manage')
  const [mode, setMode] = useState<'view' | 'cancel' | 'reassign' | 'topic' | 'delete' | 'substitute' | 'move'>('view')
  const [reason, setReason] = useState('')
  const [room, setRoom] = useState('')
  const [teacher, setTeacher] = useState('')
  const [topicId, setTopicId] = useState('')
  const [note, setNote] = useState('')
  const [conflicts, setConflicts] = useState<Conflict[]>([])

  useEffect(() => {
    setMode('view')
    setReason('')
    setRoom('')
    setTeacher('')
    setConflicts([])
    setTopicId(target?.session?.topic_id ? String(target.session.topic_id) : '')
    setNote(target?.session?.topic_note ?? '')
  }, [target])

  const topics = useQuery({ queryKey: ['subject', target?.subject.id, 'topics'], queryFn: () => api.get<SubjectDetailData>(`/subjects/${target!.subject.id}`), enabled: !!target && mode === 'topic' && can('academic.view') })

  const done = (msg: string) => {
    toast.success(msg)
    onChanged()
    onClose()
  }
  const fail = (e: unknown) => {
    if (e instanceof ApiError) {
      if (e.status === 409) setConflicts((e.context.conflicts as Conflict[]) ?? [])
      toast.error(e.firstError())
    }
  }
  const s = target?.session
  const cancelM = useMutation({ mutationFn: () => api.post<{ message: string }>(`/schedule/sessions/${s!.id}/cancel`, { reason }), onSuccess: (r) => done(r.message), onError: fail })
  const restoreM = useMutation({ mutationFn: () => api.post<{ message: string }>(`/schedule/sessions/${s!.id}/restore`), onSuccess: (r) => done(r.message), onError: fail })
  const reassignM = useMutation({ mutationFn: () => api.post<{ message: string }>(`/schedule/sessions/${s!.id}/reassign`, { classroom_id: room ? Number(room) : undefined, teacher_id: teacher ? Number(teacher) : undefined }), onSuccess: (r) => done(r.message), onError: fail })
  const topicM = useMutation({ mutationFn: () => api.post<{ message: string }>(`/schedule/sessions/${s!.id}/topic`, { topic_id: topicId ? Number(topicId) : null, topic_note: note || null }), onSuccess: (r) => done(r.message), onError: fail })
  const deleteM = useMutation({ mutationFn: () => api.delete<{ message: string }>(`/schedule/${target!.scheduleId}`), onSuccess: (r) => done(r.message), onError: fail })
  const lockM = useMutation({ mutationFn: () => api.put<{ message: string }>(`/schedule/${target!.scheduleId}/lock`, { locked: !target!.is_locked }), onSuccess: (r) => done(r.message), onError: fail })
  const actionDone = () => {
    onChanged()
    onClose()
  }

  if (!target) return null
  const cancelled = s?.status === 'cancelled'
  const past = s ? new Date(`${s.date}T${target.ends_at}:00`) < new Date() : false

  return (
    <>
      <Modal
        open={!!target && mode !== 'delete'}
        onClose={onClose}
        title={<span className="flex items-center gap-2"><ColorChip color={target.subject.color}>{target.subject.name}</ColorChip>{target.class_group.name}</span>}
        description={`${WEEKDAYS[target.weekday]} ${target.starts_at}–${target.ends_at}${s ? ` · ${date(s.date, 'long')}` : ''}`}
        footer={
          mode === 'view' ? (
            <>
              {manage && target.scheduleId && (
                <Button variant="danger-soft" className="mr-auto" icon={<Trash2 className="size-4" />} onClick={() => setMode('delete')}>Programdan kaldır</Button>
              )}
              {manage && target.scheduleId && (
                <Button icon={target.is_locked ? <LockOpen className="size-4" /> : <Lock className="size-4" />} loading={lockM.isPending} onClick={() => lockM.mutate()} title="Kilitli ders program botu çalıştırmalarında olduğu gibi korunur">
                  {target.is_locked ? 'Kilidi aç' : 'Kilitle'}
                </Button>
              )}
              {manage && target.scheduleId && onEdit && <Button icon={<Pencil className="size-4" />} onClick={onEdit}>Şablonu düzenle</Button>}
              <Button variant="primary" onClick={onClose}>Kapat</Button>
            </>
          ) : mode === 'substitute' || mode === 'move' ? (
            <Button variant="ghost" onClick={() => setMode('view')}>Geri</Button>
          ) : mode === 'cancel' ? (
            <>
              <Button variant="ghost" onClick={() => setMode('view')}>Geri</Button>
              <Button variant="danger" loading={cancelM.isPending} disabled={reason.trim().length < 3} onClick={() => cancelM.mutate()}>Dersi iptal et</Button>
            </>
          ) : mode === 'reassign' ? (
            <>
              <Button variant="ghost" onClick={() => setMode('view')}>Geri</Button>
              <Button variant="primary" loading={reassignM.isPending} disabled={!room && !teacher} onClick={() => reassignM.mutate()}>Kaydet</Button>
            </>
          ) : (
            <>
              <Button variant="ghost" onClick={() => setMode('view')}>Geri</Button>
              <Button variant="primary" loading={topicM.isPending} onClick={() => topicM.mutate()}>Kaydet</Button>
            </>
          )
        }
      >
        {mode === 'view' && (
          <div className="flex flex-col gap-4">
            <DescriptionList
              columns={2}
              items={[
                { label: 'Öğretmen', value: <span>{s?.teacher_override ? <><s className="text-ink-3">{target.teacher.name}</s> → {s.teacher_override.name}</> : target.teacher.name}</span> },
                { label: 'Derslik', value: <span>{s?.classroom_override ? <><s className="text-ink-3">{target.classroom.name}</s> → {s.classroom_override.name}</> : target.classroom.name}</span> },
                { label: 'Geçerlilik', value: target.valid_from ? `${date(target.valid_from)} – ${target.valid_until ? date(target.valid_until) : 'dönem sonu'}` : '—', hidden: !target.scheduleId },
                { label: 'Bu haftaki oturum', value: s ? <Badge tone="neutral">{sessionStatusLabel[s.status] ?? s.status}{s.attendance_taken ? ' · yoklama alındı' : ''}</Badge> : <span className="text-ink-3">Oturum üretilmemiş</span> },
                { label: 'Program botu', value: <span className="inline-flex items-center gap-1.5"><Lock className="size-3.5 text-ink-3" />Kilitli — bot bu derse dokunmaz</span>, hidden: !target.is_locked },
              ]}
            />
            {cancelled && s?.cancel_reason && <Alert tone="neutral" title="İptal gerekçesi">{s.cancel_reason}</Alert>}
            {s?.topic_note && <Alert tone="neutral" title="İşlenen konu">{s.topic_note}</Alert>}

            {s && (
              <div>
                <p className="mb-2 text-[12px] font-medium text-ink-3">Yalnızca bu ders için</p>
                <div className="flex flex-wrap gap-2">
                  {manage && !cancelled && !s.attendance_taken && !past && <Button size="sm" icon={<UserRoundCheck className="size-3.5" />} onClick={() => setMode('substitute')}>Yedek öğretmen</Button>}
                  {manage && !cancelled && !s.attendance_taken && !past && <Button size="sm" icon={<CalendarClock className="size-3.5" />} onClick={() => setMode('move')}>Taşı (telafi)</Button>}
                  {manage && !cancelled && !s.attendance_taken && <Button size="sm" icon={<Ban className="size-3.5" />} onClick={() => setMode('cancel')}>İptal et</Button>}
                  {manage && cancelled && !past && <Button size="sm" icon={<RotateCcw className="size-3.5" />} loading={restoreM.isPending} onClick={() => restoreM.mutate()}>İptali geri al</Button>}
                  {manage && !cancelled && <Button size="sm" icon={<DoorOpen className="size-3.5" />} onClick={() => setMode('reassign')}>Derslik / öğretmen değiştir</Button>}
                  {(manage || can('attendance.take')) && !cancelled && <Button size="sm" icon={<NotebookPen className="size-3.5" />} onClick={() => setMode('topic')}>İşlenen konu</Button>}
                  {s.attendance_taken && <span className="inline-flex items-center gap-1 text-[12.5px] text-ink-3"><CheckCircle2 className="size-3.5" /> Yoklama alınmış; taşınamaz</span>}
                </div>
              </div>
            )}
          </div>
        )}

        {mode === 'substitute' && s && <SubstituteList sessionId={s.id} canManage={manage} onDone={actionDone} />}
        {mode === 'move' && s && <MoveForm sessionId={s.id} duration={toMinutes(target.ends_at) - toMinutes(target.starts_at)} options={options} onDone={actionDone} />}

        {mode === 'cancel' && (
          <Field label="İptal gerekçesi" required hint="Veli ve öğrencilere bildirimde kullanılır">
            <Textarea autoFocus value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Örn. Öğretmen raporlu; telafi Cumartesi 10:00" />
          </Field>
        )}

        {mode === 'reassign' && (
          <div className="flex flex-col gap-3">
            <Field label="Derslik (yalnızca bu ders)" optional>
              <Select value={room} onChange={(e) => setRoom(e.target.value)} placeholder={`Değişmesin (${s?.classroom_override?.name ?? target.classroom.name})`} options={(options?.classrooms ?? []).filter((c) => c.is_active).map((c) => ({ value: c.id, label: `${c.name} · ${c.capacity} kişi` }))} />
            </Field>
            <Field label="Öğretmen (yalnızca bu ders)" optional hint="Yerine giren öğretmen">
              <Select value={teacher} onChange={(e) => setTeacher(e.target.value)} placeholder={`Değişmesin (${s?.teacher_override?.name ?? target.teacher.name})`} options={teacherOptions(options, target.subject.id)} />
            </Field>
            {conflicts.length > 0 && (
              <Alert tone="danger" title="Çakışma var — kaydedilmedi">
                <ul className="mt-1">{conflicts.map((c, i) => <li key={i}>{c.message}</li>)}</ul>
              </Alert>
            )}
          </div>
        )}

        {mode === 'topic' && (
          <div className="flex flex-col gap-3">
            <Field label="İşlenen konu (müfredattan)" optional>
              <Select value={topicId} onChange={(e) => setTopicId(e.target.value)} placeholder="Seçin" options={(topics.data?.topics ?? []).map((t) => ({ value: t.id, label: `${t.parent_id ? '— ' : ''}${t.outcome_code ? `${t.outcome_code} · ` : ''}${t.name}` }))} />
            </Field>
            <Field label="Not" optional>
              <Textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="İşlenen konu, verilen ödev, notlar" />
            </Field>
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={mode === 'delete'}
        onClose={() => setMode('view')}
        onConfirm={() => deleteM.mutate()}
        loading={deleteM.isPending}
        danger
        title="Dersi programdan kaldır"
        confirmLabel="Kaldır"
        description="Gelecekteki, yoklaması alınmamış oturumlar silinir; geçmiş dersler ve yoklamalar korunur."
      >
        <p className="text-[13px]"><UserRound className="inline size-3.5 mr-1" />{target.teacher.name} · {WEEKDAYS[target.weekday]} {target.starts_at}–{target.ends_at}</p>
      </ConfirmDialog>
    </>
  )
}
