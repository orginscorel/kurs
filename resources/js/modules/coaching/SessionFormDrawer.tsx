import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Search } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { todayISO } from '@/lib/format'
import { useDebounced } from '@/hooks/useListState'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/form'
import type { CoachingOptions, SessionRow } from './types'

type StudentHit = { id: number; full_name: string; student_no: string }

function StudentPicker({ label, onSelect }: { label: string; onSelect: (id: number, label: string) => void }) {
  const [q, setQ] = useState('')
  const debounced = useDebounced(q, 250)
  const [open, setOpen] = useState(false)
  const results = useQuery({
    queryKey: ['students', 'quick-search', debounced],
    queryFn: () => api.get<Paginated<StudentHit>>('/students', { q: debounced, per_page: 8 }),
    enabled: debounced.length >= 2,
  })

  return (
    <div className="relative">
      <Input value={label || q} onChange={(e) => { setQ(e.target.value); setOpen(true) }} onFocus={() => setOpen(true)}
        placeholder="Öğrenci adı ya da numarası (en az 2 harf)" aria-label="Öğrenci ara" leading={<Search />} />
      {open && debounced.length >= 2 && (
        <div className="absolute z-20 mt-1 w-full max-h-56 overflow-y-auto scroll-thin rounded-[var(--radius-md)] bg-surface ring-1 ring-line shadow-[var(--shadow-pop)]">
          {(results.data?.data ?? []).length === 0 ? (
            <p className="px-3 py-2.5 text-[13px] text-ink-3">Sonuç yok</p>
          ) : (
            results.data!.data.map((s) => (
              <button key={s.id} type="button" className="flex w-full items-center justify-between px-3 py-2 text-left text-[13px] hover:bg-surface-2"
                onClick={() => { onSelect(s.id, s.full_name); setQ(''); setOpen(false) }}>
                <span className="text-ink">{s.full_name}</span>
                <span className="text-ink-3 tabular">No: {s.student_no}</span>
              </button>
            ))
          )}
        </div>
      )}
    </div>
  )
}

type Props = {
  open: boolean
  onClose: () => void
  onSaved: () => void
  sessionId?: number | null
  studentId?: number
  studentName?: string
  options?: CoachingOptions
}

function toLocalInput(iso?: string | null): string {
  if (!iso) return ''
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Istanbul', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date(iso))
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '00'
  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`
}

const scale = [1, 2, 3, 4, 5]

export function SessionFormDrawer({ open, onClose, onSaved, sessionId, studentId, studentName, options }: Props) {
  const editing = !!sessionId
  const qc = useQueryClient()
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [pickedLabel, setPickedLabel] = useState('')

  const detail = useQuery({
    queryKey: ['coaching', 'sessions', 'detail', sessionId],
    queryFn: () => api.get<{ data: SessionRow }>(`/coaching/sessions/${sessionId}`).then((r) => r.data),
    enabled: !!sessionId && open,
  })

  useEffect(() => {
    if (!open) return
    setErrors({})
    if (editing && detail.data) {
      const d = detail.data
      setForm({
        student_id: d.student?.id, coach_id: d.coach?.id ?? '', held_at: toLocalInput(d.held_at), topics: d.topics ?? '',
        focus: d.focus ?? '', motivation: d.motivation ?? '', action_items: d.action_items ?? '', next_session_on: d.next_session_on?.slice(0, 10) ?? '',
      })
      setPickedLabel(detail.data.student?.full_name ?? '')
    } else if (!editing) {
      setForm({ student_id: studentId ?? '', coach_id: '', held_at: toLocalInput(new Date().toISOString()), topics: '', next_session_on: '' })
      setPickedLabel(studentName ?? '')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, editing, detail.data, studentId])

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form }
      Object.keys(payload).forEach((k) => (payload[k] === '' && delete payload[k]))
      return editing ? api.put<{ message: string }>(`/coaching/sessions/${sessionId}`, payload) : api.post<{ message: string }>('/coaching/sessions', payload)
    },
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['coaching'] })
      onSaved()
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  return (
    <Drawer open={open} onClose={onClose} width={520}
      title={editing ? 'Görüşmeyi düzenle' : 'Koçluk görüşmesi kaydet'}
      description={studentName ?? (editing ? detail.data?.student?.full_name : undefined)}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{editing ? 'Kaydet' : 'Görüşmeyi kaydet'}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {!studentId && !editing && (
          <Field label="Öğrenci" required error={err('student_id')}>
            <StudentPicker label={pickedLabel} onSelect={(id, label) => { set('student_id', id); setPickedLabel(label) }} />
          </Field>
        )}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Görüşme tarihi ve saati" required error={err('held_at')}><Input type="datetime-local" value={form.held_at ?? ''} onChange={(e) => set('held_at', e.target.value)} /></Field>
          <Field label="Koç" optional hint="Boş bırakılırsa kaydeden kişi atanır" error={err('coach_id')}>
            <Select value={form.coach_id ?? ''} onChange={(e) => set('coach_id', e.target.value)} placeholder="Görüşen koç"
              options={(options?.coaches ?? []).map((c) => ({ value: c.id, label: c.name }))} />
          </Field>
        </div>
        <Field label="Konuşulanlar" optional hint="Akademik durum, hedefler, gözlemler" error={err('topics')}>
          <Textarea rows={4} value={form.topics ?? ''} onChange={(e) => set('topics', e.target.value)} placeholder="Bu görüşmede konuşulanlar" />
        </Field>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Odak / çalışma düzeni" optional hint="1 çok düşük · 5 çok yüksek" error={err('focus')}>
            <Select value={form.focus ?? ''} onChange={(e) => set('focus', e.target.value)} placeholder="Seçilmedi" options={scale.map((n) => ({ value: n, label: String(n) }))} />
          </Field>
          <Field label="Motivasyon" optional hint="1 çok düşük · 5 çok yüksek" error={err('motivation')}>
            <Select value={form.motivation ?? ''} onChange={(e) => set('motivation', e.target.value)} placeholder="Seçilmedi" options={scale.map((n) => ({ value: n, label: String(n) }))} />
          </Field>
        </div>
        <Field label="Yapılacaklar" optional hint="Öğrenciye verilen görevler" error={err('action_items')}>
          <Textarea rows={3} value={form.action_items ?? ''} onChange={(e) => set('action_items', e.target.value)} placeholder="Örn. Günde 2 saat matematik, hafta sonu deneme" />
        </Field>
        <Field label="Sonraki görüşme tarihi" optional error={err('next_session_on')}>
          <Input type="date" min={todayISO()} value={form.next_session_on ?? ''} onChange={(e) => set('next_session_on', e.target.value)} />
        </Field>
      </div>
    </Drawer>
  )
}
