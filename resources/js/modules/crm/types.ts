export const STAGE_ORDER = ['new', 'called', 'meeting_scheduled', 'met', 'offered', 'undecided', 'call_again', 'won', 'lost'] as const
export type Stage = (typeof STAGE_ORDER)[number]

export const stageTone: Record<Stage, 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'accent'> = {
  new: 'info',
  called: 'primary',
  meeting_scheduled: 'accent',
  met: 'primary',
  offered: 'warning',
  undecided: 'neutral',
  call_again: 'warning',
  won: 'success',
  lost: 'danger',
}

export const activityIconKind: Record<string, string> = {
  call: 'Aradı',
  meeting: 'Görüşme',
  whatsapp: 'WhatsApp',
  note: 'Not',
  offer: 'Teklif',
  stage_change: 'Aşama değişikliği',
}

export type LeadRow = {
  id: number
  full_name: string
  first_name: string
  last_name: string
  phone: string
  stage: Stage
  stage_label: string
  stage_position: number
  source: string
  source_label: string
  program: { id: number; name: string } | null
  owner: { id: number; name: string } | null
  next_action: string | null
  next_action_at: string | null
  next_action_overdue: boolean
  last_contacted_at: string | null
  offered_price: string | null
  student_id: number | null
  created_at: string
  guardian_name: string | null
  guardian_phone: string | null
  school_name: string | null
  school_grade: string | null
  lost_reason: string | null
  /** Arama + yüz yüze + WhatsApp görüşme sayısı; liste/pano ucunda hesaplanır */
  contact_count?: number | null
}

export type LeadDetail = LeadRow & {
  guardian_name: string | null
  guardian_phone: string | null
  email: string | null
  school_name: string | null
  school_grade: string | null
  source_detail: string | null
  lost_reason: string | null
  activities: {
    id: number
    kind: string
    body: string | null
    meta: Record<string, unknown> | null
    user: string | null
    created_at: string
  }[]
  tasks: { id: number; title: string; due_at: string | null; completed_at: string | null }[]
}

export type BoardColumn = { stage: Stage; label: string; leads: LeadRow[] }

export type LeadOptions = {
  stages: Record<Stage, string>
  sources: Record<string, string>
  programs: { id: number; name: string }[]
  owners: { id: number; name: string }[]
}

export type Task = {
  id: number
  title: string
  description: string | null
  due_at: string | null
  priority: 'low' | 'normal' | 'high'
  completed_at: string | null
  taskable_type: string | null
  taskable_id: number | null
  taskable_label: string | null
  taskable_link: string | null
  assigned_to: number | null
}

export type CrmReport = {
  summary: { rate: number; won: number; total: number }
  by_source: { source: string; label: string; rate: number; won: number; total: number }[]
  funnel: { stage: Stage; label: string; count: number }[]
  by_owner: { id: number; name: string; rate: number; won: number; total: number; lost: number }[]
  lost_reasons: { lost_reason: string; total: number }[]
  monthly_trend: { ym: string; total: number; won: string | number }[]
}
