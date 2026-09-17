import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarClock, Info, MapPin, MessageSquarePlus, UsersRound } from 'lucide-react'
import { MailText, PhoneText } from '@/components/ui/contact'
import { api, ApiError } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useAuth } from '@/app/auth'
import { Alert, Avatar, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { Panel } from '@/components/ui/layout'
import { Drawer } from '@/components/ui/overlay'
import { usePortal, usePortalQuery, usePortalRole, useVoice } from '../api'
import { PortalTitle } from '../ui'

type Teacher = { id: number; full_name: string; title: string | null; avatar_url: string | null; subjects: string[]; is_counselor: boolean; is_advisor: boolean }
type Req = { id: number; kind: string; kind_label: string; subject: string; body: string; preferred_times: string | null; status: string; status_label: string; response: string | null; responded_at: string | null; created_at: string; teacher: string | null }
type Data = {
  teachers: Teacher[]
  institution: { name: string | null; phone: string | null; email: string | null; address: string | null }
  requests_enabled: boolean
  requests: Req[]
  kinds: Record<string, string>
}

const statusTone: Record<string, 'warning' | 'success' | 'neutral'> = { open: 'warning', answered: 'success', closed: 'neutral' }

export default function PortalTeachers() {
  const v = useVoice()
  const role = usePortalRole()
  const imp = useAuth((s) => s.me?.impersonation)
  const { data, isLoading } = usePortal<Data>('teachers', '/portal/teachers')
  const [composeFor, setComposeFor] = useState<number | 'any' | null>(null)
  const isGuardian = role === 'guardian'
  const canRequest = isGuardian && !!data?.requests_enabled && !imp

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle
        title={isGuardian ? 'Öğretmenler ve görüşme' : 'Öğretmenlerim'}
        description={v('Derslerine giren öğretmenler ve rehber öğretmenin', 'Öğrencinin öğretmenleri; mesaj ya da görüşme talebi oluşturabilirsiniz')}
        actions={canRequest && data!.teachers.length > 0 && <Button variant="primary" icon={<MessageSquarePlus className="size-4" />} onClick={() => setComposeFor('any')}>Talep oluştur</Button>}
      />
      {isLoading || !data ? <Skeleton className="h-64" /> : (
        <>
          {data.teachers.length === 0 ? (
            <EmptyState icon={<UsersRound />} title="Öğretmen bilgisi yok" description="Sınıf ve ders programı belirlendiğinde öğretmenler burada listelenir." />
          ) : (
            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {data.teachers.map((t) => (
                <li key={t.id} className="flex items-center gap-3 rounded-[var(--radius-lg)] bg-surface p-3.5 ring-1 ring-line">
                  <Avatar name={t.full_name} src={t.avatar_url} size={42} />
                  <div className="min-w-0 flex-1">
                    <p className="break-words text-[14px] font-semibold">{t.full_name}</p>
                    <p className="break-words text-[12.5px] text-ink-3">{t.subjects.length ? t.subjects.join(', ') : t.title ?? 'Öğretmen'}</p>
                    <p className="mt-1 flex flex-wrap gap-1">
                      {t.is_counselor && <Badge tone="accent">Rehber öğretmen</Badge>}
                      {t.is_advisor && <Badge tone="info">Sınıf danışmanı</Badge>}
                    </p>
                  </div>
                  {canRequest && (
                    <Button variant="outline" size="icon" aria-label={`${t.full_name} için talep oluştur`} title="Mesaj / görüşme talebi" onClick={() => setComposeFor(t.id)}>
                      <MessageSquarePlus className="size-4" />
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}

          {isGuardian && (
            <Panel title="Taleplerim" description="Yanıtlar öğretmen tarafından bu sayfaya yazılır" flush>
              {!data.requests_enabled && <Alert tone="info" className="mx-4 mb-3">Kurum şu an portal üzerinden talep almıyor. Lütfen kurumu arayın.</Alert>}
              {data.requests.length === 0 ? (
                <p className="px-4 pb-4 text-[14px] text-ink-3">Henüz talep oluşturmadınız.</p>
              ) : (
                <ul>
                  {data.requests.map((r) => (
                    <li key={r.id} className="border-t border-line px-4 py-3">
                      <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="text-[14.5px] font-semibold">{r.subject}</p>
                          <p className="text-[12.5px] text-ink-3">{r.teacher} · {r.kind_label} · <span title={dateTime(r.created_at)}>{relative(r.created_at)}</span></p>
                        </div>
                        <Badge tone={statusTone[r.status] ?? 'neutral'} dot>{r.status_label}</Badge>
                      </div>
                      <p className="mt-1 whitespace-pre-line text-[14px] text-ink-2">{r.body}</p>
                      {r.preferred_times && <p className="mt-1 inline-flex items-center gap-1.5 text-[12.5px] text-ink-3"><CalendarClock className="size-3.5" /> {r.preferred_times}</p>}
                      {r.response && (
                        <div className="mt-2 rounded-[var(--radius-sm)] bg-success-soft px-3 py-2 text-[14px]">
                          <p className="text-[12.5px] font-medium text-success">Öğretmenin yanıtı{r.responded_at ? ` · ${dateTime(r.responded_at)}` : ''}</p>
                          <p className="whitespace-pre-line">{r.response}</p>
                        </div>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          )}

          <Panel title="Kurum iletişim">
            <div className="flex flex-col gap-1 text-[14px]">
              <p className="font-medium">{data.institution.name}</p>
              {data.institution.phone && <PhoneText value={data.institution.phone} />}
              {data.institution.email && <MailText value={data.institution.email} />}
              {data.institution.address && <p className="inline-flex items-start gap-1.5 text-ink-2"><MapPin className="mt-0.5 size-3.5 shrink-0 text-ink-3" aria-hidden />{data.institution.address}</p>}
            </div>
            <p className="mt-2 inline-flex items-start gap-1.5 text-[12.5px] text-ink-3"><Info className="mt-0.5 size-3.5 shrink-0" /> Öğretmenlerin kişisel telefon numaraları paylaşılmaz.</p>
          </Panel>

          {composeFor !== null && (
            <RequestForm teachers={data.teachers} kinds={data.kinds} defaultTeacher={composeFor === 'any' ? '' : String(composeFor)} onClose={() => setComposeFor(null)} />
          )}
        </>
      )}
    </div>
  )
}

function RequestForm({ teachers, kinds, defaultTeacher, onClose }: { teachers: Teacher[]; kinds: Record<string, string>; defaultTeacher: string; onClose: () => void }) {
  const qc = useQueryClient()
  const extra = usePortalQuery()
  const [form, setForm] = useState({ teacher_id: defaultTeacher, kind: 'meeting', subject: '', body: '', preferred_times: '' })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const send = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/portal/requests${extra.student_id ? `?student_id=${extra.student_id}` : ''}`, { ...form, preferred_times: form.kind === 'meeting' ? form.preferred_times || null : null }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['portal', 'teachers'] })
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      } else toast.error('Talep gönderilemedi.')
    },
  })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    setErrors({})
    send.mutate()
  }
  const err = (k: string) => errors[k]?.[0] ?? null

  return (
    <Drawer open onClose={onClose} title="Öğretmene talep" description="Talebiniz öğretmenin portalına düşer; yanıt bu sayfada görünür."
      footer={<>
        <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
        <Button variant="primary" type="submit" form="req-form" loading={send.isPending}>Gönder</Button>
      </>}>
      <form id="req-form" onSubmit={submit} className="flex flex-col gap-3.5">
        <Field label="Öğretmen" required error={err('teacher_id')}>
          <Select value={form.teacher_id} placeholder="Öğretmen seçin" options={teachers.map((t) => ({ value: t.id, label: `${t.full_name}${t.subjects.length ? ` · ${t.subjects.join(', ')}` : ''}` }))}
            onChange={(e) => setForm((f) => ({ ...f, teacher_id: e.target.value }))} />
        </Field>
        <Field label="Talep türü" required>
          <Segmented value={form.kind} onChange={(k) => setForm((f) => ({ ...f, kind: k }))} options={Object.entries(kinds).map(([value, label]) => ({ value, label }))} />
        </Field>
        <Field label="Konu" required error={err('subject')}>
          <Input value={form.subject} maxLength={150} placeholder={form.kind === 'meeting' ? 'Örn. Matematik dersindeki durumu hakkında görüşme' : 'Örn. Ödev hakkında soru'} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} />
        </Field>
        <Field label="Mesajınız" required error={err('body')}>
          <Textarea rows={5} maxLength={2000} value={form.body} onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} />
        </Field>
        {form.kind === 'meeting' && (
          <Field label="Size uygun zamanlar" optional hint="Örn. Hafta içi 17:00 sonrası, cumartesi öğlen" error={err('preferred_times')}>
            <Input value={form.preferred_times} maxLength={200} onChange={(e) => setForm((f) => ({ ...f, preferred_times: e.target.value }))} />
          </Field>
        )}
        <p className="text-[12.5px] text-ink-3">Bu talep SMS/WhatsApp olarak gönderilmez; öğretmen portalda görür ve yanıtlar. Acil durumlarda kurumu arayın.</p>
      </form>
    </Drawer>
  )
}
