import { useEffect, useRef, useState } from 'react'
import { api, errorText, listen, type AppState, type RuntimeStatus } from '../api'
import { Alert, Button, Icon, Spinner } from '../components'

/** Kurulu yerel kipte açılış: Rust yerel sunucuyu başlatır, hazır olunca pencereyi yönlendirir. */
export function StartingScreen({ state, onSetup }: { state: AppState; onSetup: () => void }) {
  const [status, setStatus] = useState<RuntimeStatus>({ phase: 'starting', message: 'Yerel sunucu başlatılıyor…' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const started = useRef(false)

  const start = async () => {
    setError(null)
    setBusy(true)
    try {
      await api.startLocal()
    } catch (e) {
      setError(errorText(e))
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    let off: (() => void) | undefined
    void listen<RuntimeStatus>('runtime://status', setStatus).then((u) => { off = u })
    if (!started.current) {
      started.current = true
      void start()
    }
    return () => off?.()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <div className="center-screen">
      <div className="center-card">
        <div className={`big-icon ${error ? 'danger-bg' : 'brand-bg'}`}>
          {error ? <Icon name="alert" size={26} /> : <Icon name="database" size={26} />}
        </div>
        <h1>{error ? 'Yerel sunucu açılamadı' : 'Erbaa Bilgi Eğitim açılıyor'}</h1>
        {!error && (
          <p style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}>
            <Spinner size={16} /> {status.message}
          </p>
        )}
        {error && (
          <>
            <Alert tone="danger">{error}</Alert>
            <div className="actions" style={{ justifyContent: 'center' }}>
              <Button variant="ghost" icon={<Icon name="folder" size={16} />} onClick={() => void api.openLogs()}>Günlükler</Button>
              <Button variant="secondary" onClick={onSetup}>Kullanım biçimini değiştir</Button>
              <Button variant="primary" icon={<Icon name="refresh" size={16} />} loading={busy} onClick={() => void start()}>Yeniden dene</Button>
            </div>
          </>
        )}
        <p className="muted">Sürüm {state.version}</p>
      </div>
    </div>
  )
}
