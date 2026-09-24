import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { MoreHorizontal, Package, Pencil, Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { money } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader } from '@/components/ui/layout'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Menu, Modal } from '@/components/ui/overlay'
import { MoneyInput } from './components'
import { fromCents, toCents } from './shared'

type Pkg = { id: number; name: string; program_id: number | null; program: string | null; academic_term_id: number | null; term: string | null; list_price: string; default_installments: number; includes: string | null; is_active: boolean; enrollment_count: number; monthly: string | null }
type Options = { programs: { id: number; name: string }[]; terms: { id: number; name: string; is_current: boolean }[] }

export default function Packages() {
  const can = useCan()
  const qc = useQueryClient()
  const [includeInactive, setIncludeInactive] = useState(false)
  const [programId, setProgramId] = useState('')
  const [edit, setEdit] = useState<Pkg | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Pkg | null>(null)
  const options = useQuery({ queryKey: ['finance', 'enrollment-options'], queryFn: () => api.get<Options>('/finance/enrollment-options'), staleTime: 5 * 60_000 })
  const { data, isLoading } = useQuery({
    queryKey: ['finance', 'packages', includeInactive, programId],
    queryFn: () => api.get<{ data: Pkg[] }>('/finance/packages', { include_inactive: includeInactive ? 1 : undefined, program_id: programId }),
  })
  const remove = useMutation({
    mutationFn: (p: Pkg) => api.delete<{ message: string }>(`/finance/packages/${p.id}`),
    onSuccess: (r) => { toast.success(r.message); setDeleting(null); qc.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.'),
  })
  const manage = can('installments.manage')
  // "Paket tanımla" kısayolu (Yönetim Paneli / komut paleti) ?yeni=1 ile create modalını açar.
  const [params, setParams] = useSearchParams()
  useEffect(() => {
    if (params.get('yeni') === '1' && manage) {
      setEdit('new')
      setParams((p) => { p.delete('yeni'); return p }, { replace: true })
    }
  }, [params, manage, setParams])

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Eğitim paketleri"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Eğitim paketleri' }]}
        description="Program ve dönem bazında liste fiyatı ve varsayılan taksit sayısı. Paket değişikliği mevcut kayıtların ücretini etkilemez."
        actions={manage && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEdit('new')}>Yeni paket</Button>}
      />

      <div className="mb-3 flex flex-wrap items-center gap-3">
        <Select className="w-full sm:w-56" value={programId} onChange={(e) => setProgramId(e.target.value)} placeholder="Tüm programlar" options={(options.data?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
        <Switch checked={includeInactive} onChange={setIncludeInactive} label="Pasif paketleri göster" />
      </div>

      {isLoading ? (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-40" />)}</div>
      ) : !data?.data.length ? (
        <EmptyState icon={<Package />} title="Eğitim paketi yok" description="Kayıt ekranında fiyatı otomatik doldurmak için paket tanımlayın." action={manage ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEdit('new')}>Yeni paket</Button> : undefined} />
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
          {data.data.map((p) => (
            <div key={p.id} className={cn('flex flex-col rounded-[var(--radius-lg)] bg-surface p-4 ring-1 ring-line', !p.is_active && 'opacity-60')}>
              <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                  <p className="font-semibold text-ink">{p.name}</p>
                  <p className="text-[12px] text-ink-3">{p.program ?? 'Programsız'} · {p.term ?? 'Tüm dönemler'}</p>
                </div>
                {!p.is_active && <Badge>Pasif</Badge>}
                {manage && (
                  <Menu
                    trigger={<Button size="icon-sm" variant="ghost" aria-label="İşlemler"><MoreHorizontal className="size-4" /></Button>}
                    items={[
                      { label: 'Düzenle', icon: <Pencil />, onClick: () => setEdit(p) },
                      { label: p.enrollment_count ? 'Pasife al' : 'Sil', icon: <Trash2 />, danger: true, onClick: () => setDeleting(p), hidden: !p.is_active && p.enrollment_count > 0 },
                    ]}
                  />
                )}
              </div>
              <p className="mt-3 text-[24px] font-semibold tabular tracking-tight">{money(p.list_price)}</p>
              <p className="text-[12.5px] text-ink-3 tabular">{p.default_installments} taksit{p.monthly ? ` · ~${money(p.monthly, { short: true })}/ay` : ''}</p>
              {p.includes && <p className="mt-2 line-clamp-2 text-[12.5px] text-ink-2">{p.includes}</p>}
              <p className="mt-auto pt-3 text-[12px] text-ink-3">{p.enrollment_count} kayıtta kullanıldı</p>
            </div>
          ))}
        </div>
      )}

      <PackageForm target={edit} options={options.data} onClose={() => setEdit(null)} />
      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting)}
        loading={remove.isPending}
        danger
        title={deleting?.enrollment_count ? 'Paketi pasife al' : 'Paketi sil'}
        description={deleting ? (deleting.enrollment_count ? `"${deleting.name}" ${deleting.enrollment_count} kayıtta kullanıldığı için silinmez, pasife alınır.` : `"${deleting.name}" silinecek.`) : undefined}
        confirmLabel={deleting?.enrollment_count ? 'Pasife al' : 'Sil'}
      />
    </div>
  )
}

function PackageForm({ target, options, onClose }: { target: Pkg | 'new' | null; options?: Options; onClose: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ name: '', program_id: '', academic_term_id: '', list_price: '', default_installments: '8', includes: '', is_active: true })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  useEffect(() => {
    if (!target) return
    setErrors({})
    if (target === 'new') setForm({ name: '', program_id: '', academic_term_id: String(options?.terms.find((t) => t.is_current)?.id ?? ''), list_price: '', default_installments: '8', includes: '', is_active: true })
    else setForm({ name: target.name, program_id: String(target.program_id ?? ''), academic_term_id: String(target.academic_term_id ?? ''), list_price: target.list_price.replace('.', ','), default_installments: String(target.default_installments), includes: target.includes ?? '', is_active: target.is_active })
  }, [target, options])
  const set = (k: keyof typeof form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))
  const cents = toCents(form.list_price)
  const monthly = cents && Number(form.default_installments) > 0 ? cents / Number(form.default_installments) / 100 : null

  const save = useMutation({
    mutationFn: () => {
      const body = { ...form, program_id: form.program_id || null, academic_term_id: form.academic_term_id || null, list_price: fromCents(cents ?? 0), default_installments: Number(form.default_installments), includes: form.includes || null }
      return target === 'new' ? api.post<{ message: string }>('/finance/packages', body) : api.put<{ message: string }>(`/finance/packages/${(target as Pkg).id}`, body)
    },
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  return (
    <Modal
      open={!!target}
      onClose={onClose}
      title={target === 'new' ? 'Yeni eğitim paketi' : 'Paketi düzenle'}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={form.name.trim().length < 2 || !cents || cents <= 0} onClick={() => save.mutate()}>Kaydet</Button></>}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Paket adı" required className="sm:col-span-2" error={errors.name?.[0]}><Input value={form.name} onChange={(e) => set('name', e.target.value)} maxLength={160} placeholder="Örn. TYT Hazırlık 2026-2027" /></Field>
        <Field label="Program" optional><Select value={form.program_id} onChange={(e) => set('program_id', e.target.value)} placeholder="Programsız" options={(options?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} /></Field>
        <Field label="Dönem" optional><Select value={form.academic_term_id} onChange={(e) => set('academic_term_id', e.target.value)} placeholder="Tüm dönemler" options={(options?.terms ?? []).map((t) => ({ value: t.id, label: t.name }))} /></Field>
        <Field label="Liste fiyatı" required error={errors.list_price?.[0]}><MoneyInput value={form.list_price} onChange={(v) => set('list_price', v)} /></Field>
        <Field label="Varsayılan taksit sayısı" required hint={monthly ? `Aylık yaklaşık ${money(monthly, { short: true })}` : undefined} error={errors.default_installments?.[0]}><Input type="number" min={1} max={36} value={form.default_installments} onChange={(e) => set('default_installments', e.target.value)} /></Field>
        <Field label="Paket içeriği" optional className="sm:col-span-2"><Textarea rows={3} value={form.includes} onChange={(e) => set('includes', e.target.value)} maxLength={2000} placeholder="Ders, deneme sınavları, rehberlik, yayın seti" /></Field>
        <div className="sm:col-span-2"><Switch checked={form.is_active} onChange={(v) => set('is_active', v)} label="Aktif (kayıt ekranında seçilebilir)" /></div>
      </div>
    </Modal>
  )
}
