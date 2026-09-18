import type { Tone } from '@/components/ui/feedback'

export type SyncDevice = {
  id: number
  uuid: string
  code: string
  name: string
  platform: string
  platform_label: string
  mode: 'desktop' | 'mobile'
  app_version: string | null
  status: 'active' | 'revoked'
  user: { id: number; name: string | null } | null
  last_seen_at: string | null
  last_push_at: string | null
  last_pull_at: string | null
  last_cursor: number
  pending_reported: number
  rejected_total: number
  behind: number
  key_issued_at: string | null
  paired_at: string | null
  revoked_at: string | null
  open_conflicts: number
  /** Cihazda şu an açık uygulama oturumları (cihazın son raporundan) */
  open_sessions?: { user_id: number; name: string | null; last_active_at: string | null; closing: boolean }[]
}

export type SyncOverview = {
  node: 'server' | 'local'
  cursor: number
  changes_24h: number
  device_changes_24h: number
  devices_active: number
  devices_online: number
  open_conflicts: number
  finance_pending: number
  last_sweep_at: string | null
  tables_prepared: number
}

export type ConflictKind = 'field' | 'attendance' | 'delete' | 'finance' | 'rejected'

export type SyncConflict = {
  id: number
  kind: ConflictKind
  kind_label: string
  table: string
  table_label: string
  row_uuid: string | null
  row_label: string | null
  field: string | null
  field_label: string | null
  device_value: string | null
  server_value: string | null
  device_at: string | null
  server_at: string | null
  winner: 'device' | 'server' | 'both' | null
  status: 'open' | 'resolved' | 'dismissed'
  resolution: string | null
  note: string | null
  /** Finans mutabakatı / reddedilen komut: işlemin adı (Tahsilat, İade, POS yatışı…) */
  command_label?: string | null
  device: { id: number; name: string; code: string } | null
  resolved_by: string | null
  resolved_at: string | null
  created_at: string | null
  history?: { id: number; op: string; source: string; value: unknown; has_field: boolean; device: string | null; user: string | null; at: string }[]
}

export const kindTone: Record<ConflictKind, Tone> = {
  field: 'info',
  attendance: 'accent',
  delete: 'warning',
  finance: 'danger',
  rejected: 'neutral',
}

export const sourceLabel: Record<string, string> = {
  web: 'Web',
  device: 'Cihaz',
  sweep: 'Toplu yazma',
  command: 'Cihaz işlemi',
  conflict: 'Çakışma çözümü',
  local: 'Yerel',
}

/** Cihaz 5 dk içinde görüldüyse çevrimiçi sayılır */
export function isOnline(d: SyncDevice): boolean {
  return !!d.last_seen_at && Date.now() - new Date(d.last_seen_at).getTime() < 5 * 60_000
}
