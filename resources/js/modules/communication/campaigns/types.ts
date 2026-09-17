import type { Tone } from '@/components/ui/feedback'

export type CampaignChannel = 'sms' | 'email'
export type CampaignStatus = 'draft' | 'scheduled' | 'sending' | 'completed' | 'cancelled'
export type AudienceGroup = 'students' | 'guardians' | 'teachers' | 'employees' | 'leads' | 'manual'

export type ManualRecipient = { name: string; phone: string | null; email: string | null }

export type Audience = {
  groups: AudienceGroup[]
  student_statuses: string[]
  class_group_ids: number[]
  program_ids: number[]
  lead_stages: string[]
  manual: ManualRecipient[]
}

export type CampaignRow = {
  id: number
  name: string
  channels: CampaignChannel[]
  is_commercial: boolean
  status: CampaignStatus
  status_label: string
  scheduled_at: string | null
  recipients_total: number
  sms_parts: number
  counts: { sent: number; delivered: number; failed: number; pending: number; skipped: number } | null
  created_by: string | null
  approved_by: string | null
  approved_at: string | null
  started_at: string | null
  completed_at: string | null
  created_at: string
}

export type ChannelCounts = { total: number; pending: number; sending: number; sent: number; delivered: number; failed: number; skipped: number; parts_sent: number }

export type CampaignDetailData = CampaignRow & {
  audience: Audience
  options: { sms_opt_out?: boolean }
  sms_body: string | null
  email_subject: string | null
  email_body: string | null
  estimate: PreviewSummary | null
  counts: Partial<Record<CampaignChannel, ChannelCounts>>
}

export type ChannelStats = {
  total: number; sendable: number; no_address: number; invalid: number; no_consent: number; suppressed: number; denied: number; duplicate: number
  parts?: number; max_parts?: number; encoding?: 'gsm7' | 'tr' | 'unicode' | null; unit_price?: number | null; cost?: number | null
}

export type PreviewSample = {
  key: string; name: string; group: AudienceGroup
  sms: string | null; sms_analysis: { encoding: string; length: number; parts: number } | null
  email_subject: string | null; email_body: string | null
}

export type Readiness = { ok: boolean; reason: string | null }

export type PreviewSummary = {
  people: number
  reachable_people: number
  by_group: Partial<Record<AudienceGroup, number>>
  channels: Partial<Record<CampaignChannel, ChannelStats>>
  samples: PreviewSample[]
  unknown_vars: string[]
  opt_out_text: string | null
  readiness?: Partial<Record<CampaignChannel, Readiness>>
}

export type CampaignOptions = {
  groups: Record<AudienceGroup, string>
  student_statuses: Record<string, string>
  lead_stages: Record<string, string>
  open_lead_stages: string[]
  class_groups: { id: number; name: string; program_id: number | null }[]
  programs: { id: number; name: string }[]
  variables: Record<string, string>
  templates: { id: number; channel: CampaignChannel; name: string; subject: string | null; body: string }[]
  channels: {
    sms: Readiness & { provider: string | null; simulation: boolean; encoding: 'tr' | 'unicode' | 'ascii'; header: string | null; unit_price: string | number | null; opt_out_text: string | null; iys_brand_code_set: boolean }
    email: Readiness & { provider: string | null; simulation: boolean; from: string | null }
    whatsapp: { ok: boolean }
  }
  limits: { sms_body: number; email_body: number; manual: number }
}

export type RecipientRow = {
  id: number
  channel: CampaignChannel
  group: AudienceGroup
  group_label: string
  recipient_type: string | null
  recipient_id: number | null
  student_id: number | null
  name: string | null
  to: string | null
  status: 'pending' | 'skipped' | 'sending' | 'sent' | 'delivered' | 'failed'
  status_label: string
  skip_reason: string | null
  skip_label: string | null
  sms_parts: number | null
  error: string | null
  simulated: boolean
  sent_at: string | null
  delivered_at: string | null
  updated_at: string
}

export const CAMPAIGN_STATUS_TONE: Record<CampaignStatus, Tone> = {
  draft: 'neutral', scheduled: 'info', sending: 'primary', completed: 'success', cancelled: 'warning',
}

export const RECIPIENT_STATUS_TONE: Record<RecipientRow['status'], Tone> = {
  pending: 'neutral', skipped: 'warning', sending: 'info', sent: 'primary', delivered: 'success', failed: 'danger',
}

export const CHANNEL_NAME: Record<CampaignChannel, string> = { sms: 'SMS', email: 'E-posta' }

export const SKIP_LABEL: Record<string, string> = {
  no_address: 'Telefon / e-posta yok',
  invalid: 'Geçersiz adres',
  no_consent: 'Ticari ileti izni yok',
  suppressed: 'Ret listesinde',
  denied: 'Bilgilendirmeyi reddetmiş',
  duplicate: 'Tekrar eden adres',
  cancelled: 'İptal edildi',
}

export const emptyAudience = (): Audience => ({
  groups: ['students'], student_statuses: ['active'], class_group_ids: [], program_ids: [], lead_stages: [], manual: [],
})
