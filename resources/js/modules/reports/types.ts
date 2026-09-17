export type ReportOptions = {
  class_groups: { id: number; name: string; is_active: boolean }[]
  programs: { id: number; name: string }[]
  student_statuses: Record<string, string>
  lead_sources: Record<string, string>
}

export type AttendanceCounts = { records: number; present: number; absent: number; late: number; excused: number; medical: number; rate: number | null }

export type AttendanceData = {
  from: string; to: string
  totals: AttendanceCounts & { students: number; sessions: number; cancelled_sessions: number; taken_sessions: number; missing_sessions: number }
  by_day: (AttendanceCounts & { date: string })[]
  by_class: (AttendanceCounts & { id: number; name: string; students: number })[]
  students: (AttendanceCounts & { student_id: number; full_name: string; student_no: string; class_names: string | null })[]
}

export type StudentRow = {
  id: number; student_no: string; full_name: string; status: string; status_label: string
  class_names: string | null; program_names: string | null; school_name: string | null; school_grade: string | null
  field: string | null; phone: string | null; registered_on: string | null
}

export type StudentSummary = { total: number; by_status: { key: string; label: string; total: number }[]; by_grade: { label: string; total: number }[]; without_class: number }

export type ExamsData = {
  exams: { id: number; name: string; exam_date: string | null; type: string | null; status: string; participants: number }[]
  selected_exam_id: number | null
  report: null | {
    exam: { id: number; name: string; exam_date: string | null; type: string | null; status: string }
    summary: { participants: number; avg_net: number | null; avg_score: number | null; max_net: number | null; min_net: number | null; max_score: number | null }
    by_class: { id: number | null; name: string; participants: number; avg_net: number | null; avg_score: number | null; max_net: number | null }[]
    rows: { id: number; student_id: number; full_name: string; student_no: string; class_name: string | null; correct: number; wrong: number; blank: number; net: number | null; score: number | null; institution_rank: number | null; class_rank: number | null }[]
  }
}

export type LeadsData = {
  summary: { rate: number; won: number; total: number }
  by_source: { source: string; label: string; rate: number; won: number; total: number }[]
  funnel: { stage: string; label: string; count: number }[]
  by_owner: { id: number; name: string; rate: number; won: number; total: number; lost: number }[]
  lost_reasons: { lost_reason: string; total: number }[]
  monthly_trend: { ym: string; total: number | string; won: number | string }[]
  by_source_month: { ym: string; source: string; label: string; total: number; won: number }[]
  sources: Record<string, string>
}

export type TeacherLoadRow = {
  id: number; name: string; specialty: string | null; is_active: boolean; employment_type: string | null
  weekly_hours: number; weekly_slots: number; classes: number; max_weekly_hours: number | null; target_weekly_hours: number | null
  sessions: number; cancelled: number; lesson_hours: number; attendance_due: number; attendance_taken: number; attendance_missing: number
  studies: number; study_hours: number; leave_days: number
}
