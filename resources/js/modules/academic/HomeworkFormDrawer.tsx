import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Paperclip, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useCan } from '@/app/auth'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { StudentPicker } from './ui'
import { fmtBytes, type AcademicOptions, type HomeworkDetailData, type SubjectDetailData } from './types'

type Props = { open: boolean; onClose: () => void; onSaved: (id: number) => void; options?: AcademicOptions; editing?: HomeworkDetailData['homework'] | null; presetGroupId?: number }

const ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.txt,.zip'

export function HomeworkFormDrawer({ open, onClose, onSaved, options, editing, presetGroupId }: Props) {
  const can = useCan()
  const [form, setForm] = useState<Record<string, any>>({})
  const [target, setTarget] = useState<'class' | 'students'>('class')
  const [students, setStudents] = useState<{ id: number; full_name: string }[]>([])
  const [files, setFiles] = useState<File[]>([])
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const fileInput = useRef<HTMLInputElement>(null)

  const optionsRef = useRef(options)
  optionsRef.current = options
  const presetRef = useRef(presetGroupId)
  presetRef.current = presetGroupId
  useEffect(() => {
    if (!open) return
    setErrors({})
    setFiles([])
    if (editing) {
      setForm({ subject_id: editing.subject_id, class_group_id: editing.class_group_id ?? '', topic_id: editing.topic_id ?? '', title: editing.title, description: editing.description ?? '', due_at: editing.due_at.slice(0, 16), teacher_id: editing.teacher_id })
      setTarget(editing.class_group_id ? 'class' : 'students')
    } else {
      const due = new Date()
      due.setDate(due.getDate() + 7)
      due.setHours(23, 59, 0, 0)
      const local = new Date(due.getTime() - due.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
      setForm({ subject_id: '', class_group_id: presetRef.current ?? '', topic_id: '', title: '', description: '', due_at: local, teacher_id: optionsRef.current?.my_teacher_id ?? '' })
      setTarget('class')
      setStudents([])
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, editing])

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]
  const subjectId = Number(form.subject_id) || 0
  const topics = useQuery({ queryKey: ['subject', subjectId, 'topics'], queryFn: () => api.get<SubjectDetailData>(`/subjects/${subjectId}`), enabled: open && subjectId > 0 && can('academic.view') })
  const teachers = useMemo(() => (options?.teachers ?? []).filter((t) => t.is_active && (!subjectId || t.subject_ids.includes(subjectId))), [options, subjectId])

  const save = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        subject_id: Number(form.subject_id) || undefined, topic_id: form.topic_id ? Number(form.topic_id) : null, title: form.title, description: form.description || null,
        due_at: form.due_at ? form.due_at.replace('T', ' ') : undefined, teacher_id: form.teacher_id ? Number(form.teacher_id) : undefined,
        class_group_id: target === 'class' && form.class_group_id ? Number(form.class_group_id) : null,
        student_ids: target === 'students' ? students.map((s) => s.id) : [],
      }
      let id: number
      let message: string
      if (editing) {
        delete payload.class_group_id
        delete payload.student_ids
        delete payload.teacher_id
        const r = await api.put<{ message: string }>(`/homework/${editing.id}`, payload)
        id = editing.id
        message = r.message
      } else {
        const r = await api.post<{ message: string; id: number }>('/homework', payload)
        id = r.id
        message = r.message
      }
      let failed = 0
      for (const f of files) {
        const fd = new FormData()
        fd.append('file', f)
        try {
          await api.post(`/homework/${id}/documents`, fd)
        } catch (e) {
          failed++
          toast.error(`${f.name}: ${e instanceof ApiError ? e.firstError() : 'yüklenemedi'}`)
        }
      }
      return { id, message: failed ? `${message} (${failed} dosya yüklenemedi)` : message }
    },
    onSuccess: (r) => {
      toast.success(r.message)
      onClose()
      onSaved(r.id)
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      }
    },
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={600}
      title={editing ? 'Ödevi düzenle' : 'Ödev ver'}
      description={editing ? editing.title : 'Sınıfın aktif öğrencilerine ya da seçtiğiniz öğrencilere teslim satırı açılır.'}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{editing ? 'Kaydet' : 'Ödevi ver'}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Ders" required error={err('subject_id')}>
            <Select value={form.subject_id ?? ''} onChange={(e) => setForm((f) => ({ ...f, subject_id: e.target.value, topic_id: '' }))} placeholder="Ders seçin" options={(options?.subjects ?? []).filter((s) => s.is_active).map((s) => ({ value: s.id, label: s.name }))} />
          </Field>
          <Field label="Konu" optional hint={subjectId && topics.data && topics.data.topics.length === 0 ? 'Bu derste konu tanımlı değil' : undefined}>
            <Select value={form.topic_id ?? ''} onChange={(e) => set('topic_id', e.target.value)} placeholder={subjectId ? 'Konu seçin' : 'Önce ders seçin'} options={(topics.data?.topics ?? []).map((t) => ({ value: t.id, label: `${t.parent_id ? '— ' : ''}${t.name}` }))} disabled={!subjectId} />
          </Field>
          {!editing && !options?.my_teacher_id && (
            <Field label="Veren öğretmen" required error={err('teacher_id')} className="sm:col-span-2">
              <Select value={form.teacher_id ?? ''} onChange={(e) => set('teacher_id', e.target.value)} placeholder="Öğretmen seçin" options={teachers.map((t) => ({ value: t.id, label: t.name }))} />
            </Field>
          )}
        </div>

        {!editing && (
          <div>
            <p className="mb-1.5 text-[12.5px] font-medium text-ink-2">Kime verilecek? <span className="text-danger">*</span></p>
            <Segmented value={target} onChange={setTarget} options={[{ value: 'class', label: 'Bir sınıfın tamamı' }, { value: 'students', label: 'Seçtiğim öğrenciler' }]} className="mb-3" />
            {target === 'class' ? (
              <Field label="Sınıf" required error={err('class_group_id')}>
                <Select value={form.class_group_id ?? ''} onChange={(e) => set('class_group_id', e.target.value)} placeholder="Sınıf seçin" options={(options?.class_groups ?? []).filter((g) => g.is_active).map((g) => ({ value: g.id, label: `${g.name}${g.program ? ` · ${g.program}` : ''}` }))} />
              </Field>
            ) : (
              <Field label="Öğrenciler" required error={err('student_ids')}>
                <StudentPicker selected={students} onChange={setStudents} />
              </Field>
            )}
          </div>
        )}

        <Field label="Ödev başlığı" required error={err('title')}><Input value={form.title ?? ''} onChange={(e) => set('title', e.target.value)} placeholder="Örn. Problemler test 1-3" /></Field>
        <Field label="Açıklama" optional error={err('description')}><Textarea rows={3} value={form.description ?? ''} onChange={(e) => set('description', e.target.value)} placeholder="Sayfa aralığı, teslim biçimi, notlar" /></Field>
        <Field label="Son teslim tarihi ve saati" required error={err('due_at')}><Input type="datetime-local" value={form.due_at ?? ''} onChange={(e) => set('due_at', e.target.value)} /></Field>

        <Field label="Dosya ekleri" optional hint="PDF, Office, görsel veya zip · dosya başına en fazla 15 MB">
          <div className="flex flex-col gap-2">
            <input ref={fileInput} type="file" multiple accept={ACCEPT} className="hidden" onChange={(e) => { setFiles((f) => [...f, ...Array.from(e.target.files ?? [])]); e.target.value = '' }} />
            <Button size="sm" icon={<Paperclip className="size-4" />} onClick={() => fileInput.current?.click()}>Dosya seç</Button>
            {files.map((f, i) => (
              <div key={`${f.name}-${i}`} className="flex items-center justify-between rounded-[var(--radius-sm)] bg-surface-2 px-2.5 py-1.5 text-[12.5px]">
                <span className="truncate">{f.name} <span className="text-ink-3">· {fmtBytes(f.size)}</span></span>
                <button type="button" className="text-ink-3 hover:text-ink" onClick={() => setFiles((l) => l.filter((_, j) => j !== i))} aria-label={`${f.name} dosyasını kaldır`}><X className="size-3.5" /></button>
              </div>
            ))}
          </div>
        </Field>
      </div>
    </Drawer>
  )
}
