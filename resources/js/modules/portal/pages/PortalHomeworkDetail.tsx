import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, CheckCircle2, Download, FileText, Lock, Paperclip, Send, Trash2, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useAuth } from '@/app/auth'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Textarea } from '@/components/ui/form'
import { Panel } from '@/components/ui/layout'
import { homeworkTone, usePortal, usePortalQuery, usePortalRole, useVoice } from '../api'
import { usePortalPageTitle } from '@/modules/portal/PortalLayout'

type Doc = { id: number; title: string; mime_type: string; size: number }
type Data = {
  id: number
  homework: { title: string; description: string | null; subject: string | null; subject_color: string | null; teacher: string | null; topic: string | null; class_group: string | null; assigned_at: string | null; due_at: string; is_past_due: boolean }
  status: string; status_label: string; seen_at: string | null; submitted_at: string | null
  answer_text: string | null; score: number | null; teacher_note: string | null; graded_at: string | null
  teacher_files: Doc[]; my_files: Doc[]
  can_submit: boolean; locked_reason: string | null
  max_files: number; max_file_mb: number; allowed_ext: string[]
}

function size(bytes: number) {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`
  return `${(bytes / 1024 / 1024).toLocaleString('tr-TR', { maximumFractionDigits: 1 })} MB`
}

export default function PortalHomeworkDetail() {
  const { id } = useParams()
  const v = useVoice()
  const role = usePortalRole()
  const imp = useAuth((s) => s.me?.impersonation)
  const qc = useQueryClient()
  const extra = usePortalQuery()
  const { data, isLoading, error } = usePortal<Data>(`homework-${id}`, `/portal/homework/${id}`)
  const [answer, setAnswer] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const seenSent = useRef(false)

  useEffect(() => {
    if (data) setAnswer(data.answer_text ?? '')
  }, [data])

  // Öğrenci ödevi açınca "görüldü" (veli ve önizleme hariç)
  useEffect(() => {
    if (!data || seenSent.current || role !== 'student' || imp || data.seen_at) return
    seenSent.current = true
    api.post(`/portal/homework/${data.id}/seen`).then(() => qc.invalidateQueries({ queryKey: ['portal', 'homework'] })).catch(() => {})
  }, [data, role, imp, qc])

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['portal', `homework-${id}`] })
    qc.invalidateQueries({ queryKey: ['portal', 'homework'] })
    qc.invalidateQueries({ queryKey: ['portal', 'summary'] })
  }

  const submit = useMutation({
    mutationFn: () => {
      const fd = new FormData()
      fd.append('answer_text', answer)
      files.forEach((f) => fd.append('files[]', f))
      return api.post<{ message: string }>(`/portal/homework/${id}/submit`, fd)
    },
    onSuccess: (r) => {
      toast.success(r.message)
      setFiles([])
      setErrors({})
      invalidate()
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      } else toast.error('Teslim edilemedi.')
    },
  })

  const removeFile = useMutation({
    mutationFn: (docId: number) => api.delete<{ message: string }>(`/portal/homework/${id}/files/${docId}`),
    onSuccess: (r) => { toast.success(r.message); invalidate() },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Dosya kaldırılamadı.'),
  })

  const download = async (doc: Doc) => {
    try {
      await api.download(`/portal/homework/${id}/files/${doc.id}`, extra, doc.title)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Dosya indirilemedi.')
    }
  }

  usePortalPageTitle(data?.homework?.title)
  if (error) return <EmptyState title="Ödev bulunamadı" description={error instanceof ApiError ? error.message : undefined} action={<ButtonLink to="/portal/odevler">Ödevlere dön</ButtonLink>} />
  if (isLoading || !data) return <div className="flex flex-col gap-3"><Skeleton className="h-20" /><Skeleton className="h-64" /></div>

  const h = data.homework
  const graded = data.score !== null || !!data.graded_at
  const done = data.status === 'submitted' || data.status === 'late'
  const slots = data.max_files - data.my_files.length - files.length
  const dirty = answer.trim() !== (data.answer_text ?? '').trim() || files.length > 0

  const FileChip = ({ d, removable }: { d: Doc; removable?: boolean }) => (
    <li className="flex items-center gap-2 rounded-[var(--radius-sm)] bg-surface-2 px-2.5 py-1.5 text-[14px]">
      <FileText className="size-4 shrink-0 text-primary" />
      <button className="min-w-0 flex-1 break-words text-left hover:underline" onClick={() => download(d)}>{d.title}</button>
      <span className="shrink-0 text-[12.5px] text-ink-3">{size(d.size)}</span>
      <Button variant="ghost" size="icon-sm" aria-label="İndir" onClick={() => download(d)}><Download className="size-3.5" /></Button>
      {removable && (
        <Button variant="ghost" size="icon-sm" aria-label="Dosyayı kaldır" loading={removeFile.isPending && removeFile.variables === d.id} onClick={() => removeFile.mutate(d.id)}><Trash2 className="size-3.5" /></Button>
      )}
    </li>
  )

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <div>
        <Link to="/portal/odevler" className="hidden items-center gap-1 text-[12.5px] font-medium text-ink-3 hover:text-ink md:inline-flex"><ArrowLeft className="size-3.5" /> Ödevler</Link>
        <div className="mt-1 flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <h1 className="text-[20px] font-semibold tracking-[-0.02em] sm:text-[22px]">{h.title}</h1>
            <p className="text-[14px] text-ink-3">{[h.subject, h.topic, h.teacher].filter(Boolean).join(' · ')}</p>
          </div>
          <Badge tone={homeworkTone[data.status] ?? 'neutral'} dot>{data.status_label}</Badge>
        </div>
        <p className={cn('mt-1 text-[12.5px]', !h.is_past_due && !done ? 'font-medium text-warning' : 'text-ink-3')}>
          Son teslim: {dateTime(h.due_at)}{!h.is_past_due ? ` (${relative(h.due_at)})` : ''}
          {data.submitted_at ? ` · teslim edildi ${dateTime(data.submitted_at)}` : ''}
        </p>
      </div>

      {graded && (
        <section className="rounded-[var(--radius-lg)] bg-success-soft px-4 py-3 ring-1 ring-success/25">
          <div className="flex items-center gap-3">
            <CheckCircle2 className="size-6 shrink-0 text-success" />
            <div className="min-w-0 flex-1">
              <p className="text-[14px] font-medium text-success">Öğretmen değerlendirdi{data.graded_at ? ` · ${dateTime(data.graded_at)}` : ''}</p>
              {data.teacher_note && <p className="mt-0.5 whitespace-pre-line text-[14.5px] text-ink">{data.teacher_note}</p>}
            </div>
            {data.score !== null && (
              <div className="shrink-0 text-center">
                <p className="text-[28px] font-semibold leading-none text-success tabular">{data.score}</p>
                <p className="text-[12.5px] text-ink-3">/ 100</p>
              </div>
            )}
          </div>
        </section>
      )}

      <div className="grid gap-4 lg:grid-cols-5">
        <Panel className="lg:col-span-2" title="Ödev">
          {h.description ? <p className="whitespace-pre-line text-[14.5px] text-ink-2">{h.description}</p> : <p className="text-[14px] text-ink-3">Açıklama yok.</p>}
          {data.teacher_files.length > 0 && (
            <>
              <p className="mt-3 text-[12.5px] font-medium text-ink-3">Öğretmenin eklediği dosyalar</p>
              <ul className="mt-1.5 flex flex-col gap-1.5">{data.teacher_files.map((d) => <FileChip key={d.id} d={d} />)}</ul>
            </>
          )}
        </Panel>

        <Panel className="lg:col-span-3" title={v('Teslimim', 'Öğrencinin teslimi')}>
          {data.can_submit ? (
            <form onSubmit={(e) => { e.preventDefault(); setErrors({}); submit.mutate() }} className="flex flex-col gap-3">
              {h.is_past_due && !done && <Alert tone="warning">Son teslim tarihi geçti; teslimin “geç teslim” olarak kaydedilir.</Alert>}
              <Field label="Cevabın / açıklaman" error={errors.answer_text?.[0]} hint="Yazılı cevap ya da dosya/fotoğraftan en az biri gerekli.">
                <Textarea rows={6} maxLength={5000} value={answer} placeholder="Örn. 1-20 arası soruları çözdüm, 14. soruda takıldım…" onChange={(e) => setAnswer(e.target.value)} />
              </Field>
              {data.my_files.length > 0 && <ul className="flex flex-col gap-1.5">{data.my_files.map((d) => <FileChip key={d.id} d={d} removable />)}</ul>}
              {files.length > 0 && (
                <ul className="flex flex-col gap-1.5">
                  {files.map((f, i) => (
                    <li key={i} className="flex items-center gap-2 rounded-[var(--radius-sm)] bg-primary-soft/60 px-2.5 py-1.5 text-[14px]">
                      <Paperclip className="size-4 shrink-0 text-primary" />
                      <span className="min-w-0 flex-1 break-words">{f.name}</span>
                      <span className="shrink-0 text-[12.5px] text-ink-3">{size(f.size)}</span>
                      <Button variant="ghost" size="icon-sm" aria-label="Kaldır" onClick={() => setFiles((c) => c.filter((_, j) => j !== i))}><X className="size-3.5" /></Button>
                    </li>
                  ))}
                </ul>
              )}
              {(errors.files?.[0] || errors['files.0']?.[0]) && <p className="text-[12.5px] text-danger">{errors.files?.[0] ?? errors['files.0']?.[0]}</p>}
              <div className="flex flex-wrap items-center justify-between gap-2">
                {slots > 0 ? (
                  <label className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-[var(--radius-sm)] border border-dashed border-line-strong px-3 text-[14px] text-ink-2 hover:bg-surface-2">
                    <Paperclip className="size-4" /> Dosya / fotoğraf ekle
                    <input type="file" multiple className="sr-only" accept={data.allowed_ext.map((x) => `.${x}`).join(',')}
                      onChange={(e) => { const picked = Array.from(e.target.files ?? []); setFiles((c) => [...c, ...picked].slice(0, Math.max(0, data.max_files - data.my_files.length))); e.target.value = '' }} />
                  </label>
                ) : <span className="text-[12.5px] text-ink-3">En fazla {data.max_files} dosya eklenebilir.</span>}
                <Button type="submit" variant="primary" icon={<Send className="size-4" />} loading={submit.isPending} disabled={!dirty && done}>
                  {done ? 'Teslimi güncelle' : 'Teslim et'}
                </Button>
              </div>
              <p className="text-[12.5px] text-ink-3">Her dosya en fazla {data.max_file_mb} MB · {data.allowed_ext.join(', ')}. Öğretmen değerlendirene kadar teslimini değiştirebilirsin.</p>
            </form>
          ) : (
            <div className="flex flex-col gap-3">
              {data.answer_text ? <p className="whitespace-pre-line rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 text-[14.5px]">{data.answer_text}</p> : null}
              {data.my_files.length > 0 && <ul className="flex flex-col gap-1.5">{data.my_files.map((d) => <FileChip key={d.id} d={d} />)}</ul>}
              {!data.answer_text && data.my_files.length === 0 && <p className="text-[14px] text-ink-3">{done ? 'Teslim içeriği yok (öğretmen işaretledi).' : v('Henüz teslim etmedin.', 'Henüz teslim edilmedi.')}</p>}
              {data.locked_reason ? (
                <p className="inline-flex items-center gap-1.5 text-[12.5px] text-ink-3"><Lock className="size-3.5" /> {data.locked_reason}</p>
              ) : role === 'guardian' ? (
                <p className="text-[12.5px] text-ink-3">Teslimi öğrenci kendi hesabından yapar.</p>
              ) : imp ? (
                <p className="text-[12.5px] text-ink-3">Önizlemede teslim yapılamaz.</p>
              ) : null}
            </div>
          )}
        </Panel>
      </div>
    </div>
  )
}
