import { useState, type FormEvent } from 'react'
import { MailText, PhoneText } from '@/components/ui/contact'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { KeyRound, LogOut, ShieldCheck } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { useAuth } from '@/app/auth'
import { DescriptionList, PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Avatar, Badge, Skeleton } from '@/components/ui/feedback'
import { Field, Input } from '@/components/ui/form'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/overlay'
import { SessionList, type SessionRow } from './SessionList'

type LoginRow = { id: number; successful: boolean; channel: string; ip_address: string | null; user_agent: string | null; created_at: string }
type SessionsResponse = { data: SessionRow[]; recent_logins: LoginRow[]; node?: 'server' | 'local'; scope?: 'all' | 'local'; notice?: string | null }

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

  const sessions = useQuery({ queryKey: ['auth', 'sessions'], queryFn: () => api.get<SessionsResponse>('/auth/sessions'), refetchInterval: 60_000 })
  const [confirmAll, setConfirmAll] = useState(false)

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
    mutationFn: (id: string) => api.delete<{ message: string }>(`/auth/sessions/${encodeURIComponent(id)}`),
    onSuccess: (r) => {
      toast.success(r?.message || 'Oturum kapatıldı.')
      qc.invalidateQueries({ queryKey: ['auth', 'sessions'] })
    },
    onError: (e) => {
      toast.error(e instanceof ApiError ? e.firstError() : 'Oturum kapatılamadı.')
      qc.invalidateQueries({ queryKey: ['auth', 'sessions'] })
    },
  })

  const revokeAll = useMutation({
    mutationFn: () => api.delete<{ message: string; warning?: boolean }>('/auth/sessions'),
    onSuccess: (r) => {
      if (r?.warning) toast.warning(r.message)
      else toast.success(r?.message || 'Diğer oturumlar kapatıldı.')
      setConfirmAll(false)
      qc.invalidateQueries({ queryKey: ['auth', 'sessions'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Oturumlar kapatılamadı.'),
  })

  const rows = sessions.data?.data ?? []
  const others = rows.filter((s) => !s.is_current && s.status !== 'closing')

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

        <Panel
          className="lg:col-span-3"
          title="Açık oturumlar ve cihazlar"
          description="Tarayıcılar, Mac uygulaması ve mobil uygulamadaki oturumlarınız."
          actions={others.length > 0 && (
            <Button size="sm" variant="danger-soft" icon={<LogOut className="size-3.5" />} onClick={() => setConfirmAll(true)}>
              <span className="hidden sm:inline">Diğer tüm oturumları kapat</span><span className="sm:hidden">Tümünü kapat</span>
            </Button>
          )}
          flush
        >
          {sessions.data?.notice && (
            <div className="px-4 pb-3">
              <Alert tone={sessions.data.scope === 'local' ? 'info' : 'warning'}>{sessions.data.notice}</Alert>
            </div>
          )}
          {sessions.isLoading ? (
            <div className="p-4"><Skeleton className="h-32" /></div>
          ) : (
            <SessionList rows={rows} pendingId={revoke.isPending ? revoke.variables : null} onRevoke={(s) => revoke.mutate(s.id)} />
          )}
        </Panel>

        <Panel className="lg:col-span-2" title="Son giriş denemeleri" flush>
          <ul>
            {sessions.data?.recent_logins.map((l, i) => (
              <li key={`${i}-${l.id}`} className="flex items-center gap-3 border-t border-line px-4 py-2.5">
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

      <ConfirmDialog
        open={confirmAll}
        onClose={() => setConfirmAll(false)}
        onConfirm={() => revokeAll.mutate()}
        loading={revokeAll.isPending}
        danger
        title="Diğer tüm oturumlar kapatılsın mı?"
        confirmLabel="Hepsini kapat"
        description="Bu cihaz dışındaki tarayıcı, Mac uygulaması ve mobil uygulama oturumlarınız kapanır. Çevrimdışı bir Mac'teki oturum, o Mac sunucuya bağlandığında kapanır."
      />
    </div>
  )
}
