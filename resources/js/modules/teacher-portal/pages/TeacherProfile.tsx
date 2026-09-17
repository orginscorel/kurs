import { useQueryClient } from '@tanstack/react-query'
import { Building2, CheckCircle2, KeyRound, UserRound, XCircle } from 'lucide-react'
import { date, dateTime, phone } from '@/lib/format'
import { useAuth } from '@/app/auth'
import { Alert, Avatar, Skeleton } from '@/components/ui/feedback'
import { DescriptionList, Panel } from '@/components/ui/layout'
import { PortalTitle } from '@/modules/portal/ui'
import { PasswordForm } from '@/modules/portal/PasswordForm'
import { useTeacherQuery, type TeacherIdentity } from '../api'

type Data = {
  teacher: TeacherIdentity & { phone: string | null; email: string | null; hired_on: string | null; employment_type_label: string | null; subjects: string[] }
  institution: { name: string | null; phone: string | null; email: string | null; address: string | null }
  account: { username: string; last_login_at: string | null; password_changed_at: string | null; uses_initial_password: boolean }
  permissions: { attendance: boolean; homework: boolean; observations: boolean }
}

const PERMS: { key: keyof Data['permissions']; label: string }[] = [
  { key: 'attendance', label: 'Yoklama alma' },
  { key: 'homework', label: 'Ödev verme ve puanlama' },
  { key: 'observations', label: 'Gözlem notu / davranış puanı' },
]

export default function TeacherProfile() {
  const { data, isLoading } = useTeacherQuery<Data>(['profile'], '/profile')
  const imp = useAuth((s) => s.me?.impersonation)
  const qc = useQueryClient()
  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-28" /><Skeleton className="h-64" /></div>
  const t = data.teacher

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Profilim" />
      <section className="flex items-center gap-4 rounded-[var(--radius-lg)] bg-surface p-4 ring-1 ring-line">
        <Avatar name={t.full_name} src={t.avatar_url} size={56} />
        <div className="min-w-0">
          <p className="break-words text-[18px] font-semibold">{t.full_name}</p>
          <p className="text-[14px] text-ink-3">{[t.title, t.specialty].filter(Boolean).join(' · ') || 'Öğretmen'}</p>
          {t.subjects.length > 0 && <p className="mt-0.5 text-[12.5px] text-ink-2">Branşlar: {t.subjects.join(', ')}</p>}
        </div>
      </section>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="flex flex-col gap-4">
          <Panel title={<span className="inline-flex items-center gap-1.5"><UserRound className="size-4 text-primary" /> Bilgilerim</span>} description="Değişiklik için kurum yönetimine başvurun">
            <DescriptionList columns={1} items={[
              { label: 'Telefon', value: t.phone ? phone(t.phone) : '—' },
              { label: 'E-posta', value: t.email ?? '—' },
              { label: 'Çalışma şekli', value: t.employment_type_label ?? '—' },
              { label: 'Başlama tarihi', value: t.hired_on ? date(t.hired_on, 'long') : '—' },
            ]} />
          </Panel>
          <Panel title="Portal yetkilerim">
            <ul className="flex flex-col gap-1.5 text-[14px]">
              {PERMS.map((p) => (
                <li key={p.key} className="flex items-center gap-2">
                  {data.permissions[p.key] ? <CheckCircle2 className="size-4 text-success" /> : <XCircle className="size-4 text-ink-3" />}
                  <span className={data.permissions[p.key] ? '' : 'text-ink-3'}>{p.label}</span>
                </li>
              ))}
            </ul>
            <p className="mt-2 text-[12.5px] text-ink-3">Finans, öğrenci iletişim bilgileri ve yönetim ekranları öğretmen portalına kapalıdır.</p>
          </Panel>
          <Panel title={<span className="inline-flex items-center gap-1.5"><Building2 className="size-4 text-primary" /> Kurum</span>}>
            <DescriptionList columns={1} items={[
              { label: 'Kurum', value: data.institution.name ?? '—' },
              { label: 'Telefon', value: data.institution.phone ? phone(data.institution.phone) : '—' },
              { label: 'E-posta', value: data.institution.email ?? '—' },
              { label: 'Adres', value: data.institution.address ?? '—' },
            ]} />
          </Panel>
        </div>
        <Panel title={<span className="inline-flex items-center gap-1.5"><KeyRound className="size-4 text-primary" /> Hesap ve şifre</span>}>
          <DescriptionList columns={1} items={[
            { label: 'Kullanıcı adı', value: <span className="tabular">{data.account.username}</span> },
            { label: 'Son giriş', value: data.account.last_login_at ? dateTime(data.account.last_login_at) : '—' },
            { label: 'Şifre', value: data.account.uses_initial_password ? 'Kurumun verdiği başlangıç şifresi' : data.account.password_changed_at ? `Değiştirildi ${dateTime(data.account.password_changed_at)}` : 'Kurumun belirlediği şifre' },
          ]} />
          {imp ? <Alert tone="warning" className="mt-3">Önizleme modunda şifre değiştirilemez.</Alert> : (
            <PasswordForm className="mt-4" onDone={() => qc.invalidateQueries({ queryKey: ['teacher-portal', 'profile'] })} />
          )}
        </Panel>
      </div>
    </div>
  )
}
