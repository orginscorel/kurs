import { useEffect, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, ApiError } from '@/lib/api'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch } from '@/components/ui/form'
import type { AcademicOptions, ClassGroupRow } from './types'

type Props = { open: boolean; onClose: () => void; onSaved: (id: number) => void; options?: AcademicOptions; group?: ClassGroupRow | null }

export function ClassGroupFormDrawer({ open, onClose, onSaved, options, group }: Props) {
  const editing = !!group
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const optionsRef = useRef(options)
  optionsRef.current = options
  useEffect(() => {
    if (!open) return
    setErrors({})
    if (group) {
      setForm({ name: group.name, academic_term_id: group.academic_term_id, program_id: group.program_id, homeroom_classroom_id: group.homeroom_classroom_id ?? '', advisor_teacher_id: group.advisor_teacher_id ?? '', capacity: group.capacity, is_active: group.is_active })
    } else {
      const o = optionsRef.current
      setForm({ name: '', academic_term_id: o?.terms.find((t) => t.is_current)?.id ?? o?.terms[0]?.id ?? '', program_id: '', homeroom_classroom_id: '', advisor_teacher_id: '', capacity: 24, is_active: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, group])

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, homeroom_classroom_id: form.homeroom_classroom_id || null, advisor_teacher_id: form.advisor_teacher_id || null, capacity: Number(form.capacity) }
      return editing ? api.put<{ message: string }>(`/class-groups/${group!.id}`, payload).then((r) => ({ ...r, id: group!.id })) : api.post<{ message: string; id: number }>('/class-groups', payload)
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

  const room = options?.classrooms.find((c) => c.id === Number(form.homeroom_classroom_id))

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={editing ? 'Sınıfı düzenle' : 'Yeni sınıf'}
      description={editing ? group?.name : 'Dönem, program ve kontenjan; öğrenciler sınıf detayından eklenir.'}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{editing ? 'Kaydet' : 'Sınıfı oluştur'}</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Sınıf adı" required error={err('name')} className="sm:col-span-2" hint="Örn. 12-SAY-A, 8-LGS-B, MEZUN-EA">
          <Input value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} autoFocus />
        </Field>
        <Field label="Eğitim dönemi" required error={err('academic_term_id')}>
          <Select placeholder="Dönem seçin" value={form.academic_term_id ?? ''} onChange={(e) => set('academic_term_id', e.target.value)} options={(options?.terms ?? []).map((t) => ({ value: t.id, label: t.name }))} />
        </Field>
        <Field label="Program" required error={err('program_id')}>
          <Select value={form.program_id ?? ''} onChange={(e) => set('program_id', e.target.value)} placeholder="Program seçin" options={(options?.programs ?? []).filter((p) => p.is_active).map((p) => ({ value: p.id, label: p.name }))} />
        </Field>
        <Field label="Ana derslik" optional error={err('homeroom_classroom_id')} hint={room ? `Derslik kapasitesi ${room.capacity}` : undefined}>
          <Select value={form.homeroom_classroom_id ?? ''} onChange={(e) => { const r = options?.classrooms.find((c) => c.id === Number(e.target.value)); setForm((f) => ({ ...f, homeroom_classroom_id: e.target.value, capacity: r && !editing ? Math.min(Number(f.capacity) || 24, r.capacity) : f.capacity })) }} placeholder="Atanmadı" options={(options?.classrooms ?? []).filter((c) => c.is_active).map((c) => ({ value: c.id, label: `${c.name} · ${c.capacity} kişi` }))} />
        </Field>
        <Field label="Danışman öğretmen" optional error={err('advisor_teacher_id')}>
          <Select value={form.advisor_teacher_id ?? ''} onChange={(e) => set('advisor_teacher_id', e.target.value)} placeholder="Atanmadı" options={(options?.teachers ?? []).filter((t) => t.is_active).map((t) => ({ value: t.id, label: t.name }))} />
        </Field>
        <Field label="Kontenjan (kişi)" required error={err('capacity')}>
          <Input type="number" min={1} max={500} value={form.capacity ?? 24} onChange={(e) => set('capacity', e.target.value)} />
        </Field>
        {editing && (
          <div className="flex items-end pb-2">
            <Switch checked={!!form.is_active} onChange={(v) => set('is_active', v)} label="Aktif sınıf" />
          </div>
        )}
      </div>
    </Drawer>
  )
}
