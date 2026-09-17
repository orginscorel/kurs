import { useState, type FormEvent } from 'react'
import type { AppState, SetupInput } from '../api'
import { Alert, Button, Field, Icon, Shell } from '../components'

type Errors = Partial<Record<'code' | 'login' | 'password' | 'device_name', string>>

export function AccountScreen({ state, serverUrl, onBack, onSubmit }: {
  state: AppState
  serverUrl: string
  onBack: () => void
  onSubmit: (input: SetupInput) => void
}) {
  const [code, setCode] = useState('')
  const [login, setLogin] = useState('')
  const [password, setPassword] = useState('')
  const [deviceName, setDeviceName] = useState(state.device_name)
  const [errors, setErrors] = useState<Errors>({})

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const next: Errors = {}
    if (code.trim().length < 4) next.code = 'Eşleştirme kodunu yazın.'
    if (!login.trim()) next.login = 'Kullanıcı adınızı yazın.'
    if (!password) next.password = 'Parolanızı yazın.'
    if (!deviceName.trim()) next.device_name = 'Bu bilgisayara bir ad verin.'
    setErrors(next)
    if (Object.keys(next).length) return
    onSubmit({ server_url: serverUrl, code: code.trim().toUpperCase(), login: login.trim(), password, device_name: deviceName.trim() })
  }

  return (
    <Shell active="account" version={state.version}>
      <div>
        <h1>Eşleştirme ve giriş</h1>
        <p className="lead">Bu bilgisayarı kurum sistemine bağlamak için eşleştirme kodu ve personel hesabınız gerekir.</p>
      </div>

      <Alert tone="info">
        Eşleştirme kodu web panelinde <strong>Ayarlar › Bağlı cihazlar</strong> sayfasındadır. Hesabınızın
        “Masaüstü uygulamasıyla eşitleme” yetkisi olmalıdır.
      </Alert>

      <form className="panel" onSubmit={submit} noValidate>
        <Field label="Eşleştirme kodu" htmlFor="code" error={errors.code}>
          <input id="code" className="input" value={code} autoComplete="off" spellCheck={false} autoFocus
            aria-invalid={errors.code ? true : undefined} onChange={(e) => setCode(e.target.value)} />
        </Field>
        <div className="row">
          <Field label="Kullanıcı adı" htmlFor="login" error={errors.login}>
            <input id="login" className="input" value={login} autoComplete="username" spellCheck={false}
              aria-invalid={errors.login ? true : undefined} onChange={(e) => setLogin(e.target.value)} />
          </Field>
          <Field label="Parola" htmlFor="password" error={errors.password}>
            <input id="password" className="input" type="password" value={password} autoComplete="current-password"
              aria-invalid={errors.password ? true : undefined} onChange={(e) => setPassword(e.target.value)} />
          </Field>
        </div>
        <Field label="Bilgisayar adı" htmlFor="device-name" error={errors.device_name}
          hint="Bağlı cihazlar listesinde bu adla görünür (ör. Ön büro Mac).">
          <input id="device-name" className="input" value={deviceName} maxLength={60}
            aria-invalid={errors.device_name ? true : undefined} onChange={(e) => setDeviceName(e.target.value)} />
        </Field>
        <p className="muted">Parolanız bu bilgisayara kaydedilmez; yalnız eşleştirme için sunucuya gönderilir. Cihaz anahtarı macOS Anahtar Zinciri'nde saklanır.</p>
        <div className="actions between">
          <Button variant="ghost" icon={<Icon name="arrowLeft" size={16} />} onClick={onBack}>Geri</Button>
          <Button variant="primary" type="submit">Kurulumu başlat</Button>
        </div>
      </form>
    </Shell>
  )
}
