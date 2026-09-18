import type { SessionRow } from '@/modules/core/SessionList'
export type Institution = {
  name: string
  short_name: string
  phone: string | null
  email: string | null
  website: string | null
  address: string | null
  tax_office: string | null
  tax_number: string | null
  currency: string
  timezone: string
  logo_path: string | null
  logo_url?: string | null
  onboarding_completed: boolean
}

export type AttendanceSettings = {
  late_after_minutes: number
  absent_after_minutes: number
  auto_absence_enabled: boolean
  notify_guardian_entry: boolean
  notify_guardian_exit: boolean
  notify_guardian_absent_delay_minutes: number
  min_minutes_between_events: number
}

export type FinanceReminderSettings = {
  reminder_offsets: number[]
  reminders_enabled: boolean
  reminder_hour: string
  receipt_prefix?: string
  enrollment_prefix?: string
}

export type RetentionSettings = {
  attendance_events_days: number
  outbound_messages_days: number
  login_events_days: number
  withdrawn_student_anonymize_after_days: number
}

export type PortalSettings = { guardian_requests_enabled: boolean; show_student_overdue_alert: boolean; show_guardian_overdue_alert: boolean }

export type DisciplinePortalSettings = { portal_enabled: boolean; portal_defense_requests: boolean; portal_defense_submission: boolean; teacher_portal_reporting: boolean }

export type BackupSettings = { daily_enabled: boolean; daily_keep: number; weekly_keep: number }

export type AcademicTerm = { id: number; name: string; starts_on: string; ends_on: string; is_current: boolean }

export type Branch = { id: number; code: string; name: string; phone: string | null; email: string | null; address: string | null; city: string | null; is_active: boolean; users_count: number }

export type AdminUserRow = {
  id: number
  name: string
  username: string
  phone: string | null
  email?: string | null
  user_type: string
  user_type_label: string
  branch: { id: number; name: string } | null
  roles: string[]
  is_active: boolean
  must_change_password: boolean
  last_login_at: string | null
  last_login_ip: string | null
}

export type AdminUserDetail = AdminUserRow & {
  email: string | null
  recent_logins: { id: number; username: string; successful: boolean; channel: string; ip_address: string | null; created_at: string }[]
  sessions: SessionRow[]
  sessions_notice?: string | null
}

export type UserOptions = { roles: string[]; role_labels?: Record<string, string>; branches: { id: number; name: string }[]; user_types: Record<string, string> }

export type RoleRow = { id: number; name: string; label: string; is_protected: boolean; user_count: number; permissions: string[] }

export type PermissionCatalog = Record<string, { label: string; items: Record<string, string> }>

export type AuditLogRow = {
  id: number
  created_at: string
  action: string
  description: string
  subject_type: string | null
  subject_id: number | null
  subject_label?: string | null
  subject_url?: string | null
  ip_address: string | null
  user: { id: number; name: string; username: string } | null
}

export type AuditLogDetail = AuditLogRow & { changes: { before?: Record<string, unknown>; after?: Record<string, unknown> } | null; user_agent: string | null }

export type HealthStatus = 'healthy' | 'warning' | 'critical'

export type SystemHealth = {
  api: { status: HealthStatus; response_ms: number }
  database: { status: HealthStatus; connected: boolean; response_ms?: number; size_mb?: number; table_count?: number; error?: string }
  queue: { status: HealthStatus; pending: number; failed: number }
  scheduler: { status: HealthStatus; last_run_at: string | null; minutes_ago: number | null }
  storage: { status: HealthStatus; writable: boolean; free_gb: number | null; free_percent: number | null }
  integrations: Record<string, { status: HealthStatus; provider: string | null; is_enabled: boolean; last_checked_at?: string | null; last_error?: string | null; label?: string }>
  devices: { status: HealthStatus; total: number; offline: number; devices: { id: number; name: string; last_seen_at: string | null; online: boolean }[] }
  versions: { status: HealthStatus; php: string; laravel: string }
}

export type FailedJob = { id: number; uuid: string; queue: string; exception: string; failed_at: string }

export type BackupRun = {
  id: number
  kind: 'daily' | 'weekly' | 'manual'
  status: 'running' | 'success' | 'failed'
  size: number | null
  checksum: string | null
  error: string | null
  started_at: string
  finished_at: string | null
  duration_seconds: number | null
}

export const healthTone: Record<HealthStatus, 'success' | 'warning' | 'danger'> = { healthy: 'success', warning: 'warning', critical: 'danger' }
export const healthLabel: Record<HealthStatus, string> = { healthy: 'Sağlıklı', warning: 'Uyarı', critical: 'Hata' }
export const backupKindLabel: Record<string, string> = { daily: 'Günlük', weekly: 'Haftalık', manual: 'Manuel' }
export const integrationLabel: Record<string, string> = { whatsapp: 'WhatsApp', sms: 'SMS', email: 'E-posta' }
