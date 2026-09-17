export type Behavior = {
  id: number; code: string; name: string; category: string; category_label: string; kind: 'negative' | 'positive'
  points: number; severity: string; suggested_sanction: string | null; description: string | null; is_active: boolean; sort_order: number
}

export type SanctionType = {
  id: number; code: string; name: string; level: number; authority: 'staff' | 'board'; is_suspension: boolean; has_duty: boolean
  expires_after_days: number | null; tone: string; description: string | null; is_active: boolean
}

export type DisciplineSettings = {
  portal_enabled: boolean; portal_defense_requests: boolean; portal_defense_submission: boolean; default_defense_days: number
  merit_offsets_penalty: boolean; threshold_watch: number; threshold_warning: number; threshold_critical: number
  suspension_attendance_status: 'excused' | 'absent'; teacher_portal_reporting: boolean
}

export type DisciplineOptions = {
  behaviors: Behavior[]
  sanction_types: SanctionType[]
  categories: Record<string, string>
  positive_categories: string[]
  severities: Record<string, string>
  incident_statuses: Record<string, string>
  sanction_statuses: Record<string, string>
  defense_statuses: Record<string, string>
  appeal_statuses: Record<string, string>
  board_statuses: Record<string, string>
  board_roles: Record<string, string>
  roles: Record<string, string>
  levels: Record<string, string>
  class_groups: { id: number; name: string }[]
  subjects: { id: number; name: string }[]
  teachers: { id: number; name: string }[]
  staff: { id: number; name: string }[]
  settings: DisciplineSettings
}

export type IncidentRow = {
  id: number; incident_no: string; kind: 'negative' | 'positive'; occurred_at: string; location: string | null; title: string | null
  severity: string; severity_label: string; status: string; status_label: string; outcome: string | null; source: string
  reporter: string | null; teacher: string | null; subject: string | null; class_group: string | null
  students: { id: number; full_name: string; student_no: string; behavior: string | null; points: number }[]
  others_count: number; sanctions_count: number | null; guardian_notified_at: string | null; created_at: string
}

export type Standing = { penalty: number; merit: number; net: number; level: string; level_label: string; incidents: number; positives: number }

export type Appeal = {
  id: number; appellant: string; appealed_on: string; reason: string; status: string; status_label: string
  result_note: string | null; decided_at: string | null; decided_by: string | null
}

export type Sanction = {
  id: number; sanction_no: string; incident_id: number; incident_no: string | null
  student: { id: number; full_name: string; student_no: string } | null
  type: { id: number; code: string; name: string; level: number; authority: string; is_suspension: boolean; tone: string } | null
  status: string; status_label: string; decision_note: string | null; duty_description: string | null
  starts_on: string | null; ends_on: string | null; days: number | null; expires_on: string | null; visible_to_portal: boolean
  decided_by: string | null; decided_at: string | null; board_meeting_id: number | null; board_meeting_no: string | null
  cancel_reason: string | null; guardian_notified_at: string | null; appeals: Appeal[]
}

export type Defense = {
  id: number; incident_id: number; incident_no: string | null; student: { id: number; full_name: string; student_no: string } | null
  status: string; status_label: string; overdue: boolean; requested_at: string; requested_by: string | null; due_on: string
  request_note: string | null; statement: string | null; submitted_at: string | null; submitted_via: string | null
}

export type Participant = {
  student_id: number; full_name: string; student_no: string; class_name: string | null; role: string; role_label: string
  behavior_id: number | null; behavior: string | null; suggested_sanction: string | null
  penalty_points: number; merit_points: number; note: string | null; standing: Standing | null
}

export type IncidentDetail = IncidentRow & {
  description: string | null; witnesses: string | null; class_group_id: number | null; subject_id: number | null; teacher_id: number | null
  guardian_notified_via: string | null; decided_at: string | null; closed_at: string | null; transitions: string[]
  participants: Participant[]; sanctions: Sanction[]; defenses: Defense[]
  events: { id: number; type: string; message: string; user: string | null; created_at: string }[]
  documents: { id: number; title: string; mime_type: string | null; size: number | null; uploaded_by: string | null; created_at: string }[]
  board_items: { id: number; meeting_id: number; meeting_no: string; scheduled_at: string; result: string }[]
}

export type Meeting = {
  id: number; meeting_no: string; title: string; scheduled_at: string; location: string | null; status: string; status_label: string
  members: { user_id: number; name: string; role: string; role_label: string; present: boolean }[]
  notes: string | null; held_at: string | null; items_count: number | null; pending_count: number | null
}

export type MeetingItem = {
  id: number; position: number; result: string; votes_for: number; votes_against: number; votes_abstain: number; decision: string | null
  incident: { id: number; incident_no: string; occurred_at: string; severity: string; severity_label: string; status: string; title: string | null } | null
  student: { id: number; full_name: string; student_no: string } | null
  involved: { student_id: number; full_name: string; behavior: string | null; defense_status: string | null }[]
  sanction: Sanction | null
  defense: { status: string; statement: string | null } | null
}

export type AttentionRow = Standing & { student_id: number; full_name: string; student_no: string }

export type Overview = {
  term: { from: string; to: string; name: string }
  counts: { today: number; week: number; positives_week: number; open: number; appealed: number; active_sanctions: number; proposed: number; pending_defenses: number; overdue_defenses: number; suspended_today: number }
  suspended_today: Sanction[]
  pending_defenses: Defense[]
  recent: IncidentRow[]
  weekly: { week: string; label: string; incidents: number; positives: number }[]
  top_behaviors: { id: number; name: string; count: number }[]
  meetings: Meeting[]
  attention: AttentionRow[]
}
