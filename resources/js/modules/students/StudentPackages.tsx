import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeftRight, CalendarDays, CheckCircle2, PackagePlus } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, money, todayISO } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Drawer, Menu } from '@/components/ui/overlay'
import { Field, Input, Select, Switch } from '@/components/ui/form'
import { MetricRow, MoneyInput } from '@/modules/finance/components'
import { addDaysISO, addMonthsISO, fromCents, toCents } from '@/modules/finance/shared'
import type { StudentDetailData } from './types'

type Options = {
  terms: { id: number; name: string; starts_on: string; ends_on: string; is_current: boolean }[]
  programs: { id: number; name: string; code: string }[]
  packages: { id: number; name: string; program_id: number | null; academic_term_id: number | null; list_price: string; default_installments: number; includes: string | null }[]
  class_groups: { id: number; name: string; program_id: number | null; academic_term_id: number | null; capacity: number; active_students: number }[]
}

const enrollmentLabel: Record<string, string> = { active: 'Aktif', frozen: 'Donduruldu', withdrawn: 'Ayrıldı', completed: 'Tamamlandı', pending: 'Bekliyor' }
const editableStatuses = ['pending', 'active', 'frozen']

type PlanForm = {
  academic_term_id: string; program_id: string; education_package_id: string; class_group_id: string
  list_price: string; discount_amount: string; discount_reason: string; scholarship_amount: string; scholarship_reason: string
  down_payment: string; installment_count: string; enrolled_on: string; first_due_date: string; create_contract: boolean
}

const emptyForm = (): PlanForm => ({
  academic_term_id: '', program_id: '', education_package_id: '', class_group_id: '',
  list_price: '', discount_amount: '', discount_reason: '', scholarship_amount: '', scholarship_reason: '',
  down_payment: '', installment_count: '8', enrolled_on: todayISO(), first_due_date: addMonthsISO(todayISO(), 1), create_contract: true,
})

/** Öğrenci profili: paketler/kayıtlar + profilden çıkmadan yeni paket / paket değiştirme. */
export function StudentPackagesPanel({ studentId, enrollments, onChanged }: { studentId: number; enrollments: StudentDetailData['enrollments']; onChanged: () => void }) {
  const can = useCan()
  const navigate = useNavigate()
  const canManage = can('enrollments.create')
  const [newOpen, setNewOpen] = useState(false)
  const [change, setChange] = useState<StudentDetailData['enrollments'][number] | null>(null)

  return (
    <Panel
      title="Paketler / kayıtlar"
      flush
      actions={canManage ? <Button size="sm" variant="primary" icon={<PackagePlus className="size-4" />} onClick={() => setNewOpen(true)}>Yeni paket tanımla</Button> : undefined}
    >
      {enrollments.length === 0 ? (
        <div className="px-4 pb-4">
          <EmptyState compact icon={<PackagePlus />} title="Kayıtlı paket yok" description={canManage ? '"Yeni paket tanımla" ile başlayın.' : undefined} />
        </div>
      ) : (
        <ul>
          {enrollments.map((e) => {
            const editable = canManage && editableStatuses.includes(e.status)
            return (
              <li key={e.id} className="flex items-center gap-2 border-t border-line px-4 py-2.5 text-[13px]">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className="truncate font-medium">{e.program ?? 'Program yok'}</span>
                    <Badge tone={e.status === 'active' ? 'success' : e.status === 'withdrawn' ? 'neutral' : 'warning'}>{enrollmentLabel[e.status] ?? e.status}</Badge>
                  </div>
                  <p className="truncate text-[12px] text-ink-3 tabular">Kayıt {e.enrollment_no} · {e.term ?? '—'}{e.net_price ? ` · Net ${money(e.net_price, { short: true })}` : ''}</p>
                </div>
                <Menu
                  align="end"
                  trigger={<Button size="sm" variant="ghost">İşlem</Button>}
                  items={[
                    { label: 'Kayıt detayı / plan', onClick: () => navigate(`/finans/kayitlar/${e.id}`) },
                    { label: 'Paketi değiştir', icon: <ArrowLeftRight />, onClick: () => setChange(e), hidden: !editable },
                  ]}
                />
              </li>
            )
          })}
        </ul>
      )}

      {canManage && <NewPackageDrawer open={newOpen} onClose={() => setNewOpen(false)} studentId={studentId} onSaved={() => { setNewOpen(false); onChanged() }} />}
      {canManage && change && (
        <ChangePackageDrawer enrollment={change} onClose={() => setChange(null)} onSaved={() => { setChange(null); onChanged() }} />
      )}
    </Panel>
  )
}

function useEnrollmentOptions() {
  return useQuery({ queryKey: ['finance', 'enrollment-options'], queryFn: () => api.get<Options>('/finance/enrollment-options'), staleTime: 5 * 60_000 })
}

/** İndirim/burs/taksit alanları + net özet + plan önizleme (sunucu ile birebir). */
function FeeAndPlan({ form, set, includeEnrolledOn }: { form: PlanForm; set: (k: keyof PlanForm, v: string | boolean) => void; includeEnrolledOn?: boolean }) {
  const listC = toCents(form.list_price) ?? 0
  const discC = toCents(form.discount_amount) ?? 0
  const scholC = toCents(form.scholarship_amount) ?? 0
  const netC = listC - discC - scholC
  const downC = toCents(form.down_payment) ?? 0

  const previewInput = useDebounced(
    { list_price: form.list_price || '0', discount_amount: form.discount_amount || '0', scholarship_amount: form.scholarship_amount || '0', down_payment: form.down_payment || '0', installment_count: Number(form.installment_count || 0), enrolled_on: form.enrolled_on, first_due_date: form.first_due_date },
    300,
  )
  const preview = useQuery({
    queryKey: ['finance', 'preview-plan', previewInput],
    queryFn: () => api.post<{ data: { rows: { due_date: string; amount: string }[]; total: string } }>('/finance/enrollments/preview-plan', previewInput),
    enabled: listC > 0 && netC >= 0 && !!previewInput.enrolled_on && !!previewInput.first_due_date,
    retry: false,
  })
  const previewError = preview.error instanceof ApiError ? preview.error.firstError() : null

  return (
    <div className="flex flex-col gap-3">
      {includeEnrolledOn && (
        <Field label="Kayıt tarihi" required>
          <Input type="date" value={form.enrolled_on} onChange={(e) => set('enrolled_on', e.target.value)} />
        </Field>
      )}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Liste fiyatı" required><MoneyInput value={form.list_price} onChange={(v) => set('list_price', v)} /></Field>
        <Field label="Peşinat" optional hint="Kayıt günü vadeli ilk taksit"><MoneyInput value={form.down_payment} onChange={(v) => set('down_payment', v)} /></Field>
        <Field label="İndirim tutarı" optional><MoneyInput value={form.discount_amount} onChange={(v) => set('discount_amount', v)} /></Field>
        <Field label="İndirim gerekçesi" optional><Input value={form.discount_reason} maxLength={200} onChange={(e) => set('discount_reason', e.target.value)} placeholder="Örn. Kardeş indirimi" /></Field>
        <Field label="Burs tutarı" optional><MoneyInput value={form.scholarship_amount} onChange={(v) => set('scholarship_amount', v)} /></Field>
        <Field label="Burs gerekçesi" optional><Input value={form.scholarship_reason} maxLength={200} onChange={(e) => set('scholarship_reason', e.target.value)} placeholder="Örn. Başarı bursu" /></Field>
        <Field label="Taksit sayısı" required hint="0 = peşin"><Input type="number" min={0} max={36} value={form.installment_count} onChange={(e) => set('installment_count', e.target.value)} /></Field>
        <Field label="İlk taksit vadesi" required>
          <Input type="date" value={form.first_due_date} onChange={(e) => set('first_due_date', e.target.value)} />
        </Field>
      </div>
      <div className="flex flex-wrap gap-1.5">
        {[1, 15].map((day) => (
          <Button key={day} size="sm" variant="ghost" icon={<CalendarDays className="size-3.5" />} onClick={() => { const base = addMonthsISO(form.enrolled_on || todayISO(), 1); set('first_due_date', `${base.slice(0, 8)}${String(day).padStart(2, '0')}`) }}>Ayın {day}'i</Button>
        ))}
        <Button size="sm" variant="ghost" onClick={() => set('first_due_date', addDaysISO(form.enrolled_on || todayISO(), 30))}>+30 gün</Button>
      </div>

      {netC < 0 ? (
        <Alert tone="danger">İndirim ve burs toplamı liste fiyatını aşamaz.</Alert>
      ) : (
        <div className="rounded-[var(--radius-md)] bg-surface-2 px-3 py-2 ring-1 ring-line">
          <div className="divide-y divide-line">
            <MetricRow label="Liste fiyatı" value={money(listC / 100)} />
            {discC > 0 && <MetricRow label="İndirim" value={`−${money(discC / 100)}`} />}
            {scholC > 0 && <MetricRow label="Burs" value={`−${money(scholC / 100)}`} />}
            <MetricRow label="Net tutar" value={money(Math.max(0, netC) / 100)} strong />
          </div>
        </div>
      )}

      <div>
        <p className="mb-1 text-[12.5px] font-medium text-ink-2">Plan önizlemesi{preview.data ? ` · ${preview.data.data.rows.length} taksit` : ''}</p>
        {listC <= 0 ? (
          <p className="text-[12.5px] text-ink-3">Liste fiyatını girince plan oluşur.</p>
        ) : preview.isLoading ? (
          <Skeleton className="h-24" />
        ) : previewError ? (
          <Alert tone="danger">{previewError}</Alert>
        ) : (
          <ul className="max-h-[220px] overflow-y-auto scroll-thin rounded-[var(--radius-md)] ring-1 ring-line divide-y divide-line">
            {preview.data?.data.rows.map((r, i) => (
              <li key={i} className="flex items-center justify-between px-3 py-1.5 text-[12.5px]">
                <span className="text-ink-2"><span className="inline-block w-6 text-ink-3 tabular">{i + 1}.</span>{date(r.due_date)}</span>
                <span className="font-medium tabular">{money(r.amount)}</span>
              </li>
            ))}
          </ul>
        )}
      </div>
      <input type="hidden" value={downC} readOnly />
    </div>
  )
}

function programPackageFields(form: PlanForm, set: (k: keyof PlanForm, v: string | boolean) => void, options: Options | undefined) {
  const packages = (options?.packages ?? []).filter((p) => (!form.program_id || p.program_id === Number(form.program_id)) && (!form.academic_term_id || !p.academic_term_id || p.academic_term_id === Number(form.academic_term_id)))
  const pkg = options?.packages.find((p) => p.id === Number(form.education_package_id))
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <Field label="Program" required>
        <Select value={form.program_id} onChange={(e) => { set('program_id', e.target.value); set('education_package_id', ''); set('class_group_id', '') }} placeholder="Seçin" options={(options?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
      </Field>
      <Field label="Eğitim paketi" optional hint={pkg?.includes ?? 'Seçilince fiyat ve taksit dolar'}>
        <Select
          value={form.education_package_id}
          onChange={(e) => {
            const p = options?.packages.find((x) => x.id === Number(e.target.value))
            if (p) { set('education_package_id', e.target.value); set('list_price', p.list_price.replace('.', ',')); set('installment_count', String(p.default_installments)); if (p.program_id) set('program_id', String(p.program_id)) } else set('education_package_id', '')
          }}
          placeholder="Paketsiz"
          options={packages.map((p) => ({ value: p.id, label: `${p.name} · ${money(p.list_price, { short: true })}` }))}
        />
      </Field>
    </div>
  )
}

function NewPackageDrawer({ open, onClose, studentId, onSaved }: { open: boolean; onClose: () => void; studentId: number; onSaved: () => void }) {
  const options = useEnrollmentOptions()
  const [form, setForm] = useState<PlanForm>(emptyForm)
  const set = (k: keyof PlanForm, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))

  useEffect(() => {
    if (!open) { setForm(emptyForm()); return }
    const cur = options.data?.terms.find((t) => t.is_current)
    if (cur) setForm((f) => (f.academic_term_id ? f : { ...f, academic_term_id: String(cur.id) }))
  }, [open, options.data])

  const listC = toCents(form.list_price) ?? 0
  const netC = listC - (toCents(form.discount_amount) ?? 0) - (toCents(form.scholarship_amount) ?? 0)
  const groups = (options.data?.class_groups ?? []).filter((g) => (!form.program_id || g.program_id === Number(form.program_id)) && (!form.academic_term_id || !g.academic_term_id || g.academic_term_id === Number(form.academic_term_id)))

  const save = useMutation({
    mutationFn: () => api.post<{ id: number; enrollment_no: string }>('/finance/enrollments', {
      student_id: studentId,
      academic_term_id: form.academic_term_id, program_id: form.program_id,
      education_package_id: form.education_package_id || null, class_group_id: form.class_group_id || null,
      list_price: fromCents(listC), discount_amount: fromCents(toCents(form.discount_amount) ?? 0), discount_reason: form.discount_reason,
      scholarship_amount: fromCents(toCents(form.scholarship_amount) ?? 0), scholarship_reason: form.scholarship_reason,
      down_payment: fromCents(toCents(form.down_payment) ?? 0), installment_count: Number(form.installment_count || 0),
      enrolled_on: form.enrolled_on, first_due_date: form.first_due_date, create_contract: form.create_contract,
    }),
    onSuccess: (r) => { toast.success(`${r.enrollment_no} numaralı kayıt oluşturuldu.`); onSaved() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kayıt oluşturulamadı.'),
  })

  const missing = [!form.academic_term_id && 'Dönem', !form.program_id && 'Program', listC <= 0 && 'Liste fiyatı', !form.first_due_date && 'İlk vade'].filter(Boolean) as string[]

  return (
    <Drawer open={open} onClose={onClose} width={620} title="Yeni paket tanımla" description="Öğrenciye program/paket, ücret ve ödeme planı ekleyin."
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={missing.length > 0 || netC < 0} icon={<CheckCircle2 className="size-4" />} onClick={() => save.mutate()}>Kaydı oluştur</Button></>}>
      {options.isLoading ? <Skeleton className="h-64" /> : (
        <div className="flex flex-col gap-4">
          <Field label="Dönem" required>
            <Select value={form.academic_term_id} onChange={(e) => set('academic_term_id', e.target.value)} placeholder="Seçin" options={(options.data?.terms ?? []).map((t) => ({ value: t.id, label: `${t.name}${t.is_current ? ' (güncel)' : ''}` }))} />
          </Field>
          {programPackageFields(form, set, options.data)}
          <Field label="Sınıf" optional hint="Boş bırakılırsa sonra atanır">
            <Select value={form.class_group_id} onChange={(e) => set('class_group_id', e.target.value)} placeholder="Sonra atanacak" options={groups.map((g) => ({ value: g.id, label: `${g.name} · ${g.active_students}/${g.capacity}`, disabled: g.active_students >= g.capacity }))} />
          </Field>
          <FeeAndPlan form={form} set={set} includeEnrolledOn />
          <Switch checked={form.create_contract} onChange={(v) => set('create_contract', v)} label="Kayıt sözleşmesi taslağı oluştur" />
          {missing.length > 0 && <p className="text-[12.5px] text-ink-3">Doldurulması gereken: {missing.join(', ')}</p>}
        </div>
      )}
    </Drawer>
  )
}

function ChangePackageDrawer({ enrollment, onClose, onSaved }: { enrollment: StudentDetailData['enrollments'][number]; onClose: () => void; onSaved: () => void }) {
  const qc = useQueryClient()
  const options = useEnrollmentOptions()
  const [form, setForm] = useState<PlanForm>(emptyForm)
  const set = (k: keyof PlanForm, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))
  const open = true

  const listC = toCents(form.list_price) ?? 0
  const netC = listC - (toCents(form.discount_amount) ?? 0) - (toCents(form.scholarship_amount) ?? 0)

  const save = useMutation({
    mutationFn: () => api.post<{ enrollment_no: string }>(`/finance/enrollments/${enrollment.id}/change-package`, {
      program_id: form.program_id, education_package_id: form.education_package_id || null,
      list_price: fromCents(listC), discount_amount: fromCents(toCents(form.discount_amount) ?? 0), discount_reason: form.discount_reason,
      scholarship_amount: fromCents(toCents(form.scholarship_amount) ?? 0), scholarship_reason: form.scholarship_reason,
      down_payment: fromCents(toCents(form.down_payment) ?? 0), installment_count: Number(form.installment_count || 0),
      enrolled_on: enrollment.enrolled_on, first_due_date: form.first_due_date,
    }),
    onSuccess: (r) => { toast.success(`${r.enrollment_no} kaydının paketi değiştirildi.`); qc.invalidateQueries({ queryKey: ['finance'] }); onSaved() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Paket değiştirilemedi.'),
  })

  const missing = [!form.program_id && 'Program', listC <= 0 && 'Liste fiyatı', !form.first_due_date && 'İlk vade'].filter(Boolean) as string[]

  return (
    <Drawer open={open} onClose={onClose} width={620} title="Paketi değiştir" description={`${enrollment.enrollment_no} · ${enrollment.program ?? ''}`}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={missing.length > 0 || netC < 0} icon={<ArrowLeftRight className="size-4" />} onClick={() => save.mutate()}>Paketi değiştir</Button></>}>
      {options.isLoading ? <Skeleton className="h-64" /> : (
        <div className="flex flex-col gap-4">
          <Alert tone="warning">Yeni paket seçildiğinde mevcut ödeme planı silinip yeniden kurulur. Tahsilat yapılmış kayıtlarda paket değiştirilemez (yeni kayıt açın veya planı düzenleyin).</Alert>
          {programPackageFields(form, set, options.data)}
          <FeeAndPlan form={form} set={set} />
          {missing.length > 0 && <p className="text-[12.5px] text-ink-3">Doldurulması gereken: {missing.join(', ')}</p>}
        </div>
      )}
    </Drawer>
  )
}
