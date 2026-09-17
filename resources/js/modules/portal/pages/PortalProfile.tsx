import type { ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { KeyRound } from 'lucide-react'
import { date, dateTime } from '@/lib/format'
import { MailText, PersonText, PhoneText } from '@/components/ui/contact'
import { cn } from '@/lib/cn'
import { useAuth } from '@/app/auth'
import { Alert, Avatar, Badge, Skeleton } from '@/components/ui/feedback'
import { DescriptionList, Panel } from '@/components/ui/layout'
import { usePortal, usePortalContext, usePortalRole, usePortalStudent, useSelectedStudentId, type Identity } from '../api'
import { PortalTitle } from '../ui'
import { PasswordForm } from '../PasswordForm'

type Data = {
  student: Identity & {
    first_name: string; last_name: string; birth_date: string | null; school_name: string | null; school_grade: string | null; field: string | null
    target_university: string | null; target_department: string | null; phone: string | null; email: string | null; registered_on: string | null; status_label: string
  }
  guardians: { name: string; relationship: string; is_primary: boolean; phone: string | null }[]
  guardian: { full_name: string; phone: string | null; whatsapp_phone: string | null; email: string | null; occupation: string | null; relationship: string | null } | null
  account: { username: string; last_login_at: string | null; password_changed_at: string | null; uses_initial_password: boolean }
}

const relationLabel: Record<string, string> = { mother: 'Anne', father: 'Baba', parent: 'Veli', guardian: 'Vasi', sibling: 'Kardeş', other: 'Diğer' }

export default function PortalProfile() {
  const { data, isLoading } = usePortal<Data>('profile', '/portal/profile')
  const role = usePortalRole()
  const imp = useAuth((s) => s.me?.impersonation)
  const qc = useQueryClient()

  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-28" /><Skeleton className="h-64" /></div>
  const s = data.student

  const account = (
    <Panel title={<span className="inline-flex items-center gap-1.5"><KeyRound className="size-4 text-primary" /> Hesap ve şifre</span>}>
      <DescriptionList
        columns={1}
        items={[
          { label: 'Kullanıcı adı', value: <span className="tabular">{data.account.username}</span> },
          { label: 'Son giriş zamanı', value: data.account.last_login_at ? dateTime(data.account.last_login_at) : '—' },
          { label: 'Şifre', value: data.account.uses_initial_password ? 'Kurumun verdiği başlangıç şifresi' : `Değiştirildi ${data.account.password_changed_at ? dateTime(data.account.password_changed_at) : ''}` },
        ]}
      />
      {imp ? (
        <Alert tone="warning" className="mt-3">Önizleme modunda şifre değiştirilemez.</Alert>
      ) : (
        <PasswordForm className="mt-4" onDone={() => qc.invalidateQueries({ queryKey: ['portal', 'profile'] })} />
      )}
    </Panel>
  )

  if (role === 'guardian' && data.guardian) return <GuardianProfile data={data} account={account} />

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Profilim" />

      <section className="flex items-center gap-4 rounded-[var(--radius-lg)] bg-surface p-4 ring-1 ring-line">
        <Avatar name={s.full_name} src={s.photo_url} size={56} />
        <div className="min-w-0">
          <p className="break-words text-[17px] font-semibold">{s.full_name}</p>
          <p className="text-[14px] text-ink-2 tabular">Öğrenci no {s.student_no} · {s.status_label}</p>
          <p className="break-words text-[12.5px] text-ink-3">{s.class_groups.map((g) => [g.name, g.program].filter(Boolean).join(' · ')).join(', ') || 'Sınıf atanmamış'}</p>
        </div>
      </section>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel title="Bilgilerim">
          <StudentInfo s={s} />
          <p className="mt-3 text-[12.5px] text-ink-3">Bilgilerinde hata varsa kurum sekreterliğine bildir.</p>
        </Panel>

        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="Velilerim" flush>
            {data.guardians.length === 0 ? (
              <p className="px-4 pb-4 text-[14px] text-ink-3">Veli kaydı yok.</p>
            ) : (
              <ul>
                {data.guardians.map((g, i) => (
                  <li key={i} className="flex items-center justify-between gap-3 border-t border-line px-4 py-2.5 text-[14px]">
                    <span className="min-w-0 break-words font-medium"><PersonText>{g.name}</PersonText> <span className="font-normal text-ink-3">· {relationLabel[g.relationship] ?? g.relationship}{g.is_primary ? ' · ana veli' : ''}</span></span>
                    <PhoneText value={g.phone} className="shrink-0" />
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          {account}
        </div>
      </div>
    </div>
  )
}

function StudentInfo({ s }: { s: Data['student'] }) {
  return (
    <DescriptionList
      columns={1}
      items={[
        { label: 'Okul', value: [s.school_name, s.school_grade ? `${s.school_grade}. sınıf` : null].filter(Boolean).join(' · ') || '—' },
        { label: 'Alan (puan türü)', value: s.field ?? '—' },
        { label: 'Hedef bölüm / üniversite', value: [s.target_department, s.target_university].filter(Boolean).join(' · ') || '—' },
        { label: 'Doğum tarihi', value: date(s.birth_date) },
        { label: 'Telefon', value: <PhoneText value={s.phone} /> },
        { label: 'E-posta', value: <MailText value={s.email} /> },
        { label: 'Rehber öğretmen', value: s.counselor ?? '—' },
        { label: 'Kayıt tarihi', value: date(s.registered_on) },
      ]}
    />
  )
}

/** Veli profili: kendi bilgileri, bağlı öğrencileri (kardeş geçişi), seçili öğrencinin bilgileri, şifre. */
function GuardianProfile({ data, account }: { data: Data; account: ReactNode }) {
  const g = data.guardian!
  const s = data.student
  const ctx = usePortalContext()
  const userId = useAuth((st) => st.me?.user.id)
  const current = useSelectedStudentId()
  const setStudent = usePortalStudent((st) => st.set)
  const children = ctx.data?.students ?? []

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Profilim" />

      <section className="flex items-center gap-4 rounded-[var(--radius-lg)] bg-surface p-4 ring-1 ring-line">
        <Avatar name={g.full_name} size={56} />
        <div className="min-w-0">
          <p className="break-words text-[17px] font-semibold">{g.full_name}</p>
          <p className="text-[14px] text-ink-2">Veli · {children.length || 1} öğrenci</p>
          {g.occupation && <p className="break-words text-[12.5px] text-ink-3">Meslek: {g.occupation}</p>}
        </div>
      </section>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="Bilgilerim">
            <DescriptionList
              columns={1}
              items={[
                { label: 'Cep telefonu', value: <PhoneText value={g.phone} /> },
                { label: 'WhatsApp numarası', value: <PhoneText value={g.whatsapp_phone} whatsapp /> },
                { label: 'E-posta', value: <MailText value={g.email} /> },
              ]}
            />
            <p className="mt-3 text-[12.5px] text-ink-3">Bilgilerinizde hata varsa kurum sekreterliğine bildirin. Telefon numaranız değişirse kullanıcı adınız da değişir.</p>
          </Panel>

          <Panel title="Öğrencilerim" flush>
            <ul>
              {children.map((c) => (
                <li key={c.id}>
                  <button
                    type="button"
                    onClick={() => userId && setStudent(userId, c.id)}
                    className={cn('flex w-full items-center gap-3 border-t border-line px-4 py-2.5 text-left hover:bg-surface-2', c.id === current && 'bg-primary-soft/50')}
                  >
                    <Avatar name={c.full_name} src={c.photo_url} size={32} />
                    <span className="min-w-0 flex-1">
                      <span className="block break-words text-[14.5px] font-medium">{c.full_name}</span>
                      <span className="block break-words text-[12.5px] text-ink-3 tabular">Öğrenci no {c.student_no}{c.class_groups.length ? ` · Sınıf: ${c.class_groups.join(', ')}` : ''}{c.relationship ? ` · ${relationLabel[c.relationship] ?? c.relationship}` : ''}</span>
                    </span>
                    {c.id === current && <Badge tone="primary">Görüntüleniyor</Badge>}
                  </button>
                </li>
              ))}
            </ul>
          </Panel>
        </div>

        <div className="flex min-w-0 flex-col gap-4">
          <Panel title={`${s.first_name} · öğrenci bilgileri`}>
            <StudentInfo s={s} />
          </Panel>
          {account}
        </div>
      </div>
    </div>
  )
}
