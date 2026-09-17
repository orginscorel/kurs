import { useState } from 'react'
import { api, errorText, type AppState, type ErrorInfo } from '../api'
import { Alert, Button, Icon } from '../components'

const ICONS: Record<string, 'wifiOff' | 'alert'> = { server_unreachable: 'wifiOff', offline: 'wifiOff' }

/**
 * Rust'un yönlendirdiği hata ekranı (index.html?screen=error). Örnekler:
 * çevrimiçi kipte sunucuya ulaşılamaması, yerel sunucunun art arda çökmesi, anahtar zinciri erişim reddi.
 */
export function ErrorScreen({ state, error, onRetry }: { state: AppState; error: ErrorInfo; onRetry: () => void }) {
  const [resetOpen, setResetOpen] = useState(false)
  const [resetMsg, setResetMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const retry = async () => {
    setBusy(true)
    try {
      if (state.mode === 'remote' && state.server_url) await api.chooseRemote(state.server_url)
      else onRetry()
    } catch (e) {
      setResetMsg(errorText(e))
    } finally {
      setBusy(false)
    }
  }

  const reset = async (force: boolean) => {
    setBusy(true)
    try {
      const r = await api.resetSetup(force)
      if (r.done) { window.location.search = '?screen=setup'; return }
      setResetMsg(r.message)
    } catch (e) {
      setResetMsg(errorText(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="center-screen">
      <div className="center-card">
        <div className="big-icon danger-bg"><Icon name={ICONS[error.code] ?? 'alert'} size={26} /></div>
        <h1>{error.title}</h1>
        <p>{error.message}</p>
        {error.detail && <div className="detail">{error.detail}</div>}

        <div className="actions" style={{ justifyContent: 'center' }}>
          <Button variant="ghost" icon={<Icon name="folder" size={16} />} onClick={() => void api.openLogs()}>Günlükler</Button>
          <Button variant="secondary" onClick={() => setResetOpen((v) => !v)}>Kurulum seçenekleri</Button>
          <Button variant="primary" icon={<Icon name="refresh" size={16} />} loading={busy} onClick={() => void retry()}>Yeniden dene</Button>
        </div>

        {resetOpen && (
          <div className="panel" style={{ textAlign: 'left', width: '100%' }}>
            <strong>Kullanım biçimini değiştir / kurulumu sıfırla</strong>
            <p className="muted">
              {state.mode === 'local'
                ? 'Yerel veritabanı, cihaz eşleşmesi ve anahtarlar bu bilgisayardan silinir. Web sunucusundaki veriye dokunulmaz. Gönderilmemiş değişiklik varsa önce uyarılırsınız.'
                : 'Kayıtlı sunucu adresi silinir ve kurulum ekranı açılır.'}
            </p>
            {resetMsg && <Alert tone="warning">{resetMsg}</Alert>}
            <div className="actions">
              {resetMsg && state.mode === 'local' && (
                <Button variant="danger" loading={busy} onClick={() => void reset(true)}>Yine de sıfırla</Button>
              )}
              <Button variant="secondary" loading={busy} onClick={() => void reset(false)}>Sıfırla</Button>
            </div>
          </div>
        )}
        <p className="muted">Sürüm {state.version}</p>
      </div>
    </div>
  )
}
