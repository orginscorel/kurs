import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { todayISO } from '@/lib/format'
import { useCan } from '@/app/auth'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Switch, Textarea } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'
import { StudentPicker } from './ui'
import { teacherOptions } from './hooks'
import { toMinutes, toTime, type AcademicOptions, type Conflict, type FreeSlots, type StudyDetailData } from './types'

type Props = { open: boolean; onClose: () => void; onSaved: () => void; options?: AcademicOptions; editing?: StudyDetailData | null; presetStudent?: { id: number; full_name: string } }

export function StudyFormDrawer({ open, onClose, onSaved, options, editing, presetStudent }: Props) {
  const can = useCan()
  const manage = can('study.manage')
  const [form, setForm] = useState<Record<string, any>>({})
  const [students, setStudents] = useState<{ id: number; full_name: string }[]>([])
  const [approve, setApprove] = useState(true)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [conflicts, setConflicts] = useState<Conflict[]>([])

  const optionsRef = useRef(options)
  optionsRef.current = options
  const presetRef = useRef(presetStudent)
  presetRef.current = presetStudent
  useEffect(() => {
    if (!open) return
    setErrors({})
    setConflicts([])
    if (editing) {
      setForm({
        kind: editing.kind, teacher_id: editing.teacher_id, subject_id: editing.subject_id ?? '', classroom_id: editing.classroom_id ?? '', topic: editing.topic ?? '',
        date: editing.date, starts_at: editing.start_time, ends_at: editing.end_time, capacity: editing.capacity, fee: editing.fee ?? '', notes: editing.notes ?? '',
      })
      setStudents(editing.students.map((s) => ({ id: s.id, full_name: s.full_name })))
    } else {
      setForm({ kind: 'study', teacher_id: optionsRef.current?.my_teacher_id ?? '', subject_id: '', classroom_id: '', topic: '', date: todayISO(), starts_at: '', ends_at: '', capacity: 6, fee: '', notes: '' })
      setStudents(presetRef.current ? [presetRef.current] : [])
      setApprove(manage)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, editing])

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]
  const isPrivate = form.kind === 'private'
  const teachers = useMemo(() => teacherOptions(options, Number(form.subject_id) || null), [options, form.subject_id])

  const free = useQuery({
    queryKey: ['study', 'free', form.teacher_id, form.date],
    queryFn: () => api.get<FreeSlots>(`/study/teachers/${form.teacher_id}/free-slots`, { date: form.date, min: 30 }),
    enabled: open && !!form.teacher_id && !!form.date,
  })

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = {
        kind: form.kind, teacher_id: Number(form.teacher_id), subject_id: form.subject_id ? Number(form.subject_id) : null, classroom_id: form.classroom_id ? Number(form.classroom_id) : null,
        topic: form.topic || null, starts_at: `${form.date} ${form.starts_at}`, ends_at: `${form.date} ${form.ends_at}`, capacity: isPrivate ? 1 : Number(form.capacity) || 1,
        fee: isPrivate && form.fee !== '' ? Number(form.fee) : null, notes: form.notes || null, student_ids: students.map((s) => s.id), approve,
      }
      if (editing) {
        delete payload.kind
        delete payload.student_ids
        delete payload.approve
        return api.put<{ message: string }>(`/study-sessions/${editing.id}`, payload)
      }
      return api.post<{ message: string }>('/study-sessions', payload)
    },
    onSuccess: (r) => {
      toast.success(r.message)
      onClose()
      onSaved()
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        if (e.status === 409) setConflicts((e.context.conflicts as Conflict[]) ?? [])
        toast.error(e.firstError())
      }
    },
  })

  const dayMinutes = form.starts_at && form.ends_at ? toMinutes(form.ends_at) - toMinutes(form.starts_at) : 0

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={600}
      title={editing ? 'Kaydı düzenle' : 'Etüt / birebir ders planla'}
      description={editing ? `${editing.kind_label} · ${editing.teacher?.name ?? ''}` : manage ? 'Öğretmenin uygun boşluklarından saat seçin; çakışma varsa kaydedilmez.' : 'Talebiniz yönetici onayına düşer.'}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{editing ? 'Kaydet' : approve ? 'Planla' : 'Talep oluştur'}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-5">
        {!editing && (
          <Segmented value={form.kind ?? 'study'} onChange={(v) => setForm((f) => ({ ...f, kind: v, capacity: v === 'private' ? 1 : 6 }))} options={[{ value: 'study', label: 'Etüt (grup)' }, { value: 'private', label: 'Birebir ders' }]} />
        )}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Ders" optional error={err('subject_id')}>
            <Select value={form.subject_id ?? ''} onChange={(e) => set('subject_id', e.target.value)} placeholder="Belirtilmedi" options={(options?.subjects ?? []).filter((s) => s.is_active).map((s) => ({ value: s.id, label: s.name }))} />
          </Field>
          <Field label="Öğretmen" required error={err('teacher_id')}>
            <Select value={form.teacher_id ?? ''} onChange={(e) => set('teacher_id', e.target.value)} placeholder="Seçin" options={teachers} />
          </Field>
          <Field label="Tarih" required error={err('starts_at')}>
            <Input type="date" value={form.date ?? ''} onChange={(e) => set('date', e.target.value)} />
          </Field>
          <Field label="Derslik" optional error={err('classroom_id')}>
            <Select value={form.classroom_id ?? ''} onChange={(e) => set('classroom_id', e.target.value)} placeholder="Belirtilmedi" options={(options?.classrooms ?? []).filter((c) => c.is_active).map((c) => ({ value: c.id, label: `${c.name}${c.kind === 'study' ? ' · etüt' : ''}` }))} />
          </Field>
          <Field label="Başlangıç" required error={err('starts_at')}>
            <Input type="time" step={300} value={form.starts_at ?? ''} onChange={(e) => { const s = e.target.value; setForm((f) => ({ ...f, starts_at: s, ends_at: s && (!f.ends_at || toMinutes(f.ends_at) <= toMinutes(s)) ? toTime(toMinutes(s) + (isPrivate ? 60 : 50)) : f.ends_at })) }} />
          </Field>
          <Field label="Bitiş" required error={err('ends_at')} hint={dayMinutes > 0 ? `${dayMinutes} dakika` : undefined}>
            <Input type="time" step={300} value={form.ends_at ?? ''} onChange={(e) => set('ends_at', e.target.value)} />
          </Field>
        </div>

        {form.teacher_id && form.date && (
          <div className="rounded-[var(--radius-md)] bg-surface-2/60 ring-1 ring-line p-3">
            <p className="mb-2 text-[12px] font-medium text-ink-2">Öğretmenin uygun boşlukları {free.data && !free.data.defined ? <span className="text-ink-3">(uygunluk tanımlı değil; 09:00–21:00 varsayıldı)</span> : null}</p>
            {free.isLoading ? (
              <p className="text-[12.5px] text-ink-3">Hesaplanıyor…</p>
            ) : free.data?.on_leave ? (
              <Alert tone="warning">Öğretmen bu tarihte izinli.</Alert>
            ) : free.data?.free.length ? (
              <div className="flex flex-wrap gap-1.5">
                {free.data.free.map(([a, b]) => (
                  <button key={a} type="button" onClick={() => setForm((f) => ({ ...f, starts_at: a, ends_at: toTime(Math.min(toMinutes(b), toMinutes(a) + (isPrivate ? 60 : 50))) }))} className="h-7 rounded-full px-3 text-[12.5px] font-medium tabular bg-success-soft text-success ring-1 ring-success/20 hover:brightness-95">
                    {a}–{b}
                  </button>
                ))}
              </div>
            ) : (
              <p className="text-[12.5px] text-ink-3">Bu gün boşluk yok.</p>
            )}
            {free.data && free.data.busy.length > 0 && (
              <p className="mt-2 text-[12px] text-ink-3">Dolu: {free.data.busy.map((b) => `${b.start}–${b.end} ${b.label}`).join(' · ')}</p>
            )}
          </div>
        )}

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Konu" optional error={err('topic')}><Input value={form.topic ?? ''} onChange={(e) => set('topic', e.target.value)} placeholder="Örn. Parabol soru çözümü" /></Field>
          {isPrivate ? (
            <Field label="Birebir ücreti (₺)" optional error={err('fee')}><Input type="number" min={0} step="0.01" value={form.fee ?? ''} onChange={(e) => set('fee', e.target.value)} /></Field>
          ) : (
            <Field label="Kontenjan (kişi)" optional error={err('capacity')}><Input type="number" min={1} max={60} value={form.capacity ?? 6} onChange={(e) => set('capacity', e.target.value)} /></Field>
          )}
        </div>

        <Field label={isPrivate ? 'Öğrenci' : 'Öğrenciler'} error={err('student_ids')} hint={editing ? 'Öğrenci ekleme/çıkarma kayıt detayından yapılır' : undefined}>
          <StudentPicker selected={students} onChange={setStudents} max={isPrivate ? 1 : Number(form.capacity) || 60} disabled={!!editing} />
        </Field>

        <Field label="Notlar" optional error={err('notes')}><Textarea rows={2} value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} /></Field>

        {!editing && manage && <Switch checked={approve} onChange={setApprove} label="Doğrudan onaylı planla (onay adımını atla)" />}

        {conflicts.length > 0 && (
          <Alert tone="danger" title="Çakışma var — kaydedilmedi">
            <ul className="mt-1 flex flex-col gap-1">{conflicts.map((c, i) => <li key={i} className="flex items-start gap-1.5"><AlertTriangle className="mt-0.5 size-3.5 shrink-0" />{c.message}</li>)}</ul>
          </Alert>
        )}
      </div>
    </Drawer>
  )
}
