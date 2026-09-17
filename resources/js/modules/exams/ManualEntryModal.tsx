import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, ApiError, type Paginated } from '@/lib/api'
import { useDebounced } from '@/hooks/useListState'
import { Modal } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented } from '@/components/ui/form'
import { Avatar } from '@/components/ui/feedback'

type StudentRow = { id: number; student_no: string; full_name: string; photo_url: string | null }

/** Tek öğrenci için elle sonuç girişi: öğrenci ara → kitapçık → bölüm başına cevap dizisi. */
export function ManualEntryModal({ open, examId, sections, booklets, onClose, onSaved }: { open: boolean; examId: number; sections: { code: string; name: string; question_count: number }[]; booklets: string[]; onClose: () => void; onSaved: () => void }) {
  const [q, setQ] = useState('')
  const debounced = useDebounced(q, 250)
  const [student, setStudent] = useState<StudentRow | null>(null)
  const [booklet, setBooklet] = useState('A')
  const [answers, setAnswers] = useState<Record<string, string>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (open) { setQ(''); setStudent(null); setBooklet(booklets[0] ?? 'A'); setAnswers({}); setErrors({}) }
  }, [open, booklets])

  const search = useQuery({ queryKey: ['students', 'search', debounced], queryFn: () => api.get<Paginated<StudentRow>>('/students', { q: debounced, per_page: 8 }), enabled: open && debounced.length >= 2 && !student })

  const save = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/exams/${examId}/results/manual`, { student_id: student!.id, booklet, answers }),
    onSuccess: (r) => { toast.success(r.message); onSaved(); onClose() },
    onError: (e) => { setErrors(e instanceof ApiError ? e.errors : {}); toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.') },
  })

  const clean = (v: string) => v.toUpperCase().replace(/[^ABCDE ]/g, ' ')

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title="Elle sonuç girişi"
      description="Optik dışı (kâğıt) okumalar için. Cevaplar kitapçık sırasıyla girilir; boş için boşluk ya da nokta kullanın."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" disabled={!student} loading={save.isPending} onClick={() => save.mutate()}>Puanla ve kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <Field label="Öğrenci" required error={errors.student_id?.[0]}>
          {student ? (
            <div className="flex items-center gap-2.5 rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2">
              <Avatar name={student.full_name} src={student.photo_url} size={28} />
              <span className="flex-1 text-[13.5px] font-medium">{student.full_name} <span className="text-ink-3 font-normal">· Öğrenci no: {student.student_no}</span></span>
              <Button size="xs" variant="ghost" onClick={() => setStudent(null)}>Değiştir</Button>
            </div>
          ) : (
            <div className="relative">
              <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Ad ya da öğrenci no yazın (en az 2 harf)" autoFocus />
              {search.data && search.data.data.length > 0 && (
                <ul className="absolute z-10 mt-1 w-full max-h-56 overflow-y-auto rounded-[var(--radius-md)] bg-surface p-1 ring-1 ring-line shadow-[var(--shadow-pop)]">
                  {search.data.data.map((s) => (
                    <li key={s.id}>
                      <button type="button" className="flex w-full items-center gap-2.5 rounded px-2 py-1.5 text-left text-[13px] hover:bg-surface-2" onClick={() => setStudent(s)}>
                        <Avatar name={s.full_name} src={s.photo_url} size={24} />
                        <span className="flex-1 truncate">{s.full_name}</span>
                        <span className="text-ink-3 tabular">{s.student_no}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </Field>
        {booklets.length > 1 && (
          <Field label="Kitapçık" required error={errors.booklet?.[0]}>
            <Segmented value={booklet} onChange={setBooklet} options={booklets.map((b) => ({ value: b, label: b }))} />
          </Field>
        )}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {sections.map((s) => (
            <Field key={s.code} label={`${s.name} (${s.question_count} soru)`} optional error={errors[`answers.${s.code}`]?.[0]} hint={`${(answers[s.code] ?? '').replace(/ /g, '').length} soru işaretlendi; boş bırakılan soru boş sayılır`}>
              <Input value={answers[s.code] ?? ''} onChange={(e) => setAnswers((a) => ({ ...a, [s.code]: clean(e.target.value).slice(0, s.question_count) }))} placeholder={'A'.repeat(Math.min(5, s.question_count)) + '…'} className="font-mono tracking-[0.15em] uppercase" spellCheck={false} aria-label={`${s.name} cevapları`} />
            </Field>
          ))}
        </div>
      </div>
    </Modal>
  )
}
