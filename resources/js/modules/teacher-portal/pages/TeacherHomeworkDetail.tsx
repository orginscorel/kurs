import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, CalendarClock, Download, FileText, Paperclip, Pencil, Save, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Alert, Avatar, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { Panel } from '@/components/ui/layout'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import { MiniStat } from '@/modules/portal/ui'
import { fileSize, homeworkTone, TP, useTeacherCan, useTeacherQuery, type DocRow, type HomeworkRow } from '../api'
import { usePortalPageTitle } from '@/modules/portal/PortalLayout'

type Sub = {
  id: number; student_id: number; full_name: string; student_no: string; photo_url: string | null; status: string; status_label: string
  seen_at: string | null; submitted_at: string | null; answer_text: string | null; score: number | null; teacher_note: string | null; graded_at: string | null
  files: DocRow[]
}
type Data = {
  homework: HomeworkRow & { description: string | null; topic: string | null; topic_id: number | null }
  documents: DocRow[]
  submissions: Sub[]
  avg_score: number | null
  statuses: Record<string, string>
  can_manage: boolean
}
type Edit = { status: string; score: string; teacher_note: string }
type Filter = 'all' | 'to_grade' | 'submitted' | 'missing'

export default function TeacherHomeworkDetail() {
  const { id } = useParams()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const can = useTeacherCan()
  const { data, isLoading, error } = useTeacherQuery<Data>(['homework', 'detail', id], `/homework/${id}`)
  const [edits, setEdits] = useState<Record<number, Edit>>({})
  const [filter, setFilter] = useState<Filter>('all')
  const [open, setOpen] = useState<number | null>(null)
  const [editing, setEditing] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const editable = can('homework')

  useEffect(() => {
    if (!data) return
    setEdits(Object.fromEntries(data.submissions.map((s) => [s.student_id, { status: s.status, score: s.score === null ? '' : String(s.score), teacher_note: s.teacher_note ?? '' }])))
  }, [data])

  const dirty = useMemo(() => {
    if (!data) return []
    return data.submissions.filter((s) => {
      const e = edits[s.student_id]
      return e && (e.status !== s.status || e.score !== (s.score === null ? '' : String(s.score)) || e.teacher_note !== (s.teacher_note ?? ''))
    })
  }, [data, edits])

  const invalidate = () => qc.invalidateQueries({ queryKey: ['teacher-portal'] })

  const save = useMutation({
    mutationFn: () => api.put<{ message: string }>(`${TP}/homework/${id}/submissions`, {
      rows: dirty.map((s) => {
        const e = edits[s.student_id]!
        return { student_id: s.student_id, status: e.status, score: e.score === '' ? null : Number(e.score), teacher_note: e.teacher_note.trim() || null }
      }),
    }),
    onSuccess: (r) => { toast.success(r.message); invalidate() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const del = useMutation({
    mutationFn: () => api.delete<{ message: string }>(`${TP}/homework/${id}`),
    onSuccess: (r) => { toast.success(r.message); invalidate(); navigate('/ogretmen/odevler') },
    onError: (e) => { toast.error(e instanceof ApiError ? e.message : 'Silinemedi.'); setDeleting(false) },
  })

  const upload = useMutation({
    mutationFn: (file: File) => { const fd = new FormData(); fd.append('file', file); return api.post<{ message: string }>(`${TP}/homework/${id}/documents`, fd) },
    onSuccess: (r) => { toast.success(r.message); invalidate() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Dosya yüklenemedi.'),
  })

  const download = async (doc: DocRow) => {
    try {
      await api.download(`${TP}/homework/${id}/documents/${doc.id}`, undefined, doc.title)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Dosya indirilemedi.')
    }
  }

  usePortalPageTitle(data?.homework?.title)
  if (error) return <EmptyState title="Ödev bulunamadı" description={error instanceof ApiError ? error.message : undefined} action={<Link to="/ogretmen/odevler" className="text-[14px] font-medium text-primary">Ödevlere dön</Link>} />
  if (isLoading || !data) return <div className="flex flex-col gap-3"><Skeleton className="h-20" /><Skeleton className="h-96" /></div>

  const h = data.homework
  const subs = data.submissions.filter((s) => {
    const graded = s.score !== null || !!s.graded_at
    if (filter === 'to_grade') return ['submitted', 'late'].includes(s.status) && !graded
    if (filter === 'submitted') return ['submitted', 'late'].includes(s.status)
    if (filter === 'missing') return !['submitted', 'late'].includes(s.status)
    return true
  })
  const set = (sid: number, patch: Partial<Edit>) => setEdits((cur) => ({ ...cur, [sid]: { ...cur[sid]!, ...patch } }))
  const openSub = data.submissions.find((s) => s.id === open) ?? null

  return (
    <div className="animate-fade-in flex flex-col gap-4 pb-16">
      <div>
        <Link to="/ogretmen/odevler" className="hidden items-center gap-1 text-[12.5px] font-medium text-ink-3 hover:text-ink md:inline-flex"><ArrowLeft className="size-3.5" /> Ödevler</Link>
        <div className="mt-1 flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h1 className="text-[20px] font-semibold tracking-[-0.02em] sm:text-[22px]">{h.title}</h1>
            <p className="text-[14px] text-ink-3">{[h.class_group, h.subject, h.topic].filter(Boolean).join(' · ')}</p>
          </div>
          {editable && (
            <div className="flex gap-1.5">
              <Button size="sm" icon={<Pencil className="size-4" />} onClick={() => setEditing(true)}>Düzenle</Button>
              <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => setDeleting(true)}>Sil</Button>
            </div>
          )}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <MiniStat label="Teslim" value={`${h.done_count}/${h.total_count}`} sub={`%${h.completion}`} />
        <MiniStat label="Kontrol bekleyen" value={h.to_grade_count} tone={h.to_grade_count ? 'warning' : undefined} />
        <MiniStat label="Yapılmadı" value={h.missed_count} tone={h.missed_count ? 'danger' : undefined} />
        <MiniStat label="Ortalama puan" value={data.avg_score ?? '—'} />
      </div>

      <Panel>
        <div className="flex flex-col gap-3">
          <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[14px]">
            <span className="inline-flex items-center gap-1.5"><CalendarClock className="size-4 text-ink-3" /> Son teslim <b>{dateTime(h.due_at)}</b></span>
            <span className="text-ink-3">{h.is_open ? relative(h.due_at) : 'süresi doldu'}</span>
          </p>
          {data.homework.description ? <p className="whitespace-pre-line text-[14.5px] text-ink-2">{data.homework.description}</p> : <p className="text-[14px] text-ink-3">Açıklama yok.</p>}
          <div className="flex flex-wrap items-center gap-2">
            {data.documents.map((d) => (
              <button key={d.id} onClick={() => download(d)} className="inline-flex max-w-full items-center gap-1.5 rounded-[var(--radius-sm)] bg-surface-2 px-2.5 py-1.5 text-[12.5px] hover:bg-surface-3">
                <FileText className="size-3.5 shrink-0 text-ink-3" /> <span className="break-words">{d.title}</span> <span className="text-ink-3">{fileSize(d.size)}</span>
              </button>
            ))}
            {editable && (
              <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-[var(--radius-sm)] border border-dashed border-line-strong px-2.5 py-1.5 text-[12.5px] text-ink-2 hover:bg-surface-2">
                <Paperclip className="size-3.5" /> {upload.isPending ? 'Yükleniyor…' : 'Dosya ekle'}
                <input type="file" className="sr-only" onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f); e.target.value = '' }} />
              </label>
            )}
          </div>
        </div>
      </Panel>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <Segmented value={filter} onChange={setFilter} options={[
          { value: 'all', label: `Tümü (${data.submissions.length})` },
          { value: 'to_grade', label: `Kontrol (${h.to_grade_count})` },
          { value: 'submitted', label: 'Teslim edenler' },
          { value: 'missing', label: 'Etmeyenler' },
        ]} className="max-w-full" />
        {!editable && <span className="text-[12.5px] text-ink-3">Önizleme: değerlendirme kaydedilemez.</span>}
      </div>

      {subs.length === 0 ? (
        <EmptyState compact title="Bu filtrede öğrenci yok" />
      ) : (
        <ul className="overflow-hidden rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          {subs.map((s, i) => {
            const e = edits[s.student_id]
            if (!e) return null
            const hasWork = !!s.answer_text || s.files.length > 0
            return (
              <li key={s.id} className={cn('flex flex-col gap-2 px-3 py-3 sm:px-4 lg:flex-row lg:items-center lg:gap-3', i > 0 && 'border-t border-line')}>
                <div className="flex min-w-0 flex-1 items-center gap-2.5">
                  <Avatar name={s.full_name} src={s.photo_url} size={34} />
                  <div className="min-w-0">
                    <Link to={`/ogretmen/ogrenci/${s.student_id}`} className="block break-words text-[14.5px] font-medium hover:underline">{s.full_name}</Link>
                    <p className="flex flex-wrap items-center gap-1.5 text-[12.5px] text-ink-3">
                      <Badge tone={homeworkTone[s.status] ?? 'neutral'} dot>{s.status_label}</Badge>
                      {s.submitted_at && <span>{dateTime(s.submitted_at)}</span>}
                      {hasWork && (
                        <button className="font-medium text-primary hover:underline" onClick={() => setOpen(s.id)}>
                          Teslimi gör{s.files.length ? ` (${s.files.length} dosya)` : ''}
                        </button>
                      )}
                    </p>
                  </div>
                </div>
                <div className="grid grid-cols-[1fr_84px] gap-2 sm:grid-cols-[150px_84px_minmax(0,1fr)] lg:w-[560px]">
                  <Select aria-label="Durum" value={e.status} disabled={!editable} options={Object.entries(data.statuses).map(([v, l]) => ({ value: v, label: l }))} onChange={(ev) => set(s.student_id, { status: ev.target.value })} />
                  <Input aria-label="Puan (0-100)" type="number" min={0} max={100} inputMode="numeric" placeholder="Puan" value={e.score} disabled={!editable} onChange={(ev) => set(s.student_id, { score: ev.target.value })} />
                  <Input aria-label="Geri bildirim" className="col-span-2 sm:col-span-1" maxLength={1000} placeholder="Geri bildirim (öğrenci ve veli görür)" value={e.teacher_note} disabled={!editable} onChange={(ev) => set(s.student_id, { teacher_note: ev.target.value })} />
                </div>
              </li>
            )
          })}
        </ul>
      )}

      {editable && dirty.length > 0 && (
        <div className="fixed inset-x-0 bottom-[calc(var(--portal-tabbar,62px)+env(safe-area-inset-bottom))] z-20 border-t border-line bg-surface/95 px-4 py-2.5 backdrop-blur-md md:bottom-0 md:left-[var(--portal-sb,0px)] md:px-6 md:pb-[calc(env(safe-area-inset-bottom)+10px)] lg:px-8">
          <div className="mx-auto flex max-w-[1136px] items-center justify-between gap-3">
            <p className="min-w-0 text-[12.5px] text-ink-2">{dirty.length} öğrencide kaydedilmemiş değişiklik</p>
            <Button variant="primary" icon={<Save className="size-4" />} loading={save.isPending} onClick={() => save.mutate()}>Değerlendirmeyi kaydet</Button>
          </div>
        </div>
      )}

      <Modal open={!!openSub} onClose={() => setOpen(null)} title={openSub ? `${openSub.full_name} · teslim` : ''} description={openSub?.submitted_at ? `Teslim: ${dateTime(openSub.submitted_at)}` : undefined}>
        {openSub && (
          <div className="flex flex-col gap-3">
            {openSub.answer_text ? <p className="whitespace-pre-line rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 text-[14.5px]">{openSub.answer_text}</p> : <p className="text-[14px] text-ink-3">Metin cevabı yok.</p>}
            {openSub.files.length > 0 && (
              <ul className="flex flex-col gap-1.5">
                {openSub.files.map((d) => (
                  <li key={d.id}>
                    <button onClick={() => download(d)} className="flex w-full items-center gap-2 rounded-[var(--radius-sm)] ring-1 ring-line px-3 py-2 text-left text-[14px] hover:bg-surface-2">
                      <FileText className="size-4 shrink-0 text-ink-3" /><span className="min-w-0 flex-1 break-words">{d.title}</span><span className="text-ink-3">{fileSize(d.size)}</span><Download className="size-4 text-ink-3" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
      </Modal>

      {editing && <EditHomework data={data} onClose={() => setEditing(false)} onSaved={invalidate} />}

      <ConfirmDialog
        open={deleting}
        onClose={() => setDeleting(false)}
        onConfirm={() => del.mutate()}
        loading={del.isPending}
        title="Ödev silinsin mi?"
        description="Teslim edilmiş ödev silinemez. Teslim yoksa ödev ve dosyaları kalıcı olarak kaldırılır."
        confirmLabel="Sil"
      />
    </div>
  )
}

function EditHomework({ data, onClose, onSaved }: { data: Data; onClose: () => void; onSaved: () => void }) {
  const h = data.homework
  const toLocal = (iso: string) => iso.slice(0, 16)
  const [form, setForm] = useState({ title: h.title, description: data.homework.description ?? '', due_at: toLocal(h.due_at) })
  const m = useMutation({
    mutationFn: () => api.put<{ message: string }>(`${TP}/homework/${h.id}`, form),
    onSuccess: (r) => { toast.success(r.message); onSaved(); onClose() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })
  return (
    <Modal open onClose={onClose} title="Ödevi düzenle" footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={m.isPending} onClick={() => m.mutate()}>Kaydet</Button></>}>
      <div className="flex flex-col gap-3">
        <Field label="Ödev başlığı" required><Input value={form.title} maxLength={200} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} /></Field>
        <Field label="Açıklama" optional><Textarea rows={4} value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} /></Field>
        <Field label="Son teslim tarihi" required hint="Süreyi uzatırsanız teslim etmeyen öğrenciler yeniden teslim edebilir."><Input type="datetime-local" value={form.due_at} onChange={(e) => setForm((f) => ({ ...f, due_at: e.target.value }))} /></Field>
        <Alert tone="info">Sınıf ve ders değiştirilemez; gerekirse yeni ödev verin.</Alert>
      </div>
    </Modal>
  )
}
