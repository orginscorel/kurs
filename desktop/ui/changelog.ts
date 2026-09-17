/**
 * Masaüstü değişiklik günlüğü (CHANGELOG.json) web'deki resources/changelog.json ile aynı biçimdedir.
 * Güncelleme bildirimindeki `notes` alanı (latest.json) bu kayıtların JSON dizisidir; eski/elle yazılmış
 * sürümlerde düz metin olabilir — o zaman boş dizi döner ve metin olduğu gibi gösterilir.
 */
export type ChangeType = 'yeni' | 'iyilestirme' | 'duzeltme'
export type ChangelogEntry = { version: string; date?: string; title: string; items: { type: ChangeType; text: string }[] }

export function compareVersions(a: string, b: string): number {
  const pa = a.split(/[.-]/).map((x) => Number.parseInt(x, 10) || 0)
  const pb = b.split(/[.-]/).map((x) => Number.parseInt(x, 10) || 0)
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] ?? 0) - (pb[i] ?? 0)
    if (d !== 0) return d
  }
  return 0
}

export function parseNotes(notes: string | null, currentVersion: string): ChangelogEntry[] {
  if (!notes) return []
  try {
    const data = JSON.parse(notes) as unknown
    if (!Array.isArray(data)) return []
    return (data as ChangelogEntry[])
      .filter((e) => e && typeof e.version === 'string' && Array.isArray(e.items))
      .filter((e) => compareVersions(e.version, currentVersion) > 0)
      .map((e) => ({ ...e, items: e.items.filter((i) => ['yeni', 'iyilestirme', 'duzeltme'].includes(i.type)) }))
  } catch {
    return []
  }
}
