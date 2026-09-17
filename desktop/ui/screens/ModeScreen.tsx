import { useState } from 'react'
import { api, errorText, type AppState } from '../api'
import { Alert, Button, Icon, Shell } from '../components'

/** Menüden "Kullanım biçimi ve kurulum" ile gelindiğinde mevcut kurulumun özeti: geri dön ya da sıfırla. */
function CurrentSetup({ state, onBackToApp, onReset }: { state: AppState; onBackToApp: () => void; onReset: () => void }) {
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [needsForce, setNeedsForce] = useState(false)
  const reset = async (force: boolean) => {
    setBusy(true)
    setMsg(null)
    try {
      const r = await api.resetSetup(force)
      if (r.done) onReset()
      else { setMsg(r.message); setNeedsForce(r.pending > 0) }
    } catch (e) {
      setMsg(errorText(e))
    } finally {
      setBusy(false)
    }
  }
  const local = state.mode === 'local'
  return (
    <div className="panel">
      <strong>Şu anki kullanım: {local ? 'Kurum bilgisayarı (yerel kurulum)' : 'Çevrimiçi kullanım'}</strong>
      <p className="muted">
        {local
          ? 'Kullanım biçimini değiştirmek için önce yerel kurulumu sıfırlayın. Yerel veritabanı ve anahtarlar bu bilgisayardan silinir; web sunucusundaki veriye dokunulmaz.'
          : `Sunucu: ${state.server_url ?? '—'}. Aşağıdan başka bir kullanım biçimi seçebilirsiniz.`}
      </p>
      {msg && <Alert tone="warning">{msg}</Alert>}
      <div className="actions">
        {local && needsForce && <Button variant="danger" loading={busy} onClick={() => void reset(true)}>Yine de sıfırla</Button>}
        {local && !needsForce && <Button variant="secondary" loading={busy} onClick={() => void reset(false)}>Yerel kurulumu sıfırla</Button>}
        <Button variant="primary" onClick={onBackToApp}>Uygulamaya dön</Button>
      </div>
    </div>
  )
}

export function ModeScreen({ state, onNext, onBackToApp, onReset }: {
  state: AppState
  onNext: (mode: 'local' | 'remote') => void
  onBackToApp: () => void
  onReset: () => void
}) {
  const configured = (state.mode === 'local' && state.setup.paired) || (state.mode === 'remote' && !!state.server_url)
  const [mode, setMode] = useState<'local' | 'remote' | null>(state.mode === 'unset' || (configured && state.mode === 'local') ? null : state.mode)

  return (
    <Shell active="mode" version={state.version}>
      <div>
        <h1>Hoş geldiniz</h1>
        <p className="lead">Bu bilgisayarda uygulamayı nasıl kullanacağınızı seçin. Seçiminizi daha sonra menüden değiştirebilirsiniz.</p>
      </div>

      {configured && <CurrentSetup state={state} onBackToApp={onBackToApp} onReset={onReset} />}

      <div className="choice-grid" role="radiogroup" aria-label="Kullanım biçimi">
        <button type="button" role="radio" aria-checked={mode === 'local'} className={`choice ${mode === 'local' ? 'selected' : ''}`}
          onClick={() => setMode('local')} disabled={!state.runtime_available || (configured && state.mode === 'local')}>
          <span className="icon"><Icon name="monitor" /></span>
          <span className="body">
            <span className="title">Kurum bilgisayarı (yerel kurulum)</span>
            <span className="desc">Kurum verisi bu bilgisayarda da tutulur; internet kesilse de çalışmaya devam edersiniz.</span>
            <ul>
              <li>Kayıt, yoklama, tahsilat çevrimdışı yapılır</li>
              <li>Bağlantı gelince web ile kendiliğinden eşitlenir</li>
              <li>Kurum personeli içindir; eşleştirme kodu gerekir</li>
            </ul>
          </span>
        </button>

        <button type="button" role="radio" aria-checked={mode === 'remote'} className={`choice ${mode === 'remote' ? 'selected' : ''}`}
          onClick={() => setMode('remote')} disabled={configured && state.mode === 'local'}>
          <span className="icon"><Icon name="cloud" /></span>
          <span className="body">
            <span className="title">Çevrimiçi kullanım (öğretmen / personel)</span>
            <span className="desc">Kurum sunucusuna bağlanan uygulama penceresi. Bu bilgisayarda veri tutulmaz.</span>
            <ul>
              <li>Öğretmen portalı ve yönetim ekranları</li>
              <li>Bildirimler masaüstünde gösterilir</li>
              <li>İnternet bağlantısı gerekir</li>
            </ul>
          </span>
        </button>
      </div>

      {!state.runtime_available && (
        <Alert tone="warning" title="Yerel kurulum bu pakette yok">
          Bu sürüm yalnız çevrimiçi kullanım için hazırlanmış. Kurum bilgisayarı için tam paketi indirin.
        </Alert>
      )}

      <div className="actions">
        <Button variant="primary" disabled={!mode} onClick={() => mode && onNext(mode)}>Devam</Button>
      </div>
    </Shell>
  )
}
