import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { Eye, EyeOff, Lock, User } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useAuth, type Me } from '@/app/auth'
import { BrandMark } from '@/app/AuthGate'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'

export default function Login() {
  const [login, setLogin] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)
  const [show, setShow] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const setMe = useAuth((s) => s.setMe)
  const status = useAuth((s) => s.status)
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const next = params.get('next')
  const safeNext = next && next.startsWith('/') && !next.startsWith('//') ? next : '/'

  if (status === 'authenticated') return <Navigate to={safeNext} replace />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
      const me = await api.post<Me>('/auth/login', { login: login.trim(), password, remember })
      setMe(me)
      const isPortal = me.user.user_type === 'student' || me.user.user_type === 'guardian'
      // Portal hesabı: şifre belirleme adımı portal kabuğunda gösterilir
      navigate(isPortal ? (safeNext.startsWith('/portal') ? safeNext : '/portal') : me.user.must_change_password ? '/hesabim?parola=1' : safeNext, { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err.firstError() : 'Giriş yapılamadı. Lütfen tekrar deneyin.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="grid min-h-dvh lg:grid-cols-[1fr_minmax(480px,560px)] bg-bg">
      {/* Marka alanı */}
      <div className="relative hidden lg:flex flex-col justify-between overflow-hidden bg-[#141230] p-12 text-white">
        <div
          aria-hidden
          className="absolute inset-0 opacity-[0.18]"
          style={{
            backgroundImage: 'radial-gradient(circle at 1px 1px, rgba(255,255,255,.55) 1px, transparent 0)',
            backgroundSize: '26px 26px',
            maskImage: 'linear-gradient(180deg, transparent, black 30%, black 70%, transparent)',
          }}
        />
        <div className="relative flex items-center gap-3">
          <BrandMark size={36} />
          <span className="text-[15px] font-semibold tracking-[-0.01em]">Erbaa Bilgi Eğitim</span>
        </div>
        <div className="relative max-w-lg">
          <h1 className="text-[40px] font-semibold leading-[1.1] tracking-[-0.03em]">
            Kurumun tüm operasyonu,
            <br />
            <span className="text-[#aca5ff]">tek merkezden.</span>
          </h1>
          <p className="mt-5 text-[15px] leading-relaxed text-white/65">
            Öğrenci, veli, yoklama, deneme sınavları, ders programı ve finans. Her şey birbirine bağlı, anlık ve güvenli.
          </p>
          <div className="mt-10 grid grid-cols-3 gap-6 border-t border-white/10 pt-8">
            {[
              ['Canlı', 'giriş–çıkış takibi'],
              ['Otomatik', 'veli bilgilendirme'],
              ['Kazanım', 'bazlı sınav analizi'],
            ].map(([a, b]) => (
              <div key={a}>
                <p className="text-[18px] font-semibold">{a}</p>
                <p className="text-[12.5px] text-white/55">{b}</p>
              </div>
            ))}
          </div>
        </div>
        <p className="relative text-[12px] text-white/40">© {new Date().getFullYear()} Erbaa Bilgi Eğitim · Kişisel veriler KVKK kapsamında korunur.</p>
      </div>

      {/* Form */}
      <div className="flex items-center justify-center px-5 py-10">
        <div className="w-full max-w-[380px] animate-slide-up">
          <div className="mb-8 flex items-center gap-3 lg:hidden">
            <BrandMark size={36} />
            <span className="text-[15px] font-semibold">Erbaa Bilgi Eğitim</span>
          </div>
          <h2 className="text-[24px] font-semibold tracking-[-0.02em]">Hoş geldiniz</h2>
          <p className="mt-1 text-[14px] text-ink-2">Devam etmek için hesabınıza giriş yapın.</p>

          <form onSubmit={submit} className="mt-8 flex flex-col gap-4">
            {error && <Alert tone="danger">{error}</Alert>}
            <Field label="Kullanıcı adı / öğrenci no / veli cep telefonu" htmlFor="login">
              <Input
                id="login"
                autoFocus
                autoComplete="username"
                value={login}
                onChange={(e) => setLogin(e.target.value)}
                leading={<User />}
                className="[&_input]:h-11"
                required
              />
            </Field>
            <Field label="Parola" htmlFor="password">
              <Input
                id="password"
                type={show ? 'text' : 'password'}
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                leading={<Lock />}
                className="[&_input]:h-11"
                trailing={
                  <button type="button" onClick={() => setShow((v) => !v)} className="grid size-7 place-items-center rounded text-ink-3 hover:text-ink" aria-label={show ? 'Parolayı gizle' : 'Parolayı göster'}>
                    {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                  </button>
                }
                required
              />
            </Field>
            <Checkbox checked={remember} onChange={setRemember} label="Bu cihazda oturumumu açık tut" />
            <Button type="submit" variant="primary" size="lg" loading={loading} className="mt-2 w-full">
              Giriş yap
            </Button>
          </form>
          <p className="mt-8 text-center text-[12.5px] text-ink-3">Parolanızı unuttuysanız kurum yöneticinizle iletişime geçin.</p>
        </div>
      </div>
    </div>
  )
}
