import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, ApiError } from '@/lib/api'
import { Modal } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/form'
import type { CoachingOptions } from './types'

type Props = {
  open: boolean
  onClose: () => void
  onSaved: () => void
  studentId: number
  studentName?: string
  currentCoachId?: number | null
}

export function AssignCoachModal({ open, onClose, onSaved, studentId, studentName, currentCoachId }: Props) {
  const qc = useQueryClient()
  const [coachId, setCoachId] = useState<string>('')
  const [note, setNote] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const options = useQuery({ queryKey: ['coaching', 'options'], queryFn: () => api.get<CoachingOptions>('/coaching/assignments/options'), staleTime: 5 * 60_000, enabled: open })

  useEffect(() => {
    if (!open) return
    setCoachId(currentCoachId ? String(currentCoachId) : '')
    setNote('')
    setErrors({})
  }, [open, currentCoachId])

  const save = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/coaching/students/${studentId}/coach`, { coach_id: Number(coachId), note: note || null }),
    onSuccess: (res) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['coaching'] })
      onSaved()
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  return (
    <Modal open={open} onClose={onClose} title={currentCoachId ? 'Koç değiştir' : 'Koç ata'} description={studentName}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} disabled={!coachId} onClick={() => save.mutate()}>Kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label="Koç" required error={errors.coach_id?.[0]}>
          <Select value={coachId} onChange={(e) => setCoachId(e.target.value)} placeholder="Koç seçin"
            options={(options.data?.coaches ?? []).map((c) => ({ value: c.id, label: c.name }))} />
        </Field>
        <Field label="Not" optional error={errors.note?.[0]}>
          <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Atama ile ilgili kısa not (opsiyonel)" />
        </Field>
      </div>
    </Modal>
  )
}
