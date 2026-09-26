import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Plus } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch } from '@/components/ui/form'
import type { AcademicOptions, ClassGroupRow } from './types'

type Props = { open: boolean; onClose: () => void; onSaved: (id: number) => void; options?: AcademicOptions; group?: ClassGroupRow | null }

export function ClassGroupFormDrawer({ open, onClose, onSaved, options, group }: Props) {
  const editing = !!group
  const qc = useQueryClient()
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  // Boş listede oracıkta derslik ekleme (veriler sıfırlanınca "Ana derslik" seçilemiyordu)
  const [newRoom, setNewRoom] = useState<{ open: boolean; name: string; capacity: string }>({ open: false, name: '', capacity: '24' })
  const createRoom = useMutation({
    mutationFn: () => api.post<{ id: number }>('/classrooms', { name: newRoom.name.trim(), kind: 'classroom', capacity: Number(newRoom.capacity) || 24 }),
    onSuccess: async (r) => {
      await qc.invalidateQueries({ queryKey: ['academic', 'options'] })
      setForm((f) => ({ ...f, homeroom_classroom_id: String(r.id) }))
      setNewRoom({ open: false, name: '', capacity: '24' })
      toast.success('Derslik eklendi ve ana derslik olarak seçildi.')
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Derslik eklenemedi.'),
  })
  // Program da boş olabilir (sıfırlama sonrası). Oracıkta program tanımlama kısayolu.
  const [newProg, setNewProg] = useState<{ open: boolean; name: string; kind: string }>({ open: false, name: '', kind: 'group' })
  const progCode = (name: string) => {
    const tr: Record<string, string> = { ç: 'C', Ç: 'C', ğ: 'G', Ğ: 'G', ı: 'I', İ: 'I', ö: 'O', Ö: 'O', ş: 'S', Ş: 'S', ü: 'U', Ü: 'U' }
    return (name.replace(/[çÇğĞıİöÖşŞüÜ]/g, (c) => tr[c] ?? c).toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 20)) || 'PRG'
  }
  const createProg = useMutation({
    mutationFn: () => api.post<{ id: number }>('/programs', { name: newProg.name.trim(), code: progCode(newProg.name), kind: newProg.kind }),
    onSuccess: async (r) => {
      await qc.invalidateQueries({ queryKey: ['academic', 'options'] })
      setForm((f) => ({ ...f, program_id: String(r.id) }))
      setNewProg({ open: false, name: '', kind: 'group' })
      toast.success('Program eklendi ve seçildi.')
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Program eklenemedi.'),
  })

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
        <Field label="Program" required error={err('program_id')} hint={(options?.programs ?? []).filter((p) => p.is_active).length === 0 ? 'Program = sınıfın bağlı olduğu kurs türü (örn. TYT-AYT Hazırlık, LGS). Henüz yok — "Yeni" ile ekleyin.' : 'Sınıfın bağlı olduğu eğitim programı (kurs türü)'}>
          <div className="flex items-center gap-2">
            <Select className="flex-1" value={form.program_id ?? ''} onChange={(e) => set('program_id', e.target.value)} placeholder="Program seçin" options={(options?.programs ?? []).filter((p) => p.is_active).map((p) => ({ value: p.id, label: p.name }))} />
            <Button type="button" size="sm" variant="ghost" icon={<Plus className="size-3.5" />} onClick={() => setNewProg((n) => ({ ...n, open: !n.open }))}>Yeni</Button>
          </div>
          {newProg.open && (
            <div className="mt-2 flex flex-wrap items-end gap-2 rounded-[var(--radius-sm)] bg-surface-2 p-2">
              <Input className="min-w-[160px] flex-1" placeholder="Program adı (örn. TYT-AYT Hazırlık)" value={newProg.name} onChange={(e) => setNewProg((n) => ({ ...n, name: e.target.value }))} />
              <Select className="w-32" value={newProg.kind} onChange={(e) => setNewProg((n) => ({ ...n, kind: e.target.value }))} options={[{ value: 'group', label: 'Grup dersi' }, { value: 'private', label: 'Birebir' }, { value: 'study', label: 'Etüt' }]} />
              <Button type="button" size="sm" variant="primary" loading={createProg.isPending} disabled={newProg.name.trim().length < 2} onClick={() => createProg.mutate()}>Ekle</Button>
            </div>
          )}
        </Field>
        <Field label="Ana derslik" optional error={err('homeroom_classroom_id')} hint={room ? `Derslik kapasitesi ${room.capacity}` : ((options?.classrooms ?? []).filter((c) => c.is_active).length === 0 ? 'Henüz derslik yok — "Yeni derslik" ile ekleyin' : undefined)}>
          <div className="flex items-center gap-2">
            <Select className="flex-1" value={form.homeroom_classroom_id ?? ''} onChange={(e) => { const r = options?.classrooms.find((c) => c.id === Number(e.target.value)); setForm((f) => ({ ...f, homeroom_classroom_id: e.target.value, capacity: r && !editing ? Math.min(Number(f.capacity) || 24, r.capacity) : f.capacity })) }} placeholder="Atanmadı" options={(options?.classrooms ?? []).filter((c) => c.is_active).map((c) => ({ value: c.id, label: `${c.name} · ${c.capacity} kişi` }))} />
            <Button type="button" size="sm" variant="ghost" icon={<Plus className="size-3.5" />} onClick={() => setNewRoom((n) => ({ ...n, open: !n.open }))}>Yeni</Button>
          </div>
          {newRoom.open && (
            <div className="mt-2 flex flex-wrap items-end gap-2 rounded-[var(--radius-sm)] bg-surface-2 p-2">
              <Input className="min-w-[140px] flex-1" placeholder="Derslik adı (örn. A-101)" value={newRoom.name} onChange={(e) => setNewRoom((n) => ({ ...n, name: e.target.value }))} />
              <Input type="number" min={1} max={1000} className="w-24" placeholder="Kapasite" value={newRoom.capacity} onChange={(e) => setNewRoom((n) => ({ ...n, capacity: e.target.value }))} />
              <Button type="button" size="sm" variant="primary" loading={createRoom.isPending} disabled={newRoom.name.trim().length < 1} onClick={() => createRoom.mutate()}>Ekle</Button>
            </div>
          )}
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
