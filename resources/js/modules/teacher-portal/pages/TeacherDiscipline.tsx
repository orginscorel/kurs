import { useMemo, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Gavel, Plus, ThumbsUp, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { dateTime } from '@/lib/format'
import { Button } from '@/components/ui/Button'
import { Alert, Badge, EmptyState, Skeleton, type Tone } from '@/components/ui/feedback'
import { Field, Input, Segmented, Textarea } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import { ListCard, PortalTitle } from '@/modules/portal/ui'
import { TP, useReadOnly, useTeacherQuery } from '../api'

type Behavior = { id: number; name: string; category: string; category_label: string; kind: 'negative' | 'positive'; points: number; severity: string | null }
type Options = { enabled: boolean; behaviors: Behavior[]; categories: Record<string, string>; severities: Record<string, string> }
type Row = { id: number; incident_no: string; kind: string; occurred_at: string; status: string; status_label: string; location: string | null; students: { full_name: string; behavior: string | null }[] }
type StudentOpt = { id: number; full_name: string; student_no: string; class_groups: string[] }

const statusTone: Record<string, Tone> = { open: 'warning', reviewing: 'info', decided: 'primary', appealed: 'warning', closed: 'neutral' }

/** Öğretmen portalı › Olay bildir: öğretmen kendi öğrencileri için olay ya da olumlu davranış bildirir; karar disiplin sorumlusundadır. */
export default function TeacherDiscipline() {
  const readOnly = useReadOnly()
  const opts = useTeacherQuery<Options>(['discipline', 'options'], '/discipline/options')
  const list = useTeacherQuery<{ data: Row[] }>(['discipline', 'incidents'], '/discipline/incidents')
  const [open, setOpen] = useState<null | 'negative' | 'positive'>(null)
  const enabled = opts.data?.enabled !== false

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle
        title="Olay bildir"
        description="Derste ya da kurumda yaşanan olayları ve takdir edilecek davranışları bildirin. Karar disiplin sorumlusu tarafından verilir."
        actions={!readOnly && enabled && (
          <>
            <Button variant="success" icon={<ThumbsUp className="size-4" />} onClick={() => setOpen('positive')}>Olumlu davranış</Button>
            <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setOpen('negative')}>Olay bildir</Button>
          </>
        )}
      />
      {!enabled && <Alert tone="neutral" title="Olay bildirimi kapalı">Kurum öğretmen portalından olay bildirimini kapatmış. Disiplin sorumlusuyla görüşün.</Alert>}

      {list.isLoading ? <Skeleton className="h-48" /> : !list.data?.data.length ? (
        <EmptyState icon={<Gavel />} title="Henüz bildiriminiz yok" description="Bildirdiğiniz olaylar ve durumları burada listelenir." />
      ) : (
        <ListCard>
          {list.data.data.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3">
              <div className="min-w-0 flex-1">
                <p className="break-words text-[14px] font-medium">{r.students.map((s) => s.full_name).join(', ')}</p>
                <p className="break-words text-[12.5px] text-ink-3">{r.students.map((s) => s.behavior).filter(Boolean).join(' · ') || '—'} · {dateTime(r.occurred_at)}{r.location ? ` · ${r.location}` : ''}</p>
              </div>
              {r.kind === 'positive' ? <Badge tone="success">Olumlu</Badge> : null}
              <Badge tone={statusTone[r.status] ?? 'neutral'}>{r.status_label}</Badge>
              <span className="text-[12.5px] text-ink-3 tabular">{r.incident_no}</span>
            </li>
          ))}
        </ListCard>
      )}

      {open && opts.data && <ReportDrawer kind={open} options={opts.data} onClose={() => setOpen(null)} />}
    </div>
  )
}

function ReportDrawer({ kind, options, onClose }: { kind: 'negative' | 'positive'; options: Options; onClose: () => void }) {
  const qc = useQueryClient()
  const students = useTeacherQuery<{ data: StudentOpt[] }>(['students', 'all'], '/students')
  const [q, setQ] = useState('')
  const [picked, setPicked] = useState<StudentOpt[]>([])
  const [behaviorId, setBehaviorId] = useState<number | null>(null)
  const [description, setDescription] = useState('')
  const [location, setLocation] = useState('')
  const [when, setWhen] = useState<'now' | 'custom'>('now')
  const [at, setAt] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const behaviors = useMemo(() => options.behaviors.filter((b) => b.kind === kind), [options, kind])
  const matches = useMemo(() => {
    const t = q.trim().toLocaleLowerCase('tr')
    return (students.data?.data ?? []).filter((s) => !picked.some((p) => p.id === s.id) && (!t || s.full_name.toLocaleLowerCase('tr').includes(t) || s.student_no.includes(t))).slice(0, 8)
  }, [students.data, q, picked])

  const save = useMutation({
    mutationFn: () => api.post<{ message: string }>(`${TP}/discipline/incidents`, {
      description, location: location || undefined,
      occurred_at: when === 'custom' && at ? at.replace('T', ' ') : undefined,
      students: picked.map((s) => ({ student_id: s.id, behavior_id: behaviorId, role: 'involved' })),
    }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['teacher-portal', 'discipline'] })
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } else toast.error('Kaydedilemedi.')
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const err: Record<string, string[]> = {}
    if (!picked.length) err.students = ['En az bir öğrenci seçin.']
    if (!behaviorId) err.behavior = ['Davranışı seçin.']
    if (description.trim().length < 5) err.description = ['Kısaca açıklayın.']
    setErrors(err)
    if (!Object.keys(err).length) save.mutate()
  }

  return (
    <Drawer open onClose={onClose} title={kind === 'positive' ? 'Olumlu davranış bildir' : 'Olay bildir'}
      description="Yalnız kendi sınıflarınızdaki öğrenciler listelenir."
      footer={<div className="flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant={kind === 'positive' ? 'success' : 'primary'} loading={save.isPending} onClick={submit}>Gönder</Button></div>}>
      <form onSubmit={submit} className="flex flex-col gap-4">
        <Field label="1. Öğrenci" required error={errors.students?.[0] ?? errors['students.0.student_id']?.[0]}>
          {picked.length > 0 && (
            <div className="mb-1.5 flex flex-wrap gap-1.5">
              {picked.map((s) => (
                <span key={s.id} className="inline-flex items-center gap-1 rounded-[4px] border border-line bg-surface-2 py-0.5 pl-2 pr-1 text-[14px]">
                  {s.full_name}
                  <button type="button" className="rounded p-0.5 text-ink-3 hover:text-ink" onClick={() => setPicked((p) => p.filter((x) => x.id !== s.id))} aria-label="Kaldır"><X className="size-3.5" /></button>
                </span>
              ))}
            </div>
          )}
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Ad ya da öğrenci no ile ara" />
          {students.isLoading ? <Skeleton className="mt-1 h-20" /> : matches.length > 0 && (
            <ul className="mt-1 max-h-52 overflow-y-auto rounded-[var(--radius-sm)] border border-line">
              {matches.map((s) => (
                <li key={s.id}>
                  <button type="button" onClick={() => { setPicked((p) => [...p, s]); setQ('') }} className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-[14.5px] hover:bg-surface-2">
                    <span className="break-words">{s.full_name}</span>
                    <span className="shrink-0 text-[12.5px] text-ink-3">{s.class_groups.join(', ')} · {s.student_no}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Field>

        <Field label="2. Davranış" required error={errors.behavior?.[0]}>
          <div className="grid gap-1.5 sm:grid-cols-2">
            {behaviors.map((b) => (
              <button key={b.id} type="button" onClick={() => setBehaviorId(b.id)}
                className={cn('flex items-start justify-between gap-2 rounded-[var(--radius-sm)] border px-3 py-2 text-left text-[14px] transition-colors',
                  behaviorId === b.id ? (kind === 'positive' ? 'border-success bg-success-soft' : 'border-primary bg-primary-soft') : 'border-line hover:border-line-strong')}>
                <span className="min-w-0">
                  <span className="block font-medium leading-snug">{b.name}</span>
                  <span className="block text-[12.5px] text-ink-3">{b.category_label}{b.severity && options.severities[b.severity] ? ` · ${options.severities[b.severity]}` : ''}</span>
                </span>
                <span className={cn('shrink-0 tabular text-[12.5px] font-semibold', kind === 'positive' ? 'text-success' : 'text-danger')}>{kind === 'positive' ? '+' : '−'}{Math.abs(b.points)}</span>
              </button>
            ))}
          </div>
        </Field>

        <Field label="3. Ne oldu?" required error={errors.description?.[0]}>
          <Textarea rows={4} value={description} onChange={(e) => setDescription(e.target.value)} placeholder={kind === 'positive' ? 'Takdir edilecek davranışı kısaca yazın.' : 'Olayı kısaca, tarafsız biçimde anlatın.'} maxLength={3000} />
        </Field>

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Ne zaman oldu" required>
            <Segmented size="sm" value={when} onChange={setWhen} options={[{ value: 'now', label: 'Şimdi' }, { value: 'custom', label: 'Başka zaman' }]} />
            {when === 'custom' && <Input className="mt-1.5" type="datetime-local" value={at} onChange={(e) => setAt(e.target.value)} />}
          </Field>
          <Field label="Nerede oldu" optional>
            <Input value={location} onChange={(e) => setLocation(e.target.value)} placeholder="Sınıf, koridor, bahçe…" maxLength={120} />
          </Field>
        </div>
      </form>
    </Drawer>
  )
}
