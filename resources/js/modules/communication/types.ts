export type Channel = 'whatsapp' | 'sms' | 'email' | 'push'
export type RecipientKind = 'student' | 'guardian' | 'teacher' | 'admin'

export const CHANNEL_LABEL: Record<Channel, string> = { whatsapp: 'WhatsApp', sms: 'SMS', email: 'E-posta', push: 'Push' }

export const MESSAGE_STATUS_LABEL: Record<string, string> = {
  queued: 'Kuyrukta', sending: 'Gönderiliyor', sent: 'Gönderildi', delivered: 'İletildi', read: 'Okundu', failed: 'Başarısız', cancelled: 'İptal edildi',
}

export const MESSAGE_STATUS_TONE: Record<string, 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info'> = {
  queued: 'neutral', sending: 'info', sent: 'primary', delivered: 'success', read: 'success', failed: 'danger', cancelled: 'neutral',
}

export type OutboundMessageRow = {
  id: number
  channel: Channel
  to: string
  recipient_type: string | null
  student_id: number | null
  template_key: string | null
  subject: string | null
  body: string
  status: string
  status_label: string
  attempts: number
  error: string | null
  trigger: string | null
  sent_at: string | null
  delivered_at: string | null
  read_at: string | null
  created_at: string
}

export type MessageTemplateRow = {
  id: number
  branch_id: number | null
  key: string
  channel: Channel
  name: string
  subject: string | null
  body: string
  provider_template: string | null
  language: string
  is_active: boolean
}

export type AnnouncementAudience = { type: 'all_students' | 'class_group' | 'program' | 'teachers' | 'guardians'; id?: number | null; students_only?: boolean }

export type AnnouncementRow = {
  id: number
  title: string
  body: string
  audience: AnnouncementAudience
  channels: string[]
  recipient_count: number
  author: string | null
  published_at: string
}

export type AutomationAction = { type: 'whatsapp' | 'sms' | 'email' | 'app'; to: RecipientKind; template?: string; attach_report_card?: boolean }

/** Mesaj kanallarının bağlantı durumu (Entegrasyonlar: etkin + test başarılı). */
export type ChannelStatus = { whatsapp: boolean; sms: boolean; email: boolean }

export type RecommendedRule = { id: number; name: string; trigger_label: string; is_active: boolean; missing_channels: string[] }

export type AutomationRuleRow = {
  id: number
  name: string
  trigger: string
  trigger_label: string
  conditions: Record<string, unknown> | null
  actions: AutomationAction[]
  delay_minutes: number
  is_active: boolean
  run_count: number
  last_run_at: string | null
  description: string
}

export type AutomationRunRow = {
  id: number
  subject_type: string | null
  subject_id: number | null
  status: 'scheduled' | 'done' | 'skipped' | 'failed'
  run_at: string
  result: string | null
}

export type AutomationWiringRow = {
  trigger: string
  label: string
  kind: 'event' | 'schedule'
  source: string | null
  rules_total: number
  rules_active: number
  fired_24h: number
  runs_24h: { done: number; scheduled: number; skipped: number; failed: number }
}

export type AutomationWiring = {
  data: AutomationWiringRow[]
  chains: { event: string; effects: string[] }[]
  webhook_events: { event: string; deliveries_24h: number }[]
}
