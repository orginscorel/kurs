import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch } from '@/components/ui/form'
import type { StudentGoal } from './types'

const subjectOptions = [
  { value: 'TUR', label: 'Türkçe' }, { value: 'MAT', label: 'Matematik' }, { value: 'FEN', label: 'Fen Bilimleri' }, { value: 'SOS', label: 'Sosyal Bilimler' },
  { value: 'FIZ', label: 'Fizik' }, { value: 'KIM', label: 'Kimya' }, { value: 'BIY', label: 'Biyoloji' }, { value: 'ING', label: 'İngilizce' },
  { value: 'INK', label: 'İnkılap Tarihi' }, { value: 'DIN', label: 'Din Kültürü' },
]

type Props = { open: boolean; onClose: () => void; onSaved: () => void; studentId: number; studentName?: string; goal?: StudentGoal | null }

export function GoalFormDrawer({ open, onClose, onSaved, studentId, studentName, goal }: Props) {
  const editing = !!goal
  const qc = useQueryClient()
  const [form, setForm] = useState<Record<string, any>>({})
  const [rows, setRows] = useState<{ code: string; value: string }[]>([])
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!open) return
    setErrors({})
    if (goal) {
      setForm({ university: goal.university ?? '', department: goal.department ?? '', target_rank: goal.target_rank ?? '', target_tyt_net: goal.target_tyt_net ?? '', target_ayt_net: goal.target_ayt_net ?? '', is_active: goal.is_active })
      setRows(Object.entries(goal.subject_targets ?? {}).map(([code, value]) => ({ code, value: String(value) })))
    } else {
      setForm({ university: '', department: '', target_rank: '', target_tyt_net: '', target_ayt_net: '', is_active: true })
      setRows([])
    }
  }, [open, goal])

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const subject_targets: Record<string, number> = {}
      rows.forEach((r) => { if (r.code && r.value !== '') subject_targets[r.code] = Number(r.value) })
      const payload: Record<string, unknown> = { ...form, subject_targets }
      Object.keys(payload).forEach((k) => (payload[k] === '' && delete payload[k]))
      return editing
        ? api.put<{ message: string }>(`/guidance/goals/${goal!.id}`, payload)
        : api.post<{ message: string }>('/guidance/goals', { ...payload, student_id: studentId })
    },
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['guidance', 'goals'] })
      onSaved()
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  const usedCodes = rows.map((r) => r.code)

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={480}
      title={editing ? 'Hedefi düzenle' : 'Yeni hedef'}
      description={studentName}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button></>}
    >
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Hedef üniversite" optional error={err('university')}><Input value={form.university ?? ''} onChange={(e) => set('university', e.target.value)} /></Field>
          <Field label="Hedef bölüm" optional error={err('department')}><Input value={form.department ?? ''} onChange={(e) => set('department', e.target.value)} /></Field>
          <Field label="Hedef başarı sırası" optional error={err('target_rank')}><Input type="number" min={1} value={form.target_rank ?? ''} onChange={(e) => set('target_rank', e.target.value)} /></Field>
          <Field label="Durum">
            <div className="flex h-9 items-center"><Switch checked={form.is_active ?? true} onChange={(v) => set('is_active', v)} label={form.is_active ? 'Aktif' : 'Pasif'} /></div>
          </Field>
          <Field label="Hedef TYT neti" optional hint="0–120" error={err('target_tyt_net')}><Input type="number" min={0} max={120} step="0.25" value={form.target_tyt_net ?? ''} onChange={(e) => set('target_tyt_net', e.target.value)} /></Field>
          <Field label="Hedef AYT neti" optional hint="0–80" error={err('target_ayt_net')}><Input type="number" min={0} max={80} step="0.25" value={form.target_ayt_net ?? ''} onChange={(e) => set('target_ayt_net', e.target.value)} /></Field>
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <h3 className="text-[13px] font-semibold text-ink-2">Ders bazlı net hedefleri <span className="font-normal text-ink-3">(isteğe bağlı)</span></h3>
            <Button size="xs" variant="ghost" icon={<Plus className="size-3.5" />} onClick={() => setRows((r) => [...r, { code: '', value: '' }])}>Ders ekle</Button>
          </div>
          <div className="flex flex-col gap-2">
            {rows.map((r, i) => (
              <div key={i} className="flex items-center gap-2">
                <Select value={r.code} onChange={(e) => setRows((list) => list.map((x, j) => (j === i ? { ...x, code: e.target.value } : x)))} placeholder="Ders seçin" aria-label={`${i + 1}. hedef dersi`} className="min-w-0 flex-1"
                  options={subjectOptions.filter((o) => o.value === r.code || !usedCodes.includes(o.value))} />
                <Input type="number" min={0} step="0.25" value={r.value} onChange={(e) => setRows((list) => list.map((x, j) => (j === i ? { ...x, value: e.target.value } : x)))} placeholder="Hedef net" aria-label={`${i + 1}. ders hedef neti`} className="w-28" />
                <Button size="icon-sm" variant="danger-soft" onClick={() => setRows((list) => list.filter((_, j) => j !== i))} aria-label="Ders hedefini kaldır"><Trash2 className="size-3.5" /></Button>
              </div>
            ))}
            {rows.length === 0 && <p className="text-[12.5px] text-ink-3">Henüz ders bazlı hedef eklenmedi.</p>}
          </div>
        </div>
      </div>
    </Drawer>
  )
}
