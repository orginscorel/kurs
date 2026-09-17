import { useEffect, useMemo, useState } from 'react'
import { SchoolInput } from '@/components/forms/SchoolInput'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { money, todayISO } from '@/lib/format'
import { useCan } from '@/app/auth'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'
import { fieldOptions, relationshipOptions, type GuardianInput, type MarketingConsents, type StudentDetailData, type StudentOptions } from './types'

type Props = {
  open: boolean
  onClose: () => void
  onSaved: (id: number) => void
  options?: StudentOptions
  student?: StudentDetailData['student']
}

const noConsent = (): MarketingConsents => ({ sms: false, email: false, whatsapp: false })
const emptyGuardian = (): GuardianInput => ({ first_name: '', last_name: '', phone: '', relationship: 'mother', is_primary: true, whatsapp_consent: true, marketing_consents: noConsent() })

/** Ticari ileti onayı (SMS / e-posta / WhatsApp) — varsayılan kapalı; kaynak "Kayıt formu" olarak kaydedilir. */
function MarketingConsentFields({ value, onChange, idPrefix }: { value: MarketingConsents; onChange: (v: MarketingConsents) => void; idPrefix: string }) {
  return (
    <fieldset className="flex flex-wrap items-center gap-x-4 gap-y-2" aria-label="Ticari ileti onayı">
      <legend className="sr-only">Ticari ileti onayı</legend>
      <span className="text-[12.5px] text-ink-3">Ticari ileti onayı:</span>
      {(['sms', 'email', 'whatsapp'] as const).map((c) => (
        <Checkbox key={`${idPrefix}-${c}`} checked={value[c]} onChange={(v) => onChange({ ...value, [c]: v })} label={c === 'sms' ? 'SMS' : c === 'email' ? 'E-posta' : 'WhatsApp'} />
      ))}
    </fieldset>
  )
}

/** Kuruş farkını son taksite yazan plan önizlemesi (sunucu hesabının aynısı; kesin hesap sunucuda). */
function previewPlan(net: number, down: number, count: number, firstDue: string) {
  const rows: { due: string; amount: number }[] = []
  if (net <= 0) return rows
  if (down > 0) rows.push({ due: todayISO(), amount: down })
  const remaining = Math.round((net - down) * 100)
  if (remaining > 0 && count > 0) {
    const each = Math.floor(remaining / count)
    const [y, m, d] = firstDue.split('-').map(Number)
    for (let i = 0; i < count; i++) {
      const cents = i === count - 1 ? remaining - each * (count - 1) : each
      const date = new Date(y!, m! - 1 + i, 1)
      const last = new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()
      date.setDate(Math.min(d!, last))
      rows.push({ due: date.toLocaleDateString('tr-TR'), amount: cents / 100 })
    }
  }
  return rows
}

export function StudentFormDrawer({ open, onClose, onSaved, options, student }: Props) {
  const can = useCan()
  const editing = !!student
  const [form, setForm] = useState<Record<string, any>>({})
  const [guardians, setGuardians] = useState<GuardianInput[]>([emptyGuardian()])
  const [tagIds, setTagIds] = useState<number[]>([])
  const [consents, setConsents] = useState<MarketingConsents>(noConsent())
  const [withEnrollment, setWithEnrollment] = useState(false)
  const [enrollment, setEnrollment] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!open) return
    setErrors({})
    if (student) {
      setForm({
        first_name: student.first_name, last_name: student.last_name, national_id: '', birth_date: student.birth_date ?? '', gender: student.gender ?? '',
        school_name: student.school_name ?? '', school_grade: student.school_grade ?? '', field: student.field ?? '', target_university: student.target_university ?? '',
        target_department: student.target_department ?? '', phone: student.phone?.includes('•') ? undefined : (student.phone ?? ''), email: student.email ?? '',
        address: student.address ?? '', guidance_teacher_id: student.guidance_teacher?.id ?? '', medical_notes: student.medical_notes ?? '', notes: student.notes ?? '',
      })
      setGuardians(student.guardians.map((g) => ({ ...g, phone: g.phone?.includes('•') ? '' : (g.phone ?? ''), marketing_consents: g.marketing_consents ?? noConsent() })))
      setTagIds(student.tag_ids)
      setConsents(student.marketing_consents ?? noConsent())
      setWithEnrollment(false)
    } else {
      setForm({ registered_on: todayISO() })
      setGuardians([emptyGuardian()])
      setTagIds([])
      setConsents(noConsent())
      const term = options?.terms.find((t) => t.is_current) ?? options?.terms[0]
      const nextMonth = new Date()
      nextMonth.setMonth(nextMonth.getMonth() + 1, 15)
      setEnrollment({ academic_term_id: term?.id ?? '', enrolled_on: todayISO(), installment_count: 8, down_payment: 0, discount_amount: 0, scholarship_amount: 0, first_due_date: nextMonth.toISOString().slice(0, 10) })
      setWithEnrollment(can('enrollments.create'))
    }
  }, [open, student, options, can])

  const set = (key: string, value: unknown) => setForm((f) => ({ ...f, [key]: value }))
  const setE = (key: string, value: unknown) => setEnrollment((f) => ({ ...f, [key]: value }))
  const err = (key: string) => errors[key]?.[0]

  const net = Math.max(0, Number(enrollment.list_price || 0) - Number(enrollment.discount_amount || 0) - Number(enrollment.scholarship_amount || 0))
  const plan = useMemo(
    () => (withEnrollment && enrollment.first_due_date ? previewPlan(net, Number(enrollment.down_payment || 0), Number(enrollment.installment_count || 0), enrollment.first_due_date) : []),
    [withEnrollment, net, enrollment.down_payment, enrollment.installment_count, enrollment.first_due_date],
  )

  /** Sunucuya gitmeden önce zorunlu alan kontrolü — eksikleri alanların altında ve tek mesajda gösterir. */
  const localCheck = (): boolean => {
    const e: Record<string, string[]> = {}
    const need = (key: string, ok: unknown, label: string) => { if (!ok) e[key] = [`${label} zorunludur.`] }
    need('first_name', String(form.first_name ?? '').trim(), 'Ad')
    need('last_name', String(form.last_name ?? '').trim(), 'Soyad')
    if (form.national_id && String(form.national_id).length !== 11) e.national_id = ['TC kimlik numarası 11 haneli olmalıdır.']
    if (!editing && guardians.length === 0) e.guardians = ['En az bir veli bilgisi girilmelidir.']
    guardians.forEach((g, i) => {
      if (g.id) return
      need(`guardians.${i}.first_name`, g.first_name.trim(), 'Veli adı')
      need(`guardians.${i}.last_name`, g.last_name.trim(), 'Veli soyadı')
      need(`guardians.${i}.phone`, (g.phone ?? '').trim(), 'Veli telefonu')
    })
    if (!editing && withEnrollment) {
      need('enrollment.academic_term_id', enrollment.academic_term_id, 'Dönem')
      need('enrollment.program_id', enrollment.program_id, 'Program')
      need('enrollment.list_price', enrollment.list_price !== '' && enrollment.list_price !== undefined, 'Liste fiyatı')
      need('enrollment.installment_count', enrollment.installment_count !== '' && enrollment.installment_count !== undefined, 'Taksit sayısı')
      need('enrollment.first_due_date', enrollment.first_due_date, 'İlk taksit vadesi')
    }
    setErrors(e)
    const msgs = Object.values(e).map((x) => x[0])
    if (msgs.length) toast.error(msgs.length === 1 ? msgs[0]! : `Eksik bilgiler: ${msgs.map((m) => m.replace(/ zorunludur\.$/, '')).join(', ')}`)
    return msgs.length === 0
  }

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = { ...form, tag_ids: tagIds, marketing_consents: consents, guardians: guardians.map((g) => ({ ...g, phone: g.phone || undefined })) }
      Object.keys(payload).forEach((k) => (payload[k] === '' || payload[k] === undefined) && delete payload[k])
      if (editing && !form.national_id) delete payload.national_id
      if (!editing && withEnrollment) payload.enrollment = enrollment
      return editing ? api.put<{ message: string }>(`/students/${student!.id}`, payload).then((r) => ({ ...r, id: student!.id })) : api.post<{ message: string; id: number }>('/students', payload)
    },
    onSuccess: (res) => {
      toast.success(res.message)
      onClose()
      onSaved(res.id)
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      }
    },
  })

  const programPackages = (options?.packages ?? []).filter((p) => !enrollment.program_id || p.program_id === Number(enrollment.program_id))
  const programGroups = (options?.class_groups ?? []).filter((g) => !enrollment.program_id || g.program_id === Number(enrollment.program_id))

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={680}
      title={editing ? 'Öğrenciyi düzenle' : 'Yeni öğrenci'}
      description={editing ? student?.full_name : 'Öğrenci, veli ve isteğe bağlı kayıt/ödeme planı tek adımda oluşturulur.'}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => localCheck() && save.mutate()}>{editing ? 'Kaydet' : 'Öğrenciyi oluştur'}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-7">
        <p className="-mb-3 text-[12.5px] text-ink-3"><span className="text-danger">*</span> işaretli alanlar zorunludur; diğerleri isteğe bağlıdır.</p>
        <Section title="Kimlik">
          <Field label="Ad" required error={err('first_name')}><Input value={form.first_name ?? ''} onChange={(e) => set('first_name', e.target.value)} autoFocus /></Field>
          <Field label="Soyad" required error={err('last_name')}><Input value={form.last_name ?? ''} onChange={(e) => set('last_name', e.target.value)} /></Field>
          <Field label="TC kimlik no" optional error={err('national_id')} hint={editing ? `Kayıtlı: ${student?.national_id_masked ?? 'yok'} — değiştirmek için yazın` : 'Şifreli saklanır'}>
            <Input inputMode="numeric" maxLength={11} value={form.national_id ?? ''} onChange={(e) => set('national_id', e.target.value.replace(/\D/g, ''))} />
          </Field>
          <Field label="Doğum tarihi" optional error={err('birth_date')}><Input type="date" value={form.birth_date ?? ''} onChange={(e) => set('birth_date', e.target.value)} /></Field>
          <Field label="Cinsiyet" optional error={err('gender')}><Select value={form.gender ?? ''} onChange={(e) => set('gender', e.target.value)} placeholder="Seçin" options={[{ value: 'female', label: 'Kadın' }, { value: 'male', label: 'Erkek' }]} /></Field>
          <Field label="Kayıt tarihi" optional error={err('registered_on')} hint="Boş bırakılırsa bugün"><Input type="date" value={form.registered_on ?? ''} onChange={(e) => set('registered_on', e.target.value)} /></Field>
        </Section>

        <Section title="Okul ve hedef">
          <Field label="Okul" optional error={err('school_name')} className="sm:col-span-2"><SchoolInput value={form.school_name ?? ''} onChange={(v) => set('school_name', v)} /></Field>
          <Field label="Sınıf seviyesi" optional error={err('school_grade')}><Select value={form.school_grade ?? ''} onChange={(e) => set('school_grade', e.target.value)} placeholder="Seçin" options={['5', '6', '7', '8', '9', '10', '11', '12', 'Mezun'].map((v) => ({ value: v, label: v === 'Mezun' ? 'Mezun' : `${v}. sınıf` }))} /></Field>
          <Field label="Alan" optional error={err('field')}><Select value={form.field ?? ''} onChange={(e) => set('field', e.target.value)} placeholder="Seçin" options={fieldOptions} /></Field>
          <Field label="Hedef üniversite" optional error={err('target_university')}><Input value={form.target_university ?? ''} onChange={(e) => set('target_university', e.target.value)} /></Field>
          <Field label="Hedef bölüm" optional error={err('target_department')}><Input value={form.target_department ?? ''} onChange={(e) => set('target_department', e.target.value)} /></Field>
          <Field label="Rehber öğretmen" optional error={err('guidance_teacher_id')} className="sm:col-span-2">
            <Select value={form.guidance_teacher_id ?? ''} onChange={(e) => set('guidance_teacher_id', e.target.value)} placeholder="Atanmadı" options={(options?.teachers ?? []).map((t) => ({ value: t.id, label: `${t.first_name} ${t.last_name}` }))} />
          </Field>
        </Section>

        <Section title="İletişim">
          <Field label="Öğrenci telefonu" optional error={err('phone')}><Input type="tel" value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} placeholder="05xx xxx xx xx" /></Field>
          <Field label="E-posta" optional error={err('email')}><Input type="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} /></Field>
          <Field label="Adres" optional error={err('address')} className="sm:col-span-2"><Input value={form.address ?? ''} onChange={(e) => set('address', e.target.value)} /></Field>
          <div className="sm:col-span-2 rounded-[var(--radius-md)] bg-surface-2/60 px-3 py-2.5 ring-1 ring-line">
            <MarketingConsentFields idPrefix="student" value={consents} onChange={setConsents} />
            <p className="mt-1 text-[12px] text-ink-3">Kampanya/tanıtım mesajları için öğrencinin açık onayı. İşaretlemezseniz ticari ileti gönderilmez; bilgilendirme mesajlarını etkilemez.</p>
          </div>
        </Section>

        <div>
          <div className="mb-3 flex items-center justify-between">
            <h3 className="text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">Veli bilgileri</h3>
            {guardians.length < 3 && (
              <Button size="xs" variant="ghost" icon={<Plus className="size-3.5" />} onClick={() => setGuardians((g) => [...g, { ...emptyGuardian(), is_primary: false, relationship: 'father' }])}>
                Veli ekle
              </Button>
            )}
          </div>
          {err('guardians') && <Alert tone="danger" className="mb-3">{err('guardians')}</Alert>}
          <div className="flex flex-col gap-3">
            {guardians.map((g, i) => {
              const up = (patch: Partial<GuardianInput>) => setGuardians((list) => list.map((x, j) => (j === i ? { ...x, ...patch } : patch.is_primary ? { ...x, is_primary: false } : x)))
              return (
                <div key={i} className="rounded-[var(--radius-md)] ring-1 ring-line p-3.5">
                  {guardians.length > 1 && <p className="mb-2.5 text-[12.5px] font-semibold text-ink-2">{i + 1}. veli</p>}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <Field label="Adı" required={!g.id} error={errors[`guardians.${i}.first_name`]?.[0]}><Input value={g.first_name} onChange={(e) => up({ first_name: e.target.value })} disabled={!!g.id && !can('guardians.manage')} /></Field>
                    <Field label="Soyadı" required={!g.id} error={errors[`guardians.${i}.last_name`]?.[0]}><Input value={g.last_name} onChange={(e) => up({ last_name: e.target.value })} disabled={!!g.id && !can('guardians.manage')} /></Field>
                    <Field label="Cep telefonu" required={!g.id} error={errors[`guardians.${i}.phone`]?.[0]} hint={g.id && !g.phone ? 'Kayıtlı numara korunur' : undefined}><Input type="tel" value={g.phone} onChange={(e) => up({ phone: e.target.value })} placeholder="05xx xxx xx xx" /></Field>
                    <Field label="Yakınlığı"><Select value={g.relationship} onChange={(e) => up({ relationship: e.target.value })} options={relationshipOptions} /></Field>
                  </div>
                  <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2">
                    <Checkbox checked={g.is_primary} onChange={(v) => v && up({ is_primary: true })} label="Birincil veli" />
                    <Checkbox checked={g.whatsapp_consent ?? true} onChange={(v) => up({ whatsapp_consent: v })} label="WhatsApp bilgilendirme izni" />
                    {guardians.length > 1 && (
                      <Button size="xs" variant="danger-soft" className="ml-auto" icon={<Trash2 className="size-3.5" />} onClick={() => setGuardians((list) => list.filter((_, j) => j !== i))}>
                        Kaldır
                      </Button>
                    )}
                  </div>
                  <div className="mt-2.5 border-t border-line pt-2.5">
                    <MarketingConsentFields idPrefix={`g${i}`} value={g.marketing_consents ?? noConsent()} onChange={(v) => up({ marketing_consents: v })} />
                  </div>
                </div>
              )
            })}
          </div>
        </div>

        {(options?.tags.length ?? 0) > 0 && (
          <div>
            <h3 className="mb-3 text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">Etiketler</h3>
            <div className="flex flex-wrap gap-2">
              {options!.tags.filter((t) => t.name !== 'Demo').map((t) => {
                const on = tagIds.includes(t.id)
                return (
                  <button
                    key={t.id}
                    type="button"
                    onClick={() => setTagIds((ids) => (on ? ids.filter((x) => x !== t.id) : [...ids, t.id]))}
                    className={on ? 'h-7 rounded-full px-3 text-[12.5px] font-medium bg-primary-soft text-primary-ink ring-1 ring-primary/20' : 'h-7 rounded-full px-3 text-[12.5px] text-ink-2 ring-1 ring-line hover:bg-surface-2'}
                  >
                    {t.name}
                  </button>
                )
              })}
            </div>
          </div>
        )}

        <Section title="Notlar">
          <Field label="Sağlık notu" optional className="sm:col-span-2"><Textarea rows={2} value={form.medical_notes ?? ''} onChange={(e) => set('medical_notes', e.target.value)} /></Field>
          <Field label="Genel not" optional className="sm:col-span-2"><Textarea rows={2} value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} /></Field>
        </Section>

        {!editing && can('enrollments.create') && (
          <div className="rounded-[var(--radius-lg)] bg-surface-2/60 ring-1 ring-line p-4">
            <Switch checked={withEnrollment} onChange={setWithEnrollment} label={<span className="font-medium">Kayıt ve ödeme planı oluştur <span className="font-normal text-ink-3">(isteğe bağlı; sonra öğrenci sayfasından da yapılabilir)</span></span>} />
            {withEnrollment && (
              <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 animate-fade-in">
                <Field label="Dönem" required error={errors['enrollment.academic_term_id']?.[0]}>
                  <Select value={enrollment.academic_term_id ?? ''} onChange={(e) => setE('academic_term_id', e.target.value)} placeholder="Seçin" options={(options?.terms ?? []).map((t) => ({ value: t.id, label: t.name }))} />
                </Field>
                <Field label="Program" required error={errors['enrollment.program_id']?.[0]}>
                  <Select value={enrollment.program_id ?? ''} onChange={(e) => setEnrollment((x) => ({ ...x, program_id: e.target.value, education_package_id: '', class_group_id: '' }))} placeholder="Seçin" options={(options?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
                </Field>
                <Field label="Eğitim paketi" optional>
                  <Select
                    value={enrollment.education_package_id ?? ''}
                    onChange={(e) => {
                      const pkg = options?.packages.find((p) => p.id === Number(e.target.value))
                      setEnrollment((x) => ({ ...x, education_package_id: e.target.value, list_price: pkg ? Number(pkg.list_price) : x.list_price, installment_count: pkg?.default_installments ?? x.installment_count }))
                    }}
                    placeholder="Paket yok"
                    options={programPackages.map((p) => ({ value: p.id, label: `${p.name} · ${money(p.list_price, { short: true })}` }))}
                  />
                </Field>
                <Field label="Sınıf" optional>
                  <Select value={enrollment.class_group_id ?? ''} onChange={(e) => setE('class_group_id', e.target.value)} placeholder="Sonra atanacak" options={programGroups.map((g) => ({ value: g.id, label: g.name }))} />
                </Field>
                <Field label="Liste fiyatı (₺)" required error={errors['enrollment.list_price']?.[0]}><Input type="number" min={0} value={enrollment.list_price ?? ''} onChange={(e) => setE('list_price', e.target.value)} /></Field>
                <Field label="İndirim (₺)" optional error={errors['enrollment.discount_amount']?.[0]}><Input type="number" min={0} value={enrollment.discount_amount ?? 0} onChange={(e) => setE('discount_amount', e.target.value)} /></Field>
                <Field label="Burs (₺)" optional error={errors['enrollment.scholarship_amount']?.[0]}><Input type="number" min={0} value={enrollment.scholarship_amount ?? 0} onChange={(e) => setE('scholarship_amount', e.target.value)} /></Field>
                <Field label="Peşinat (₺)" optional error={errors['enrollment.down_payment']?.[0]} hint="Kayıt günü vadeli ilk taksit olur"><Input type="number" min={0} value={enrollment.down_payment ?? 0} onChange={(e) => setE('down_payment', e.target.value)} /></Field>
                <Field label="Taksit sayısı" required hint="0 = peşin" error={errors['enrollment.installment_count']?.[0]}><Input type="number" min={0} max={36} value={enrollment.installment_count ?? 0} onChange={(e) => setE('installment_count', e.target.value)} /></Field>
                <Field label="İlk taksit vadesi" required error={errors['enrollment.first_due_date']?.[0]}><Input type="date" value={enrollment.first_due_date ?? ''} onChange={(e) => setE('first_due_date', e.target.value)} /></Field>

                {net > 0 && (
                  <div className="sm:col-span-2 rounded-[var(--radius-md)] bg-surface ring-1 ring-line">
                    <div className="flex items-center justify-between px-3.5 py-2.5 border-b border-line">
                      <span className="text-[13px] text-ink-2">Net kayıt bedeli</span>
                      <span className="text-[16px] font-semibold tabular">{money(net)}</span>
                    </div>
                    <ol className="max-h-48 overflow-y-auto scroll-thin px-3.5 py-2 text-[12.5px]">
                      {plan.map((row, i) => (
                        <li key={i} className="flex justify-between py-1 tabular">
                          <span className="text-ink-3">{i + 1}. {row.due}</span>
                          <span>{money(row.amount)}</span>
                        </li>
                      ))}
                    </ol>
                  </div>
                )}
              </div>
            )}
          </div>
        )}
      </div>
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
