import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { todayISO } from '@/lib/format'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Select, Segmented } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'
import type { ExamDetailData, ExamOptions, ExamSectionDef } from './types'

type Form = {
  exam_type_id: string
  name: string
  exam_date: string
  publisher: string
  scope: 'institution' | 'national'
  booklets: string[]
  wrong_penalty_ratio: string
  base_score: string
  academic_term_id: string
}

const emptyForm = (): Form => ({ exam_type_id: '', name: '', exam_date: todayISO(), publisher: '', scope: 'institution', booklets: ['A', 'B'], wrong_penalty_ratio: '0.25', base_score: '100', academic_term_id: '' })

export function ExamFormDrawer({ open, onClose, onSaved, options, exam }: { open: boolean; onClose: () => void; onSaved: (id: number) => void; options?: ExamOptions; exam?: ExamDetailData['exam'] }) {
  const editing = !!exam
  const [form, setForm] = useState<Form>(emptyForm)
  const [sections, setSections] = useState<ExamSectionDef[]>([])
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!open) return
    setErrors({})
    if (exam) {
      setForm({
        exam_type_id: String(exam.type?.id ?? ''), name: exam.name, exam_date: exam.exam_date, publisher: exam.publisher ?? '', scope: exam.scope, booklets: exam.booklets,
        wrong_penalty_ratio: String(exam.wrong_penalty_ratio), base_score: String(exam.base_score), academic_term_id: String(exam.academic_term_id ?? ''),
      })
      setSections(exam.sections.map((s) => ({ ...s })))
    } else {
      const f = emptyForm()
      const term = options?.terms.find((t) => t.is_current)
      f.academic_term_id = term ? String(term.id) : ''
      const first = options?.types[0]
      if (first) applyType(String(first.id), f)
      setForm(f)
      setSections(first ? first.sections.map((s) => ({ ...s })) : [])
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, exam, options])

  function applyType(id: string, target?: Form) {
    const t = options?.types.find((x) => String(x.id) === id)
    if (!t) return
    const patch = { exam_type_id: id, wrong_penalty_ratio: String(Number(t.wrong_penalty_ratio)), base_score: String(Number(t.base_score)) }
    if (target) Object.assign(target, patch)
    else {
      setForm((f) => ({ ...f, ...patch }))
      setSections(t.sections.map((s) => ({ ...s })))
    }
  }

  const set = (key: keyof Form, value: unknown) => setForm((f) => ({ ...f, [key]: value }))
  const err = (key: string) => errors[key]?.[0]
  const totalQuestions = sections.reduce((a, s) => a + Number(s.question_count || 0), 0)
  const sectionsChanged = editing && JSON.stringify(sections.map((s) => [s.code, s.name, Number(s.question_count), Number(s.coefficient)])) !== JSON.stringify((exam?.sections ?? []).map((s) => [s.code, s.name, Number(s.question_count), Number(s.coefficient)]))

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = {
        name: form.name, exam_date: form.exam_date, publisher: form.publisher || null, scope: form.scope, booklets: form.booklets,
        wrong_penalty_ratio: Number(form.wrong_penalty_ratio), base_score: Number(form.base_score), academic_term_id: form.academic_term_id ? Number(form.academic_term_id) : null,
      }
      if (!editing) payload.exam_type_id = Number(form.exam_type_id)
      if (!editing || sectionsChanged) payload.sections = sections.map((s) => ({ code: s.code.toUpperCase(), name: s.name, subject_id: s.subject_id ?? null, subject_code: s.subject_code ?? null, question_count: Number(s.question_count), coefficient: Number(s.coefficient) }))
      return editing ? api.put<{ message: string }>(`/exams/${exam!.id}`, payload).then((r) => ({ ...r, id: exam!.id })) : api.post<{ message: string; id: number }>('/exams', payload)
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

  const updateSection = (i: number, patch: Partial<ExamSectionDef>) => setSections((list) => list.map((s, k) => (k === i ? { ...s, ...patch } : s)))
  const toggleBooklet = (b: string, on: boolean) => set('booklets', on ? [...new Set([...form.booklets, b])].sort() : form.booklets.filter((x) => x !== b))

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={editing ? 'Denemeyi düzenle' : 'Yeni deneme'}
      description={editing ? exam?.name : 'Tür şablonundan bölümler kopyalanır; soru sayısı ve katsayıları düzenleyebilirsiniz.'}
      width={640}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} disabled={!form.name || !form.exam_date || sections.length === 0} onClick={() => save.mutate()}>
            {editing ? 'Kaydet' : 'Oluştur ve cevap anahtarına geç'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Sınav türü" required error={err('exam_type_id')} className="sm:col-span-2">
            <Select value={form.exam_type_id} disabled={editing} onChange={(e) => applyType(e.target.value)} options={(options?.types ?? []).map((t) => ({ value: t.id, label: `${t.name} · ${t.sections.reduce((a, s) => a + s.question_count, 0)} soru` }))} placeholder="Tür seçin" />
          </Field>
          <Field label="Deneme adı" required error={err('name')} className="sm:col-span-2">
            <Input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Örn. Özdebir TYT Türkiye Geneli 6" autoFocus />
          </Field>
          <Field label="Sınav tarihi" required error={err('exam_date')}>
            <Input type="date" value={form.exam_date} onChange={(e) => set('exam_date', e.target.value)} />
          </Field>
          <Field label="Yayınevi" optional hint="Boş bırakılırsa kurum denemesi sayılır" error={err('publisher')}>
            <Input list="exam-publishers" value={form.publisher} onChange={(e) => set('publisher', e.target.value)} placeholder="Özdebir, 3D, Limit…" />
            <datalist id="exam-publishers">{(options?.publishers ?? []).map((p) => <option key={p} value={p} />)}</datalist>
          </Field>
          <Field label="Kapsam" error={err('scope')}>
            <Segmented value={form.scope} onChange={(v) => set('scope', v)} options={[{ value: 'institution', label: 'Kurum içi' }, { value: 'national', label: 'Türkiye geneli' }]} />
          </Field>
          <Field label="Kitapçık türleri" hint="A her zaman vardır; diğer kitapçıkların soru sırası cevap anahtarında eşlenir" error={err('booklets')}>
            <div className="flex h-9 items-center gap-4">
              {['A', 'B', 'C', 'D'].map((b) => (
                <Checkbox key={b} checked={form.booklets.includes(b)} disabled={b === 'A'} onChange={(on) => toggleBooklet(b, on)} label={b} />
              ))}
            </div>
          </Field>
          <Field label="Yanlış cezası oranı" hint="0,25 → 4 yanlış 1 doğruyu götürür; 0 → ceza yok" error={err('wrong_penalty_ratio')}>
            <Input type="number" step="0.01" min="0" max="1" value={form.wrong_penalty_ratio} onChange={(e) => set('wrong_penalty_ratio', e.target.value)} />
          </Field>
          <Field label="Taban puan" hint="Puan hesabında başlangıç değeri" error={err('base_score')}>
            <Input type="number" step="0.001" min="0" value={form.base_score} onChange={(e) => set('base_score', e.target.value)} />
          </Field>
          <Field label="Eğitim dönemi" optional error={err('academic_term_id')} className="sm:col-span-2">
            <Select value={form.academic_term_id} onChange={(e) => set('academic_term_id', e.target.value)} placeholder="Dönem seçin" options={(options?.terms ?? []).map((t) => ({ value: t.id, label: t.name }))} />
          </Field>
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <p className="text-[13px] font-semibold text-ink">Bölümler <span className="text-danger">*</span> <span className="font-normal text-ink-3">· toplam {totalQuestions} soru</span></p>
            <Button size="xs" variant="ghost" icon={<Plus className="size-3.5" />} onClick={() => setSections((l) => [...l, { code: '', name: '', question_count: 10, coefficient: 1 }])}>Bölüm ekle</Button>
          </div>
          {editing && exam && exam.participant_count > 0 && sectionsChanged && (
            <Alert tone="warning" className="mb-2">Bölüm değişikliği mevcut sonuçların yeniden puanlanmasına yol açar.</Alert>
          )}
          {err('sections') && <p className="mb-2 text-xs text-danger">{err('sections')}</p>}
          <div className="overflow-x-auto rounded-[var(--radius-md)] ring-1 ring-line">
            <table className="tbl w-full text-[13px]">
              <thead className="bg-surface-2/60 text-[12px] text-ink-3">
                <tr>
                  <th className="px-2.5 py-2 font-medium text-left">Kod <span className="text-danger">*</span></th>
                  <th className="fill px-2.5 py-2 font-medium text-center">Bölüm adı <span className="text-danger">*</span></th>
                  <th className="px-2.5 py-2 font-medium text-center">Ders</th>
                  <th className="px-2.5 py-2 font-medium whitespace-nowrap text-center">Soru sayısı <span className="text-danger">*</span></th>
                  <th className="px-2.5 py-2 font-medium text-center">Katsayı <span className="text-danger">*</span></th>
                  <th className="w-8 text-center" />
                </tr>
              </thead>
              <tbody>
                {sections.map((s, i) => (
                  <tr key={i} className="border-t border-line">
                    <td className="px-2 py-1.5 text-left"><Input value={s.code} onChange={(e) => updateSection(i, { code: e.target.value.toUpperCase() })} aria-label={`${i + 1}. bölüm kodu`} placeholder="TUR" className="h-8 w-[84px] min-w-[84px] uppercase" invalid={!!err(`sections.${i}.code`)} /></td>
                    <td className="fill px-2 py-1.5 text-center"><Input value={s.name} onChange={(e) => updateSection(i, { name: e.target.value })} aria-label={`${i + 1}. bölüm adı`} placeholder="Türkçe" className="h-8 min-w-[140px]" invalid={!!err(`sections.${i}.name`)} /></td>
                    <td className="px-2 py-1.5 text-center">
                      <Select value={String(s.subject_id ?? options?.subjects.find((x) => x.code === s.subject_code)?.id ?? '')} onChange={(e) => updateSection(i, { subject_id: e.target.value ? Number(e.target.value) : null, subject_code: null })} aria-label={`${i + 1}. bölümün dersi`} className="min-w-[130px] [&_select]:h-8" placeholder="Ders seçin" options={(options?.subjects ?? []).map((x) => ({ value: x.id, label: x.name }))} />
                    </td>
                    <td className="px-2 py-1.5 text-center"><Input type="number" min={1} max={120} value={s.question_count} onChange={(e) => updateSection(i, { question_count: Number(e.target.value) })} aria-label={`${i + 1}. bölüm soru sayısı`} className="h-8 w-[70px] text-right" invalid={!!err(`sections.${i}.question_count`)} /></td>
                    <td className="px-2 py-1.5 text-center"><Input type="number" step="0.0001" min={0} value={s.coefficient} onChange={(e) => updateSection(i, { coefficient: Number(e.target.value) })} aria-label={`${i + 1}. bölüm katsayısı`} className="h-8 w-[84px] text-right" invalid={!!err(`sections.${i}.coefficient`)} /></td>
                    <td className="px-1 py-1.5 text-center">
                      <button className="grid size-7 place-items-center rounded text-ink-3 hover:bg-danger-soft hover:text-danger" onClick={() => setSections((l) => l.filter((_, k) => k !== i))} aria-label="Bölümü kaldır"><Trash2 className="size-3.5" /></button>
                    </td>
                  </tr>
                ))}
                {sections.length === 0 && <tr><td colSpan={6} className="px-3 py-4 text-ink-3 text-left">Bölüm yok. Tür seçin ya da bölüm ekleyin.</td></tr>}
              </tbody>
            </table>
          </div>
          {Object.keys(errors).some((k) => k.startsWith('sections.')) && (
            <ul className="mt-2 space-y-0.5 text-xs text-danger">
              {Object.entries(errors).filter(([k]) => k.startsWith('sections.')).map(([k, v]) => {
                const n = Number(k.split('.')[1]) + 1
                return <li key={k}>{n}. bölüm: {v[0]}</li>
              })}
            </ul>
          )}
          <p className="mt-2 text-[12.5px] text-ink-3">Ders eşlemesi isteğe bağlıdır (konu analizinde kullanılır). Puan = taban puan + Σ(bölüm neti × katsayı). İptal edilen sorular herkese doğru sayılır.</p>
        </div>
      </div>
    </Drawer>
  )
}
