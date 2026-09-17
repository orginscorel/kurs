import { useEffect, useRef, useState } from 'react'
import { api, errorText, listen, type AppState, type SetupInput, type SetupProgress, type SetupStep } from '../api'
import { Alert, Button, Icon, ProgressBar, Shell, Spinner } from '../components'

const STEPS: { key: SetupStep; label: string; hint: string }[] = [
  { key: 'prepare', label: 'Hazırlık', hint: 'Veri klasörü, yapılandırma ve şifreleme anahtarı' },
  { key: 'migrate', label: 'Yerel veritabanı', hint: 'Tablolar oluşturuluyor' },
  { key: 'pair', label: 'Eşleştirme', hint: 'Bu bilgisayar kurum sistemine bağlanıyor' },
  { key: 'snapshot', label: 'Kurum verisinin indirilmesi', hint: 'İlk eşitleme; veri miktarına göre birkaç dakika sürebilir' },
  { key: 'start', label: 'Başlatma', hint: 'Yerel sunucu açılıyor' },
]

type StepState = { status: 'pending' | 'running' | 'done' | 'error'; percent?: number | null; detail?: string | null }

/**
 * `input` varsa baştan kurulum, yoksa yarım kalan kurulumu sürdürme (eşleşmiş, anlık görüntü eksik).
 * İlerleme Rust'tan `setup://progress` olayıyla gelir.
 */
export function InstallScreen({ state, input, onBack }: { state: AppState; input: SetupInput | null; onBack: () => void }) {
  const [steps, setSteps] = useState<Record<SetupStep, StepState>>(() => {
    const init = Object.fromEntries(STEPS.map((s) => [s.key, { status: 'pending' }])) as Record<SetupStep, StepState>
    if (!input) {
      init.prepare = { status: 'done' }
      init.migrate = { status: 'done' }
      init.pair = { status: 'done' }
    }
    return init
  })
  const [error, setError] = useState<string | null>(null)
  const [running, setRunning] = useState(false)
  const started = useRef(false)

  const run = async (resume = !input) => {
    setError(null)
    setRunning(true)
    try {
      if (!resume && input) await api.setupLocal(input)
      else await api.resumeSetup()
      // Başarılıysa Rust pencereyi yerel adrese yönlendirir; bu ekran kapanır.
    } catch (e) {
      setError(errorText(e))
      setSteps((prev) => {
        const next = { ...prev }
        for (const s of STEPS) if (next[s.key].status === 'running') next[s.key] = { ...next[s.key], status: 'error' }
        return next
      })
    } finally {
      setRunning(false)
    }
  }

  useEffect(() => {
    let off: (() => void) | undefined
    void listen<SetupProgress>('setup://progress', (p) => {
      setSteps((prev) => ({ ...prev, [p.step]: { status: p.status, percent: p.percent ?? prev[p.step].percent, detail: p.detail ?? null } }))
    }).then((u) => { off = u })
    if (!started.current) {
      started.current = true
      void run()
    }
    return () => off?.()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const pairFailed = steps.pair.status === 'error' || steps.prepare.status === 'error'

  return (
    <Shell active="install" version={state.version}>
      <div>
        <h1>{input ? 'Kurulum' : 'Kurulumu sürdür'}</h1>
        <p className="lead">Pencereyi kapatmayın. İnternet bağlantısının açık olması gerekir.</p>
      </div>

      <div className="panel">
        <ol className="progress-list">
          {STEPS.map((s) => {
            const st = steps[s.key]
            return (
              <li key={s.key} className={st.status === 'pending' ? 'pending' : ''}>
                <span className="state">
                  {st.status === 'running' && <Spinner size={18} />}
                  {st.status === 'done' && <span style={{ color: 'var(--success)' }}><Icon name="check" size={18} /></span>}
                  {st.status === 'error' && <span style={{ color: 'var(--danger)' }}><Icon name="x" size={18} /></span>}
                  {st.status === 'pending' && <span className="muted">•</span>}
                </span>
                <span style={{ flex: 1, minWidth: 0 }}>
                  <span className="name">{s.label}</span>
                  {s.key === 'snapshot' && st.status === 'running' && st.percent != null && (
                    <span className="pct muted"> · %{Math.round(st.percent)}</span>
                  )}
                  <div className="sub">{st.detail || s.hint}</div>
                  {s.key === 'snapshot' && (st.status === 'running' || (st.status === 'error' && st.percent != null)) && (
                    <ProgressBar value={st.percent ?? 0} />
                  )}
                </span>
              </li>
            )
          })}
        </ol>
      </div>

      {error && (
        <Alert tone="danger" title="Kurulum tamamlanamadı">
          {error}
        </Alert>
      )}

      <div className="actions between">
        {pairFailed || (error && input) ? (
          <Button variant="ghost" icon={<Icon name="arrowLeft" size={16} />} onClick={onBack} disabled={running}>Bilgileri düzelt</Button>
        ) : <span />}
        <div className="actions">
          <Button variant="ghost" icon={<Icon name="folder" size={16} />} onClick={() => void api.openLogs()}>Günlükler</Button>
          {error && (
            <Button variant="primary" icon={<Icon name="refresh" size={16} />} loading={running}
              onClick={() => void run(steps.pair.status === 'done')}>
              Yeniden dene
            </Button>
          )}
        </div>
      </div>
    </Shell>
  )
}
