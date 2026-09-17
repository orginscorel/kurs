import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CheckCheck, Download, FileText, Paperclip, Pencil, Save, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { Avatar, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { ConfirmDialog } from '@/components/ui/overlay'
import { useAcademicOptions } from './hooks'
import { ColorChip, MiniStat } from './ui'
import { fmtBytes, submissionTone, type HomeworkDetailData, type SubmissionRow } from './types'
import { HomeworkFormDrawer } from './HomeworkFormDrawer'

type Draft = { status?: string; score?: string; teacher_note?: string }

export default function HomeworkDetail() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const options = useAcademicOptions(can('homework.manage'))
  const { data, isLoading, error } = useQuery({ queryKey: ['homework', id], queryFn: () => api.get<HomeworkDetailData>(`/homework/${id}`) })
  const [drafts, setDrafts] = useState<Record<number, Draft>>({})
  const [editOpen, setEditOpen] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [filter, setFilter] = useState('')
  const fileInput = useRef<HTMLInputElement>(null)

  useEffect(() => setDrafts({}), [data])

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['homework', id] })
    qc.invalidateQueries({ queryKey: ['homework', 'list'] })
  }
  const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')

  const grade = useMutation({
    mutationFn: (rows: ({ student_id: number } & Draft)[]) => api.put<{ message: string }>(`/homework/${id}/submissions`, { rows: rows.map((r) => ({ ...r, score: r.score === undefined ? undefined : r.score === '' ? null : Number(r.score) })) }),
    onSuccess: (r) => { toast.success(r.message); invalidate() },
    onError: fail,
  })
  const upload = useMutation({
    mutationFn: (file: File) => { const fd = new FormData(); fd.append('file', file); return api.post<{ message: string }>(`/homework/${id}/documents`, fd) },
    onSuccess: (r) => { toast.success(r.message); invalidate() },
    onError: fail,
  })
  const removeDoc = useMutation({ mutationFn: (docId: number) => api.delete<{ message: string }>(`/homework/${id}/documents/${docId}`), onSuccess: (r) => { toast.success(r.message); invalidate() }, onError: fail })
  const remove = useMutation({ mutationFn: () => api.delete<{ message: string }>(`/homework/${id}`), onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['homework'] }); navigate('/odevler') }, onError: (e) => { fail(e); setConfirmDelete(false) } })

  const rows = useMemo(() => {
    const list = data?.submissions ?? []
    return filter ? list.filter((s) => s.status === filter) : list
  }, [data, filter])
  const dirty = Object.keys(drafts).length

  if (error) return <EmptyState title="Ödev bulunamadı" action={<Button onClick={() => navigate('/odevler')}>Ödevlere dön</Button>} />
  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-8 w-72" /><Skeleton className="h-24" /><Skeleton className="h-96" /></div>

  const h = data.homework
  const manage = can('homework.manage')
  const setDraft = (sid: number, patch: Draft) => setDrafts((d) => ({ ...d, [sid]: { ...d[sid], ...patch } }))
  const val = (s: SubmissionRow, k: keyof Draft) => drafts[s.student_id]?.[k] ?? (k === 'score' ? (s.score ?? '') : k === 'status' ? s.status : (s.teacher_note ?? ''))
  const saveDrafts = () => grade.mutate(Object.entries(drafts).map(([sid, d]) => ({ student_id: Number(sid), ...d })))

  return (
    <div className="animate-fade-in">
      <PageHeader
        breadcrumbs={[{ label: 'Ödevler', to: '/odevler' }, { label: h.title }]}
        title={h.title}
        description={<span className="flex flex-wrap items-center gap-2">{h.subject && <ColorChip color={h.subject.color}>{h.subject.name}</ColorChip>}<span>{h.class_group ? `Sınıf: ${h.class_group}` : 'Seçili öğrencilere'} · Veren öğretmen: {h.teacher || '—'}</span>{h.topic && <span className="text-ink-3">· Konu: {h.topic}</span>}</span>}
        actions={
          manage && (
            <>
              <Button icon={<Pencil className="size-4" />} onClick={() => setEditOpen(true)}>Düzenle</Button>
              <Button variant="danger-soft" icon={<Trash2 className="size-4" />} onClick={() => setConfirmDelete(true)}>Sil</Button>
            </>
          )
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_300px] gap-4 items-start">
        <div className="flex min-w-0 flex-col gap-4">
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <MiniStat label="Teslim eden" value={`${h.done_count}/${h.total_count}`} sub={`Tamamlanma %${h.completion}`} tone={h.completion >= 80 ? 'success' : undefined} />
            <MiniStat label="Yapmayan" value={h.missed_count} tone={h.missed_count ? 'danger' : undefined} />
            <MiniStat label="Puan verilen" value={h.graded_count} sub={data.avg_score ? `Ortalama puan: ${data.avg_score}` : undefined} />
            <MiniStat label="Son teslim tarihi" value={<span className="text-[15px]">{dateTime(h.due_at)}</span>} sub={h.is_open ? relative(h.due_at) : 'Süresi doldu'} tone={h.is_open ? undefined : 'warning'} />
          </div>

          <Panel
            title="Teslimler"
            description={dirty ? `${dirty} öğrencideki değişiklik henüz kaydedilmedi` : `${data.submissions.length} öğrenci`}
            flush
          >
            <div className="flex flex-wrap items-center gap-2 px-4 pb-3">
                <Select value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Tüm durumlar" aria-label="Teslim durumu" options={Object.entries(data.stats).map(([k, n]) => ({ value: k, label: `${statusLabel(k)} (${n})` }))} className="w-full sm:w-[180px]" />
                {manage && (
                  <Button size="sm" icon={<CheckCheck className="size-3.5" />} onClick={() => grade.mutate(rows.filter((s) => ['assigned', 'seen'].includes(s.status)).map((s) => ({ student_id: s.student_id, status: 'submitted' })))} disabled={!rows.some((s) => ['assigned', 'seen'].includes(s.status))}>
                    Kalanları teslim işaretle
                  </Button>
                )}
                {manage && <Button size="sm" variant="primary" icon={<Save className="size-3.5" />} disabled={!dirty} loading={grade.isPending} onClick={saveDrafts}>Değişiklikleri kaydet</Button>}
              </div>
            <div className="overflow-x-auto scroll-thin">
              <table className="tbl w-full text-[13px]">
                <thead>
                  <tr className="border-y border-line bg-surface-2/60 text-[12.5px] text-ink-3">
                    <th className="h-9 px-4 font-medium text-left">Öğrenci</th>
                    <th className="px-3 font-medium text-center">Durum</th>
                    <th className="px-3 font-medium text-center">Teslim tarihi</th>
                    <th className="px-3 font-medium w-[90px] whitespace-nowrap text-center">Puan (0–100)</th>
                    <th className="fill px-3 font-medium text-center">Öğretmen notu</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((s) => (
                    <tr key={s.id} className="border-b border-line last:border-0 hover:bg-surface-2/50">
                      <td className="px-4 py-2 text-left">
                        <Link to={`/ogrenciler/${s.student_id}`} className="flex items-center gap-2.5 hover:text-primary">
                          <Avatar name={s.full_name} src={s.photo_url} size={28} />
                          <span className="min-w-0"><span className="block truncate font-medium">{s.full_name}</span><span className="block text-[12px] text-ink-3">{s.class_group ? `Sınıf: ${s.class_group}` : `Öğrenci no: ${s.student_no}`}</span></span>
                        </Link>
                      </td>
                      <td className="px-3 py-2 text-center">
                        {manage ? (
                          <Select value={val(s, 'status') as string} onChange={(e) => setDraft(s.student_id, { status: e.target.value })} options={Object.keys(submissionTone).map((k) => ({ value: k, label: statusLabel(k) }))} aria-label={`${s.full_name} teslim durumu`} className="w-[140px]" />
                        ) : (
                          <Badge tone={submissionTone[s.status] ?? 'neutral'} dot>{s.status_label}</Badge>
                        )}
                      </td>
                      <td className="px-3 py-2 text-ink-3 tabular whitespace-nowrap text-center">{s.submitted_at ? dateTime(s.submitted_at) : s.seen_at ? `Teslim yok · görüldü ${relative(s.seen_at)}` : '—'}</td>
                      <td className="px-3 py-2 text-center">
                        {manage ? <Input type="number" min={0} max={100} value={val(s, 'score') as string | number} onChange={(e) => setDraft(s.student_id, { score: e.target.value })} aria-label={`${s.full_name} puanı`} placeholder="—" className="w-[80px]" /> : <span className="tabular">{s.score ?? '—'}</span>}
                      </td>
                      <td className="fill px-3 py-2 text-center">
                        {manage ? <Input value={val(s, 'teacher_note') as string} onChange={(e) => setDraft(s.student_id, { teacher_note: e.target.value })} placeholder="Not ekleyin (isteğe bağlı)" aria-label={`${s.full_name} öğretmen notu`} className="min-w-[180px]" /> : <span className="text-ink-2">{s.teacher_note ?? '—'}</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {rows.length === 0 && <EmptyState compact title="Bu durumda öğrenci yok" />}
            </div>
          </Panel>
        </div>

        <aside className="flex flex-col gap-4">
          <Panel title="Açıklama">
            {h.description ? <p className="whitespace-pre-line text-[13px] text-ink-2">{h.description}</p> : <p className="text-[13px] text-ink-3">Açıklama girilmemiş.</p>}
            <p className="mt-3 text-[12.5px] text-ink-3">Verilme tarihi: {dateTime(h.assigned_at)}</p>
          </Panel>
          <Panel
            title="Dosyalar"
            actions={manage && (
              <>
                <input ref={fileInput} type="file" className="hidden" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.txt,.zip" onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f); e.target.value = '' }} />
                <Button size="xs" variant="ghost" icon={<Paperclip className="size-3.5" />} loading={upload.isPending} onClick={() => fileInput.current?.click()}>Dosya ekle</Button>
              </>
            )}
          >
            {data.documents.length === 0 ? (
              <p className="text-[13px] text-ink-3">Ek dosya yok.</p>
            ) : (
              <ul className="flex flex-col gap-1.5">
                {data.documents.map((d) => (
                  <li key={d.id} className="flex items-center gap-2 rounded-[var(--radius-sm)] bg-surface-2/60 px-2.5 py-1.5 text-[12.5px]">
                    <FileText className="size-4 text-ink-3 shrink-0" />
                    <span className="min-w-0 flex-1 truncate">{d.title}<span className="text-ink-3"> · {fmtBytes(d.size)}</span></span>
                    <button type="button" className="text-ink-3 hover:text-ink" aria-label={`${d.title} indir`} title="İndir" onClick={() => api.download(`/homework/${id}/documents/${d.id}/download`, undefined, d.title).catch(fail)}><Download className="size-4" /></button>
                    {manage && <button type="button" className="text-ink-3 hover:text-danger" aria-label={`${d.title} sil`} title="Sil" onClick={() => removeDoc.mutate(d.id)}><Trash2 className="size-4" /></button>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          <Panel title="Teslim durumu dağılımı">
            {Object.entries(submissionTone).map(([k]) => {
              const n = data.stats[k] ?? 0
              return (
                <div key={k} className="mb-2 last:mb-0">
                  <div className="flex justify-between text-[12.5px]"><span className="text-ink-2">{statusLabel(k)}</span><span className="tabular text-ink-3">{n} öğrenci</span></div>
                  <ProgressBar value={h.total_count ? (n / h.total_count) * 100 : 0} tone={submissionTone[k]} className="mt-1" />
                </div>
              )
            })}
          </Panel>
        </aside>
      </div>

      <HomeworkFormDrawer open={editOpen} editing={h} options={options.data} onClose={() => setEditOpen(false)} onSaved={invalidate} />
      <ConfirmDialog open={confirmDelete} onClose={() => setConfirmDelete(false)} onConfirm={() => remove.mutate()} loading={remove.isPending} danger title="Ödevi sil" confirmLabel="Sil" description="Teslim kayıtları ve ekler silinir. Bu işlem geri alınamaz." />
    </div>
  )
}

function statusLabel(k: string) {
  return { assigned: 'Teslim bekleniyor', seen: 'Görüldü, teslim yok', submitted: 'Teslim edildi', late: 'Geç teslim', missed: 'Yapılmadı' }[k] ?? k
}
