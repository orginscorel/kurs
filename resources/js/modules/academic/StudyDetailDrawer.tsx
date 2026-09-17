import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, Check, Plus, UserMinus } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, money } from '@/lib/format'
import { useCan } from '@/app/auth'
import { Drawer, ConfirmDialog } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Segmented, Textarea } from '@/components/ui/form'
import { Alert, Avatar, Badge, Skeleton } from '@/components/ui/feedback'
import { DescriptionList } from '@/components/ui/layout'
import { ColorChip, StudentPicker } from './ui'
import { studyStatusTone, type AcademicOptions, type StudyDetailData } from './types'

type Props = { id: number | null; onClose: () => void; onChanged: () => void; options?: AcademicOptions; onEdit?: (s: StudyDetailData) => void }

type Mark = 'present' | 'absent' | 'late'

export function StudyDetailDrawer({ id, onClose, onChanged, onEdit }: Props) {
  const can = useCan()
  const qc = useQueryClient()
  const manage = can('study.manage')
  const { data, isLoading } = useQuery({ queryKey: ['study', id], queryFn: () => api.get<StudyDetailData>(`/study-sessions/${id}`), enabled: !!id })
  const [marks, setMarks] = useState<Record<number, Mark>>({})
  const [ask, setAsk] = useState<null | 'reject' | 'cancel'>(null)
  const [reason, setReason] = useState('')
  const [adding, setAdding] = useState(false)
  const [picked, setPicked] = useState<{ id: number; full_name: string }[]>([])

  useEffect(() => {
    setMarks({})
    setAsk(null)
    setReason('')
    setAdding(false)
    setPicked([])
  }, [id])

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['study'] })
    onChanged()
  }
  const ok = (r: { message: string }) => {
    toast.success(r.message)
    refresh()
  }
  const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')

  const approve = useMutation({ mutationFn: () => api.post<{ message: string }>(`/study-sessions/${id}/approve`), onSuccess: ok, onError: fail })
  const reject = useMutation({ mutationFn: () => api.post<{ message: string }>(`/study-sessions/${id}/reject`, { reason }), onSuccess: (r) => { ok(r); setAsk(null) }, onError: fail })
  const cancel = useMutation({ mutationFn: () => api.post<{ message: string }>(`/study-sessions/${id}/cancel`, { reason }), onSuccess: (r) => { ok(r); setAsk(null) }, onError: fail })
  const attendance = useMutation({ mutationFn: () => api.post<{ message: string }>(`/study-sessions/${id}/attendance`, { marks }), onSuccess: (r) => { ok(r); setMarks({}) }, onError: fail })
  const add = useMutation({ mutationFn: () => api.post<{ message: string }>(`/study-sessions/${id}/students`, { student_ids: picked.map((p) => p.id) }), onSuccess: (r) => { ok(r); setAdding(false); setPicked([]) }, onError: fail })
  const remove = useMutation({ mutationFn: (sid: number) => api.delete<{ message: string }>(`/study-sessions/${id}/students/${sid}`), onSuccess: ok, onError: fail })

  const s = data
  const open = s ? ['requested', 'approved'].includes(s.status) : false
  const canMark = manage && s && ['approved', 'completed'].includes(s.status)
  const pending = Object.keys(marks).length

  return (
    <>
      <Drawer
        open={!!id}
        onClose={onClose}
        width={560}
        title={s ? <span className="flex items-center gap-2">{s.kind_label}{s.subject && <ColorChip color={s.subject.color}>{s.subject.name}</ColorChip>}</span> : 'Kayıt'}
        description={s ? `${date(s.date, 'day')} · ${s.start_time}–${s.end_time} · ${s.teacher?.name ?? ''}` : undefined}
        footer={
          s && (
            <>
              {manage && open && <Button variant="danger-soft" className="mr-auto" icon={<Ban className="size-4" />} onClick={() => { setReason(''); setAsk('cancel') }}>İptal et</Button>}
              {manage && s.status === 'requested' && <Button variant="ghost" onClick={() => { setReason(''); setAsk('reject') }}>Reddet</Button>}
              {manage && s.status === 'requested' && <Button variant="primary" icon={<Check className="size-4" />} loading={approve.isPending} onClick={() => approve.mutate()}>Onayla</Button>}
              {manage && open && onEdit && <Button onClick={() => onEdit(s)}>Düzenle</Button>}
              {canMark && pending > 0 && <Button variant="primary" loading={attendance.isPending} onClick={() => attendance.mutate()}>Katılımı kaydet ({pending})</Button>}
            </>
          )
        }
      >
        {isLoading || !s ? (
          <div className="flex flex-col gap-3"><Skeleton className="h-5 w-1/2" /><Skeleton className="h-24" /><Skeleton className="h-40" /></div>
        ) : (
          <div className="flex flex-col gap-5">
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={studyStatusTone[s.status] ?? 'neutral'} dot>{s.status_label}</Badge>
              <span className="text-[12.5px] text-ink-3">{s.students_count}/{s.capacity} öğrenci</span>
              {s.fee && <span className="text-[12.5px] text-ink-3">· Ücret {money(s.fee)}</span>}
            </div>
            <DescriptionList
              columns={2}
              items={[
                { label: 'Konu', value: s.topic ?? '—' }, { label: 'Derslik', value: s.classroom?.name ?? '—' },
                { label: 'Talep eden', value: s.requested_by ?? '—' }, { label: 'Onaylayan', value: s.approved_by ?? '—' },
                { label: 'Notlar', value: s.notes ? <span className="whitespace-pre-line">{s.notes}</span> : '—' },
              ]}
            />

            <div>
              <div className="mb-2 flex items-center justify-between">
                <p className="text-[12px] font-semibold uppercase tracking-[0.05em] text-ink-3">Öğrenciler</p>
                {manage && open && s.students.length < s.capacity && !adding && <Button size="xs" variant="ghost" icon={<Plus className="size-3.5" />} onClick={() => setAdding(true)}>Öğrenci ekle</Button>}
              </div>
              {adding && (
                <div className="mb-3 rounded-[var(--radius-md)] ring-1 ring-line p-3">
                  <StudentPicker selected={picked} onChange={setPicked} max={s.capacity - s.students.length} />
                  <div className="mt-2 flex justify-end gap-2">
                    <Button size="sm" variant="ghost" onClick={() => { setAdding(false); setPicked([]) }}>Vazgeç</Button>
                    <Button size="sm" variant="primary" disabled={!picked.length} loading={add.isPending} onClick={() => add.mutate()}>Ekle</Button>
                  </div>
                </div>
              )}
              {s.students.length === 0 ? (
                <p className="text-[13px] text-ink-3">Henüz öğrenci eklenmedi.</p>
              ) : (
                <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
                  {s.students.map((st) => {
                    const current = marks[st.id] ?? (st.attendance as Mark | null)
                    return (
                      <li key={st.id} className="flex items-center gap-2.5 px-3 py-2">
                        <Avatar name={st.full_name} src={st.photo_url} size={28} />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-[13px] font-medium">{st.full_name}</p>
                          <p className="text-[12px] text-ink-3">{st.class_group ? `Sınıf: ${st.class_group}` : `Öğrenci no: ${st.student_no}`}</p>
                        </div>
                        {canMark ? (
                          <Segmented
                            size="sm"
                            value={(current ?? '') as Mark | ''}
                            onChange={(v) => v && setMarks((m) => ({ ...m, [st.id]: v as Mark }))}
                            options={[{ value: 'present', label: 'Var', tone: 'text-success' }, { value: 'late', label: 'Geç', tone: 'text-warning' }, { value: 'absent', label: 'Yok', tone: 'text-danger' }]}
                          />
                        ) : st.attendance ? (
                          <Badge tone={st.attendance === 'present' ? 'success' : st.attendance === 'late' ? 'warning' : 'danger'}>{st.attendance === 'present' ? 'Var' : st.attendance === 'late' ? 'Geç' : 'Yok'}</Badge>
                        ) : null}
                        {manage && open && (
                          <Button size="icon-sm" variant="ghost" aria-label="Çıkar" onClick={() => remove.mutate(st.id)}><UserMinus className="size-3.5" /></Button>
                        )}
                      </li>
                    )
                  })}
                </ul>
              )}
            </div>

            {s.status === 'requested' && !manage && <Alert tone="warning">Talep yönetici onayı bekliyor.</Alert>}
          </div>
        )}
      </Drawer>

      <ConfirmDialog
        open={ask !== null}
        onClose={() => setAsk(null)}
        onConfirm={() => (ask === 'reject' ? reject.mutate() : cancel.mutate())}
        loading={reject.isPending || cancel.isPending}
        danger
        title={ask === 'reject' ? 'Talebi reddet' : 'Kaydı iptal et'}
        confirmLabel={ask === 'reject' ? 'Reddet' : 'İptal et'}
      >
        <Field label="Gerekçe" optional><Textarea autoFocus value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
      </ConfirmDialog>
    </>
  )
}
