import { useEffect, useState } from 'react'
import { api, errorText, listen, type UpdateInfo, type UpdateProgress } from './api'
import { Alert, Button, Icon, ProgressBar, Spinner } from './components'
import { parseNotes, type ChangelogEntry } from './changelog'

const LABEL = { yeni: 'Yeni', iyilestirme: 'İyileştirme', duzeltme: 'Düzeltme' } as const

const dateText = (iso: string) => {
  try {
    return new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso))
  } catch {
    return iso
  }
}

function ChangelogList({ entries }: { entries: ChangelogEntry[] }) {
  return (
    <ol className="cl">
      {entries.map((e) => (
        <li key={e.version}>
          <div className="head">
            <span className="ver">v{e.version}</span>
            <span className="t">{e.title}</span>
            {e.date && <span className="d">{dateText(e.date)}</span>}
          </div>
          <ul>
            {e.items.map((it, i) => (
              <li key={i}>
                <span className={`badge ${it.type}`}>{LABEL[it.type]}</span>
                <span>{it.text}</span>
              </li>
            ))}
          </ul>
        </li>
      ))}
    </ol>
  )
}

/**
 * "Yeni sürüm yayında" penceresi — web'deki UpdateNotifier ile aynı dil ve düğmeler.
 * Rust güncellemeyi bulunca bu pencereyi açar; bilgi `update_info` ile okunur.
 */
export function UpdateApp() {
  const [info, setInfo] = useState<UpdateInfo | null>(null)
  const [loaded, setLoaded] = useState(false)
  const [progress, setProgress] = useState<UpdateProgress | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let off: (() => void) | undefined
    void listen<UpdateProgress>('update://progress', setProgress).then((u) => { off = u })
    void api.updateInfo().then((i) => { setInfo(i); setLoaded(true) }).catch((e) => { setError(errorText(e)); setLoaded(true) })
    return () => off?.()
  }, [])

  const install = async () => {
    setBusy(true)
    setError(null)
    try {
      await api.updateInstall() // başarılıysa uygulama yeniden başlar
    } catch (e) {
      setError(errorText(e))
      setProgress(null)
      setBusy(false)
    }
  }

  if (!loaded) return <div className="center-screen"><Spinner size={24} /></div>

  if (!info) {
    return (
      <div className="update">
        <header data-tauri-drag-region><h1><Icon name="check" size={20} /> Uygulama güncel</h1></header>
        <div className="body">{error ? <Alert tone="danger">{error}</Alert> : <p>Kullandığınız sürüm en yenisi.</p>}</div>
        <footer><Button variant="primary" onClick={() => void api.updateLater()}>Tamam</Button></footer>
      </div>
    )
  }

  const entries = parseNotes(info.notes, info.current_version)
  const pct = progress?.total ? (progress.downloaded / progress.total) * 100 : null

  return (
    <div className="update">
      <header data-tauri-drag-region>
        <h1><Icon name="sparkles" size={20} /> Yeni sürüm yayında</h1>
        <p>v{info.current_version} → v{info.version}{info.date ? ` · ${dateText(info.date)}` : ''}</p>
      </header>
      <div className="body">
        <Alert tone="info">Güncelleme uygulamayı yeniden başlatır. Kaydetmediğiniz bir form varsa önce kaydedin. Yerel kurulumda bekleyen değişiklikler korunur.</Alert>
        {entries.length > 0 ? (
          <ChangelogList entries={entries} />
        ) : info.notes ? (
          <p style={{ whiteSpace: 'pre-line', color: 'var(--ink-2)' }}>{info.notes}</p>
        ) : (
          <p style={{ color: 'var(--ink-2)' }}>Bu güncellemede performans iyileştirmeleri ve küçük düzeltmeler var.</p>
        )}
        {error && <Alert tone="danger" title="Güncelleme kurulamadı">{error}</Alert>}
      </div>
      <footer>
        {progress && (
          <div style={{ flex: 1, minWidth: 0 }}>
            <div className="muted">
              {progress.phase === 'downloading' ? `İndiriliyor${pct != null ? ` · %${Math.round(pct)}` : '…'}` : progress.phase === 'installing' ? 'Kuruluyor…' : 'Yeniden başlatılıyor…'}
            </div>
            <ProgressBar value={pct ?? (progress.phase === 'downloading' ? 5 : 100)} />
          </div>
        )}
        <Button variant="ghost" disabled={busy} onClick={() => void api.updateLater()}>Daha sonra</Button>
        <Button variant="primary" icon={<Icon name="refresh" size={16} />} loading={busy} onClick={() => void install()}>Şimdi güncelle</Button>
      </footer>
    </div>
  )
}
