import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { todayISO } from '@/lib/format'
import { useDebounced } from '@/hooks/useListState'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'
import { subjectOptions, teacherOptions } from '../hooks'
import { toMinutes, toTime, WEEKDAYS, type AcademicOptions, type Conflict, type WeekItem } from '../types'

type Preset = { class_group_id?: number; teacher_id?: number; classroom_id?: number; weekday?: number; starts_at?: string }

type Props = {
  open: boolean
  onClose: () => void
  onSaved: () => void
  options?: AcademicOptions
  item?: WeekItem | null
  preset?: Preset
}

export function ScheduleFormDrawer({ open, onClose, onSaved, options, item, preset }: Props) {
  const editing = !!item
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [conflicts, setConflicts] = useState<Conflict[]>([])

  // preset/options her render'da yeni nesne olabilir; formu yalnız açılışta doldurmak için ref üzerinden okunur
  const presetRef = useRef(preset)
  presetRef.current = preset
  const optionsRef = useRef(options)
  optionsRef.current = options
  useEffect(() => {
    if (!open) return
    setErrors({})
    setConflicts([])
    if (item) {
      setForm({
        class_group_id: item.class_group.id, subject_id: item.subject.id, teacher_id: item.teacher.id, classroom_id: item.classroom.id,
        weekday: item.weekday, starts_at: item.starts_at, ends_at: item.ends_at, valid_from: item.valid_from, valid_until: item.valid_until ?? '',
      })
    } else {
      const preset = presetRef.current
      const start = preset?.starts_at ?? '16:30'
      const group = optionsRef.current?.class_groups.find((g) => g.id === preset?.class_group_id)
      setForm({
        class_group_id: preset?.class_group_id ?? '', subject_id: '', teacher_id: preset?.teacher_id ?? '', classroom_id: preset?.classroom_id ?? group?.homeroom_classroom_id ?? '',
        weekday: preset?.weekday ?? 1, starts_at: start, ends_at: toTime(toMinutes(start) + 50), valid_from: todayISO(), valid_until: '',
      })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, item])

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]
  const group = options?.class_groups.find((g) => g.id === Number(form.class_group_id))
  const subjects = useMemo(() => subjectOptions(options, group?.program_id), [options, group?.program_id])
  const teachers = useMemo(() => teacherOptions(options, Number(form.subject_id) || null), [options, form.subject_id])

  // Canlı çakışma önizlemesi
  const checkKey = useDebounced(JSON.stringify([form.class_group_id, form.teacher_id, form.classroom_id, form.weekday, form.starts_at, form.ends_at, form.valid_from, form.valid_until]), 350)
  const ready = !!(form.class_group_id && form.teacher_id && form.classroom_id && form.weekday && form.starts_at && form.ends_at)
  const check = useQuery({
    queryKey: ['schedule', 'check', checkKey, item?.id],
    queryFn: () => api.post<{ conflicts: Conflict[] }>('/schedule/check', { ...payload(form), ignore_id: item?.id }),
    enabled: open && ready,
  })
  const liveConflicts = check.data?.conflicts ?? []

  const save = useMutation({
    mutationFn: () => (editing ? api.put<{ message: string }>(`/schedule/${item!.id}`, payload(form)) : api.post<{ message: string }>('/schedule', payload(form))),
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

  const shown = conflicts.length ? conflicts : liveConflicts

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={560}
      title={editing ? 'Dersi düzenle' : 'Programa ders ekle'}
      description={editing ? `${item?.class_group.name} · ${item?.subject.name}` : 'Haftalık tekrar eden ders şablonu. Oturumlar otomatik üretilir.'}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} disabled={shown.length > 0} onClick={() => save.mutate()}>{editing ? 'Kaydet' : 'Programa ekle'}</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Sınıf" required error={err('class_group_id')} className="sm:col-span-2">
          <Select value={form.class_group_id ?? ''} onChange={(e) => { const g = options?.class_groups.find((x) => x.id === Number(e.target.value)); setForm((f) => ({ ...f, class_group_id: e.target.value, classroom_id: f.classroom_id || g?.homeroom_classroom_id || '' })) }} placeholder="Seçin" options={(options?.class_groups ?? []).filter((g) => g.is_active).map((g) => ({ value: g.id, label: `${g.name}${g.program ? ` · ${g.program}` : ''}` }))} disabled={editing} />
        </Field>
        <Field label="Ders" required error={err('subject_id')}>
          <Select value={form.subject_id ?? ''} onChange={(e) => set('subject_id', e.target.value)} placeholder="Seçin" options={subjects} />
        </Field>
        <Field label="Öğretmen" required error={err('teacher_id')} hint={form.subject_id ? 'Branşı uyanlar önce listelenir' : undefined}>
          <Select value={form.teacher_id ?? ''} onChange={(e) => set('teacher_id', e.target.value)} placeholder="Seçin" options={teachers} />
        </Field>
        <Field label="Derslik" required error={err('classroom_id')}>
          <Select value={form.classroom_id ?? ''} onChange={(e) => set('classroom_id', e.target.value)} placeholder="Seçin" options={(options?.classrooms ?? []).filter((c) => c.is_active).map((c) => ({ value: c.id, label: `${c.name} · ${c.capacity} kişi` }))} />
        </Field>
        <Field label="Gün" required error={err('weekday')}>
          <Select value={form.weekday ?? 1} onChange={(e) => set('weekday', Number(e.target.value))} options={WEEKDAYS.slice(1).map((d, i) => ({ value: i + 1, label: d }))} />
        </Field>
        <Field label="Başlangıç" required error={err('starts_at')}>
          <Input type="time" step={300} value={form.starts_at ?? ''} onChange={(e) => { const s = e.target.value; setForm((f) => ({ ...f, starts_at: s, ends_at: s && (!f.ends_at || toMinutes(f.ends_at) <= toMinutes(s)) ? toTime(toMinutes(s) + 50) : f.ends_at })) }} />
        </Field>
        <Field label="Bitiş" required error={err('ends_at')}>
          <Input type="time" step={300} value={form.ends_at ?? ''} onChange={(e) => set('ends_at', e.target.value)} />
        </Field>
        <Field label="Geçerlilik başlangıcı" optional error={err('valid_from')} hint="Bu tarihten itibaren oturum üretilir">
          <Input type="date" value={form.valid_from ?? ''} onChange={(e) => set('valid_from', e.target.value)} />
        </Field>
        <Field label="Geçerlilik bitişi" optional error={err('valid_until')} hint="Boş bırakılırsa dönem boyunca sürer">
          <Input type="date" value={form.valid_until ?? ''} onChange={(e) => set('valid_until', e.target.value)} />
        </Field>
      </div>

      {ready && check.isFetching && !shown.length && <p className="mt-4 text-[12.5px] text-ink-3">Çakışma denetleniyor…</p>}
      {ready && !check.isFetching && !shown.length && !conflicts.length && (
        <Alert tone="success" className="mt-4">Bu saatte öğretmen, derslik ve sınıf uygun.</Alert>
      )}
      {shown.length > 0 && (
        <Alert tone="danger" className="mt-4" title="Çakışma var — kaydedilmedi">
          <ul className="mt-1 flex flex-col gap-1">
            {shown.map((c, i) => (
              <li key={i} className="flex items-start gap-1.5"><AlertTriangle className="mt-0.5 size-3.5 shrink-0" />{c.message}</li>
            ))}
          </ul>
        </Alert>
      )}
      {editing && (
        <p className="mt-4 text-[12.5px] text-ink-3">Değişiklik yalnızca gelecekteki, yoklaması alınmamış oturumlara uygulanır; geçmiş dersler korunur.</p>
      )}
    </Drawer>
  )
}

function payload(form: Record<string, any>) {
  const p: Record<string, unknown> = {
    class_group_id: Number(form.class_group_id) || undefined, subject_id: Number(form.subject_id) || undefined, teacher_id: Number(form.teacher_id) || undefined,
    classroom_id: Number(form.classroom_id) || undefined, weekday: Number(form.weekday) || undefined, starts_at: form.starts_at || undefined, ends_at: form.ends_at || undefined,
    valid_from: form.valid_from || undefined, valid_until: form.valid_until || null,
  }
  Object.keys(p).forEach((k) => p[k] === undefined && delete p[k])
  return p
}
