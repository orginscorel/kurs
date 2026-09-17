export const meetingKinds: Record<string, string> = { individual: 'Bireysel', guardian: 'Veli', group: 'Grup', phone: 'Telefon', online: 'Çevrim içi' }

export type MeetingRow = {
  id: number | null
  student: { id: number; full_name: string; student_no: string; school_grade: string | null } | null
  counselor?: { id: number; name: string } | null
  met_at?: string
  kind?: string
  kind_label?: string
  summary?: string
  goal?: string | null
  motivation?: number | null
  study_discipline?: number | null
  visibility?: string
  visible_to_student?: boolean
  next_meeting_on?: string | null
  private_note?: string | null
  can_see_private?: boolean
  last_meeting_at?: string | null
  is_stale_row?: boolean
}

export type MeetingOptions = {
  kinds: Record<string, string>
  visibilities: Record<string, string>
  counselors: { id: number; name: string }[]
}

export type RiskFactor = { key: string; label: string; value: string; points: number; weight: number; finance?: boolean }
export type RiskInsight = { kind: string; tone: 'danger' | 'warning' | 'info' | 'success'; text: string }

export type RiskStudent = {
  id: number
  full_name: string
  student_no: string
  school_grade: string | null
  class_groups: { id: number; name: string }[]
  score: number
  level: 'low' | 'medium' | 'high'
  factors: RiskFactor[]
  insights: RiskInsight[]
  calculated_at: string
}

export type GoalCompare = { target: number | null; actual: number | null; pct: number | null; diff: number | null }

export type GoalProgress = {
  goal_id: number
  tyt: GoalCompare
  ayt: GoalCompare
  subjects: (GoalCompare & { code: string })[]
}

export type RiskOptions = {
  levels: Record<string, string>
  class_groups: { id: number; name: string }[]
  counselors: { id: number; name: string }[]
}

export type StudentGoal = {
  id: number
  student: { id: number; full_name: string; student_no: string; school_grade: string | null } | null
  university: string | null
  department: string | null
  target_rank: number | null
  target_tyt_net: string | null
  target_ayt_net: string | null
  subject_targets: Record<string, number> | null
  is_active: boolean
  progress: GoalProgress | null
}
