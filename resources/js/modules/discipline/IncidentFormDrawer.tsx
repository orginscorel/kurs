import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { ChevronDown, Search, ThumbsDown, ThumbsUp } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { Alert, Badge } from '@/components/ui/feedback'
import type { Behavior, IncidentDetail } from './types'
import { SEVERITY_TONE, StudentMultiPicker, toLocalInput, useDisciplineOptions, type PickedStudent } from './ui'

type Props = {
  open: boolean
  onClose: () => void
  /** Düzenleme */
  incident?: IncidentDetail | null
  /** Öğrenci profilinden açıldığında önceden seçili öğrenci */
  student?: PickedStudent | null
  defaultKind?: 'negative' | 'positive'
}

type RoleRow = { student: PickedStudent; role: string; behavior_id: number | null }

/**
 * Olay kaydı. Hızlı yol: öğrenci seç → davranış seç → not → Kaydet (≈30 sn).
 * Ayrıntılar (tarih, yer, ders, öğretmen, tanık, mağdur) katlanmış bölümde.
 */
export function IncidentFormDrawer({ open, onClose, incident, student, defaultKind = 'negative' }: Props) {
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const editing = !!incident
  const { data: opt } = useDisciplineOptions()

  const [kind, setKind] = useState<'negative' | 'positive'>(defaultKind)
  const [students, setStudents] = useState<PickedStudent[]>([])
  const [behaviorId, setBehaviorId] = useState<number | null>(null)
  const [bq, setBq] = useState('')
  const [description, setDescription] = useState('')
  const [more, setMore] = useState(false)
  const [f, setF] = useState<Record<string, any>>({})
  const [others, setOthers] = useState<RoleRow[]>([])
  const [rows, setRows] = useState<RoleRow[]>([])
  const [quick, setQuick] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!open) return
    setErrors({})
    setBq('')
    setQuick('')
    if (incident) {
      setKind(incident.kind)
      setDescription(incident.description ?? '')
      setF({
        occurred_at: toLocalInput(incident.occurred_at), location: incident.location ?? '', title: incident.title ?? '', witnesses: incident.witnesses ?? '',
        class_group_id: incident.class_group_id ?? '', subject_id: incident.subject_id ?? '', teacher_id: incident.teacher_id ?? '', severity: incident.severity,
      })
      setRows(incident.participants.map((p) => ({ student: { id: p.student_id, full_name: p.full_name, student_no: p.student_no }, role: p.role, behavior_id: p.behavior_id })))
      setMore(true)
    } else {
      setKind(defaultKind)
      setStudents(student ? [student] : [])
      setBehaviorId(null)
      setDescription('')
      setOthers([])
      setF({ occurred_at: toLocalInput(new Date()), location: '', title: '', witnesses: '', class_group_id: '', subject_id: '', teacher_id: '', severity: '' })
      setMore(false)
    }
  }, [open, incident, student, defaultKind])

  const behaviors = useMemo(() => (opt?.behaviors ?? []).filter((b) => b.kind === kind), [opt, kind])
  const filtered = useMemo(() => {
    const q = bq.trim().toLocaleLowerCase('tr')
    return q ? behaviors.filter((b) => b.name.toLocaleLowerCase('tr').includes(q) || b.category_label.toLocaleLowerCase('tr').includes(q)) : behaviors
  }, [behaviors, bq])
  const grouped = useMemo(() => {
    const m = new Map<string, Behavior[]>()
    filtered.forEach((b) => m.set(b.category_label, [...(m.get(b.category_label) ?? []), b]))
    return [...m.entries()]
  }, [filtered])
  const selected = behaviors.find((b) => b.id === behaviorId) ?? null
  const staffTypes = (opt?.sanction_types ?? []).filter((t) => t.authority === 'staff' && !t.has_duty)
  const suggested = selected?.suggested_sanction ? opt?.sanction_types.find((t) => t.code === selected.suggested_sanction) : null

  const set = (k: string, v: unknown) => setF((x) => ({ ...x, [k]: v }))
  // Hata ayrıntı alanlarındaysa katlanmış bölümü aç (hata görünür olsun)
  useEffect(() => {
    if (['occurred_at', 'location', 'class_group_id', 'subject_id', 'teacher_id', 'severity', 'title', 'witnesses'].some((k) => errors[k])) setMore(true)
  }, [errors])
  const err = (k: string) => errors[k]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const base: Record<string, unknown> = { ...f, description: description || null }
      Object.keys(base).forEach((k) => base[k] === '' && (base[k] = null))
      if (editing) {
        base.students = rows.map((r) => ({ student_id: r.student.id, role: r.role, behavior_id: r.role === 'involved' ? r.behavior_id : null }))
        return api.put<{ message: string }>(`/discipline/incidents/${incident!.id}`, base)
      }
      base.students = [
        ...students.map((s) => ({ student_id: s.id, behavior_id: behaviorId, role: 'involved' })),
        ...others.filter((o) => !students.some((s) => s.id === o.student.id)).map((o) => ({ student_id: o.student.id, role: o.role })),
      ]
      if (quick) base.quick_sanction_type_id = Number(quick)
      return api.post<{ message: string; id: number }>('/discipline/incidents', base)
    },
    onSuccess: (res: any) => {
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['discipline'] })
      qc.invalidateQueries({ queryKey: ['risk'] })
      onClose()
      if (!editing && res.id && kind === 'negative' && !student) navigate(`/disiplin/olaylar/${res.id}`)
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      } else toast.error('Kaydedilemedi.')
    },
  })

  const canSave = editing ? rows.length > 0 : students.length > 0 && !!behaviorId

  return (
    <Drawer open={open} onClose={onClose} width={600}
      title={editing ? `${incident!.incident_no} düzenle` : kind === 'positive' ? 'Olumlu davranış kaydet' : 'Olay kaydet'}
      description={editing ? undefined : 'Öğrenci seçin, davranışı işaretleyin, kısa bir not yazın.'}
      footer={
        <>
          {!canSave && <span className="mr-auto text-[12.5px] text-ink-3">{editing ? 'En az bir öğrenci kalmalı' : students.length === 0 ? 'Önce öğrenci seçin' : 'Davranışı seçin'}</span>}
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} disabled={!canSave} onClick={() => save.mutate()}>Kaydet</Button>
        </>
      }>
      <div className="flex flex-col gap-5">
        {!editing && (
          <Segmented value={kind} onChange={(v) => { setKind(v); setBehaviorId(null) }} className="self-start"
            options={[
              { value: 'negative', label: <span className="inline-flex items-center gap-1.5"><ThumbsDown className="size-3.5" />Disiplin olayı</span> },
              { value: 'positive', label: <span className="inline-flex items-center gap-1.5"><ThumbsUp className="size-3.5" />Olumlu davranış</span>, tone: 'text-success' },
            ]} />
        )}

        {!editing ? (
          <>
            <Field label={<span><StepNo n={1} />Öğrenci{students.length > 1 ? `ler (${students.length})` : ''}</span>} required error={err('students')}>
              <StudentMultiPicker value={students} onChange={setStudents} autoFocus={!student} />
            </Field>

            <Field label={<span><StepNo n={2} />Davranış</span>} required error={err('students.0.behavior_id') ?? err('students.0.student_id')}>
              <Input value={bq} onChange={(e) => setBq(e.target.value)} placeholder="Davranış ara (ör. telefon, kavga, geç)" leading={<Search />} aria-label="Davranış ara" />
              <div className="mt-1 max-h-[280px] overflow-y-auto scroll-thin rounded-[var(--radius-md)] ring-1 ring-line">
                {grouped.length === 0 && <p className="px-3 py-3 text-[13px] text-ink-3">Eşleşen davranış yok.</p>}
                {grouped.map(([cat, list]) => (
                  <div key={cat}>
                    <p className="sticky top-0 z-[1] bg-surface-2 px-3 py-1 text-[12px] font-semibold text-ink-2">{cat}</p>
                    {list.map((b) => (
                      <button key={b.id} type="button" onClick={() => setBehaviorId(b.id)} aria-pressed={behaviorId === b.id}
                        className={cn('flex w-full items-center gap-2 border-b border-line px-3 py-2 text-left text-[13px] last:border-0 transition-colors',
                          behaviorId === b.id ? 'bg-primary-soft text-primary-ink' : 'hover:bg-surface-2')}>
                        <span className={cn('size-3.5 shrink-0 rounded-full border', behaviorId === b.id ? 'border-primary bg-primary shadow-[inset_0_0_0_2px_var(--surface)]' : 'border-line-strong')} />
                        <span className="min-w-0 flex-1 truncate">{b.name}</span>
                        {kind === 'negative' && <Badge tone={SEVERITY_TONE[b.severity] ?? 'neutral'}>{opt?.severities[b.severity]}</Badge>}
                        <span className={cn('w-12 shrink-0 text-right tabular text-[12px] font-medium', kind === 'positive' ? 'text-success' : 'text-danger')}>{kind === 'positive' ? '+' : '−'}{b.points} puan</span>
                      </button>
                    ))}
                  </div>
                ))}
              </div>
            </Field>

            <Field label={<span><StepNo n={3} />Not</span>} optional hint="Ne oldu? Kısa ve nesnel yazın." error={err('description')}>
              <Textarea rows={3} value={description} onChange={(e) => setDescription(e.target.value)} placeholder={kind === 'positive' ? 'Örn. Arkadaşına matematik konusunda ders çalıştırdı.' : 'Örn. Ders sırasında telefonla oyun oynadı, uyarıya rağmen devam etti.'} />
            </Field>

            {kind === 'negative' && can('discipline.decide') && staffTypes.length > 0 && (
              <Field label="Hemen yaptırım" optional error={err('quick_sanction_type_id')} hint={suggested ? `Katalog önerisi: ${suggested.name}${suggested.authority === 'board' ? ' (kurul kararı gerekir — olay ayrıntısından önerin)' : ''}` : 'Kurul gerektirmeyen yaptırımlar burada verilebilir.'}>
                <Select value={quick} onChange={(e) => setQuick(e.target.value)} placeholder="Şimdi yaptırım verme"
                  options={staffTypes.map((t) => ({ value: t.id, label: t.name }))} />
              </Field>
            )}
          </>
        ) : (
          <Field label="Öğrenciler" error={err('students')}>
            <div className="flex flex-col divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
              {rows.map((r, i) => (
                <div key={r.student.id} className="grid grid-cols-1 gap-2 p-2.5 sm:grid-cols-[1fr_130px_1fr_auto] sm:items-center">
                  <span className="truncate text-[13px] font-medium">{r.student.full_name}</span>
                  <Select aria-label="Öğrencinin olaydaki rolü" value={r.role} onChange={(e) => setRows(rows.map((x, xi) => (xi === i ? { ...x, role: e.target.value } : x)))}
                    options={Object.entries(opt?.roles ?? {}).map(([value, label]) => ({ value, label }))} />
                  {r.role === 'involved' ? (
                    <Select aria-label="Davranış" invalid={!!err(`students.${i}.behavior_id`)} value={r.behavior_id ?? ''} onChange={(e) => setRows(rows.map((x, xi) => (xi === i ? { ...x, behavior_id: e.target.value ? Number(e.target.value) : null } : x)))}
                      placeholder="Davranış seçin" options={behaviors.map((b) => ({ value: b.id, label: `${b.name} (${b.points})` }))} />
                  ) : <span className="text-[12px] text-ink-3">Bu role puan yazılmaz</span>}
                  <Button size="xs" variant="ghost" onClick={() => setRows(rows.filter((_, xi) => xi !== i))} disabled={rows.length === 1}>Çıkar</Button>
                </div>
              ))}
              <div className="p-2.5">
                <StudentMultiPicker value={[]} placeholder="Öğrenci ekle" onChange={(v) => v[0] && !rows.some((r) => r.student.id === v[0]!.id) && setRows([...rows, { student: v[0]!, role: 'involved', behavior_id: null }])} />
              </div>
            </div>
            <p className="mt-1 text-[12px] text-ink-3">Davranışı değişmeyen öğrencinin puanı korunur. Yaptırımı olan öğrenci çıkarılamaz.</p>
          </Field>
        )}

        <div className="rounded-[var(--radius-md)] ring-1 ring-line">
          <button type="button" onClick={() => setMore((v) => !v)} className="flex w-full items-center justify-between px-3.5 py-2.5 text-[13px] font-medium text-ink-2 hover:text-ink">
            <span>Ayrıntılar: tarih, yer, ders, tanık{!editing && kind === 'negative' ? ', mağdur' : ''}{f.occurred_at && !more ? <span className="ml-1.5 font-normal text-ink-3">· Olay zamanı: {f.occurred_at.replace('T', ' ')}</span> : null}</span>
            <ChevronDown className={cn('size-4 transition-transform', more && 'rotate-180')} />
          </button>
          {more && (
            <div className="grid grid-cols-1 gap-3 border-t border-line p-3.5 sm:grid-cols-2">
              <Field label="Olay tarihi ve saati" required error={err('occurred_at')}><Input type="datetime-local" value={f.occurred_at ?? ''} onChange={(e) => set('occurred_at', e.target.value)} /></Field>
              <Field label="Yer" optional error={err('location')}><Input value={f.location ?? ''} onChange={(e) => set('location', e.target.value)} placeholder="Sınıf, koridor, bahçe…" list="dsp-locations" /></Field>
              <datalist id="dsp-locations">{['Sınıf', 'Koridor', 'Kantin', 'Bahçe', 'Etüt salonu', 'Kurum önü', 'Deneme sınavı salonu', 'Çevrim içi'].map((l) => <option key={l} value={l} />)}</datalist>
              <Field label="Sınıf" optional error={err('class_group_id')}><Select value={f.class_group_id ?? ''} onChange={(e) => set('class_group_id', e.target.value)} placeholder="Seçilmedi" options={(opt?.class_groups ?? []).map((c) => ({ value: c.id, label: c.name }))} /></Field>
              <Field label="Ders" optional error={err('subject_id')}><Select value={f.subject_id ?? ''} onChange={(e) => set('subject_id', e.target.value)} placeholder="Seçilmedi" options={(opt?.subjects ?? []).map((c) => ({ value: c.id, label: c.name }))} /></Field>
              <Field label="Öğretmen" optional error={err('teacher_id')}><Select value={f.teacher_id ?? ''} onChange={(e) => set('teacher_id', e.target.value)} placeholder="Seçilmedi" options={(opt?.teachers ?? []).map((c) => ({ value: c.id, label: c.name }))} /></Field>
              {kind === 'negative' && (
                <Field label="Ciddiyet" optional hint="Boş bırakılırsa davranıştan belirlenir." error={err('severity')}>
                  <Select value={f.severity ?? ''} onChange={(e) => set('severity', e.target.value)} placeholder="Davranışa göre" options={Object.entries(opt?.severities ?? {}).map(([value, label]) => ({ value, label }))} />
                </Field>
              )}
              <Field label="Başlık" optional className="sm:col-span-2" error={err('title')}><Input value={f.title ?? ''} onChange={(e) => set('title', e.target.value)} placeholder="Kısa başlık" maxLength={160} /></Field>
              <Field label="Tanıklar (personel / diğer)" optional className="sm:col-span-2" error={err('witnesses')}>
                <Input value={f.witnesses ?? ''} onChange={(e) => set('witnesses', e.target.value)} placeholder="Örn. Nöbetçi öğretmen Ayşe Hanım, kantin görevlisi" maxLength={500} />
              </Field>
              {!editing && kind === 'negative' && (
                <Field label="Mağdur / tanık öğrenciler" optional className="sm:col-span-2" hint="Bu öğrencilere puan yazılmaz.">
                  <div className="flex flex-col gap-2">
                    {others.map((o, i) => (
                      <div key={o.student.id} className="flex items-center gap-2">
                        <span className="min-w-0 flex-1 truncate text-[13px]">{o.student.full_name}</span>
                        <Select aria-label="Öğrencinin rolü" className="w-32" value={o.role} onChange={(e) => setOthers(others.map((x, xi) => (xi === i ? { ...x, role: e.target.value } : x)))}
                          options={[{ value: 'victim', label: 'Mağdur' }, { value: 'witness', label: 'Tanık' }]} />
                        <Button size="xs" variant="ghost" onClick={() => setOthers(others.filter((_, xi) => xi !== i))}>Çıkar</Button>
                      </div>
                    ))}
                    <StudentMultiPicker value={[]} placeholder="Mağdur ya da tanık öğrenci ekle" onChange={(v) => v[0] && !others.some((o) => o.student.id === v[0]!.id) && setOthers([...others, { student: v[0]!, role: 'victim', behavior_id: null }])} />
                  </div>
                </Field>
              )}
            </div>
          )}
        </div>

        {editing && <Field label="Açıklama" optional error={err('description')}><Textarea rows={4} value={description} onChange={(e) => setDescription(e.target.value)} /></Field>}
        {Object.keys(errors).length > 0 && !err('students') && <Alert tone="danger">{Object.values(errors)[0]?.[0]}</Alert>}
      </div>
    </Drawer>
  )
}

function StepNo({ n }: { n: number }) {
  return <span className="mr-1.5 inline-grid size-[18px] place-items-center rounded-full bg-primary text-[10.5px] font-semibold text-white align-[1px]">{n}</span>
}

export function useIncident(id: number | null) {
  return useQuery({
    queryKey: ['discipline', 'incident', id],
    queryFn: () => api.get<{ data: IncidentDetail }>(`/discipline/incidents/${id}`).then((r) => r.data),
    enabled: !!id,
  })
}
