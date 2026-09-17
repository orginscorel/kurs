import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { BookOpenCheck, Paperclip, Plus, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, dateTime, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/form'
import { Tabs } from '@/components/ui/layout'
import { Drawer } from '@/components/ui/overlay'
import { PortalTitle } from '@/modules/portal/ui'
import { fileSize, TP, useTeacherCan, useTeacherQuery, type HomeworkRow } from '../api'

type ListData = { data: HomeworkRow[]; counts: { open: number; closed: number; to_grade: number } }
type Status = 'open' | 'to_grade' | 'closed'

export default function TeacherHomework() {
  const [params, setParams] = useSearchParams()
  const status = (['open', 'to_grade', 'closed'].includes(params.get('durum') ?? '') ? params.get('durum') : 'open') as Status
  const groupFilter = params.get('sinif') ?? ''
  const can = useTeacherCan()
  const [creating, setCreating] = useState(params.get('yeni') === '1')
  const { data, isLoading } = useTeacherQuery<ListData>(['homework', 'list'], '/homework', { status, class_group_id: groupFilter || undefined })

  const setStatus = (s: Status) => {
    const next = new URLSearchParams(params)
    next.set('durum', s)
    setParams(next, { replace: true })
  }

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle
        title="Ödevler"
        description="Verdiğiniz ödevler, teslimler ve değerlendirmeler"
        actions={can('homework') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>Ödev ver</Button>}
      />

      <Tabs
        value={status}
        onChange={setStatus}
        tabs={[
          { value: 'open', label: 'Süresi devam eden', count: data?.counts.open },
          { value: 'to_grade', label: 'Kontrol bekleyen', count: data?.counts.to_grade },
          { value: 'closed', label: 'Süresi dolan', count: data?.counts.closed },
        ]}
      />

      {isLoading || !data ? (
        <Skeleton className="h-64" />
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<BookOpenCheck />}
          title={status === 'to_grade' ? 'Kontrol bekleyen teslim yok' : status === 'open' ? 'Süresi devam eden ödev yok' : 'Süresi dolan ödev yok'}
          action={status === 'open' && can('homework') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>İlk ödevi verin</Button> : undefined}
        />
      ) : (
        <ul className="flex flex-col gap-2">
          {data.data.map((h) => (
            <li key={h.id}>
              <Link to={`/ogretmen/odevler/${h.id}`} className="block rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line hover:ring-line-strong">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="break-words text-[14px] font-medium">{h.title}</p>
                    <p className="break-words text-[12.5px] text-ink-3">{[h.class_group, h.subject].filter(Boolean).join(' · ')}</p>
                  </div>
                  {h.to_grade_count > 0 ? <Badge tone="warning">{h.to_grade_count} kontrol bekliyor</Badge> : h.is_open ? <Badge tone="primary">Açık</Badge> : <Badge>Kapandı</Badge>}
                </div>
                <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-ink-3">
                  <span className={cn(h.is_open && new Date(h.due_at).getTime() - Date.now() < 36e5 * 24 && 'font-medium text-warning')}>
                    Son teslim {dateTime(h.due_at)}{h.is_open ? ` (${relative(h.due_at)})` : ''}
                  </span>
                  <span className="tabular">{h.done_count}/{h.total_count} teslim{h.missed_count ? ` · ${h.missed_count} yapılmadı` : ''}</span>
                </div>
                <ProgressBar className="mt-2" value={h.completion} tone={h.completion >= 80 ? 'success' : h.completion >= 50 ? 'primary' : 'warning'} />
              </Link>
            </li>
          ))}
        </ul>
      )}

      {creating && <HomeworkForm onClose={() => { setCreating(false); if (params.get('yeni')) { const n = new URLSearchParams(params); n.delete('yeni'); setParams(n, { replace: true }) } }} defaultGroup={groupFilter} />}
    </div>
  )
}

type Options = {
  groups: { id: number; name: string; student_count: number; subject_ids: number[] }[]
  subjects: { id: number; name: string; color: string | null }[]
  topics: { id: number; subject_id: number; name: string }[]
  max_file_mb: number
  allowed_ext: string[]
}

function defaultDue() {
  const d = new Date(Date.now() + 7 * 864e5)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T23:59`
}

function HomeworkForm({ onClose, defaultGroup }: { onClose: () => void; defaultGroup?: string }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const { data: opt, isLoading } = useTeacherQuery<Options>(['homework', 'options'], '/homework/options')
  const [form, setForm] = useState({ class_group_id: defaultGroup ?? '', subject_id: '', topic_id: '', title: '', description: '', due_at: defaultDue() })
  const [files, setFiles] = useState<File[]>([])
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const group = opt?.groups.find((g) => String(g.id) === form.class_group_id)
  const subjects = useMemo(() => {
    if (!opt) return []
    const inGroup = group ? opt.subjects.filter((s) => group.subject_ids.includes(s.id)) : []
    return inGroup.length ? inGroup : opt.subjects
  }, [opt, group])
  const topics = opt?.topics.filter((t) => String(t.subject_id) === form.subject_id) ?? []

  useEffect(() => {
    // Sınıf seçilince (tek dersi varsa) dersi otomatik seç
    if (subjects.length === 1 && form.subject_id !== String(subjects[0]!.id)) setForm((f) => ({ ...f, subject_id: String(subjects[0]!.id), topic_id: '' }))
  }, [subjects, form.subject_id])

  const create = useMutation({
    mutationFn: () => {
      const fd = new FormData()
      Object.entries(form).forEach(([k, v]) => { if (v) fd.append(k, v) })
      files.forEach((f) => fd.append('files[]', f))
      return api.post<{ message: string; id: number }>(`${TP}/homework`, fd)
    },
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['teacher-portal'] })
      onClose()
      navigate(`/ogretmen/odevler/${r.id}`)
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      } else toast.error('Ödev verilemedi.')
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setErrors({})
    create.mutate()
  }
  const err = (k: string) => errors[k]?.[0] ?? null

  return (
    <Drawer
      open
      onClose={onClose}
      title="Ödev ver"
      description="Ödev seçtiğiniz sınıfın tüm öğrencilerine atanır."
      footer={<>
        <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
        <Button variant="primary" type="submit" form="hw-form" loading={create.isPending}>Ödevi ver</Button>
      </>}
    >
      {isLoading || !opt ? <Skeleton className="h-64" /> : opt.groups.length === 0 ? (
        <EmptyState compact title="Size bağlı sınıf bulunamadı" description="Ders programında sınıfınız olmadığı için ödev veremezsiniz." />
      ) : (
        <form id="hw-form" onSubmit={submit} className="flex flex-col gap-3.5">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Sınıf" required error={err('class_group_id')}>
              <Select value={form.class_group_id} placeholder="Sınıf seçin" options={opt.groups.map((g) => ({ value: g.id, label: `${g.name} (${g.student_count} öğrenci)` }))}
                onChange={(e) => setForm((f) => ({ ...f, class_group_id: e.target.value, subject_id: '', topic_id: '' }))} />
            </Field>
            <Field label="Ders" required error={err('subject_id')}>
              <Select value={form.subject_id} placeholder="Ders seçin" options={subjects.map((s) => ({ value: s.id, label: s.name }))}
                onChange={(e) => setForm((f) => ({ ...f, subject_id: e.target.value, topic_id: '' }))} />
            </Field>
          </div>
          {topics.length > 0 && (
            <Field label="Konu" optional hint="Konu eksikleri analizinde kullanılır.">
              <Select value={form.topic_id} placeholder="Konu seçin" options={topics.map((t) => ({ value: t.id, label: t.name }))} onChange={(e) => setForm((f) => ({ ...f, topic_id: e.target.value }))} />
            </Field>
          )}
          <Field label="Ödev başlığı" required error={err('title')}>
            <Input value={form.title} maxLength={200} placeholder="Örn. Fonksiyonlar test 3-5" onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} />
          </Field>
          <Field label="Açıklama" optional error={err('description')} hint="Öğrencinin göreceği yönerge.">
            <Textarea rows={4} value={form.description} maxLength={5000} placeholder="Sayfa aralığı, nasıl teslim edileceği…" onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />
          </Field>
          <Field label="Son teslim tarihi" required error={err('due_at')}>
            <Input type="datetime-local" value={form.due_at} onChange={(e) => setForm((f) => ({ ...f, due_at: e.target.value }))} />
          </Field>
          <Field label="Dosyalar" optional hint={`En fazla 5 dosya, her biri ${opt.max_file_mb} MB · ${opt.allowed_ext.join(', ')}`} error={err('files') ?? err('files.0')}>
            <label className="flex cursor-pointer items-center justify-center gap-2 rounded-[var(--radius-sm)] border border-dashed border-line-strong px-3 py-3 text-[14px] text-ink-2 hover:bg-surface-2">
              <Paperclip className="size-4" /> Dosya ekle
              <input type="file" multiple className="sr-only" accept={opt.allowed_ext.map((x) => `.${x}`).join(',')}
                onChange={(e) => { setFiles((cur) => [...cur, ...Array.from(e.target.files ?? [])].slice(0, 5)); e.target.value = '' }} />
            </label>
            {files.length > 0 && (
              <ul className="mt-2 flex flex-col gap-1">
                {files.map((f, i) => (
                  <li key={i} className="flex items-center gap-2 rounded-[var(--radius-xs)] bg-surface-2 px-2 py-1 text-[12.5px]">
                    <span className="min-w-0 flex-1 break-words">{f.name}</span>
                    <span className="text-ink-3">{fileSize(f.size)}</span>
                    <button type="button" aria-label="Kaldır" className="text-ink-3 hover:text-danger" onClick={() => setFiles((cur) => cur.filter((_, j) => j !== i))}><X className="size-3.5" /></button>
                  </li>
                ))}
              </ul>
            )}
          </Field>
          {group && <p className="text-[12.5px] text-ink-3">Ödev {group.name} sınıfındaki {group.student_count} öğrenciye atanacak · son teslim {date(form.due_at.slice(0, 10))}.</p>}
        </form>
      )}
    </Drawer>
  )
}
