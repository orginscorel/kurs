import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Search } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { todayISO } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Select, Textarea } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'
import type { MeetingOptions, MeetingRow } from './types'

type StudentHit = { id: number; full_name: string; student_no: string }

function StudentPicker({ value, label, onSelect }: { value: number | ''; label: string; onSelect: (id: number, label: string) => void }) {
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
      <Input
        value={value ? label : q}
        onChange={(e) => { setQ(e.target.value); setOpen(true) }}
        onFocus={() => setOpen(true)}
        placeholder="Öğrenci adı ya da numarası (en az 2 harf)"
        aria-label="Öğrenci ara"
        leading={<Search />}
      />
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
  meetingId?: number | null
  studentId?: number
  studentName?: string
  options?: MeetingOptions
}

function toLocalInput(iso?: string | null): string {
  if (!iso) return ''
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Istanbul', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date(iso))
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '00'
  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`
}

const scale = [1, 2, 3, 4, 5]

export function MeetingFormDrawer({ open, onClose, onSaved, meetingId, studentId, studentName, options }: Props) {
  const can = useCan()
  const editing = !!meetingId
  const qc = useQueryClient()
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [pickedStudentLabel, setPickedStudentLabel] = useState('')

  const detail = useQuery({
    queryKey: ['guidance', 'meetings', 'detail', meetingId],
    queryFn: () => api.get<{ data: MeetingRow }>(`/guidance/meetings/${meetingId}`).then((r) => r.data),
    enabled: !!meetingId && open,
  })

  useEffect(() => {
    if (!open) return
    setErrors({})
    if (editing && detail.data) {
      const d = detail.data
      setForm({
        student_id: d.student?.id, counselor_id: d.counselor?.id ?? '', met_at: toLocalInput(d.met_at), kind: d.kind, summary: d.summary ?? '', goal: d.goal ?? '',
        motivation: d.motivation ?? '', study_discipline: d.study_discipline ?? '', visibility: d.visibility ?? 'staff', visible_to_student: !!d.visible_to_student,
        next_meeting_on: d.next_meeting_on?.slice(0, 10) ?? '', private_note: d.private_note ?? '',
      })
    } else if (!editing) {
      setForm({ student_id: studentId ?? '', counselor_id: '', met_at: toLocalInput(new Date().toISOString()), kind: 'individual', visibility: 'staff', visible_to_student: false, summary: '', next_meeting_on: '' })
      setPickedStudentLabel(studentName ?? '')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, editing, detail.data, studentId])

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form }
      Object.keys(payload).forEach((k) => (payload[k] === '' && delete payload[k]))
      payload.visible_to_student = payload.visibility !== 'counselor' && !!payload.visible_to_student
      return editing ? api.put<{ message: string }>(`/guidance/meetings/${meetingId}`, payload) : api.post<{ message: string }>('/guidance/meetings', payload)
    },
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['guidance'] })
      qc.invalidateQueries({ queryKey: ['risk'] })
      onSaved()
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={540}
      title={editing ? 'Görüşmeyi düzenle' : 'Rehberlik görüşmesi kaydet'}
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
            <StudentPicker value={form.student_id ?? ''} label={pickedStudentLabel} onSelect={(id, label) => { set('student_id', id); setPickedStudentLabel(label) }} />
          </Field>
        )}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Görüşme tarihi ve saati" required error={err('met_at')}><Input type="datetime-local" value={form.met_at ?? ''} onChange={(e) => set('met_at', e.target.value)} /></Field>
          <Field label="Görüşme türü" required error={err('kind')}>
            <Select value={form.kind ?? ''} onChange={(e) => set('kind', e.target.value)} placeholder="Tür seçin" options={Object.entries(options?.kinds ?? {}).map(([value, label]) => ({ value, label }))} />
          </Field>
        </div>
        <Field label="Görüşen (rehber öğretmen)" optional hint="Boş bırakılırsa görüşmeyi kaydeden kişi atanır" error={err('counselor_id')}>
          <Select value={form.counselor_id ?? ''} onChange={(e) => set('counselor_id', e.target.value)} placeholder="Görüşmeyi yapan öğretmen / rehber"
            options={(options?.counselors ?? []).map((c) => ({ value: c.id, label: c.name }))} />
        </Field>
        <Field label="Görüşme özeti" required error={err('summary')}><Textarea rows={4} value={form.summary ?? ''} onChange={(e) => set('summary', e.target.value)} placeholder="Konuşulanlar, gözlemler ve öneriler" /></Field>
        <Field label="Belirlenen hedef" optional error={err('goal')}><Input value={form.goal ?? ''} onChange={(e) => set('goal', e.target.value)} placeholder="Örn. Günlük 100 soru" /></Field>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Motivasyon düzeyi" optional hint="1 çok düşük · 5 çok yüksek" error={err('motivation')}>
            <Select value={form.motivation ?? ''} onChange={(e) => set('motivation', e.target.value)} placeholder="Seçilmedi" options={scale.map((n) => ({ value: n, label: String(n) }))} />
          </Field>
          <Field label="Çalışma düzeni" optional hint="1 çok düzensiz · 5 çok düzenli" error={err('study_discipline')}>
            <Select value={form.study_discipline ?? ''} onChange={(e) => set('study_discipline', e.target.value)} placeholder="Seçilmedi" options={scale.map((n) => ({ value: n, label: String(n) }))} />
          </Field>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Kayıt kimlere açık?" required error={err('visibility')}>
            <Select value={form.visibility ?? 'staff'} onChange={(e) => set('visibility', e.target.value)} options={Object.entries(options?.visibilities ?? { counselor: 'Yalnız rehber', staff: 'Personel', guardian: 'Veli de görebilir' }).map(([value, label]) => ({ value, label }))} />
          </Field>
          <Field label="Sonraki görüşme tarihi" optional error={err('next_meeting_on')}><Input type="date" min={todayISO()} value={form.next_meeting_on ?? ''} onChange={(e) => set('next_meeting_on', e.target.value)} /></Field>
        </div>

        <div className="-mt-1 flex flex-col gap-1">
          <Checkbox
            checked={form.visibility !== 'counselor' && !!form.visible_to_student}
            disabled={form.visibility === 'counselor'}
            onChange={(v) => set('visible_to_student', v)}
            label="Öğrenci portalında göster"
          />
          <p className="pl-6 text-[12.5px] text-ink-3">
            {form.visibility === 'counselor'
              ? '“Yalnız rehber” kayıtları öğrenciye gösterilemez.'
              : 'İşaretlenirse görüşme özeti ve hedef öğrencinin portalında görünür. Veli portalı “Veli de görebilir” seçeneğini kullanır. Gizli not hiçbir portalda görünmez.'}
          </p>
        </div>

        {can('guidance.private') ? (
          <Field label="Gizli not" optional error={err('private_note')} hint="Yalnızca gizli not yetkisi olanlar görür"><Textarea rows={3} value={form.private_note ?? ''} onChange={(e) => set('private_note', e.target.value)} /></Field>
        ) : (
          <Alert tone="neutral">Gizli not eklemek için ek yetki gerekir.</Alert>
        )}
      </div>
    </Drawer>
  )
}
