import { useState, type FormEvent } from 'react'
import { api, errorText, type AppState } from '../api'
import { Alert, Button, Field, Icon, Shell } from '../components'

/** Adresi https://… biçimine getirir; sondaki / ve yol kaldırılır. Geçersizse null. */
export function normalizeServerUrl(raw: string): string | null {
  let v = raw.trim()
  if (!v) return null
  if (!/^https?:\/\//i.test(v)) v = `https://${v}`
  try {
    const u = new URL(v)
    if (u.protocol !== 'https:' && !['localhost', '127.0.0.1'].includes(u.hostname)) return null
    return `${u.protocol}//${u.host}`
  } catch {
    return null
  }
}

export function ServerScreen({ state, mode, onBack, onLocalNext }: {
  state: AppState
  mode: 'local' | 'remote'
  onBack: () => void
  onLocalNext: (serverUrl: string) => void
}) {
  const [url, setUrl] = useState(state.server_url ?? state.default_server)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldError, setFieldError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    const normalized = normalizeServerUrl(url)
    if (!normalized) {
      setFieldError('Geçerli bir adres yazın (ör. https://kurs.ornek.com). Güvenli bağlantı (https) zorunludur.')
      return
    }
    setFieldError(null)
    setBusy(true)
    try {
      const info = await api.checkServer(normalized)
      if (!info.ok) {
        setError(info.message)
        return
      }
      if (mode === 'remote') {
        await api.chooseRemote(normalized) // Rust pencereyi sunucu adresine yönlendirir
      } else {
        onLocalNext(normalized)
      }
    } catch (err) {
      setError(errorText(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Shell active="server" steps={mode === 'remote' ? ['mode', 'server'] : undefined} version={state.version}>
      <div>
        <h1>Kurum sunucusu</h1>
        <p className="lead">
          {mode === 'remote'
            ? 'Uygulama bu adresteki kurum sistemini açar. Giriş bilgileriniz web ile aynıdır.'
            : 'Yerel kurulum verisini bu sunucudan alır ve değişiklikleri buraya gönderir.'}
        </p>
      </div>

      <form className="panel" onSubmit={submit} noValidate>
        <Field label="Sunucu adresi" htmlFor="server-url" error={fieldError}
          hint="Kurumunuzun web adresi. Emin değilseniz yöneticinize sorun.">
          <input id="server-url" className="input" value={url} inputMode="url" autoComplete="url" spellCheck={false}
            aria-invalid={fieldError ? true : undefined} onChange={(e) => setUrl(e.target.value)} autoFocus />
        </Field>
        {error && <Alert tone="danger" title="Sunucuya bağlanılamadı">{error}</Alert>}
        <div className="actions between">
          <Button variant="ghost" icon={<Icon name="arrowLeft" size={16} />} onClick={onBack}>Geri</Button>
          <Button variant="primary" type="submit" loading={busy}>
            {mode === 'remote' ? 'Bağlan ve aç' : 'Devam'}
          </Button>
        </div>
      </form>
    </Shell>
  )
}
