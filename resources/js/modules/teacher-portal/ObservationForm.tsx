import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Minus, Plus } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { Button } from '@/components/ui/Button'
import { Field, Select, Segmented, Switch, Textarea } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { TP, useTeacherQuery, type ObservationOptions } from './api'

type Kind = 'positive' | 'improve' | 'note'

const DEFAULT_OPTIONS: ObservationOptions = {
  kinds: { positive: 'Olumlu', improve: 'Gelişmeli', note: 'Not' },
  categories: {},
}

/**
 * Öğrenciye gözlem notu / davranış puanı ekleme. Öğrenci verilmezse öğretmenin öğrencilerinden seçilir.
 * Puan yönü türle tutarlı: olumlu → 0..+5, gelişmeli → -5..0, not → 0.
 */
export function ObservationForm({
  studentId,
  studentName,
  options,
  onClose,
}: {
  studentId?: number
  studentName?: string
  options?: ObservationOptions
  onClose: () => void
}) {
  const qc = useQueryClient()
  const opts = options && Object.keys(options.categories).length ? options : null
  const needStudent = !studentId
  const students = useTeacherQuery<{ data: { id: number; full_name: string; student_no: string; class_groups: string[] }[] }>(['students', 'all'], '/students', undefined, needStudent)
  const optQuery = useTeacherQuery<{ options: ObservationOptions }>(['observations'], '/observations', undefined, !opts)
  const o = opts ?? optQuery.data?.options ?? DEFAULT_OPTIONS
  const categoryKeys = Object.keys(o.categories)

  const [form, setForm] = useState({ student_id: studentId ? String(studentId) : '', kind: 'positive' as Kind, category: '', points: 1, body: '', visible_to_guardian: true, visible_to_student: true })
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!form.category && categoryKeys.length) setForm((f) => ({ ...f, category: categoryKeys[0]! }))
  }, [categoryKeys, form.category])

  const setKind = (kind: Kind) => setForm((f) => ({ ...f, kind, points: kind === 'positive' ? 1 : kind === 'improve' ? -1 : 0 }))
  const min = form.kind === 'improve' ? -5 : 0
  const max = form.kind === 'positive' ? 5 : 0
  const step = (d: number) => setForm((f) => ({ ...f, points: Math.max(min, Math.min(max, f.points + d)) }))

  const save = useMutation({
    mutationFn: () => api.post<{ message: string }>(`${TP}/students/${form.student_id}/observations`, {
      kind: form.kind, category: form.category, points: form.points, body: form.body,
      visible_to_guardian: form.visible_to_guardian, visible_to_student: form.visible_to_student,
    }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['teacher-portal'] })
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      } else toast.error('Gözlem kaydedilemedi.')
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setErrors({})
    if (!form.student_id) {
      setErrors({ student_id: ['Öğrenci seçin.'] })
      return
    }
    save.mutate()
  }
  const err = (k: string) => errors[k]?.[0] ?? null
  const loading = (needStudent && students.isLoading) || (!opts && optQuery.isLoading)

  return (
    <Drawer
      open
      onClose={onClose}
      title="Gözlem notu ekle"
      description={studentName ? `${studentName} için ders içi gözlem ve davranış puanı` : 'Öğrencinizle ilgili gözlem ve davranış puanı'}
      footer={<>
        <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
        <Button variant="primary" type="submit" form="obs-form" loading={save.isPending}>Kaydet</Button>
      </>}
    >
      {loading ? <Skeleton className="h-64" /> : needStudent && !students.data?.data.length ? (
        <EmptyState compact title="Öğrenciniz bulunamadı" description="Sınıflarınızda öğrenci olmadığı için gözlem eklenemez." />
      ) : (
        <form id="obs-form" onSubmit={submit} className="flex flex-col gap-4">
          {needStudent && (
            <Field label="Öğrenci" required error={err('student_id')}>
              <Select value={form.student_id} placeholder="Öğrenci seçin"
                options={(students.data?.data ?? []).map((s) => ({ value: s.id, label: `${s.full_name}${s.class_groups.length ? ` · ${s.class_groups.join(', ')}` : ''}` }))}
                onChange={(e) => setForm((f) => ({ ...f, student_id: e.target.value }))} />
            </Field>
          )}
          <Field label="Gözlem türü" required>
            <Segmented value={form.kind} onChange={setKind} options={(Object.keys(o.kinds) as Kind[]).map((k) => ({ value: k, label: o.kinds[k] }))} />
          </Field>
          <Field label="Alan" required error={err('category')}>
            <Select value={form.category} options={categoryKeys.map((k) => ({ value: k, label: o.categories[k]! }))} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))} />
          </Field>
          {form.kind !== 'note' && (
            <Field label="Puan" optional hint={form.kind === 'positive' ? 'Olumlu davranış için 1–5 artı puan.' : 'Gelişmesi gereken davranış için 1–5 eksi puan.'} error={err('points')}>
              <div className="flex items-center gap-2">
                <Button variant="outline" size="icon" onClick={() => step(-1)} disabled={form.points <= min} aria-label="Azalt"><Minus className="size-4" /></Button>
                <span className={cn('w-14 text-center text-[20px] font-semibold tabular', form.points > 0 ? 'text-success' : form.points < 0 ? 'text-danger' : 'text-ink-3')}>
                  {form.points > 0 ? `+${form.points}` : form.points}
                </span>
                <Button variant="outline" size="icon" onClick={() => step(1)} disabled={form.points >= max} aria-label="Artır"><Plus className="size-4" /></Button>
              </div>
            </Field>
          )}
          <Field label="Not" required error={err('body')}>
            <Textarea rows={4} maxLength={1000} value={form.body} placeholder={form.kind === 'positive' ? 'Örn. Derse aktif katıldı, arkadaşlarına soru çözümünde yardım etti.' : 'Örn. Son iki derste ödevini getirmedi.'}
              onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} />
          </Field>
          <div className="flex flex-col gap-2.5 rounded-[var(--radius-md)] bg-surface-2 px-3 py-3">
            <Switch checked={form.visible_to_student} onChange={(v) => setForm((f) => ({ ...f, visible_to_student: v }))} label="Öğrenci portalında görünsün" />
            <Switch checked={form.visible_to_guardian} onChange={(v) => setForm((f) => ({ ...f, visible_to_guardian: v }))} label="Veli portalında görünsün" />
            <p className="text-[12.5px] text-ink-3">Kapalıysa not yalnız öğretmenler ve yönetim tarafından görülür.</p>
          </div>
        </form>
      )}
    </Drawer>
  )
}
