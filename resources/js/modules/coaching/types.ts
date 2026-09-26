export const targetKinds: Record<string, string> = { questions: 'Soru', hours: 'Saat', topic: 'Konu' }

export type Coach = { id: number; name: string }

export type CoachingOptions = { coaches: Coach[] }

export type StudentLite = { id: number; full_name: string; student_no: string; school_grade: string | null }

export type AssignmentRow = {
  id: number
  full_name: string
  student_no: string
  school_grade: string | null
  field: string | null
  coach: Coach | null
  assigned_at: string | null
  coaching_source: 'package' | 'extra' | null
  coaching_package: string | null
  coaching_start: string | null
  coaching_end: string | null
  last_session_at: string | null
}

export type SessionRow = {
  id: number
  student: StudentLite | null
  coach: Coach | null
  held_at: string
  topics: string
  focus: number | null
  motivation: number | null
  action_items: string | null
  next_session_on: string | null
}

export type PlanItem = {
  id: number
  subject: string
  target_kind: string
  target_kind_label?: string
  target: number | null
  is_done: boolean
}

export type PlanRow = {
  id: number
  student: StudentLite | null
  coach: Coach | null
  week_start: string
  note: string | null
  items_total: number
  items_done: number
  progress: number
  items: PlanItem[]
}

export type DashboardStudent = {
  id: number
  full_name: string
  student_no: string
  school_grade: string | null
  field: string | null
  last_session_at: string | null
  next_session_on: string | null
  overdue: boolean
  has_plan: boolean
  plan_progress: number | null
}

export type Dashboard = {
  coach_id: number
  week_start: string
  stats: { students: number; overdue: number; plans_this_week: number; without_plan: number }
  students: DashboardStudent[]
  upcoming: { id: number; student: { id: number; full_name: string; student_no: string } | null; next_session_on: string }[]
}

export type StudentCoachingSummary = {
  student: { id: number; full_name: string; student_no: string; school_grade: string | null; field: string | null }
  coach: (Coach & { assigned_at: string }) | null
  session_count: number
  has_current_plan: boolean
  current_week_start: string
}
