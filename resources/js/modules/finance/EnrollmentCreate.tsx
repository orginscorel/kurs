import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarDays, CheckCircle2, UserPlus } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, money, todayISO } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Switch } from '@/components/ui/form'
import { MetricRow, MoneyInput, StudentPicker, type PickedStudent } from './components'
import { addDaysISO, addMonthsISO, fromCents, toCents } from './shared'

type Options = {
  terms: { id: number; name: string; starts_on: string; ends_on: string; is_current: boolean }[]
  programs: { id: number; name: string; code: string }[]
  packages: { id: number; name: string; type?: string; program_id: number | null; academic_term_id: number | null; list_price: string; default_installments: number; includes: string | null }[]
  class_groups: { id: number; name: string; program_id: number | null; academic_term_id: number | null; capacity: number; active_students: number }[]
}
type Guardian = { id: number; name: string; is_financially_responsible: boolean }
type Kind = 'course' | 'library' | 'study'
const KIND_LABEL: Record<Kind, string> = { course: 'Ders (sınıflı)', library: 'Kütüphane', study: 'Etüt' }

export default function EnrollmentCreate() {
  const can = useCan()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [student, setStudent] = useState<PickedStudent | null>(null)
  // Sihirbaz: yeni öğrenciyi de buradan ekle (arama yerine). Veli zorunlu (en az 1).
  const [newMode, setNewMode] = useState(false)
  const [ns, setNs] = useState({ first_name: '', last_name: '', g_first: '', g_last: '', g_phone: '' })
  // Kayıt türü: ders (sınıflı) / kütüphane / etüt. Kütüphane-etüt program/sınıf gerektirmez, mezun da alabilir.
  const [kind, setKind] = useState<Kind>('course')
  const [form, setForm] = useState({
    academic_term_id: '', program_id: '', education_package_id: '', class_group_id: '', financial_guardian_id: '',
    list_price: '', discount_amount: '', discount_reason: '', scholarship_amount: '', scholarship_reason: '',
    down_payment: '', installment_count: '8', enrolled_on: todayISO(), first_due_date: addMonthsISO(todayISO(), 1), create_contract: true,
  })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const set = (k: keyof typeof form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))

  const options = useQuery({ queryKey: ['finance', 'enrollment-options'], queryFn: () => api.get<Options>('/finance/enrollment-options'), staleTime: 5 * 60_000 })
  const initialId = Number(params.get('ogrenci') || 0) || null
  const initial = useQuery({
    queryKey: ['finance', 'student-context', initialId],
    queryFn: () => api.get<{ student: PickedStudent; guardians: Guardian[] }>(`/finance/students/${initialId}/context`),
    enabled: !!initialId && !student,
  })
  useEffect(() => {
    if (initial.data && !student) setStudent(initial.data.student)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initial.data])

  const guardians = useQuery({
    queryKey: ['finance', 'student-context', student?.id],
    queryFn: () => api.get<{ guardians: Guardian[] }>(`/finance/students/${student!.id}/context`),
    enabled: !!student,
  })
  useEffect(() => {
    const g = guardians.data?.guardians.find((x) => x.is_financially_responsible) ?? guardians.data?.guardians[0]
    set('financial_guardian_id', g ? String(g.id) : '')
  }, [guardians.data])

  useEffect(() => {
    const current = options.data?.terms.find((t) => t.is_current)
    if (current && !form.academic_term_id) set('academic_term_id', String(current.id))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [options.data])

  const termOk = (t: number | null) => !form.academic_term_id || !t || t === Number(form.academic_term_id)
  const packages = (options.data?.packages ?? []).filter((p) => {
    const pt = (p.type || 'course') as Kind
    if (kind === 'course') return pt === 'course' && (!form.program_id || p.program_id === Number(form.program_id)) && termOk(p.academic_term_id)
    return pt === kind && termOk(p.academic_term_id)
  })
  const groups = (options.data?.class_groups ?? []).filter((g) => (!form.program_id || g.program_id === Number(form.program_id)) && termOk(g.academic_term_id))
  const pkg = options.data?.packages.find((p) => p.id === Number(form.education_package_id))

  const listC = toCents(form.list_price) ?? 0
  const discC = toCents(form.discount_amount) ?? 0
  const scholC = toCents(form.scholarship_amount) ?? 0
  const netC = listC - discC - scholC
  const downC = toCents(form.down_payment) ?? 0

  // Kesin plan sunucudan (EnrollmentService::buildPlan) gelir; ekrandaki önizleme onunla birebir aynıdır.
  const previewInput = useDebounced(
    { list_price: form.list_price || '0', discount_amount: form.discount_amount || '0', scholarship_amount: form.scholarship_amount || '0', down_payment: form.down_payment || '0', installment_count: Number(form.installment_count || 0), enrolled_on: form.enrolled_on, first_due_date: form.first_due_date },
    300,
  )
  const preview = useQuery({
    queryKey: ['finance', 'preview-plan', previewInput],
    queryFn: () => api.post<{ data: { net_price: string; rows: { due_date: string; amount: string }[]; total: string } }>('/finance/enrollments/preview-plan', previewInput),
    enabled: listC > 0 && netC >= 0 && !!previewInput.enrolled_on && !!previewInput.first_due_date,
    retry: false,
  })
  const previewError = preview.error instanceof ApiError ? preview.error.firstError() : null

  const createStudent = useMutation({
    mutationFn: () => api.post<{ id: number }>('/students', {
      first_name: ns.first_name.trim(), last_name: ns.last_name.trim(),
      guardians: [{ first_name: ns.g_first.trim(), last_name: ns.g_last.trim(), phone: ns.g_phone.trim(), is_financially_responsible: true }],
    }),
    onSuccess: (r) => {
      setStudent({ id: r.id, full_name: `${ns.first_name.trim()} ${ns.last_name.trim()}`, student_no: '' })
      setNewMode(false)
      setNs({ first_name: '', last_name: '', g_first: '', g_last: '', g_phone: '' })
      toast.success('Öğrenci oluşturuldu; kayda devam edin.')
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Öğrenci oluşturulamadı.'),
  })
  const canCreateStudent = ns.first_name.trim().length >= 2 && ns.last_name.trim().length >= 2 && ns.g_first.trim().length >= 2 && ns.g_last.trim().length >= 2 && ns.g_phone.trim().length >= 7

  const save = useMutation({
    mutationFn: () =>
      api.post<{ message: string; id: number; enrollment_no: string }>('/finance/enrollments', {
        ...form,
        student_id: student?.id,
        education_package_id: form.education_package_id || null,
        class_group_id: form.class_group_id || null,
        financial_guardian_id: form.financial_guardian_id || null,
        list_price: fromCents(listC),
        discount_amount: fromCents(discC),
        scholarship_amount: fromCents(scholC),
        down_payment: fromCents(downC),
        installment_count: Number(form.installment_count || 0),
      }),
    onSuccess: (res) => {
      toast.success(`${res.enrollment_no} numaralı kayıt oluşturuldu.`)
      navigate(`/finans/kayitlar/${res.id}`)
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      } else toast.error('Kayıt oluşturulamadı.')
    },
  })

  // Eksik zorunlu alanlar düğmenin altında yazılır (düğme neden pasif belli olsun)
  const missing = [
    !student && 'Öğrenci',
    !form.academic_term_id && 'Dönem',
    kind === 'course' && !form.program_id && 'Program',
    kind !== 'course' && !form.education_package_id && 'Paket',
    !form.enrolled_on && 'Kayıt tarihi',
    listC <= 0 && 'Liste fiyatı',
    form.installment_count === '' && 'Taksit sayısı',
    !form.first_due_date && 'İlk taksit vadesi',
  ].filter(Boolean) as string[]
  const canSave = missing.length === 0 && netC >= 0 && !previewError && !preview.isFetching

  if (!can('enrollments.create')) return <EmptyState icon={<UserPlus />} title="Kayıt oluşturma yetkiniz yok" />

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Yeni kayıt"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Kayıtlar', to: '/finans/kayitlar' }, { label: 'Yeni kayıt' }]}
        description="Tek akışta: öğrenci (yeni ya da mevcut) → kayıt türü (ders / kütüphane / etüt) → paket → ücret ve ödeme planı → sözleşme."
      />

      <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_380px] gap-4 items-start">
        <div className="flex flex-col gap-4 min-w-0">
          <Panel title="Öğrenci" actions={!student && can('students.create') ? <Button size="sm" variant={newMode ? 'primary' : 'ghost'} icon={<UserPlus className="size-3.5" />} onClick={() => setNewMode((v) => !v)}>{newMode ? 'Aramaya dön' : 'Yeni öğrenci'}</Button> : undefined}>
            {student ? (
              <div className="flex items-center justify-between gap-3 rounded-[var(--radius-md)] bg-surface-2 px-3 py-2.5">
                <div><p className="font-medium">{student.full_name}</p>{student.student_no && <p className="text-[12px] text-ink-3 tabular">{student.student_no}</p>}</div>
                <Button size="sm" variant="ghost" onClick={() => setStudent(null)}>Değiştir</Button>
              </div>
            ) : newMode ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Field label="Öğrenci adı" required><Input value={ns.first_name} onChange={(e) => setNs((f) => ({ ...f, first_name: e.target.value }))} autoFocus /></Field>
                <Field label="Öğrenci soyadı" required><Input value={ns.last_name} onChange={(e) => setNs((f) => ({ ...f, last_name: e.target.value }))} /></Field>
                <Field label="Veli adı" required><Input value={ns.g_first} onChange={(e) => setNs((f) => ({ ...f, g_first: e.target.value }))} /></Field>
                <Field label="Veli soyadı" required><Input value={ns.g_last} onChange={(e) => setNs((f) => ({ ...f, g_last: e.target.value }))} /></Field>
                <Field label="Veli telefonu" required className="sm:col-span-2"><Input value={ns.g_phone} onChange={(e) => setNs((f) => ({ ...f, g_phone: e.target.value }))} placeholder="05xx xxx xx xx" /></Field>
                <div className="sm:col-span-2"><Button variant="primary" disabled={!canCreateStudent} loading={createStudent.isPending} icon={<UserPlus className="size-4" />} onClick={() => createStudent.mutate()}>Öğrenciyi oluştur ve devam et</Button></div>
              </div>
            ) : initial.isLoading && initialId ? <Skeleton className="h-12" /> : <StudentPicker value={student} onChange={setStudent} autoFocus={!initialId} />}
            {errors.student_id?.[0] && <p className="mt-1.5 text-xs text-danger">{errors.student_id[0]}</p>}
            {student && (
              <Field label="Ödeme sorumlusu veli" optional error={errors.financial_guardian_id?.[0]} className="mt-3">
                <Select value={form.financial_guardian_id} onChange={(e) => set('financial_guardian_id', e.target.value)} placeholder="Seçilmedi" options={(guardians.data?.guardians ?? []).map((g) => ({ value: g.id, label: g.name }))} />
              </Field>
            )}
          </Panel>

          <Panel title={kind === 'course' ? 'Program ve paket' : `${KIND_LABEL[kind]} üyeliği`}>
            <Field label="Kayıt türü" className="mb-3.5" hint={kind === 'course' ? 'Sınıfa yerleşen normal öğrenci kaydı.' : 'Sınıf/program gerektirmez; mezun öğrenci de alabilir.'}>
              <Segmented
                value={kind}
                onChange={(v) => { setKind(v as Kind); setForm((f) => ({ ...f, education_package_id: '', program_id: '', class_group_id: '' })) }}
                options={(Object.keys(KIND_LABEL) as Kind[]).map((k) => ({ value: k, label: KIND_LABEL[k] }))}
              />
            </Field>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
              <Field label="Dönem" required error={errors.academic_term_id?.[0]}>
                <Select value={form.academic_term_id} onChange={(e) => set('academic_term_id', e.target.value)} placeholder="Seçin" options={(options.data?.terms ?? []).map((t) => ({ value: t.id, label: `${t.name}${t.is_current ? ' (güncel)' : ''}` }))} />
              </Field>
              {kind === 'course' && (
                <Field label="Program" required error={errors.program_id?.[0]}>
                  <Select
                    value={form.program_id}
                    onChange={(e) => setForm((f) => ({ ...f, program_id: e.target.value, education_package_id: '', class_group_id: '' }))}
                    placeholder="Seçin"
                    options={(options.data?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))}
                  />
                </Field>
              )}
              <Field label={kind === 'course' ? 'Eğitim paketi' : `${KIND_LABEL[kind]} paketi`} optional={kind === 'course'} required={kind !== 'course'} hint={pkg?.includes ?? (kind === 'course' ? 'Seçilirse liste fiyatı ve taksit sayısı doldurulur' : 'Fiyat ve taksit paketten gelir')} error={errors.education_package_id?.[0]}>
                <Select
                  value={form.education_package_id}
                  onChange={(e) => {
                    const p = options.data?.packages.find((x) => x.id === Number(e.target.value))
                    setForm((f) => ({ ...f, education_package_id: e.target.value, list_price: p ? p.list_price.replace('.', ',') : f.list_price, installment_count: p ? String(p.default_installments) : f.installment_count, program_id: kind === 'course' && p?.program_id ? String(p.program_id) : f.program_id }))
                  }}
                  placeholder={kind === 'course' ? 'Paketsiz' : 'Paket seçin'}
                  options={packages.map((p) => ({ value: p.id, label: `${p.name} · ${money(p.list_price, { short: true })}` }))}
                />
              </Field>
              {kind === 'course' && (
                <Field label="Sınıf" optional hint="Boş bırakılırsa sonra atanır" error={errors.class_group_id?.[0]}>
                  <Select value={form.class_group_id} onChange={(e) => set('class_group_id', e.target.value)} placeholder="Sonra atanacak" options={groups.map((g) => ({ value: g.id, label: `${g.name} · ${g.active_students}/${g.capacity}`, disabled: g.active_students >= g.capacity }))} />
                </Field>
              )}
              <Field label="Kayıt tarihi" required error={errors.enrolled_on?.[0]}>
                <Input type="date" value={form.enrolled_on} onChange={(e) => set('enrolled_on', e.target.value)} />
              </Field>
            </div>
          </Panel>

          <Panel title="Ücret ve ödeme planı">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
              <Field label="Liste fiyatı" required error={errors.list_price?.[0]}>
                <MoneyInput value={form.list_price} onChange={(v) => set('list_price', v)} />
              </Field>
              <div />
              <Field label="İndirim tutarı" optional error={errors.discount_amount?.[0]}>
                <MoneyInput value={form.discount_amount} onChange={(v) => set('discount_amount', v)} />
              </Field>
              <Field label="İndirim gerekçesi" optional error={errors.discount_reason?.[0]}>
                <Input value={form.discount_reason} onChange={(e) => set('discount_reason', e.target.value)} placeholder="Örn. Kardeş indirimi" maxLength={200} />
              </Field>
              <Field label="Burs tutarı" optional error={errors.scholarship_amount?.[0]}>
                <MoneyInput value={form.scholarship_amount} onChange={(v) => set('scholarship_amount', v)} />
              </Field>
              <Field label="Burs gerekçesi" optional error={errors.scholarship_reason?.[0]}>
                <Input value={form.scholarship_reason} onChange={(e) => set('scholarship_reason', e.target.value)} placeholder="Örn. Başarı bursu" maxLength={200} />
              </Field>
              <Field label="Peşinat" optional hint="Kayıt günü vadeli ilk taksit olur" error={errors.down_payment?.[0]}>
                <MoneyInput value={form.down_payment} onChange={(v) => set('down_payment', v)} />
              </Field>
              <Field label="Taksit sayısı" required hint="0 = peşin (tek ödeme)" error={errors.installment_count?.[0]}>
                <Input type="number" min={0} max={36} value={form.installment_count} onChange={(e) => set('installment_count', e.target.value)} />
              </Field>
              <Field label="İlk taksit vadesi" required error={errors.first_due_date?.[0]}>
                <Input type="date" value={form.first_due_date} onChange={(e) => set('first_due_date', e.target.value)} />
              </Field>
              <div className="flex flex-wrap items-end gap-1.5 pb-0.5">
                {[1, 15].map((day) => (
                  <Button key={day} size="sm" variant="ghost" icon={<CalendarDays className="size-3.5" />} onClick={() => {
                    const base = addMonthsISO(form.enrolled_on || todayISO(), 1)
                    set('first_due_date', `${base.slice(0, 8)}${String(day).padStart(2, '0')}`)
                  }}>
                    Ayın {day}'i
                  </Button>
                ))}
                <Button size="sm" variant="ghost" onClick={() => set('first_due_date', addDaysISO(form.enrolled_on || todayISO(), 30))}>+30 gün</Button>
              </div>
            </div>
            {netC < 0 && <Alert tone="danger" className="mt-3">İndirim ve burs toplamı liste fiyatını aşamaz.</Alert>}
          </Panel>
        </div>

        <div className="lg:sticky lg:top-4 flex flex-col gap-3">
          <Panel title="Özet">
            <div className="divide-y divide-line">
              <MetricRow label="Liste fiyatı" value={money(listC / 100)} />
              {discC > 0 && <MetricRow label="İndirim tutarı" value={`−${money(discC / 100)}`} />}
              {scholC > 0 && <MetricRow label="Burs tutarı" value={`−${money(scholC / 100)}`} />}
              <MetricRow label="Ödenecek net tutar" value={money(Math.max(0, netC) / 100)} strong />
            </div>
          </Panel>

          <Panel title="Plan önizlemesi" description={preview.data ? `${preview.data.data.rows.length} taksit · Toplam: ${money(preview.data.data.total)}` : undefined} flush>
            {listC <= 0 ? (
              <p className="px-4 pb-4 text-[13px] text-ink-3">Liste fiyatını girince plan burada oluşur.</p>
            ) : preview.isLoading ? (
              <div className="px-4 pb-4"><Skeleton className="h-40" /></div>
            ) : previewError ? (
              <div className="px-4 pb-4"><Alert tone="danger">{previewError}</Alert></div>
            ) : preview.data && preview.data.data.rows.length === 0 ? (
              <p className="px-4 pb-4 text-[13px] text-ink-3">Net bedel sıfır; ödeme planı oluşmaz.</p>
            ) : (
              <ul className="max-h-[320px] overflow-y-auto scroll-thin divide-y divide-line border-t border-line">
                {preview.data?.data.rows.map((r, i) => (
                  <li key={i} className="flex items-center justify-between px-4 py-2 text-[13px]">
                    <span className="text-ink-2"><span className="inline-block w-6 text-ink-3 tabular">{i + 1}.</span>{date(r.due_date)}</span>
                    <span className="font-medium tabular">{money(r.amount)}</span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line px-4 py-3">
            <Switch checked={form.create_contract} onChange={(v) => set('create_contract', v)} label="Kayıt sözleşmesi taslağı oluştur" />
            <p className="mt-1 text-[12.5px] text-ink-3">İmza, kayıt detayından alınır; imza anında metin dondurulur.</p>
          </div>

          <Button variant="primary" size="lg" disabled={!canSave} loading={save.isPending} icon={<CheckCircle2 className="size-4" />} onClick={() => save.mutate()}>
            Kaydı oluştur
          </Button>
          {missing.length > 0 && <p className="text-center text-[12.5px] text-ink-3">Doldurulması gereken: {missing.join(', ')}</p>}
        </div>
      </div>
    </div>
  )
}
