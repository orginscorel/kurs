import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Copy, Eye, EyeOff, KeyRound, LogIn, MessageCircle, RotateCcw, UserPlus } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import type { Me } from '@/app/auth'
import { Panel } from '@/components/ui/layout'
import { Alert, Badge, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/overlay'
import { waLink } from './types'

type Kind = 'student' | 'guardian' | 'teacher'

type Account = {
  has_account: boolean
  username: string | null
  is_active: boolean
  last_login_at: string | null
  password_changed: boolean
  password_changed_at: string | null
  can_view_credentials: boolean
  can_impersonate: boolean
  // öğrenci
  student_no?: string
  // veli
  desired_username?: string | null
  should_be_active?: boolean
  problem?: 'phone_missing' | 'phone_conflict' | null
  conflict?: { guardian_id: number | null; guardian_name: string | null; is_guardian: boolean } | null
  // öğretmen
  portal_only?: boolean
}

const BASE: Record<Kind, string> = { student: '/students', guardian: '/guardians', teacher: '/teachers' }
const WHO: Record<Kind, string> = { student: 'Öğrenci', guardian: 'Veli', teacher: 'Öğretmen' }
const WHOSE: Record<Kind, string> = { student: 'öğrencinin', guardian: 'velinin', teacher: 'öğretmenin' }
const HOME: Record<Kind, string> = { student: '/portal', guardian: '/portal', teacher: '/ogretmen' }

async function copyText(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success('Panoya kopyalandı.')
  } catch {
    toast.error('Kopyalanamadı; metni elle seçip kopyalayın.')
  }
}

const iconBtn = 'grid size-7 place-items-center rounded text-ink-3 hover:bg-surface-2 hover:text-ink disabled:opacity-50'

/**
 * Portal giriş bilgileri paneli (öğrenci ve veli ortak): kullanıcı adı, başlangıç şifresi
 * (göster/kopyala/sıfırla — denetim kaydına yazılır), WhatsApp taslağı (gönderimi personel yapar).
 */
function PortalAccountPanel({ kind, id, name, recipientName, recipientPhone }: { kind: Kind; id: number; name: string; recipientName?: string; recipientPhone?: string | null }) {
  const qc = useQueryClient()
  const [password, setPassword] = useState<string | null>(null)
  const [revealed, setRevealed] = useState(false)
  const [confirmReset, setConfirmReset] = useState(false)
  const key = [kind, String(id), 'portal-account']
  const base = `${BASE[kind]}/${id}/portal-account`

  const { data, isLoading } = useQuery({ queryKey: key, queryFn: () => api.get<Account>(base) })

  const reveal = useMutation({
    mutationFn: () => api.get<{ username: string; password: string | null; password_changed: boolean }>(`${base}/credentials`),
    onSuccess: (r) => {
      setPassword(r.password)
      setRevealed(true)
      if (r.password_changed) qc.invalidateQueries({ queryKey: key })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Bilgiler alınamadı.'),
  })

  const reset = useMutation({
    mutationFn: () => api.post<{ message: string; password: string }>(`${base}/reset-password`),
    onSuccess: (r) => {
      toast.success(r.message)
      setPassword(r.password)
      setRevealed(true)
      setConfirmReset(false)
      qc.invalidateQueries({ queryKey: key })
    },
    onError: (e) => { toast.error(e instanceof ApiError ? e.message : 'Şifre sıfırlanamadı.'); setConfirmReset(false) },
  })

  const create = useMutation({
    mutationFn: () => api.post<{ message: string }>(base),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: key }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Hesap açılamadı.'),
  })

  if (isLoading || !data) {
    return <Panel title="Portal girişi"><Skeleton className="h-20" /></Panel>
  }

  const username = data.username ?? data.student_no ?? data.desired_username ?? ''
  const loginUrl = `${window.location.origin}/giris`
  const message = !password ? '' : kind === 'teacher'
    ? `Merhaba ${name}, öğretmen portalı giriş bilgileriniz:\nAdres: ${loginUrl}\nKullanıcı adı: ${username}\nŞifre: ${password}\nİlk girişte kendi şifrenizi belirlemeniz istenecek. Bu bilgileri kimseyle paylaşmayın.`
    : kind === 'guardian'
    ? `Merhaba ${name}, kurumumuzun veli portalı giriş bilgileriniz:\nAdres: ${loginUrl}\nKullanıcı adı (cep telefonunuz): ${username}\nŞifre: ${password}\nİlk girişte kendi şifrenizi belirlemeniz istenecek. Bu bilgileri kimseyle paylaşmayın.`
    : `Merhaba${recipientName ? ` ${recipientName}` : ''}, ${name} için öğrenci portalı giriş bilgileri:\nAdres: ${loginUrl}\nKullanıcı adı: ${username}\nŞifre: ${password}\nİlk girişte yeni şifre belirlemesi istenecek.`
  const wa = password ? waLink(recipientPhone, message) : null

  const status = data.has_account
    ? (data.is_active ? <Badge tone="success" dot>Açık</Badge> : <Badge tone="danger" dot>Pasif</Badge>)
    : <Badge>Hesap yok</Badge>

  return (
    <Panel
      title={<span id={kind === 'guardian' ? 'portal-girisi' : undefined} className="inline-flex scroll-mt-24 items-center gap-1.5"><KeyRound className="size-4 text-primary" /> Portal girişi</span>}
      actions={status}
    >
      {!data.has_account ? (
        <div className="flex flex-col gap-2">
          {data.problem === 'phone_missing' ? (
            <Alert tone="warning">Veli portal hesabı için geçerli bir cep telefonu (05xx…) girilmeli. Telefon eklenince hesap otomatik açılır.</Alert>
          ) : data.problem === 'phone_conflict' ? (
            <Alert tone="warning">
              Bu cep telefonu {data.conflict?.guardian_id
                ? <Link className="font-medium underline" to={`/veliler/${data.conflict.guardian_id}`}>{data.conflict.guardian_name}</Link>
                : 'başka bir kullanıcı'} hesabında kullanılıyor. Aynı kişiyse kayıtları birleştirin; değilse telefonu düzeltin.
            </Alert>
          ) : (
            <p className="text-[13px] text-ink-3">{WHO[kind]} portal hesabı henüz açılmamış.</p>
          )}
          {data.can_view_credentials && !data.problem && (
            <Button size="sm" icon={<UserPlus className="size-4" />} loading={create.isPending} onClick={() => create.mutate()} className="self-start">Hesap aç</Button>
          )}
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          <dl className="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-2 text-[13px]">
            <dt className="text-ink-3">{kind === 'student' ? 'Öğrenci no' : 'Kullanıcı adı'}</dt>
            <dd className="flex min-w-0 items-center justify-end gap-1.5">
              <span className="truncate font-semibold tabular tracking-wide">{username}</span>
              <button className={iconBtn} onClick={() => copyText(username)} aria-label="Kullanıcı adını kopyala"><Copy className="size-3.5" /></button>
            </dd>
            <dt className="text-ink-3">Şifre</dt>
            <dd className="flex min-w-0 items-center justify-end gap-1.5">
              {data.password_changed && !password ? (
                <span className="text-right text-[12.5px] text-ink-2">{data.password_changed_at ? `${WHO[kind]} şifresini değiştirdi · ${relative(data.password_changed_at)}` : kind === 'teacher' ? 'Başlangıç şifresi yok (öğretmenin kendi şifresi)' : `${WHO[kind]} şifresini değiştirdi`}</span>
              ) : !data.can_view_credentials ? (
                <span className="text-ink-3">Görme yetkiniz yok</span>
              ) : (
                <>
                  <span className="font-mono text-[14px] font-semibold tracking-[0.12em]">{revealed && password ? password : '•••••••'}</span>
                  {revealed && password ? (
                    <>
                      <button className={iconBtn} onClick={() => copyText(password)} aria-label="Şifreyi kopyala"><Copy className="size-3.5" /></button>
                      <button className={iconBtn} onClick={() => setRevealed(false)} aria-label="Şifreyi gizle"><EyeOff className="size-3.5" /></button>
                    </>
                  ) : (
                    <button className={iconBtn} onClick={() => (password ? setRevealed(true) : reveal.mutate())} disabled={reveal.isPending} title="Göster (denetim kaydına yazılır)" aria-label="Şifreyi göster">
                      <Eye className="size-3.5" />
                    </button>
                  )}
                </>
              )}
            </dd>
            <dt className="text-ink-3">Son giriş</dt>
            <dd className="text-right text-ink-2">{data.last_login_at ? dateTime(data.last_login_at) : 'Hiç giriş yapmadı'}</dd>
          </dl>

          {!data.password_changed && <p className="text-[12px] text-ink-3">İlk girişte kendi şifresini belirlemesi istenir.</p>}
          {!data.is_active && (
            <p className="text-[12px] text-ink-3">
              {kind === 'teacher' ? 'Öğretmen pasif olduğu için hesap kapalı.' : kind === 'guardian' ? 'Velinin açık (ayrılmamış/mezun olmamış) öğrencisi olmadığı için hesap kapalı.' : 'Ayrılan/mezun öğrencinin hesabı kapalıdır; durumu değişince yeniden açılır.'}
            </p>
          )}
          {kind === 'teacher' && data.portal_only === false && (
            <p className="text-[12px] text-ink-3">Öğretmenin ek yönetim rolü var; girişte yönetim paneli açılır, öğretmen portalına /ogretmen adresinden ulaşır.</p>
          )}
          {kind === 'teacher' && data.portal_only !== false && (
            <p className="text-[12px] text-ink-3">Girişte yalnız öğretmen portalı açılır: kendi dersleri, yoklama, ödev/puan ve gözlem notları. Finans ve yönetim ekranları kapalıdır.</p>
          )}
          {kind === 'guardian' && data.problem === 'phone_conflict' && (
            <Alert tone="warning">Velinin yeni telefonu başka bir hesapta kullanıldığından kullanıcı adı güncellenemedi.</Alert>
          )}

          {data.can_view_credentials && (
            <div className="flex flex-wrap gap-1.5">
              {revealed && password && (
                <>
                  <Button size="xs" icon={<Copy className="size-3.5" />} onClick={() => copyText(message)}>Bilgileri kopyala</Button>
                  {wa && (
                    <a href={wa} target="_blank" rel="noopener" className="inline-flex h-7 items-center gap-1 rounded-[var(--radius-xs)] border border-line bg-surface px-2 text-xs font-medium text-ink hover:bg-surface-2" title="WhatsApp'ta hazır mesaj taslağı açılır; gönderimi siz yaparsınız">
                      <MessageCircle className="size-3.5" /> {kind === 'student' ? 'Veliye WhatsApp taslağı' : 'WhatsApp taslağı'}
                    </a>
                  )}
                </>
              )}
              <Button size="xs" variant="ghost" icon={<RotateCcw className="size-3.5" />} onClick={() => setConfirmReset(true)}>Şifreyi sıfırla</Button>
            </div>
          )}
        </div>
      )}

      <ConfirmDialog
        open={confirmReset}
        onClose={() => setConfirmReset(false)}
        onConfirm={() => reset.mutate()}
        loading={reset.isPending}
        title="Portal şifresi sıfırlansın mı?"
        description={`Yeni bir başlangıç şifresi üretilir, eski şifre geçersiz olur ve ${WHOSE[kind]} açık oturumları kapanır. İlk girişte yeniden şifre belirlemesi istenir. İşlem denetim kaydına yazılır.`}
        confirmLabel="Sıfırla"
      />
    </Panel>
  )
}

/** Öğrenci profili: portal giriş bilgileri (kullanıcı adı = öğrenci no). */
export function StudentPortalAccountPanel({ studentId, studentName, guardianName, guardianPhone }: { studentId: number; studentName: string; guardianName?: string; guardianPhone?: string | null }) {
  return <PortalAccountPanel kind="student" id={studentId} name={studentName} recipientName={guardianName} recipientPhone={guardianPhone} />
}

/** Veli profili: veli portal giriş bilgileri (kullanıcı adı = cep telefonu). */
export function GuardianPortalAccountPanel({ guardianId, guardianName, phone }: { guardianId: number; guardianName: string; phone?: string | null }) {
  return <PortalAccountPanel kind="guardian" id={guardianId} name={guardianName} recipientPhone={phone} />
}

function ImpersonateAs({ kind, id }: { kind: Kind; id: number }) {
  const qc = useQueryClient()
  const m = useMutation({
    mutationFn: () => api.post<Me>(`${BASE[kind]}/${id}/impersonate`),
    onSuccess: () => { qc.clear(); window.location.assign(HOME[kind]) },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Önizleme başlatılamadı.'),
  })
  return (
    <Button icon={<LogIn className="size-4" />} loading={m.isPending} onClick={() => m.mutate()} title={`${WHO[kind]} portalını onun gözünden görün (değişiklik yapılamaz)`}>
      {WHO[kind]} olarak giriş yap
    </Button>
  )
}

/** Başlık alanı için kısa düğme (yalnız students.impersonate). */
export function ImpersonateButton({ studentId }: { studentId: number }) {
  return <ImpersonateAs kind="student" id={studentId} />
}

/** Veli profili başlığı (yalnız guardians.impersonate). */
export function ImpersonateGuardianButton({ guardianId }: { guardianId: number }) {
  return <ImpersonateAs kind="guardian" id={guardianId} />
}

/** Öğretmen profili: öğretmen portalı giriş bilgileri (kullanıcı adı = ad.soyad). */
export function TeacherPortalAccountPanel({ teacherId, teacherName, phone }: { teacherId: number; teacherName: string; phone?: string | null }) {
  return <PortalAccountPanel kind="teacher" id={teacherId} name={teacherName} recipientPhone={phone} />
}

/** Öğretmen profili başlığı (yalnız teachers.impersonate). */
export function ImpersonateTeacherButton({ teacherId }: { teacherId: number }) {
  return <ImpersonateAs kind="teacher" id={teacherId} />
}
