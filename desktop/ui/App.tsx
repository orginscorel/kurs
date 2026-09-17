import { useCallback, useEffect, useState } from 'react'
import { api, errorText, type AppState, type ErrorInfo } from './api'
import { Spinner } from './components'
import { ModeScreen } from './screens/ModeScreen'
import { ServerScreen } from './screens/ServerScreen'
import { AccountScreen } from './screens/AccountScreen'
import { InstallScreen } from './screens/InstallScreen'
import { StartingScreen } from './screens/StartingScreen'
import { ErrorScreen } from './screens/ErrorScreen'
import type { SetupInput } from './api'

type View =
  | { name: 'loading' }
  | { name: 'mode' }
  | { name: 'server'; mode: 'local' | 'remote' }
  | { name: 'account'; serverUrl: string }
  | { name: 'install'; input: SetupInput | null }
  | { name: 'starting' }
  | { name: 'error'; error: ErrorInfo }

/**
 * Kurulum akışı:
 *   kip seçimi → (B) sunucu adresi → Rust pencereyi sunucuya yönlendirir
 *              → (A) sunucu adresi → eşleştirme kodu + personel girişi → kurulum ilerlemesi → başlatma
 * Kurulmuş yerel kipte doğrudan "Başlatılıyor"; Rust bir hata bildirdiyse (?screen=error) hata ekranı.
 */
export function App() {
  const [state, setState] = useState<AppState | null>(null)
  const [view, setView] = useState<View>({ name: 'loading' })

  const load = useCallback(async () => {
    try {
      const s = await api.appState()
      setState(s)
      const screen = new URLSearchParams(window.location.search).get('screen')
      if (screen === 'error' && s.last_error) return setView({ name: 'error', error: s.last_error })
      if (screen === 'setup') return setView({ name: 'mode' })
      if (s.mode === 'local') {
        if (s.setup.paired && s.setup.snapshot_done) return setView({ name: 'starting' })
        if (s.setup.paired) return setView({ name: 'install', input: null }) // yarım kalan anlık görüntü
        return setView({ name: 'server', mode: 'local' })
      }
      setView({ name: 'mode' })
    } catch (e) {
      setView({ name: 'error', error: { code: 'state', title: 'Uygulama durumu okunamadı', message: errorText(e) } })
    }
  }, [])

  useEffect(() => { void load() }, [load])

  if (!state || view.name === 'loading') {
    return <div className="center-screen"><Spinner size={28} /></div>
  }

  switch (view.name) {
    case 'mode':
      return (
        <ModeScreen state={state}
          onNext={(mode) => setView({ name: 'server', mode })}
          onBackToApp={() => {
            if (state.mode === 'remote' && state.server_url) void api.chooseRemote(state.server_url).catch((e) => setView({ name: 'error', error: { code: 'server_unreachable', title: 'Kurum sunucusuna ulaşılamıyor', message: errorText(e) } }))
            else if (state.mode === 'local' && state.setup.snapshot_done) setView({ name: 'starting' })
            else if (state.mode === 'local' && state.setup.paired) setView({ name: 'install', input: null })
          }}
          onReset={() => { window.history.replaceState(null, '', window.location.pathname); void load() }} />
      )
    case 'server':
      return (
        <ServerScreen state={state} mode={view.mode}
          onBack={() => setView({ name: 'mode' })}
          onLocalNext={(serverUrl) => setView({ name: 'account', serverUrl })} />
      )
    case 'account':
      return (
        <AccountScreen state={state} serverUrl={view.serverUrl}
          onBack={() => setView({ name: 'server', mode: 'local' })}
          onSubmit={(input) => setView({ name: 'install', input })} />
      )
    case 'install':
      return (
        <InstallScreen state={state} input={view.input}
          onBack={() => setView(view.input ? { name: 'account', serverUrl: view.input.server_url } : { name: 'mode' })} />
      )
    case 'starting':
      return <StartingScreen state={state} onSetup={() => setView({ name: 'mode' })} />
    case 'error':
      return <ErrorScreen state={state} error={view.error} onRetry={() => { window.history.replaceState(null, '', window.location.pathname); void load() }} />
  }
}
