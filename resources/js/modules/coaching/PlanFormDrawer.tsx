import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/form'
import { targetKinds, type PlanRow } from './types'

type ItemDraft = { subject: string; target_kind: string; target: string }

type Props = {
  open: boolean
  onClose: () => void
  onSaved: () => void
  studentId: number
  studentName?: string
  plan?: PlanRow | null
  defaultWeekStart?: string
}

/** ISO tarihini içinde bulunduğu haftanın pazartesisine çeker. */
function mondayOf(iso: string): string {
  const d = new Date(iso + 'T00:00:00')
  const day = (d.getDay() + 6) % 7 // pazartesi = 0
  d.setDate(d.getDate() - day)
  return d.toISOString().slice(0, 10)
}

function thisMonday(): string {
  return mondayOf(new Date().toISOString().slice(0, 10))
}

export function PlanFormDrawer({ open, onClose, onSaved, studentId, studentName, plan, defaultWeekStart }: Props) {
  const editing = !!plan
  const qc = useQueryClient()
  const [weekStart, setWeekStart] = useState('')
  const [note, setNote] = useState('')
  const [items, setItems] = useState<ItemDraft[]>([])
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!open) return
    setErrors({})
    if (plan) {
      setWeekStart(plan.week_start)
      setNote(plan.note ?? '')
      setItems(plan.items.map((i) => ({ subject: i.subject, target_kind: i.target_kind, target: i.target != null ? String(i.target) : '' })))
    } else {
      setWeekStart(defaultWeekStart ?? thisMonday())
      setNote('')
      setItems([{ subject: '', target_kind: 'questions', target: '' }])
    }
  }, [open, plan, defaultWeekStart])

  const err = (k: string) => errors[k]?.[0]
  const setItem = (idx: number, patch: Partial<ItemDraft>) => setItems((prev) => prev.map((it, i) => (i === idx ? { ...it, ...patch } : it)))
  const addItem = () => setItems((prev) => [...prev, { subject: '', target_kind: 'questions', target: '' }])
  const removeItem = (idx: number) => setItems((prev) => prev.filter((_, i) => i !== idx))

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, any> = {
        student_id: studentId,
        week_start: mondayOf(weekStart || thisMonday()),
        note: note || null,
        items: items
          .filter((i) => i.subject.trim())
          .map((i) => ({ subject: i.subject.trim(), target_kind: i.target_kind, target: i.target === '' ? null : Number(i.target) })),
      }
      return editing
        ? api.put<{ message: string }>(`/coaching/plans/${plan!.id}`, payload)
        : api.post<{ message: string }>('/coaching/plans', payload)
    },
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['coaching'] })
      onSaved()
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  return (
    <Drawer open={open} onClose={onClose} width={560}
      title={editing ? 'Haftalık planı düzenle' : 'Haftalık plan oluştur'}
      description={studentName}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Planı kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label="Hafta başı (pazartesi)" required hint="Seçilen tarihin haftası kullanılır" error={err('week_start')}>
          <Input type="date" value={weekStart} onChange={(e) => setWeekStart(e.target.value)} />
        </Field>
        <Field label="Not" optional error={err('note')}>
          <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Bu hafta için genel hedef ya da açıklama" />
        </Field>

        <div className="flex flex-col gap-2">
          <div className="flex items-center justify-between">
            <span className="text-[13px] font-medium text-ink">Plan kalemleri</span>
            <Button size="xs" variant="ghost" icon={<Plus className="size-3.5" />} onClick={addItem}>Kalem ekle</Button>
          </div>
          {items.length === 0 && <p className="text-[12.5px] text-ink-3">Henüz kalem yok. "Kalem ekle" ile ders ve hedef girin.</p>}
          {items.map((it, idx) => (
            <div key={idx} className="grid grid-cols-[1fr_auto_auto_auto] items-center gap-2">
              <Input value={it.subject} onChange={(e) => setItem(idx, { subject: e.target.value })} placeholder="Ders / konu (örn. Matematik)" aria-label="Ders" />
              <Select value={it.target_kind} onChange={(e) => setItem(idx, { target_kind: e.target.value })} aria-label="Hedef türü"
                options={Object.entries(targetKinds).map(([value, label]) => ({ value, label }))} className="w-[92px]" />
              <Input type="number" min={0} value={it.target} onChange={(e) => setItem(idx, { target: e.target.value })} placeholder="Hedef" aria-label="Hedef" className="w-[84px]" />
              <button type="button" onClick={() => removeItem(idx)} className="grid size-8 place-items-center text-ink-3 hover:text-danger" aria-label="Kalemi sil">
                <Trash2 className="size-4" />
              </button>
            </div>
          ))}
        </div>
      </div>
    </Drawer>
  )
}
