import { useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { KeyRound, LogOut } from 'lucide-react'
import { useAuth } from '@/app/auth'
import { BrandMark } from '@/app/AuthGate'
import { Alert } from '@/components/ui/feedback'
import { PasswordForm } from './PasswordForm'

/**
 * İlk girişte (ya da şifre sıfırlandıktan sonra) tam ekran "Şifrenizi belirleyin" adımı.
 * Sunucu da aynı kuralı uygular: şifre belirlenmeden portal uçları 403 döner.
 */
export default function PasswordSetup() {
  const me = useAuth((s) => s.me)
  const load = useAuth((s) => s.load)
  const logout = useAuth((s) => s.logout)
  const qc = useQueryClient()
  const navigate = useNavigate()
  const isGuardian = me?.user.user_type === 'guardian'

  const signOut = async () => {
    await logout()
    qc.clear()
    navigate('/giris', { replace: true })
  }

  return (
    <div className="grid min-h-dvh place-items-center bg-bg px-4 py-8">
      <div className="w-full max-w-[400px] animate-slide-up">
        <div className="mb-6 flex items-center gap-3">
          <BrandMark size={36} />
          <span className="min-w-0 leading-tight">
            <span className="block truncate text-[15px] font-semibold">{me?.institution.short_name || me?.institution.name || 'Erbaa Bilgi Eğitim'}</span>
            <span className="block text-[12px] text-ink-3">{isGuardian ? 'Veli portalı' : 'Öğrenci portalı'}</span>
          </span>
        </div>

        <div className="rounded-[var(--radius-xl)] bg-surface p-5 ring-1 ring-line sm:p-6">
          <div className="mb-4 flex items-start gap-3">
            <span className="grid size-10 shrink-0 place-items-center rounded-full bg-primary-soft text-primary"><KeyRound className="size-5" /></span>
            <div className="min-w-0">
              <h1 className="text-[20px] font-semibold tracking-[-0.02em]">Şifrenizi belirleyin</h1>
              <p className="mt-0.5 text-[13px] text-ink-2">
                Hoş geldiniz{me?.user.name ? `, ${me.user.name.split(' ')[0]}` : ''}. Güvenliğiniz için kurumun verdiği şifreyi kendi belirleyeceğiniz bir şifreyle değiştirmeniz gerekiyor.
              </p>
            </div>
          </div>
          <Alert tone="info" className="mb-4">Kullanıcı adınız: <b className="tabular">{me?.user.username}</b></Alert>
          <PasswordForm currentLabel="Kurumun verdiği şifre" submitLabel="Şifremi kaydet ve devam et" autoFocus fullWidth onDone={() => { qc.clear(); void load() }} />
        </div>

        <button onClick={signOut} className="mx-auto mt-5 flex items-center gap-1.5 text-[13px] text-ink-3 hover:text-ink">
          <LogOut className="size-4" /> Çıkış yap
        </button>
      </div>
    </div>
  )
}
