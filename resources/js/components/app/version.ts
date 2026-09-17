import { useQuery } from '@tanstack/react-query'

export const APP_VERSION = __APP_VERSION__
export const APP_BUILD = __APP_BUILD__

export type ChangeType = 'yeni' | 'iyilestirme' | 'duzeltme'
export type ChangelogEntry = { version: string; date: string; title: string; items: { type: ChangeType; text: string }[] }
export type VersionInfo = { version: string; build: string; built_at: string }

export const CHANGE_LABEL: Record<ChangeType, string> = { yeni: 'Yeni', iyilestirme: 'İyileştirme', duzeltme: 'Düzeltme' }
export const CHANGE_TONE: Record<ChangeType, 'success' | 'info' | 'warning'> = { yeni: 'success', iyilestirme: 'info', duzeltme: 'warning' }

/** Sunucudaki (yayındaki) sürüm; önbelleğe takılmasın diye zaman damgalı istenir. */
export async function fetchVersion(): Promise<VersionInfo | null> {
  try {
    const r = await fetch(`/build/version.json?t=${Date.now()}`, { cache: 'no-store' })
    return r.ok ? ((await r.json()) as VersionInfo) : null
  } catch {
    return null
  }
}

export async function fetchChangelog(): Promise<ChangelogEntry[]> {
  const r = await fetch(`/build/changelog.json?t=${Date.now()}`, { cache: 'no-store' })
  return r.ok ? ((await r.json()) as ChangelogEntry[]) : []
}

export function useChangelog() {
  return useQuery({ queryKey: ['app', 'changelog'], queryFn: fetchChangelog, staleTime: 5 * 60_000 })
}

/** "1.10.0" > "1.9.0" */
export function compareVersions(a: string, b: string): number {
  const pa = a.split('.').map(Number)
  const pb = b.split('.').map(Number)
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] ?? 0) - (pb[i] ?? 0)
    if (d !== 0) return d
  }
  return 0
}
