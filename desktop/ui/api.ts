/**
 * Rust (src-tauri/src/commands.rs) ile sözleşme. Komut ve olay adları orada birebir aynıdır.
 * Tauri dışında (tarayıcıda `npm run dev`) çalışırken sahte yanıtlar döner; ekran tasarımı böyle denenir.
 */
import { invoke as tauriInvoke } from '@tauri-apps/api/core'
import { listen as tauriListen, type UnlistenFn } from '@tauri-apps/api/event'

export type Mode = 'unset' | 'local' | 'remote'

export type AppState = {
  version: string
  mode: Mode
  server_url: string | null
  default_server: string
  device_name: string
  platform: string
  arch: string
  /** Paket içinde yerel çalışma zamanı (PHP + Laravel) var mı */
  runtime_available: boolean
  setup: { env_ready: boolean; paired: boolean; snapshot_done: boolean }
  last_error: ErrorInfo | null
}

export type ErrorInfo = { code: string; title: string; message: string; detail?: string | null }

export type ServerInfo = { ok: boolean; node?: string | null; protocol?: number | null; message: string }

export type SetupInput = { server_url: string; code: string; login: string; password: string; device_name: string }

export type SetupStep = 'prepare' | 'migrate' | 'pair' | 'snapshot' | 'start'
export type SetupProgress = {
  step: SetupStep
  status: 'running' | 'done' | 'error'
  percent?: number | null
  detail?: string | null
}

export type RuntimeStatus = { phase: 'starting' | 'migrating' | 'ready' | 'restarting' | 'error'; message: string }

export type UpdateInfo = { version: string; current_version: string; date: string | null; notes: string | null }
export type UpdateProgress = { downloaded: number; total: number | null; phase: 'downloading' | 'installing' | 'restarting' }

export type ResetResult = { done: boolean; pending: number; message: string }

export const inTauri = typeof window !== 'undefined' && '__TAURI_INTERNALS__' in window

// ---------------------------------------------------------------- sahte arka uç (yalnız tarayıcı önizlemesi)
const params = new URLSearchParams(window.location.search)
const mockHandlers = new Map<string, Set<(p: unknown) => void>>()
const mockEmit = (event: string, payload: unknown) => mockHandlers.get(event)?.forEach((h) => h(payload))
const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms))

async function mockInvoke<T>(cmd: string, args?: Record<string, unknown>): Promise<T> {
  await sleep(250)
  switch (cmd) {
    case 'app_state':
      return {
        version: '0.1.0', mode: (params.get('mode') as Mode) ?? 'unset', server_url: null,
        default_server: 'https://kurs.bogahostdeveloper.com.tr', device_name: 'Ofis MacBook', platform: 'macos', arch: 'aarch64',
        runtime_available: true, setup: { env_ready: false, paired: false, snapshot_done: false },
        last_error: params.get('screen') === 'error'
          ? { code: 'server_unreachable', title: 'Sunucuya ulaşılamıyor', message: 'İnternet bağlantınızı denetleyip yeniden deneyin.', detail: 'connect timeout (10 sn)' }
          : null,
      } as T
    case 'check_server':
      return { ok: true, node: 'server', protocol: 1, message: 'Sunucu yanıt verdi.' } as T
    case 'setup_local': {
      const steps: SetupStep[] = ['prepare', 'migrate', 'pair', 'snapshot', 'start']
      for (const s of steps) {
        mockEmit('setup://progress', { step: s, status: 'running', percent: s === 'snapshot' ? 0 : null })
        if (s === 'snapshot') {
          for (let p = 5; p <= 100; p += 5) { await sleep(120); mockEmit('setup://progress', { step: s, status: 'running', percent: p, detail: `students · ${p * 12} satır` }) }
        } else await sleep(600)
        mockEmit('setup://progress', { step: s, status: 'done', percent: 100 })
      }
      return undefined as T
    }
    case 'update_info':
      return {
        version: '0.2.0', current_version: '0.1.0', date: '2026-09-20T10:00:00Z',
        notes: JSON.stringify([{ version: '0.2.0', date: '2026-09-20', title: 'Çevrimdışı iyileştirmeler', items: [
          { type: 'yeni', text: 'Tepsi menüsünden "Şimdi eşitle"' },
          { type: 'iyilestirme', text: 'İlk kurulum %30 daha hızlı' },
          { type: 'duzeltme', text: 'Uykudan dönünce yerel sunucu yeniden başlatılıyor' },
        ] }]),
      } as T
    case 'update_install':
      for (let d = 0; d <= 100; d += 10) { await sleep(150); mockEmit('update://progress', { downloaded: d * 1_000_000, total: 100_000_000, phase: 'downloading' }) }
      mockEmit('update://progress', { downloaded: 1, total: 1, phase: 'installing' })
      return undefined as T
    case 'reset_setup':
      return { done: !args?.force, pending: 3, message: 'Gönderilmemiş 3 değişiklik var.' } as T
    default:
      return undefined as T
  }
}

export function invoke<T>(cmd: string, args?: Record<string, unknown>): Promise<T> {
  return inTauri ? tauriInvoke<T>(cmd, args) : mockInvoke<T>(cmd, args)
}

export async function listen<T>(event: string, handler: (payload: T) => void): Promise<UnlistenFn> {
  if (inTauri) return tauriListen<T>(event, (e) => handler(e.payload))
  const set = mockHandlers.get(event) ?? new Set()
  const h = (p: unknown) => handler(p as T)
  set.add(h)
  mockHandlers.set(event, set)
  return () => set.delete(h)
}

/** Rust tarafı hata mesajını düz metin döner; beklenmeyen biçimleri de metne çevir. */
export function errorText(e: unknown): string {
  if (typeof e === 'string') return e
  if (e && typeof e === 'object' && 'message' in e) return String((e as { message: unknown }).message)
  return 'Beklenmeyen bir hata oluştu.'
}

export const api = {
  appState: () => invoke<AppState>('app_state'),
  checkServer: (serverUrl: string) => invoke<ServerInfo>('check_server', { serverUrl }),
  chooseRemote: (serverUrl: string) => invoke<void>('choose_remote', { serverUrl }),
  setupLocal: (input: SetupInput) => invoke<void>('setup_local', { input }),
  resumeSetup: () => invoke<void>('resume_setup'),
  startLocal: () => invoke<void>('start_local'),
  resetSetup: (force: boolean) => invoke<ResetResult>('reset_setup', { force }),
  openLogs: () => invoke<void>('open_logs'),
  quit: () => invoke<void>('quit_app'),
  updateInfo: () => invoke<UpdateInfo | null>('update_info'),
  updateInstall: () => invoke<void>('update_install'),
  updateLater: () => invoke<void>('update_later'),
}
