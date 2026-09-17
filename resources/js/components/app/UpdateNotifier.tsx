import { useCallback, useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { RefreshCw, Sparkles } from 'lucide-react'
import { Modal } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/feedback'
import { ChangelogList } from './ChangelogList'
import { APP_BUILD, APP_VERSION, compareVersions, fetchChangelog, fetchVersion, type ChangelogEntry, type VersionInfo } from './version'

const POLL_MS = 60_000
const SNOOZE_MS = 15 * 60_000
const SEEN_KEY = 'ebe-app:seen-version'
/** Herhangi bir yerden "Yenilikler" penceresini açmak için: window.dispatchEvent(new Event(SHOW_CHANGELOG_EVENT)) */
export const SHOW_CHANGELOG_EVENT = 'ebe:show-changelog'
const SNOOZE_KEY = 'ebe-app:update-snooze'

const store = {
  get: (k: string) => { try { return localStorage.getItem(k) } catch { return null } },
  set: (k: string, v: string) => { try { localStorage.setItem(k, v) } catch { /* yok say */ } },
}

/**
 * Yayında yeni derleme varsa açık ekranda sürüm ve değişiklikleri gösteren pencere açar.
 * Yeni sürüme geçildikten sonra bir kez "Sürüm X yüklendi" bildirimi verir.
 */
export function UpdateNotifier() {
  const [remote, setRemote] = useState<VersionInfo | null>(null)
  const [entries, setEntries] = useState<ChangelogEntry[]>([])
  const [open, setOpen] = useState(false)
  const shownFor = useRef<string | null>(null)

  // Güncellemeden sonra: bir kez bilgi ver
  useEffect(() => {
    const seen = store.get(SEEN_KEY)
    if (seen && seen !== APP_VERSION && compareVersions(APP_VERSION, seen) > 0) {
      toast.success(`Sistem güncellendi: v${APP_VERSION}`, {
        description: 'Bu sürümdeki değişiklikleri görebilirsiniz.',
        action: { label: 'Yenilikler', onClick: () => window.dispatchEvent(new Event(SHOW_CHANGELOG_EVENT)) },
        duration: 10_000,
      })
    }
    store.set(SEEN_KEY, APP_VERSION)
  }, [])

  const check = useCallback(async () => {
    if (document.hidden) return
    const v = await fetchVersion()
    if (!v || v.build === APP_BUILD) return
    if (shownFor.current === v.build) return
    const snooze = Number(store.get(SNOOZE_KEY) ?? 0)
    if (snooze > Date.now()) return
    const log = await fetchChangelog().catch(() => [])
    const newer = log.filter((e) => compareVersions(e.version, APP_VERSION) > 0)
    setRemote(v)
    setEntries(newer)
    shownFor.current = v.build
    setOpen(true)
  }, [])

  useEffect(() => {
    const first = setTimeout(check, 8_000)
    const t = setInterval(check, POLL_MS)
    const onVisible = () => { if (!document.hidden) void check() }
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('focus', onVisible)
    return () => {
      clearTimeout(first)
      clearInterval(t)
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener('focus', onVisible)
    }
  }, [check])

  // "Yenilikler" penceresi (güncelleme olmadan, mevcut sürümün notları)
  const [logOpen, setLogOpen] = useState(false)
  const [log, setLog] = useState<ChangelogEntry[]>([])
  useEffect(() => {
    const show = () => { void fetchChangelog().then((l) => { setLog(l.slice(0, 5)); setLogOpen(true) }).catch(() => undefined) }
    window.addEventListener(SHOW_CHANGELOG_EVENT, show)
    return () => window.removeEventListener(SHOW_CHANGELOG_EVENT, show)
  }, [])

  const later = () => {
    store.set(SNOOZE_KEY, String(Date.now() + SNOOZE_MS))
    setOpen(false)
    toast.info('Güncelleme 15 dakika sonra tekrar hatırlatılacak.')
  }

  if (logOpen && !open) {
    return (
      <Modal open size="lg" onClose={() => setLogOpen(false)} title="Yenilikler" description={`Kullandığınız sürüm: v${APP_VERSION}`}
        footer={<div className="flex justify-end"><Button variant="primary" onClick={() => setLogOpen(false)}>Tamam</Button></div>}>
        <div className="max-h-[60vh] overflow-y-auto scroll-thin pr-1"><ChangelogList entries={log} compact /></div>
      </Modal>
    )
  }
  if (!open || !remote) return null
  const sameVersion = remote.version === APP_VERSION

  return (
    <Modal
      open
      size="lg"
      onClose={later}
      title={<span className="inline-flex items-center gap-2"><Sparkles className="size-5 text-primary" /> Yeni sürüm yayında</span>}
      description={sameVersion ? `v${remote.version} için iyileştirmeler ve düzeltmeler yüklendi.` : `v${APP_VERSION} → v${remote.version}`}
      footer={
        <div className="flex flex-wrap items-center justify-end gap-2">
          <Button variant="ghost" onClick={later}>Daha sonra</Button>
          <Button variant="primary" icon={<RefreshCw className="size-4" />} onClick={() => window.location.reload()}>Şimdi güncelle</Button>
        </div>
      }
    >
      <div className="flex flex-col gap-4">
        <Alert tone="info">Güncelleme sayfayı yeniler. Kaydetmediğiniz bir form varsa önce kaydedin.</Alert>
        {entries.length > 0 ? (
          <div className="max-h-[50vh] overflow-y-auto scroll-thin pr-1"><ChangelogList entries={entries} compact /></div>
        ) : (
          <p className="text-[14px] text-ink-2">Bu güncellemede performans iyileştirmeleri ve küçük düzeltmeler var.</p>
        )}
      </div>
    </Modal>
  )
}
