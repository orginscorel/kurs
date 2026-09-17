import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Bell, MessageCircle, Phone, PhoneCall, StickyNote, Tag, Users } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, todayISO } from '@/lib/format'
import { useAuth, useCan } from '@/app/auth'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea, Switch } from '@/components/ui/form'
import { Badge, EmptyState, Skeleton, Alert } from '@/components/ui/feedback'
import { Tabs } from '@/components/ui/layout'
import { SchoolInput } from '@/components/forms/SchoolInput'
import { activityIconKind, type LeadDetail, type LeadOptions } from './types'

type Props = {
  leadId: number | 'new' | null
  initialTab?: 'info' | 'history' | 'convert'
  options?: LeadOptions
  onClose: () => void
  onConverted: (studentId: number) => void
  onChanged: () => void
}

const emptyForm = () => ({ first_name: '', last_name: '', phone: '', guardian_name: '', guardian_phone: '', email: '', school_name: '', school_grade: '', interested_program_id: '', source: 'phone', source_detail: '', owner_id: '', next_action: '', next_action_at: '' })

/** ISO tarihi <input type="datetime-local"> için İstanbul yerel saatine çevirir (YYYY-MM-DDTHH:mm). */
function toLocalInput(iso?: string | null): string {
  if (!iso) return ''
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Istanbul', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date(iso))
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '00'
  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`
}

const optL = <span className="font-normal text-ink-3"> (isteğe bağlı)</span>

export function LeadDrawer({ leadId, initialTab, options, onClose, onConverted, onChanged }: Props) {
  const can = useCan()
  const open = leadId !== null
  const isNew = leadId === 'new'
  const [tab, setTab] = useState<'info' | 'history' | 'convert'>('info')
  const [form, setForm] = useState<Record<string, any>>(emptyForm())
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const qc = useQueryClient()

  const detail = useQuery({
    queryKey: ['crm', 'leads', 'detail', leadId],
    queryFn: () => api.get<{ data: LeadDetail }>(`/crm/leads/${leadId}`).then((r) => r.data),
    enabled: typeof leadId === 'number',
  })

  useEffect(() => {
    if (!open) return
    setErrors({})
    setTab(initialTab ?? 'info')
    if (isNew) {
      setForm(emptyForm())
    } else if (detail.data) {
      const d = detail.data
      setForm({
        first_name: d.first_name, last_name: d.last_name, phone: d.phone, guardian_name: d.guardian_name ?? '', guardian_phone: d.guardian_phone ?? '',
        email: d.email ?? '', school_name: d.school_name ?? '', school_grade: d.school_grade ?? '', interested_program_id: d.program?.id ?? '',
        source: d.source, source_detail: d.source_detail ?? '', owner_id: d.owner?.id ?? '', next_action: d.next_action ?? '', next_action_at: toLocalInput(d.next_action_at),
        offered_price: d.offered_price ?? '',
      })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, isNew, detail.data])

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form }
      Object.keys(payload).forEach((k) => (payload[k] === '' && delete payload[k]))
      return isNew
        ? api.post<{ message: string; id: number }>('/crm/leads', payload)
        : api.put<{ message: string }>(`/crm/leads/${leadId}`, payload).then((r) => ({ ...r, id: leadId as number }))
    },
    onSuccess: (res) => {
      toast.success(res.message)
      onChanged()
      if (isNew) onClose()
      else qc.invalidateQueries({ queryKey: ['crm', 'leads', 'detail', leadId] })
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={620}
      title={isNew ? 'Yeni ön kayıt' : detail.data?.full_name ?? 'Ön kayıt'}
      description={isNew ? 'Öğrenci ve veli bilgilerini girin' : detail.data?.stage_label}
      footer={
        (tab === 'info' && can('crm.manage')) ? (
          <>
            <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
            <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{isNew ? 'Ön kaydı oluştur' : 'Kaydet'}</Button>
          </>
        ) : undefined
      }
    >
      {!isNew && (
        <Tabs
          value={tab}
          onChange={setTab}
          className="mb-4 -mt-1"
          tabs={[
            { value: 'info', label: 'Bilgiler' },
            { value: 'history', label: 'Geçmiş', count: detail.data?.activities.length ?? null },
            { value: 'convert', label: 'Öğrenci kaydına çevir', hidden: !!detail.data?.student_id || !can('students.create') },
          ]}
        />
      )}

      {!isNew && detail.isLoading && <div className="space-y-3"><Skeleton className="h-5 w-1/2" /><Skeleton className="h-24" /><Skeleton className="h-24" /></div>}

      {!isNew && detail.data?.student_id && tab !== 'history' && (
        <Alert tone="success" title="Bu ön kayıt öğrenci kaydına çevrildi" className="mb-4">
          Aşama ve bilgiler artık değiştirilemez.
        </Alert>
      )}

      {tab === 'info' && (isNew || detail.data) && (
        <div className="flex flex-col gap-5">
          <Section title="Öğrenci">
            <Field label="Ad" required error={err('first_name')}><Input value={form.first_name ?? ''} onChange={(e) => set('first_name', e.target.value)} disabled={!isNew && !!detail.data?.student_id} autoFocus /></Field>
            <Field label="Soyad" required error={err('last_name')}><Input value={form.last_name ?? ''} onChange={(e) => set('last_name', e.target.value)} disabled={!isNew && !!detail.data?.student_id} /></Field>
            <Field label={<>Telefonu{optL}</>} hint="Öğrenci ya da veli telefonundan biri yeterli" error={err('phone')}><Input type="tel" inputMode="tel" value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} placeholder="05xx xxx xx xx" disabled={!isNew && !!detail.data?.student_id} /></Field>
            <Field label={<>E-posta{optL}</>} error={err('email')}><Input type="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} disabled={!isNew && !!detail.data?.student_id} /></Field>
          </Section>

          <Section title="Veli">
            <Field label={<>Adı soyadı{optL}</>} error={err('guardian_name')}><Input value={form.guardian_name ?? ''} onChange={(e) => set('guardian_name', e.target.value)} placeholder="Örn. Ali Yılmaz" disabled={!isNew && !!detail.data?.student_id} /></Field>
            <Field label={<>Telefonu{optL}</>} error={err('guardian_phone')}><Input type="tel" inputMode="tel" value={form.guardian_phone ?? ''} onChange={(e) => set('guardian_phone', e.target.value)} placeholder="05xx xxx xx xx" disabled={!isNew && !!detail.data?.student_id} /></Field>
          </Section>

          <Section title="Okul ve ilgi">
            <Field label={<>Okul{optL}</>}><SchoolInput value={form.school_name ?? ''} onChange={(v) => set('school_name', v)} disabled={!isNew && !!detail.data?.student_id} /></Field>
            <Field label={<>Sınıf{optL}</>}>
              <Select value={form.school_grade ?? ''} onChange={(e) => set('school_grade', e.target.value)} placeholder="Seçilmedi" disabled={!isNew && !!detail.data?.student_id}
                options={[...['9', '10', '11', '12'].map((g) => ({ value: g, label: `${g}. sınıf` })), { value: 'Mezun', label: 'Mezun' }]} />
            </Field>
            <Field label={<>İlgilendiği program{optL}</>} className="sm:col-span-2">
              <Select value={form.interested_program_id ?? ''} onChange={(e) => set('interested_program_id', e.target.value)} placeholder="Seçilmedi" disabled={!isNew && !!detail.data?.student_id}
                options={(options?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
            </Field>
          </Section>

          <Section title="Takip">
            <Field label="Nereden duydu" required error={err('source')}>
              <Select value={form.source ?? ''} onChange={(e) => set('source', e.target.value)} disabled={!isNew && !!detail.data?.student_id}
                options={Object.entries(options?.sources ?? {}).map(([value, label]) => ({ value, label }))} />
            </Field>
            <Field label={<>Açıklama{optL}</>} hint="Örn. tavsiye eden kişi, afişin yeri"><Input value={form.source_detail ?? ''} onChange={(e) => set('source_detail', e.target.value)} disabled={!isNew && !!detail.data?.student_id} /></Field>
            <Field label={<>Takip eden personel{optL}</>} className="sm:col-span-2">
              <Select value={form.owner_id ?? ''} onChange={(e) => set('owner_id', e.target.value)} placeholder="Atanmadı" disabled={!isNew && !!detail.data?.student_id}
                options={(options?.owners ?? []).map((o) => ({ value: o.id, label: o.name }))} />
            </Field>
          </Section>

          {!isNew && (
            <Section title="Teklif">
              <Field label={<>Teklif edilen fiyat (₺){optL}</>}><Input type="number" min={0} value={form.offered_price ?? ''} onChange={(e) => set('offered_price', e.target.value)} disabled={!!detail.data?.student_id} /></Field>
            </Section>
          )}

          <Section title="Arama planı">
            <Field label={<>Ne zaman aranacak{optL}</>} hint='Boş bırakılırsa ilk arama için "Aranacak" listesinde bekler'><Input type="datetime-local" value={form.next_action_at ?? ''} onChange={(e) => set('next_action_at', e.target.value)} disabled={!isNew && !!detail.data?.student_id} /></Field>
            <Field label={<>Arama notu{optL}</>}><Input value={form.next_action ?? ''} onChange={(e) => set('next_action', e.target.value)} placeholder="Örn. Fiyat bilgisi verilecek" disabled={!isNew && !!detail.data?.student_id} /></Field>
          </Section>

          {!isNew && detail.data && !detail.data.student_id && can('crm.manage') && (
            <QuickActions lead={detail.data} onDone={() => { qc.invalidateQueries({ queryKey: ['crm', 'leads', 'detail', leadId] }); onChanged() }} />
          )}
        </div>
      )}

      {!isNew && tab === 'history' && detail.data && <HistoryTab lead={detail.data} onChanged={() => qc.invalidateQueries({ queryKey: ['crm', 'leads', 'detail', leadId] })} />}

      {!isNew && tab === 'convert' && detail.data && (
        <ConvertPanel lead={detail.data} onConverted={onConverted} />
      )}
    </Drawer>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <h3 className="mb-3 text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">{title}</h3>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">{children}</div>
    </div>
  )
}

function QuickActions({ lead, onDone }: { lead: LeadDetail; onDone: () => void }) {
  const [kind, setKind] = useState<null | 'call' | 'meeting' | 'whatsapp' | 'note' | 'offer'>(null)
  const [body, setBody] = useState('')

  const activity = useMutation({
    mutationFn: () => api.post(`/crm/leads/${lead.id}/activities`, { kind, body: body || undefined }),
    onSuccess: () => { toast.success('Kayıt eklendi.'); setKind(null); setBody(''); onDone() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const startWhatsapp = () => {
    const digits = lead.phone.replace(/\D/g, '')
    const intl = digits.startsWith('90') ? digits : digits.startsWith('0') ? `90${digits.slice(1)}` : `90${digits}`
    window.open(`https://wa.me/${intl}`, '_blank', 'noopener')
    setKind('whatsapp')
  }

  return (
    <div>
      <h3 className="mb-3 text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">Hızlı kayıt</h3>
      <div className="flex flex-wrap gap-2">
        <Button size="sm" icon={<PhoneCall className="size-3.5" />} onClick={() => setKind('call')}>Arandı</Button>
        <Button size="sm" icon={<Users className="size-3.5" />} onClick={() => setKind('meeting')}>Görüşme</Button>
        <Button size="sm" icon={<MessageCircle className="size-3.5" />} onClick={startWhatsapp}>WhatsApp</Button>
        <Button size="sm" icon={<StickyNote className="size-3.5" />} onClick={() => setKind('note')}>Not</Button>
        <Button size="sm" icon={<Tag className="size-3.5" />} onClick={() => setKind('offer')}>Teklif</Button>
      </div>
      {kind && (
        <div className="mt-3 animate-fade-in">
          <Textarea rows={2} value={body} onChange={(e) => setBody(e.target.value)} placeholder={kind === 'whatsapp' ? 'Gönderilen mesajı not edin (isteğe bağlı)' : 'Not ekleyin'} autoFocus />
          <div className="mt-2 flex justify-end gap-2">
            <Button size="sm" variant="ghost" onClick={() => { setKind(null); setBody('') }}>Vazgeç</Button>
            <Button size="sm" variant="primary" loading={activity.isPending} onClick={() => activity.mutate()}>Kaydet</Button>
          </div>
        </div>
      )}
    </div>
  )
}

function HistoryTab({ lead, onChanged }: { lead: LeadDetail; onChanged: () => void }) {
  const me = useAuth((s) => s.me)
  const [showTask, setShowTask] = useState(false)
  const [dueAt, setDueAt] = useState('')
  const [title, setTitle] = useState('')

  const createTask = useMutation({
    mutationFn: () => api.post('/tasks', { title: title || `Aday ile ilgilen: ${lead.full_name}`, taskable_type: 'lead', taskable_id: lead.id, due_at: dueAt, assigned_to: me?.user.id }),
    onSuccess: () => { toast.success('Hatırlatma görevi oluşturuldu.'); setShowTask(false); setTitle(''); setDueAt(''); onChanged() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Görev oluşturulamadı.'),
  })

  return (
    <div className="flex flex-col gap-5">
      <div>
        <div className="mb-3 flex items-center justify-between">
          <h3 className="text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">Görevler</h3>
          <Button size="xs" variant="ghost" icon={<Bell className="size-3.5" />} onClick={() => setShowTask((v) => !v)}>Hatırlatma ekle</Button>
        </div>
        {showTask && (
          <div className="mb-3 flex flex-col gap-2 rounded-[var(--radius-md)] ring-1 ring-line p-3 animate-fade-in">
            <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder={`Aday ile ilgilen: ${lead.full_name}`} />
            <Input type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} />
            <div className="flex justify-end gap-2">
              <Button size="sm" variant="ghost" onClick={() => setShowTask(false)}>Vazgeç</Button>
              <Button size="sm" variant="primary" disabled={!dueAt} loading={createTask.isPending} onClick={() => createTask.mutate()}>Oluştur</Button>
            </div>
          </div>
        )}
        {lead.tasks.length === 0 ? <p className="text-[13px] text-ink-3">Görev yok.</p> : (
          <ul className="flex flex-col gap-1.5">
            {lead.tasks.map((t) => (
              <li key={t.id} className="flex items-center justify-between rounded-[var(--radius-sm)] px-2.5 py-1.5 ring-1 ring-line text-[13px]">
                <span className={t.completed_at ? 'text-ink-3 line-through' : 'text-ink'}>{t.title}</span>
                <span className="text-[12px] text-ink-3 tabular">{t.due_at ? dateTime(t.due_at) : '—'}</span>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div>
        <h3 className="mb-3 text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">Zaman çizelgesi</h3>
        {lead.activities.length === 0 ? (
          <EmptyState compact icon={<Phone />} title="Henüz kayıt yok" />
        ) : (
          <ol className="flex flex-col gap-3">
            {lead.activities.map((a) => (
              <li key={a.id} className="relative pl-4 border-l-2 border-line">
                <div className="absolute -left-[5px] top-1 size-2 rounded-full bg-primary" />
                <div className="flex items-center gap-2 text-[12.5px]">
                  <Badge tone="neutral">{activityIconKind[a.kind] ?? a.kind}</Badge>
                  <span className="text-ink-3">{dateTime(a.created_at)}</span>
                  {a.user && <span className="text-ink-3">· {a.user}</span>}
                </div>
                {a.body && <p className="mt-1 text-[13.5px] text-ink-2">{a.body}</p>}
              </li>
            ))}
          </ol>
        )}
      </div>
    </div>
  )
}

const plusMonthISO = (iso: string) => {
  const [y, m, d] = iso.split('-').map(Number)
  const dt = new Date(y!, m!, Math.min(d!, 28))
  return `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`
}
const opt = <span className="font-normal text-ink-3"> (isteğe bağlı)</span>

function ConvertPanel({ lead, onConverted }: { lead: LeadDetail; onConverted: (id: number) => void }) {
  const can = useCan()
  const [withEnrollment, setWithEnrollment] = useState(false)
  const [student, setStudent] = useState<Record<string, any>>({})
  const [guardian, setGuardian] = useState({ first_name: '', last_name: '', phone: '', relationship: 'parent' })
  const [enrollment, setEnrollment] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const options = useQuery({ queryKey: ['students', 'options'], queryFn: () => api.get<any>('/students/options'), enabled: withEnrollment, staleTime: 5 * 60_000 })

  useEffect(() => {
    setStudent({
      first_name: lead.first_name, last_name: lead.last_name, phone: lead.phone ?? '', school_name: lead.school_name ?? '',
      school_grade: lead.school_grade ?? '', registered_on: todayISO(),
    })
    const parts = (lead.guardian_name ?? '').trim().split(/\s+/).filter(Boolean)
    // Ön kayıtta veli yalnız adıyla girilmişse soyadı öğrencinin soyadıyla önerilir
    const gl = parts.length > 1 ? parts.slice(1).join(' ') : lead.last_name
    setGuardian({ first_name: parts[0] ?? '', last_name: parts.length ? gl : '', phone: lead.guardian_phone ?? '', relationship: 'parent' })
  }, [lead])

  // Kayıt bölümü açılınca ekranda görünen varsayılanlar gerçekten forma yazılır (önceden yalnız gösteriliyordu → sunucu "zorunlu" diyordu)
  useEffect(() => {
    if (!withEnrollment || !options.data) return
    const today = todayISO()
    const current = (options.data.terms ?? []).find((t: any) => t.is_current) ?? options.data.terms?.[0]
    setEnrollment((x) => ({
      academic_term_id: x.academic_term_id ?? current?.id ?? '',
      program_id: x.program_id ?? lead.program?.id ?? '',
      list_price: x.list_price ?? lead.offered_price ?? '',
      down_payment: x.down_payment ?? 0,
      installment_count: x.installment_count ?? 8,
      enrolled_on: x.enrolled_on ?? today,
      first_due_date: x.first_due_date ?? plusMonthISO(today),
      ...x,
    }))
  }, [withEnrollment, options.data, lead])

  const setS = (k: string, v: string) => setStudent((s: any) => ({ ...s, [k]: v }))
  const setE = (k: string, v: string) => setEnrollment((x) => ({ ...x, [k]: v }))
  const err = (k: string) => errors[k]?.[0]

  const localCheck = () => {
    const e: Record<string, string[]> = {}
    const need = (k: string, v: unknown, msg: string) => { if (v === undefined || v === null || String(v).trim() === '') e[k] = [msg] }
    need('student.first_name', student.first_name, 'Öğrencinin adını yazın.')
    need('student.last_name', student.last_name, 'Öğrencinin soyadını yazın.')
    need('student.guardians.0.first_name', guardian.first_name, 'Velinin adını yazın.')
    need('student.guardians.0.last_name', guardian.last_name, 'Velinin soyadını yazın.')
    need('student.guardians.0.phone', guardian.phone, 'Velinin telefonunu yazın.')
    if (withEnrollment) {
      need('enrollment.academic_term_id', enrollment.academic_term_id, 'Dönem seçin.')
      need('enrollment.program_id', enrollment.program_id, 'Program seçin.')
      need('enrollment.list_price', enrollment.list_price, 'Liste fiyatını yazın.')
      need('enrollment.installment_count', enrollment.installment_count, 'Taksit sayısını yazın.')
      need('enrollment.enrolled_on', enrollment.enrolled_on, 'Kayıt tarihini seçin.')
      need('enrollment.first_due_date', enrollment.first_due_date, 'İlk taksit vadesini seçin.')
    }
    return e
  }

  const convert = useMutation({
    mutationFn: () => {
      const clean = Object.fromEntries(Object.entries(student).map(([k, v]) => [k, typeof v === 'string' && v.trim() === '' ? null : v]))
      return api.post<{ message: string; student_id: number }>(`/crm/leads/${lead.id}/convert`, {
        student: { ...clean, first_name: student.first_name?.trim(), last_name: student.last_name?.trim(), guardians: [{ ...guardian, is_primary: true }] },
        enrollment: withEnrollment ? enrollment : undefined,
      })
    },
    onSuccess: (res) => { toast.success(res.message); onConverted(res.student_id) },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } else toast.error('Kayıt oluşturulamadı.') },
  })

  const submit = () => {
    const e = localCheck()
    setErrors(e)
    if (Object.keys(e).length) {
      toast.error(`${Object.keys(e).length} zorunlu alan eksik: ${Object.values(e).map((x) => x[0]).join(' ')}`)
      return
    }
    convert.mutate()
  }

  if (!can('students.create')) return <Alert tone="warning">Öğrenci oluşturma yetkiniz yok.</Alert>

  return (
    <div className="flex flex-col gap-5">
      <Alert tone="info">Ön kayıt bilgileri forma aktarıldı. <b>*</b> işaretli alanlar zorunludur; diğerleri sonradan da doldurulabilir.</Alert>
      <Section title="Öğrenci">
        <Field label="Ad" required error={err('student.first_name')}><Input value={student.first_name ?? ''} onChange={(e) => setS('first_name', e.target.value)} /></Field>
        <Field label="Soyad" required error={err('student.last_name')}><Input value={student.last_name ?? ''} onChange={(e) => setS('last_name', e.target.value)} /></Field>
        <Field label={<>Telefon{opt}</>} error={err('student.phone')}><Input value={student.phone ?? ''} onChange={(e) => setS('phone', e.target.value)} inputMode="tel" /></Field>
        <Field label={<>Kayıt tarihi{opt}</>} error={err('student.registered_on')}><Input type="date" value={student.registered_on ?? ''} onChange={(e) => setS('registered_on', e.target.value)} /></Field>
        <Field label={<>Okul{opt}</>} error={err('student.school_name')}><SchoolInput value={student.school_name ?? ''} onChange={(v) => setS('school_name', v)} /></Field>
        <Field label={<>Sınıf seviyesi{opt}</>} error={err('student.school_grade')}>
          <Select value={student.school_grade ?? ''} onChange={(e) => setS('school_grade', e.target.value)} placeholder="Seçin"
            options={['9', '10', '11', '12', 'Mezun'].map((g) => ({ value: g, label: g === 'Mezun' ? 'Mezun' : `${g}. sınıf` }))} />
        </Field>
      </Section>
      <Section title="Veli">
        <Field label="Ad" required error={err('student.guardians.0.first_name')}><Input value={guardian.first_name} onChange={(e) => setGuardian((g) => ({ ...g, first_name: e.target.value }))} /></Field>
        <Field label="Soyad" required error={err('student.guardians.0.last_name')}><Input value={guardian.last_name} onChange={(e) => setGuardian((g) => ({ ...g, last_name: e.target.value }))} /></Field>
        <Field label="Telefon" required error={err('student.guardians.0.phone')}><Input value={guardian.phone} onChange={(e) => setGuardian((g) => ({ ...g, phone: e.target.value }))} inputMode="tel" placeholder="05xx xxx xx xx" /></Field>
        <Field label={<>Yakınlık{opt}</>}>
          <Select value={guardian.relationship} onChange={(e) => setGuardian((g) => ({ ...g, relationship: e.target.value }))}
            options={[{ value: 'parent', label: 'Veli' }, { value: 'mother', label: 'Anne' }, { value: 'father', label: 'Baba' }, { value: 'guardian', label: 'Vasi' }, { value: 'other', label: 'Diğer' }]} />
        </Field>
      </Section>

      {can('enrollments.create') && (
        <div className="rounded-[var(--radius-lg)] bg-surface-2/60 ring-1 ring-line p-4">
          <Switch checked={withEnrollment} onChange={setWithEnrollment} label={<span className="font-medium">Kayıt ve ödeme planı da oluştur <span className="font-normal text-ink-3">(isteğe bağlı; sonra öğrenci sayfasından da yapılabilir)</span></span>} />
          {withEnrollment && (
            options.isLoading ? <Skeleton className="mt-4 h-40" /> : (
              <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 animate-fade-in">
                <Field label="Dönem" required error={err('enrollment.academic_term_id')}>
                  <Select value={enrollment.academic_term_id ?? ''} onChange={(e) => setE('academic_term_id', e.target.value)} placeholder="Seçin"
                    options={(options.data?.terms ?? []).map((t: any) => ({ value: t.id, label: t.name }))} />
                </Field>
                <Field label="Program" required error={err('enrollment.program_id')}>
                  <Select value={enrollment.program_id ?? ''} onChange={(e) => setE('program_id', e.target.value)} placeholder="Seçin"
                    options={(options.data?.programs ?? []).map((p: any) => ({ value: p.id, label: p.name }))} />
                </Field>
                <Field label="Liste fiyatı (₺)" required error={err('enrollment.list_price')}><Input type="number" min={0} value={enrollment.list_price ?? ''} onChange={(e) => setE('list_price', e.target.value)} /></Field>
                <Field label={<>Peşinat (₺){opt}</>} error={err('enrollment.down_payment')}><Input type="number" min={0} value={enrollment.down_payment ?? 0} onChange={(e) => setE('down_payment', e.target.value)} /></Field>
                <Field label="Taksit sayısı" required hint="0 = peşin" error={err('enrollment.installment_count')}><Input type="number" min={0} max={36} value={enrollment.installment_count ?? ''} onChange={(e) => setE('installment_count', e.target.value)} /></Field>
                <Field label="Kayıt tarihi" required error={err('enrollment.enrolled_on')}><Input type="date" value={enrollment.enrolled_on ?? ''} onChange={(e) => setE('enrolled_on', e.target.value)} /></Field>
                <Field label="İlk taksit vadesi" required error={err('enrollment.first_due_date')} className="sm:col-span-2"><Input type="date" value={enrollment.first_due_date ?? ''} onChange={(e) => setE('first_due_date', e.target.value)} /></Field>
              </div>
            )
          )}
        </div>
      )}

      <div className="flex justify-end">
        <Button variant="primary" loading={convert.isPending} onClick={submit}>Öğrenci kaydını oluştur</Button>
      </div>
    </div>
  )
}
