import { useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Combine, MessageCircle, Pencil, Phone, ShieldCheck } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, dateTime, money, num } from '@/lib/format'
import { useCan } from '@/app/auth'
import { DescriptionList, PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Avatar, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Checkbox, Field, Input, Switch, Textarea } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import { waLink } from './types'
import { AddressText, MailText, PhoneText } from '@/components/ui/contact'
import { GuardianPortalAccountPanel, ImpersonateGuardianButton } from './StudentPortalAccountPanel'

type GuardianDetailData = {
  guardian: { id: number; name: string; first_name: string; last_name: string; phone: string | null; whatsapp_phone: string | null; email: string | null; occupation: string | null; address: string | null; notes: string | null; national_id_masked: string | null; has_portal_access: boolean }
  children: {
    id: number; full_name: string; student_no: string; status: string; relationship: string; is_primary: boolean; class_groups: string[]
    absent_30: number; late_30: number
    last_exam: { name: string; exam_date: string; net: string; institution_rank: number | null } | null
    finance: { remaining: string | null; overdue: string | null; next_due: string | null } | null
  }[]
  guidance: { id: number; met_at: string; kind: string; summary: string; full_name: string }[]
  messages: { id: number; channel: string; template_key: string | null; body: string; status: string; created_at: string; read_at: string | null }[]
  consents: { channel: string; purpose: string; granted: number; recorded_at: string; source: string | null }[]
}

const rel: Record<string, string> = { mother: 'Anne', father: 'Baba', guardian: 'Vasi', other: 'Yakını', parent: 'Veli' }

export default function GuardianDetail() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const [edit, setEdit] = useState(false)
  const { data, isLoading, error } = useQuery({ queryKey: ['guardian', id], queryFn: () => api.get<GuardianDetailData>(`/guardians/${id}`) })
  const dup = useQuery({
    queryKey: ['guardians', 'duplicates', id],
    queryFn: () => api.get<{ data: { key: string; guardians: { id: number; name: string }[] }[] }>('/guardians-duplicates', { guardian_id: id }),
    enabled: !!id && can('guardians.view'),
    staleTime: 60_000,
  })
  const others = (dup.data?.data[0]?.guardians ?? []).filter((x) => String(x.id) !== String(id))
  const [params] = useSearchParams()
  const focusPortal = params.get('portal') === '1'

  // Öğrenci profilindeki veli satırından gelindiyse "Portal girişi" paneline kaydır
  useEffect(() => {
    if (!focusPortal || !data) return
    const t = setTimeout(() => document.getElementById('portal-girisi')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 350)
    return () => clearTimeout(t)
  }, [focusPortal, data])

  if (error) return <EmptyState title="Veli bulunamadı" description={error instanceof ApiError && error.status !== 404 ? error.message : 'Kayıt silinmiş ya da adres hatalı olabilir.'} action={<ButtonLink to="/veliler">Velilere dön</ButtonLink>} />
  if (isLoading || !data) return <Skeleton className="h-96 rounded-[var(--radius-lg)]" />
  const g = data.guardian
  const wa = waLink(g.whatsapp_phone || g.phone)
  const consent = data.consents.find((c) => c.channel === 'whatsapp' && c.purpose !== 'marketing')
  const marketing = data.consents.filter((c) => c.purpose === 'marketing')

  return (
    <div className="animate-fade-in">
      <PageHeader
        breadcrumbs={[{ label: 'Veliler', to: '/veliler' }, { label: g.name }]}
        title={
          <span className="flex items-center gap-3">
            <Avatar name={g.name} size={40} />
            {g.name}
          </span>
        }
        description={[g.occupation ? `Meslek: ${g.occupation}` : 'Veli', `${data.children.length} öğrenci`].join(' · ')}
        actions={
          <>
            {g.phone && !g.phone.includes('•') && <a href={`tel:${g.phone}`}><Button icon={<Phone className="size-4" />}>Ara</Button></a>}
            {wa && <a href={wa} target="_blank" rel="noopener"><Button icon={<MessageCircle className="size-4" />}>WhatsApp</Button></a>}
            {can('guardians.impersonate') && g.has_portal_access && <ImpersonateGuardianButton guardianId={g.id} />}
            {can('guardians.manage') && <Button variant="primary" icon={<Pencil className="size-4" />} onClick={() => setEdit(true)}>Düzenle</Button>}
          </>
        }
      />

      {others.length > 0 && (
        <Alert tone="warning" className="mb-4" title="Olası mükerrer veli">
          <p>
            Aynı telefon numarasıyla kayıtlı {others.length === 1 ? 'başka bir veli' : `${others.length} veli daha`} var:{' '}
            {others.map((o, i) => (
              <span key={o.id}>{i > 0 && ', '}<Link to={`/veliler/${o.id}`} className="font-medium underline-offset-2 hover:underline">{o.name}</Link></span>
            ))}.
          </p>
          {can('guardians.manage') && (
            <ButtonLink size="sm" variant="warning" className="mt-2" icon={<Combine className="size-4" />} to={`/veliler/birlestir?hedef=${g.id}&kaynak=${others[0]!.id}`}>
              Birleştirme ekranını aç
            </ButtonLink>
          )}
        </Alert>
      )}

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div className="xl:col-span-2 flex flex-col gap-4">
          {data.children.map((c) => (
            <Panel key={c.id}>
              <div className="flex flex-wrap items-center gap-3">
                <Avatar name={c.full_name} size={40} />
                <div className="min-w-0 flex-1">
                  <Link to={`/ogrenciler/${c.id}`} className="text-[15px] font-semibold hover:text-primary">{c.full_name}</Link>
                  <p className="text-[12.5px] text-ink-3">Yakınlık: {rel[c.relationship] ?? 'Veli'} · Öğrenci no {c.student_no} · {c.class_groups.join(', ') || 'Sınıf atanmadı'}</p>
                </div>
                {c.is_primary && <Badge tone="primary">Birincil veli</Badge>}
              </div>
              <div className="mt-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-[13px]">
                <Metric label="Devamsızlık (30 gün)" value={`${c.absent_30} ders`} tone={c.absent_30 >= 3 ? 'text-danger' : undefined} sub={c.late_30 ? `Geç kalma: ${c.late_30} ders` : undefined} />
                <Metric label="Son deneme neti" value={c.last_exam ? `${num(c.last_exam.net, 2)} net` : '—'} sub={c.last_exam ? `${c.last_exam.name} · kurum sırası ${c.last_exam.institution_rank ?? '—'}` : undefined} />
                {c.finance && (
                  <>
                    <Metric label="Kalan ödeme" value={money(c.finance.remaining, { short: true })} sub={c.finance.next_due ? `Sıradaki vade: ${date(c.finance.next_due)}` : undefined} />
                    <Metric label="Gecikmiş ödeme" value={money(c.finance.overdue, { short: true })} tone={Number(c.finance.overdue) > 0 ? 'text-danger' : undefined} />
                  </>
                )}
              </div>
            </Panel>
          ))}

          {can('guidance.view') && (
            <Panel title="Rehberlik notları" description="Veliyle paylaşılabilir görüşme özetleri">
              {data.guidance.length === 0 ? <p className="text-[13px] text-ink-3">Kayıt yok.</p> : (
                <ul className="flex flex-col gap-3">
                  {data.guidance.map((m) => (
                    <li key={m.id} className="text-[13px]">
                      <p className="text-[12px] text-ink-3">{dateTime(m.met_at)} · {m.full_name}</p>
                      <p className="mt-0.5">{m.summary}</p>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          )}
        </div>

        <div className="flex min-w-0 flex-col gap-4">
          <GuardianPortalAccountPanel guardianId={g.id} guardianName={g.name} phone={g.whatsapp_phone || g.phone} />
          <Panel title="Bilgiler">
            <DescriptionList
              columns={1}
              items={[
                { label: 'Cep telefonu', value: <PhoneText value={g.phone} whatsapp={!g.whatsapp_phone || g.whatsapp_phone === g.phone} /> },
                { label: 'WhatsApp numarası', value: g.whatsapp_phone && g.whatsapp_phone !== g.phone ? <PhoneText value={g.whatsapp_phone} whatsapp /> : <span className="text-ink-3">Cep telefonuyla aynı</span> },
                { label: 'E-posta', value: <MailText value={g.email} /> },
                { label: 'TC kimlik no', value: g.national_id_masked },
                { label: 'Adres', value: <AddressText value={g.address} /> },
                { label: 'Not', value: g.notes },
              ]}
            />
            <div className="mt-4 flex items-center gap-2 border-t border-line pt-3 text-[12.5px]">
              <ShieldCheck className={consent?.granted ? 'size-4 text-success' : 'size-4 text-ink-3'} />
              {consent ? `WhatsApp bilgilendirme izni ${consent.granted ? 'var' : 'yok'} · ${consent.source ?? ''} · ${date(consent.recorded_at)}` : 'WhatsApp izni kaydı yok'}
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[12.5px]">
              <span className="text-ink-3">Ticari ileti onayı:</span>
              {(['sms', 'email', 'whatsapp'] as const).map((ch) => {
                const row = marketing.find((c) => c.channel === ch)
                return (
                  <span key={ch} title={row ? `${row.source ?? ''} · ${date(row.recorded_at)}` : 'Kayıt yok'}>
                    <Badge tone={row?.granted ? 'success' : 'neutral'}>{ch === 'sms' ? 'SMS' : ch === 'email' ? 'E-posta' : 'WhatsApp'} {row?.granted ? 'var' : 'yok'}</Badge>
                  </span>
                )
              })}
            </div>
          </Panel>

          {can('messages.view') && (
            <Panel title="Mesaj geçmişi" flush>
              {data.messages.length === 0 ? <EmptyState compact icon={<MessageCircle />} title="Mesaj yok" /> : (
                <ul className="max-h-[420px] overflow-y-auto scroll-thin">
                  {data.messages.map((m) => (
                    <li key={m.id} className="border-t border-line px-4 py-2.5">
                      <p className="text-[12px] text-ink-3">{dateTime(m.created_at)} · {msgStatus[m.status] ?? m.status}</p>
                      <p className="mt-0.5 text-[13px] text-ink-2 line-clamp-3">{m.body}</p>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          )}
        </div>
      </div>

      <GuardianEditDrawer open={edit} onClose={() => setEdit(false)} data={data} onSaved={() => qc.invalidateQueries({ queryKey: ['guardian', id] })} />
    </div>
  )
}

const msgStatus: Record<string, string> = { queued: 'Sırada', pending: 'Sırada', sending: 'Gönderiliyor', sent: 'Gönderildi', delivered: 'İletildi', read: 'Okundu', failed: 'İletilemedi', cancelled: 'İptal edildi', simulated: 'Deneme gönderimi' }

function Metric({ label, value, sub, tone }: { label: string; value: string; sub?: string; tone?: string }) {
  return (
    <div className="rounded-[var(--radius-sm)] bg-surface-2/70 px-3 py-2.5">
      <p className="text-[12px] text-ink-3">{label}</p>
      <p className={`text-[15px] font-semibold tabular ${tone ?? ''}`}>{value}</p>
      {sub && <p className="text-[12px] text-ink-3 truncate" title={sub}>{sub}</p>}
    </div>
  )
}

function GuardianEditDrawer({ open, onClose, data, onSaved }: { open: boolean; onClose: () => void; data: GuardianDetailData; onSaved: () => void }) {
  const g = data.guardian
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (open) {
      setForm({
        first_name: g.first_name, last_name: g.last_name, phone: g.phone?.includes('•') ? '' : g.phone ?? '', whatsapp_phone: g.whatsapp_phone?.includes('•') ? '' : g.whatsapp_phone ?? '',
        email: g.email ?? '', occupation: g.occupation ?? '', address: g.address ?? '', notes: g.notes ?? '',
        whatsapp_consent: !!data.consents.find((c) => c.channel === 'whatsapp' && c.purpose !== 'marketing')?.granted,
        marketing_consents: Object.fromEntries((['sms', 'email', 'whatsapp'] as const).map((ch) => [ch, !!data.consents.find((c) => c.channel === ch && c.purpose === 'marketing')?.granted])),
      })
      setErrors({})
    }
  }, [open, g, data.consents])

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form }
      if (!payload.phone) delete payload.phone
      if (!payload.whatsapp_phone) delete payload.whatsapp_phone
      return api.put<{ message: string }>(`/guardians/${g.id}`, payload)
    },
    onSuccess: (r) => { toast.success(r.message); onSaved(); onClose() },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <Drawer open={open} onClose={onClose} title="Veliyi düzenle" footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button></>}>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Adı" required error={errors.first_name?.[0]}><Input value={form.first_name ?? ''} onChange={(e) => set('first_name', e.target.value)} /></Field>
        <Field label="Soyadı" required error={errors.last_name?.[0]}><Input value={form.last_name ?? ''} onChange={(e) => set('last_name', e.target.value)} /></Field>
        <Field label="Cep telefonu" optional error={errors.phone?.[0]} hint={g.phone?.includes('•') ? 'Boş bırakırsanız kayıtlı numara korunur' : 'Veli portalı kullanıcı adı bu numaradır'}><Input type="tel" value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} /></Field>
        <Field label="WhatsApp numarası" optional error={errors.whatsapp_phone?.[0]} hint="Cep telefonundan farklıysa"><Input type="tel" value={form.whatsapp_phone ?? ''} onChange={(e) => set('whatsapp_phone', e.target.value)} /></Field>
        <Field label="E-posta" optional error={errors.email?.[0]}><Input type="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} /></Field>
        <Field label="Meslek" optional error={errors.occupation?.[0]}><Input value={form.occupation ?? ''} onChange={(e) => set('occupation', e.target.value)} /></Field>
        <Field label="Adres" optional error={errors.address?.[0]} className="sm:col-span-2"><Input value={form.address ?? ''} onChange={(e) => set('address', e.target.value)} /></Field>
        <Field label="Not" optional error={errors.notes?.[0]} className="sm:col-span-2"><Textarea value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} /></Field>
        <div className="sm:col-span-2"><Switch checked={!!form.whatsapp_consent} onChange={(v) => set('whatsapp_consent', v)} label="WhatsApp bilgilendirme izni (KVKK)" /></div>
        <div className="sm:col-span-2 rounded-[var(--radius-md)] bg-surface-2/60 px-3 py-2.5 ring-1 ring-line">
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span className="text-[12.5px] text-ink-3">Ticari ileti onayı:</span>
            {(['sms', 'email', 'whatsapp'] as const).map((ch) => (
              <Checkbox key={ch} checked={!!form.marketing_consents?.[ch]} onChange={(v) => set('marketing_consents', { ...form.marketing_consents, [ch]: v })} label={ch === 'sms' ? 'SMS' : ch === 'email' ? 'E-posta' : 'WhatsApp'} />
            ))}
          </div>
          <p className="mt-1 text-[12px] text-ink-3">Kampanya/tanıtım mesajları için. Değişiklik "Kayıt formu" kaynağıyla tarihli kaydedilir.</p>
        </div>
      </div>
    </Drawer>
  )
}
