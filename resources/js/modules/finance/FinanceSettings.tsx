import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Lock, Plus, Save, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import { DOCUMENT_TYPES } from './ledger'

type SettingsData = {
  auto_journal: boolean
  invoice_prefix: string
  return_prefix: string
  default_document_type: string
  vat_rates: string[]
  default_vat_rate: string
  prices_include_vat: boolean
  withholding_enabled: boolean
  default_unit: string
  service_description: string
  invoice_note: string | null
  integrator: string
  integrators: { key: string; label: string; connected: boolean }[]
  pos_commission_rate: string
  pos_settlement_days: number
  invoice_due_days: number
  portal_show_student_overdue: boolean
  portal_show_guardian_overdue: boolean
  note_payee: string | null
  note_place: string | null
  note_court: string | null
  note_consideration: string | null
  note_acceleration: boolean
}

type Form = Omit<SettingsData, 'integrators' | 'invoice_note' | 'pos_settlement_days' | 'invoice_due_days' | 'note_payee' | 'note_place' | 'note_court' | 'note_consideration'> & {
  note_payee: string
  note_place: string
  note_court: string
  note_consideration: string
  invoice_note: string
  pos_settlement_days: string
  invoice_due_days: string
}

const normRate = (v: string) => {
  const s = String(v).trim().replace(',', '.')
  if (!/^\d{1,2}(\.\d{1,2})?$/.test(s)) return null
  return String(Number(s)) // gösterim/eşleme için sadeleştirme ("10.00" → "10"); tutar değildir
}

export default function FinanceSettings() {
  const can = useCan()
  const editable = can('settings.manage')
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['finance', 'settings'], queryFn: () => api.get<{ data: SettingsData }>('/finance/settings').then((r) => r.data) })
  const [form, setForm] = useState<Form | null>(null)
  const [newRate, setNewRate] = useState('')
  const [journalOffConfirm, setJournalOffConfirm] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!data) return
    setForm({
      auto_journal: data.auto_journal,
      invoice_prefix: data.invoice_prefix,
      return_prefix: data.return_prefix,
      default_document_type: data.default_document_type,
      vat_rates: data.vat_rates.map((r) => normRate(r) ?? r),
      default_vat_rate: normRate(data.default_vat_rate) ?? data.default_vat_rate,
      prices_include_vat: data.prices_include_vat,
      withholding_enabled: data.withholding_enabled,
      default_unit: data.default_unit,
      service_description: data.service_description,
      invoice_note: data.invoice_note ?? '',
      integrator: data.integrator,
      pos_commission_rate: String(data.pos_commission_rate),
      pos_settlement_days: String(data.pos_settlement_days),
      invoice_due_days: String(data.invoice_due_days ?? 0),
      portal_show_student_overdue: data.portal_show_student_overdue,
      portal_show_guardian_overdue: data.portal_show_guardian_overdue,
      note_payee: data.note_payee ?? '',
      note_place: data.note_place ?? '',
      note_court: data.note_court ?? 'Erbaa',
      note_consideration: data.note_consideration ?? 'eğitim hizmeti karşılığı',
      note_acceleration: data.note_acceleration ?? true,
    })
  }, [data])

  const save = useMutation({
    mutationFn: (f: Form) =>
      api.put<{ message: string }>('/finance/settings', {
        ...f,
        invoice_prefix: f.invoice_prefix.toUpperCase(),
        return_prefix: f.return_prefix.toUpperCase(),
        invoice_note: f.invoice_note || null,
        pos_settlement_days: Number(f.pos_settlement_days || 0),
        invoice_due_days: Number(f.invoice_due_days || 0),
        note_payee: f.note_payee || null,
        note_place: f.note_place || null,
        note_court: f.note_court || null,
        note_consideration: f.note_consideration || null,
      }),
    onSuccess: (res) => {
      setErrors({})
      toast.success(res.message)
      qc.invalidateQueries({ queryKey: ['finance'] })
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      } else toast.error('Ayarlar kaydedilemedi.')
    },
  })

  if (isLoading || !form) {
    return (
      <div className="animate-fade-in">
        <PageHeader title="Finans ve fatura ayarları" breadcrumbs={[{ label: 'Ayarlar' }, { label: 'Finans ve fatura' }]} />
        <div className="grid gap-4"><Skeleton className="h-40" /><Skeleton className="h-64" /></div>
      </div>
    )
  }

  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => (f ? { ...f, [k]: v } : f))
  const err = (k: string) => errors[k]?.[0] ?? null
  const addRate = () => {
    const r = normRate(newRate)
    if (r === null) return toast.error('Oranı 0-99 arasında yazın (ör. 10 ya da 7,5).')
    if (form.vat_rates.includes(r)) return setNewRate('')
    set('vat_rates', [...form.vat_rates, r].sort((a, b) => Number(a) - Number(b)))
    setNewRate('')
  }
  const removeRate = (r: string) => {
    if (r === form.default_vat_rate) return toast.error('Varsayılan oran listeden çıkarılamaz; önce varsayılanı değiştirin.')
    set('vat_rates', form.vat_rates.filter((x) => x !== r))
  }
  const prefixOk = (p: string) => /^[A-Za-z0-9]{3}$/.test(p)
  const invalid = !prefixOk(form.invoice_prefix) || !prefixOk(form.return_prefix) || form.invoice_prefix.toUpperCase() === form.return_prefix.toUpperCase()
    || !form.vat_rates.length || form.service_description.trim().length < 3 || !form.default_unit.trim()
  const integrator = data?.integrators.find((i) => i.key === form.integrator)

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Finans ve fatura ayarları"
        breadcrumbs={[{ label: 'Ayarlar' }, { label: 'Finans ve fatura' }]}
        description="Fatura numaralandırma, KDV oranları, entegratör durumu, POS komisyonu ve otomatik muhasebe"
        actions={
          editable && (
            <Button variant="primary" icon={<Save className="size-4" />} disabled={invalid} loading={save.isPending} onClick={() => save.mutate(form)}>Kaydet</Button>
          )
        }
      />

      <div className="flex flex-col gap-4">
        <Alert tone="warning" title="Muhasebeciyle teyit edin">KDV oranları ve hesap kodları varsayılan önerilerdir; kurum muhasebecisiyle teyit edin.</Alert>
        {!editable && <Alert tone="info" icon={<Lock />}>Bu ayarları yalnız "Kurum ayarları" yetkisi olan kullanıcılar değiştirebilir.</Alert>}

        <fieldset disabled={!editable} className="grid grid-cols-1 gap-4 xl:grid-cols-2">
          <Panel title="Fatura" description="Numara GİB biçiminde: önek (3 karakter) + yıl + 9 haneli sıra. Taslaklar numara almaz.">
            <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
              <Field label="Satış faturası öneki" required error={err('invoice_prefix') ?? (prefixOk(form.invoice_prefix) ? null : '3 harf/rakam olmalı')} hint={`Örn. ${form.invoice_prefix.toUpperCase()}${new Date().getFullYear()}000000001`}>
                <Input value={form.invoice_prefix} maxLength={3} onChange={(e) => set('invoice_prefix', e.target.value.toUpperCase())} />
              </Field>
              <Field label="İade faturası öneki" required error={err('return_prefix') ?? (!prefixOk(form.return_prefix) ? '3 harf/rakam olmalı' : form.return_prefix === form.invoice_prefix ? 'Satış önekinden farklı olmalı' : null)}>
                <Input value={form.return_prefix} maxLength={3} onChange={(e) => set('return_prefix', e.target.value.toUpperCase())} />
              </Field>
              <Field label="Varsayılan belge türü" optional>
                <Select value={form.default_document_type} onChange={(e) => set('default_document_type', e.target.value)} options={Object.entries(DOCUMENT_TYPES).map(([value, label]) => ({ value, label }))} />
              </Field>
              <Field label="Varsayılan birim" required>
                <Input value={form.default_unit} maxLength={12} onChange={(e) => set('default_unit', e.target.value.toUpperCase())} />
              </Field>
              <Field label="KDV oranları (%)" className="sm:col-span-2" error={err('vat_rates')} hint="Faturada seçilebilecek oranlar">
                <div className="flex flex-wrap items-center gap-1.5">
                  {form.vat_rates.map((r) => (
                    <span key={r} className={cn('inline-flex h-8 items-center gap-1 rounded-[var(--radius-sm)] px-2.5 text-[13px] ring-1', r === form.default_vat_rate ? 'bg-primary-soft text-primary-ink ring-primary/25' : 'bg-surface-2 ring-line')}>
                      %{r.replace('.', ',')}
                      {editable && (
                        <button type="button" aria-label={`%${r} oranını çıkar`} className="grid size-5 place-items-center rounded text-ink-3 hover:text-danger" onClick={() => removeRate(r)}>
                          <X className="size-3.5" />
                        </button>
                      )}
                    </span>
                  ))}
                  {editable && (
                    <span className="inline-flex items-center gap-1">
                      <Input value={newRate} onChange={(e) => setNewRate(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addRate())} placeholder="Oran" inputMode="decimal" className="w-20" />
                      <Button size="sm" variant="ghost" icon={<Plus className="size-3.5" />} onClick={addRate}>Ekle</Button>
                    </span>
                  )}
                </div>
              </Field>
              <Field label="Varsayılan KDV oranı" required error={err('default_vat_rate')}>
                <Select value={form.default_vat_rate} onChange={(e) => set('default_vat_rate', e.target.value)} options={form.vat_rates.map((r) => ({ value: r, label: `%${r.replace('.', ',')}` }))} />
              </Field>
              <Field label="Fatura vadesi (gün)" optional hint="Kesildikten kaç gün sonra 'vadesi geçmiş' sayılır" error={err('invoice_due_days')}>
                <Input type="number" min={0} max={365} value={form.invoice_due_days} onChange={(e) => set('invoice_due_days', e.target.value)} />
              </Field>
              <Field label="Hizmet açıklaması" required className="sm:col-span-2" hint="Tahsilattan oluşturulan taslaklarda kalem adı" error={err('service_description')}>
                <Input value={form.service_description} maxLength={120} onChange={(e) => set('service_description', e.target.value)} />
              </Field>
              <Field label="Fatura notu" optional className="sm:col-span-2" hint="Yeni taslaklara eklenir">
                <Textarea rows={2} value={form.invoice_note} maxLength={500} onChange={(e) => set('invoice_note', e.target.value)} />
              </Field>
              <div className="flex flex-col gap-2.5 sm:col-span-2">
                <Switch checked={form.prices_include_vat} onChange={(v) => set('prices_include_vat', v)} label="Fiyatlar KDV dahil girilir" disabled={!editable} />
                <Switch checked={form.withholding_enabled} onChange={(v) => set('withholding_enabled', v)} label="Tevkifat alanını göster (kurum faturaları için)" disabled={!editable} />
              </div>
            </div>
          </Panel>

          <div className="flex flex-col gap-4">
            <Panel title="e-Fatura / e-Arşiv entegratörü">
              <div className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center gap-2 rounded-[var(--radius-md)] bg-surface-2/60 px-3 py-2.5 ring-1 ring-line">
                  <span className="text-[13px] text-ink-2">Durum</span>
                  {integrator?.connected ? <Badge tone="success" dot>Bağlı · {integrator.label}</Badge> : <Badge tone="warning" dot>Bağlı değil</Badge>}
                  {form.integrator === 'simulation' && <Badge tone="info">Simülasyon açık</Badge>}
                </div>
                <Alert tone="warning">Gerçek gönderim yapılmaz. GİB onaylı özel entegratör sözleşmesi yapıldığında sürücü eklenecek.</Alert>
                <Field label="E-fatura entegratörü" hint="Simülasyon yalnız akışı gösterir; faturaya örnek ETTN yazar, ağ bağlantısı kurmaz.">
                  <Select value={form.integrator} onChange={(e) => set('integrator', e.target.value)} options={(data?.integrators ?? []).map((i) => ({ value: i.key, label: i.key === 'none' ? 'Bağlı değil' : i.label }))} />
                </Field>
              </div>
            </Panel>

            <Panel title="POS / kredi kartı" description="Yeni kart tahsilatlarında komisyon ve beklenen yatış tarihi hesabı. Gerçek komisyon mutabakatta kesinleşir.">
              <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                <Field label="Komisyon oranı (%)" required error={err('pos_commission_rate')}>
                  <Input value={form.pos_commission_rate} inputMode="decimal" onChange={(e) => set('pos_commission_rate', e.target.value.replace(/[^\d.,]/g, ''))} />
                </Field>
                <Field label="Valör (iş günü)" required error={err('pos_settlement_days')} hint="Bankaya geçiş süresi; hafta sonu sayılmaz">
                  <Input type="number" min={0} max={60} value={form.pos_settlement_days} onChange={(e) => set('pos_settlement_days', e.target.value)} />
                </Field>
              </div>
            </Panel>

            <Panel title="Muhasebe">
              <div className="flex flex-col gap-2">
                <Switch
                  checked={form.auto_journal}
                  disabled={!editable}
                  onChange={(v) => (v ? set('auto_journal', true) : setJournalOffConfirm(true))}
                  label="Tahsilat, iade, gelir-gider, transfer ve faturalardan otomatik yevmiye fişi kes"
                />
                {!form.auto_journal && <Alert tone="danger">Otomatik fiş kapalı: yeni işlemler muhasebeye yansımaz, mizan eksik kalır.</Alert>}
              </div>
            </Panel>

            <Panel title="Senet (bono) metni" description="Boş bırakılan lehtar ve yer, kurum adı ve adresinden alınır.">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Field label="Lehtar (alacaklı)" optional><Input value={form.note_payee} disabled={!editable} placeholder="Kurum adı" onChange={(e) => set('note_payee', e.target.value)} maxLength={200} /></Field>
                <Field label="Ödeme / düzenleme yeri" optional><Input value={form.note_place} disabled={!editable} placeholder="Kurum adresi" onChange={(e) => set('note_place', e.target.value)} maxLength={120} /></Field>
                <Field label="Yetkili mahkeme ve icra" optional><Input value={form.note_court} disabled={!editable} onChange={(e) => set('note_court', e.target.value)} maxLength={60} /></Field>
                <Field label="Bedel ibaresi" optional hint="“Bedeli … alınmıştır”"><Input value={form.note_consideration} disabled={!editable} onChange={(e) => set('note_consideration', e.target.value)} maxLength={80} /></Field>
                <div className="sm:col-span-2"><Switch checked={form.note_acceleration} disabled={!editable} onChange={(v) => set('note_acceleration', v)} label="Muacceliyet şartı (bir senet ödenmezse diğerleri de muaccel olur)" /></div>
              </div>
              <Alert tone="warning" className="mt-3">Senet metni ve şartları kurum hukukçusu/muhasebecisiyle teyit edilmelidir.</Alert>
            </Panel>

            <Panel title="Portal uyarıları" description="Veli ve öğrenci portalının ana sayfasında vadesi geçmiş ödeme uyarısı">
              <div className="flex flex-col gap-2.5">
                <Switch checked={form.portal_show_guardian_overdue} disabled={!editable} onChange={(v) => set('portal_show_guardian_overdue', v)} label="Veliye göster (tüm çocuklarının toplamı)" />
                <Switch checked={form.portal_show_student_overdue} disabled={!editable} onChange={(v) => set('portal_show_student_overdue', v)} label="Öğrenciye göster" />
              </div>
            </Panel>
          </div>
        </fieldset>

        {editable && (
          <div className="flex justify-end">
            <Button variant="primary" icon={<Save className="size-4" />} disabled={invalid} loading={save.isPending} onClick={() => save.mutate(form)}>Kaydet</Button>
          </div>
        )}
      </div>

      <ConfirmDialog
        open={journalOffConfirm}
        onClose={() => setJournalOffConfirm(false)}
        danger
        title="Otomatik muhasebe fişi kapatılsın mı?"
        description="Kapalıyken yapılan tahsilat, gider ve faturalar yevmiyeye yazılmaz; mizan ve muhasebe raporları eksik olur. Sonradan geriye dönük fiş komutuyla tamamlanması gerekir."
        confirmLabel="Kapat"
        onConfirm={() => {
          set('auto_journal', false)
          setJournalOffConfirm(false)
        }}
      />
    </div>
  )
}
