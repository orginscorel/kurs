export type TeacherRow = {
  id: number
  full_name: string
  title: string | null
  specialty: string | null
  phone: string | null
  email: string | null
  color: string
  avatar_url: string | null
  employment_type: string
  employment_type_label: string
  is_active: boolean
  has_user: boolean
  subjects: { id: number; name: string; color: string }[]
  weekly_hours: number
  /** Haftalık hedef ders saati (tanımlı değilse null) */
  target_weekly_hours?: number | null
  hired_on?: string | null
  /** Bu haftaki ders oturumu sayısı; yalnız liste ucunda hesaplanır */
  week_lessons?: number | null
  /** Geçerli programda ders verdiği sınıf sayısı; yalnız liste ucunda hesaplanır */
  class_count?: number | null
}

export type TeacherOptions = {
  subjects: { id: number; name: string; color: string }[]
  employment_types: Record<string, string>
  colors: string[]
}

export type TeacherDetail = TeacherRow & {
  first_name: string
  last_name: string
  hired_on: string | null
  hourly_rate: string | null
  max_weekly_hours: number | null
  whatsapp_phone: string | null
  notes: string | null
  subject_ids: number[]
  user: { id: number; username: string; is_active: boolean; must_change_password: boolean } | null
}

export type TeacherProfile = {
  teacher: TeacherDetail
  week_schedule: { id: number; date: string; starts_at: string; ends_at: string; status: string; subject: string; class_group: string; classroom: string }[]
  classes: { id: number; name: string; student_count: number }[]
  hours_this_month: number
  attendance_rate: number | null
  leaves: { id: number; starts_on: string; ends_on: string; kind: string; reason: string | null; status: string }[]
  availabilities: { id: number; weekday: number; starts_at: string; ends_at: string }[]
  documents: { id: number; title: string; category: string; mime_type: string; size: number; created_at: string }[]
  performance: {
    exam_trend: { id: number; name: string; exam_date: string; avg_net: string }[]
    homework_total: number
    homework_submitted: number
    homework_graded: number
    grading_rate: number | null
  }
}

export type EmployeeRow = {
  id: number
  full_name: string
  position: string
  phone: string | null
  email: string | null
  hired_on: string | null
  is_active: boolean
  username?: string | null
  user_active?: boolean | null
  last_login_at?: string | null
  has_user: boolean
}

export const weekdayLabels = ['', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar']

export const leaveKindLabels: Record<string, string> = { annual: 'Yıllık izin', sick: 'Rapor', excuse: 'Mazeret', other: 'Diğer' }
