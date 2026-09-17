import { useState, type FormEvent } from 'react'
import { MailText, PhoneText } from '@/components/ui/contact'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { KeyRound, Laptop, ShieldCheck, Smartphone } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useAuth } from '@/app/auth'
import { DescriptionList, PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Avatar, Badge, Skeleton } from '@/components/ui/feedback'
import { Field, Input } from '@/components/ui/form'
import { Button } from '@/components/ui/Button'

type SessionRow = { id: string; kind: 'web' | 'mobile'; device: string; ip_address: string | null; last_active_at: string | null; is_current: boolean }
type LoginRow = { id: number; successful: boolean; channel: string; ip_address: string | null; user_agent: string | null; created_at: string }

/** Rol kodlarının görünen adları (App\Support\Permissions::defaultRoles ile aynı) */
const ROLE_LABEL: Record<string, string> = {
  'super-admin': 'Sistem Yöneticisi', yonetici: 'Yönetici', mudur: 'Müdür', muhasebe: 'Muhasebe', rehber: 'Rehber Öğretmen',
  ogretmen: 'Öğretmen', danisman: 'Danışman / Kayıt Personeli', sistem: 'Teknik Yönetici', ogrenci: 'Öğrenci', veli: 'Veli',
}

export default function Account() {
  const me = useAuth((s) => s.me)
  const load = useAuth((s) => s.load)
  const [params] = useSearchParams()
  const forced = params.get('parola') === '1' || me?.user.must_change_password
  const qc = useQueryClient()

  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const sessions = useQuery({ queryKey: ['auth', 'sessions'], queryFn: () => api.get<{ data: SessionRow[]; recent_logins: LoginRow[] }>('/auth/sessions') })

  const changePassword = useMutation({
    mutationFn: () => api.post<{ message: string }>('/auth/change-password', form),
    onSuccess: async (res) => {
      toast.success(res.message)
      setForm({ current_password: '', password: '', password_confirmation: '' })
      setErrors({})
      await load()
      qc.invalidateQueries({ queryKey: ['auth', 'sessions'] })
    },
    onError: (err) => {
      if (err instanceof ApiError) {
        setErrors(err.errors)
        if (!Object.keys(err.errors).length) toast.error(err.message)
      }
    },
  })

  const revoke = useMutation({
    mutationFn: (id: string) => api.delete(`/auth/sessions/${encodeURIComponent(id)}`),
    onSuccess: () => {
      toast.success('Oturum kapatıldı.')
      qc.invalidateQueries({ queryKey: ['auth', 'sessions'] })
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    changePassword.mutate()
  }

  return (
    <div className="animate-fade-in max-w-5xl">
      <PageHeader title="Hesabım" description="Profil, şifre ve açık oturumlarınız" />

      {forced && (
        <Alert tone="warning" title="Şifrenizi değiştirmeniz gerekiyor" className="mb-4">
          Güvenliğiniz için size verilen geçici şifreyi kendi belirleyeceğiniz bir şifreyle değiştirin.
        </Alert>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-5 gap-4">
        <Panel className="lg:col-span-2" title="Profil">
          <div className="flex items-center gap-3 mb-5">
            <Avatar name={me?.user.name} src={me?.user.avatar_url} size={52} />
            <div>
              <p className="text-[15px] font-semibold">{me?.user.name}</p>
              <p className="text-[12.5px] text-ink-3">Kullanıcı adı: {me?.user.username}</p>
            </div>
          </div>
          <DescriptionList
            columns={1}
            items={[
              { label: 'Roller', value: <span className="flex flex-wrap gap-1">{me?.roles.map((r) => <Badge key={r} tone="primary">{ROLE_LABEL[r] ?? r}</Badge>)}</span> },
              { label: 'Şube', value: me?.branch?.name },
              { label: 'E-posta', value: <MailText value={me?.user.email} /> },
              { label: 'Telefon', value: <PhoneText value={me?.user.phone} /> },
            ]}
          />
        </Panel>

        <Panel className="lg:col-span-3" title="Şifre değiştir" description="En az 10 karakter; harf ve rakam içermeli.">
          <form onSubmit={submit} className="grid gap-3.5 max-w-md">
            <Field label="Mevcut şifre" required error={errors.current_password?.[0]}>
              <Input type="password" autoComplete="current-password" value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} required />
            </Field>
            <Field label="Yeni şifre" required error={errors.password?.[0]}>
              <Input type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required />
            </Field>
            <Field label="Yeni şifre (tekrar)" required error={errors.password_confirmation?.[0]}>
              <Input type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} required />
            </Field>
            <div>
              <Button type="submit" variant="primary" icon={<KeyRound className="size-4" />} loading={changePassword.isPending}>
                Şifreyi güncelle
              </Button>
            </div>
          </form>
        </Panel>

        <Panel className="lg:col-span-3" title="Açık oturumlar ve cihazlar" flush>
          {sessions.isLoading ? (
            <div className="p-4"><Skeleton className="h-32" /></div>
          ) : (
            <ul>
              {sessions.data?.data.map((s) => (
                <li key={s.id} className="flex items-center gap-3 border-t border-line px-4 py-3">
                  <span className="grid size-9 place-items-center rounded-[var(--radius-sm)] bg-surface-2 text-ink-2">
                    {s.kind === 'mobile' ? <Smartphone className="size-4" /> : <Laptop className="size-4" />}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="text-[13.5px] font-medium">
                      {s.device || 'Bilinmeyen cihaz'} {s.is_current && <Badge tone="success" className="ml-1">Bu cihaz</Badge>}
                    </p>
                    <p className="text-[12px] text-ink-3">
                      {s.ip_address ?? 'Mobil uygulama'} · son etkinlik {relative(s.last_active_at)}
                    </p>
                  </div>
                  {!s.is_current && (
                    <Button size="sm" variant="ghost" loading={revoke.isPending && revoke.variables === s.id} onClick={() => revoke.mutate(s.id)}>
                      Oturumu kapat
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel className="lg:col-span-2" title="Son giriş denemeleri" flush>
          <ul>
            {sessions.data?.recent_logins.map((l) => (
              <li key={l.id} className="flex items-center gap-3 border-t border-line px-4 py-2.5">
                <ShieldCheck className={l.successful ? 'size-4 text-success' : 'size-4 text-danger'} />
                <div className="min-w-0 flex-1">
                  <p className="text-[13px]">{l.successful ? 'Başarılı giriş' : 'Başarısız deneme'}</p>
                  <p className="text-[12px] text-ink-3 truncate">{l.ip_address} · {dateTime(l.created_at)}</p>
                </div>
              </li>
            ))}
          </ul>
        </Panel>
      </div>
    </div>
  )
}
