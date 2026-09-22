import { useMemo, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { BadgeCheck, CalendarClock, GraduationCap, Info, Package, PackagePlus, Sparkles } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, money } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useAuth } from '@/app/auth'
import { Alert, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Segmented, Select, Textarea } from '@/components/ui/form'
import { Panel } from '@/components/ui/layout'
import { Drawer } from '@/components/ui/overlay'
import { usePortal, usePortalQuery, useVoice } from '../api'
import { PortalTitle } from '../ui'

type Coaching = { active: boolean; coach: string | null; next_session_on: string | null; from_package: boolean }
type PackageInfo = { id: number; name: string; includes: string | null; list_price: string | null; default_installments: number | null; has_coaching: boolean }
type Enrollment = {
  id: number; enrollment_no: string; program: string | null; term: string | null; status: string; status_label: string; enrolled_on: string
  list_price: string; net_price: string; discount: string; package: PackageInfo | null
  payment: { installment_count: number; paid_count: number; overdue_count: number; total: string; paid: string; remaining: string }
}
type Available = { id: number; name: string; includes: string | null; list_price: string; default_installments: number; has_coaching: boolean; program: string | null; term: string | null }
type Req = { id: number; kind: string; kind_label: string; package: string | null; note: string | null; status: string; status_label: string; decision_note: string | null; handled_at: string | null; created_at: string }
type Data = {
  coaching: Coaching
  enrollments: Enrollment[]
  available_packages: Available[]
  coaching_addon_available: boolean
  requests: Req[]
  requests_enabled: boolean
}

const statusTone: Record<string, 'warning' | 'success' | 'danger' | 'neutral'> = { pending: 'warning', approved: 'success', rejected: 'danger' }
const enrollTone: Record<string, 'success' | 'neutral'> = { active: 'success' }

export default function PortalPackages() {
  const v = useVoice()
  const { data, isLoading } = usePortal<Data>('packages', '/portal/packages')
  const [compose, setCompose] = useState<'package' | 'coaching' | null>(null)

  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-24" /><Skeleton className="h-48" /></div>

  // Önizlemede (yönetici "öğrenci olarak giriş") düğmeler görünür ki paketler/koçluk görülebilsin; gönderim RequestForm'da engellenir.
  const canRequest = data.requests_enabled
  const c = data.coaching

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle
        title="Paketlerim"
        description={v('Aldığın eğitim paketi, içeriği ve koçluk durumun', 'Öğrencinin eğitim paketi, içeriği ve koçluk durumu')}
        actions={canRequest && (data.available_packages.length > 0 || data.coaching_addon_available) && (
          <Button variant="primary" icon={<PackagePlus className="size-4" />} onClick={() => setCompose('package')}>Paket ekle / yükselt</Button>
        )}
      />

      {/* Koçluk durumu */}
      <Panel title="Koçluk durumu" flush>
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 pb-4">
          {c.active ? (
            <>
              <span className="inline-flex items-center gap-2 text-[14.5px] font-medium">
                <GraduationCap className="size-4 text-primary" /> {c.coach ? `Koç: ${c.coach}` : 'Koç atandı'}
              </span>
              {c.next_session_on && (
                <span className="inline-flex items-center gap-1.5 text-[13px] text-ink-2"><CalendarClock className="size-3.5" /> Sonraki görüşme {date(c.next_session_on)}</span>
              )}
              <Badge tone="success" dot>Aktif</Badge>
            </>
          ) : (
            <div className="flex flex-wrap items-center gap-3">
              <span className="text-[14px] text-ink-2">{v('Koçluk dahil değil.', 'Koçluk dahil değil.')} {c.from_package ? 'Paketinizde koçluk var ancak henüz koç atanmadı.' : ''}</span>
              {canRequest && data.coaching_addon_available && (
                <Button size="sm" variant="outline" icon={<Sparkles className="size-4" />} onClick={() => setCompose('coaching')}>Koçluk talep et</Button>
              )}
            </div>
          )}
        </div>
      </Panel>

      {/* Kayıt paketleri */}
      {data.enrollments.length === 0 ? (
        <EmptyState icon={<Package />} title="Aktif paket yok" description={v('Henüz bir eğitim paketine kayıtlı görünmüyorsun. Aşağıdan paket talep edebilirsin.', 'Öğrenci henüz bir eğitim paketine kayıtlı görünmüyor.')} />
      ) : (
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
          {data.enrollments.map((e) => {
            const paidPct = Number(e.payment.total) ? (Number(e.payment.paid) / Number(e.payment.total)) * 100 : 0
            return (
              <div key={e.id} className="flex flex-col rounded-[var(--radius-lg)] bg-surface p-4 ring-1 ring-line">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="break-words text-[15px] font-semibold">{e.package?.name ?? e.program ?? 'Kayıt'}</p>
                    <p className="text-[12.5px] text-ink-3">{[e.program, e.term, `Kayıt ${date(e.enrolled_on)}`].filter(Boolean).join(' · ')}</p>
                  </div>
                  <Badge tone={enrollTone[e.status] ?? 'neutral'}>{e.status_label}</Badge>
                </div>

                {e.package?.includes && (
                  <div className="mt-3 rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2">
                    <p className="mb-1 inline-flex items-center gap-1.5 text-[12px] font-medium text-ink-2"><Package className="size-3.5" /> Paket içeriği</p>
                    <p className="whitespace-pre-line text-[13px] text-ink-2">{e.package.includes}</p>
                  </div>
                )}

                <div className="mt-3 flex flex-wrap items-center gap-2">
                  {e.package?.has_coaching ? (
                    <Badge tone="accent"><span className="inline-flex items-center gap-1"><BadgeCheck className="size-3.5" /> Koçluk dahil</span></Badge>
                  ) : (
                    <Badge tone="neutral">Koçluk dahil değil</Badge>
                  )}
                  <span className="text-[13px] text-ink-3 tabular">Ücret {money(e.net_price)}{Number(e.discount) > 0 ? ` (indirim ${money(e.discount)})` : ''}</span>
                </div>

                {e.payment.installment_count > 0 && (
                  <div className="mt-3">
                    <div className="mb-1 flex items-center justify-between text-[12.5px] text-ink-3 tabular">
                      <span>{e.payment.paid_count}/{e.payment.installment_count} taksit ödendi</span>
                      <span>{money(e.payment.paid, { short: true })} / {money(e.payment.total, { short: true })}</span>
                    </div>
                    <ProgressBar value={paidPct} tone="success" />
                    <p className="mt-1 flex items-center justify-between text-[12.5px] text-ink-3 tabular">
                      <span>Kalan {money(e.payment.remaining)}</span>
                      {e.payment.overdue_count > 0 && <Badge tone="danger">{e.payment.overdue_count} gecikmiş</Badge>}
                    </p>
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {/* Talepler */}
      <Panel title="Taleplerim" description="Paket ve koçluk talepleriniz kurum tarafından değerlendirilir; durum burada görünür" flush>
        {!data.requests_enabled && <Alert tone="info" className="mx-4 mb-3">Kurum şu an portal üzerinden paket talebi almıyor. Lütfen kurumu arayın.</Alert>}
        {data.requests.length === 0 ? (
          <p className="px-4 pb-4 text-[14px] text-ink-3">Henüz talep oluşturmadınız.</p>
        ) : (
          <ul>
            {data.requests.map((r) => (
              <li key={r.id} className="border-t border-line px-4 py-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-[14.5px] font-semibold">{r.kind === 'coaching' ? 'Koçluk talebi' : (r.package ? `Paket: ${r.package}` : 'Paket talebi')}</p>
                    <p className="text-[12.5px] text-ink-3">{r.kind_label} · {date(r.created_at)}</p>
                  </div>
                  <Badge tone={statusTone[r.status] ?? 'neutral'} dot>{r.status_label}</Badge>
                </div>
                {r.note && <p className="mt-1 whitespace-pre-line text-[14px] text-ink-2">{r.note}</p>}
                {r.decision_note && (
                  <div className={cn('mt-2 rounded-[var(--radius-sm)] px-3 py-2 text-[13px]', r.status === 'approved' ? 'bg-success-soft' : 'bg-danger-soft')}>
                    <p className="text-[12px] font-medium text-ink-2">Kurum notu{r.handled_at ? ` · ${date(r.handled_at)}` : ''}</p>
                    <p className="whitespace-pre-line">{r.decision_note}</p>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
        <p className="flex items-start gap-1.5 px-4 pb-4 text-[12.5px] text-ink-3"><Info className="mt-0.5 size-3.5 shrink-0" /> Portal üzerinden ödeme alınmaz; talebiniz onaylanınca kurum sizinle iletişime geçer.</p>
      </Panel>

      {compose !== null && canRequest && (
        <RequestForm data={data} initialKind={compose} onClose={() => setCompose(null)} />
      )}
    </div>
  )
}

function RequestForm({ data, initialKind, onClose }: { data: Data; initialKind: 'package' | 'coaching'; onClose: () => void }) {
  const qc = useQueryClient()
  const imp = useAuth((s) => s.me?.impersonation)
  const extra = usePortalQuery()
  const coachingAllowed = data.coaching_addon_available
  const packagesAllowed = data.available_packages.length > 0
  const startKind = initialKind === 'coaching' && coachingAllowed ? 'coaching' : (packagesAllowed ? 'package' : (coachingAllowed ? 'coaching' : 'package'))
  const [kind, setKind] = useState<'package' | 'coaching'>(startKind)
  const [packageId, setPackageId] = useState('')
  const [note, setNote] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const kindOptions = useMemo(() => {
    const opts: { value: string; label: string }[] = []
    if (packagesAllowed) opts.push({ value: 'package', label: 'Paket' })
    if (coachingAllowed) opts.push({ value: 'coaching', label: 'Koçluk' })
    return opts
  }, [packagesAllowed, coachingAllowed])

  const send = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/portal/packages/requests${extra.student_id ? `?student_id=${extra.student_id}` : ''}`, {
      kind,
      package_id: kind === 'package' ? Number(packageId) || null : null,
      note: note.trim() || null,
    }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['portal', 'packages'] })
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } else toast.error('Talep gönderilemedi.')
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setErrors({})
    send.mutate()
  }
  const err = (k: string) => errors[k]?.[0] ?? null
  const selected = data.available_packages.find((p) => String(p.id) === packageId)

  return (
    <Drawer open onClose={onClose} title="Paket / koçluk talebi" description="Talebiniz kuruma iletilir; onaylanınca sizinle iletişime geçilir. Portaldan ödeme alınmaz."
      footer={<>
        <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
        <Button variant="primary" type="submit" form="pkg-req-form" loading={send.isPending} disabled={(kind === 'package' && !packageId) || !!imp}>Talep oluştur</Button>
      </>}>
      <form id="pkg-req-form" onSubmit={submit} className="flex flex-col gap-3.5">
        {imp && <Alert tone="info">Önizleme modundasınız. Talep yalnız gerçek öğrenci/veli girişinde gönderilebilir; buradan paketleri ve koçluk seçeneğini görüntüleyebilirsiniz.</Alert>}
        {kindOptions.length > 1 && (
          <Field label="Talep türü" required>
            <Segmented value={kind} onChange={(k) => setKind(k as 'package' | 'coaching')} options={kindOptions} />
          </Field>
        )}
        {kind === 'package' ? (
          <Field label="Paket" required error={err('package_id')}>
            <Select value={packageId} placeholder="Paket seçin" onChange={(e) => setPackageId(e.target.value)}
              options={data.available_packages.map((p) => ({ value: p.id, label: `${p.name}${p.list_price ? ` · ${money(p.list_price)}` : ''}` }))} />
          </Field>
        ) : (
          <Alert tone="info">Öğrenciye akademik koçluk atanması için talep oluşturuyorsunuz. Kurum uygun koçu belirler.</Alert>
        )}
        {kind === 'package' && selected?.includes && (
          <div className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2">
            <p className="mb-1 text-[12px] font-medium text-ink-2">Paket içeriği</p>
            <p className="whitespace-pre-line text-[13px] text-ink-2">{selected.includes}</p>
            {selected.has_coaching && <p className="mt-1 inline-flex items-center gap-1 text-[12.5px] text-primary"><BadgeCheck className="size-3.5" /> Koçluk dahil</p>}
          </div>
        )}
        <Field label="Not" optional error={err('note')} hint="Talebinizle ilgili eklemek istedikleriniz">
          <Textarea rows={3} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Örn. Şubat döneminden itibaren başlamak istiyorum" />
        </Field>
      </form>
    </Drawer>
  )
}
