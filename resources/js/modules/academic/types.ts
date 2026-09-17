import type { Tone } from '@/components/ui/feedback'

// ------------------------------------------------------------------ seçenekler
export type AcademicOptions = {
  programs: { id: number; code: string; name: string; kind: string; color: string; exam_track: string | null; is_active: boolean; subject_ids: number[] }[]
  subjects: { id: number; code: string; name: string; short_name: string | null; color: string; is_active: boolean }[]
  classrooms: { id: number; name: string; kind: string; capacity: number; floor: string | null; is_active: boolean }[]
  teachers: { id: number; name: string; first_name: string; last_name: string; color: string; title: string | null; is_active: boolean; subject_ids: number[] }[]
  terms: { id: number; name: string; starts_on: string; ends_on: string; is_current: boolean }[]
  class_groups: { id: number; name: string; program_id: number; program: string | null; color: string | null; academic_term_id: number; capacity: number; homeroom_classroom_id: number | null; is_active: boolean }[]
  weekdays: Record<string, string>
  classroom_kinds: Record<string, string>
  my_teacher_id: number | null
  my_student_id: number | null
}

// ------------------------------------------------------------------ program / ders / derslik / sınıf
export type ProgramRow = {
  id: number; code: string; name: string; kind: string; exam_track: string | null; color: string; description: string | null; is_active: boolean
  kind_label: string; track_label: string | null; class_groups_count: number; subjects_count: number; students_count: number; weekly_hours: number
}
export type ProgramDetailData = {
  program: ProgramRow
  subjects: { id: number; name: string; code: string; color: string; weekly_hours: number; curriculum: string | null }[]
  class_groups: { id: number; name: string; term: string | null; capacity: number; students_count: number; homeroom: string | null; advisor: string | null; is_active: boolean }[]
  teachers: { id: number; name: string; color: string; subjects: string[]; lessons: number }[]
  packages: { id: number; name: string; list_price: string; default_installments: number }[]
}

export type SubjectRow = { id: number; code: string; name: string; short_name: string | null; color: string; is_active: boolean; topics_count: number; teachers_count: number; weekly_lessons: number }
export type TopicRow = { id: number; parent_id: number | null; name: string; outcome_code: string | null; sort: number; asked: number; success_rate: number | null }
export type SubjectDetailData = {
  subject: Omit<SubjectRow, 'topics_count' | 'teachers_count' | 'weekly_lessons'>
  topics: TopicRow[]
  teachers: { id: number; name: string; color: string; title: string | null; is_active: boolean }[]
  programs: { id: number; name: string; color: string; weekly_hours: number }[]
}

export type ClassroomRow = { id: number; name: string; kind: string; kind_label: string; capacity: number; floor: string | null; features: string | null; is_active: boolean; occupancy: number; weekly_minutes: number; today_sessions: number; current_session: string | null }
export type ClassroomDetailData = {
  classroom: Omit<ClassroomRow, 'occupancy' | 'weekly_minutes' | 'today_sessions' | 'current_session'>
  today: { id: number; starts_at: string; ends_at: string; status: string; attendance_taken_at: string | null; subject: string; subject_color: string; class_group: string; class_group_id: number; teacher: string; phase: string }[]
  study_today: { id: number; kind: string; starts_at: string; ends_at: string; status: string; topic: string | null; subject: string | null; teacher: string }[]
  schedules: { id: number; weekday: number; weekday_label: string; starts_at: string; ends_at: string; subject: string; subject_color: string; class_group: string; class_group_id: number; teacher: string }[]
  occupancy: { weekly: number; weekly_minutes: number; per_day: { weekday: number; label: string; minutes: number; occupancy: number; lessons: number }[] }
  homeroom_of: { id: number; name: string }[]
}

export type ClassGroupRow = {
  id: number; name: string; capacity: number; is_active: boolean; program_id: number; academic_term_id: number; homeroom_classroom_id: number | null; advisor_teacher_id: number | null
  program: string | null; program_color: string | null; program_kind: string | null; term: string | null; homeroom: string | null; advisor: string | null
  students_count: number; lessons_count: number; fill_rate: number
  /** Son 30 gün devam yüzdesi ve bugünkü ders sayısı; yalnız liste ucunda hesaplanır */
  attendance_30?: number | null; today_lessons?: number | null
}
export type ClassGroupDetailData = {
  group: ClassGroupRow & { term_dates: [string | null, string | null]; advisor_phone: string | null; homeroom_capacity: number | null }
  students: { id: number; student_no: string; full_name: string; status: string; status_label: string; photo_url: string | null; school_grade: string | null; phone: string | null; guardian: { name: string; phone: string | null } | null; joined_on: string }[]
  schedules: { id: number; weekday: number; weekday_label: string; starts_at: string; ends_at: string; subject: string; subject_color: string; classroom: string; teacher: string }[]
  weekly_minutes: number
  attendance_30: { total: number; present: string | number; late: string | number; absent: string | number; excused: string | number }
  exams: { id: number; name: string; exam_date: string; avg_net: string; max_net: string; participants: number }[]
  homework: { total: number; has_open: number; done: string | number; missed: string | number; assignments: number }
  upcoming: { id: number; starts_at: string; ends_at: string; subject: string; subject_color: string; classroom: string; teacher: string }[]
}

// ------------------------------------------------------------------ ders programı
export type ScheduleView = 'class_group' | 'teacher' | 'classroom' | 'student'
export type Ref = { id: number; name: string }
export type WeekItem = {
  id: number; weekday: number; starts_at: string; ends_at: string; valid_from: string; valid_until: string | null; active_this_week: boolean; is_locked?: boolean
  subject: { id: number; name: string; short_name: string | null; color: string }
  teacher: { id: number; name: string; color: string }
  classroom: Ref; class_group: Ref
  session: null | { id: number; date: string; status: string; cancel_reason: string | null; topic_note: string | null; topic_id: number | null; attendance_taken: boolean; classroom_override: Ref | null; teacher_override: Ref | null }
}
export type WeekStudy = { id: number; kind: string; status: string; date: string; weekday: number; starts_at: string; ends_at: string; topic: string | null; subject: string | null; classroom: string | null; teacher: string | null }
export type WeekData = {
  view: ScheduleView; id: number; week_start: string; week_end: string
  days: { date: string; weekday: number; label: string; is_today: boolean }[]
  items: WeekItem[]; studies: WeekStudy[]; range: { start: number; end: number }
}
export type SessionRow = {
  id: number; schedule_id: number | null; date: string; starts_at: string; ends_at: string; status: string; cancel_reason: string | null; topic_note: string | null; topic_id: number | null
  attendance_taken: boolean; present_count: number; absent_count: number; phase: 'upcoming' | 'in_progress' | 'done' | 'cancelled'
  subject: { id: number; name: string; short_name: string | null; color: string }; teacher: { id: number; name: string; color: string }; classroom: Ref; class_group: Ref
}
export type Conflict = { type: string; message: string; schedule_id?: number }

// ------------------------------------------------------------------ etüt / birebir
export type StudyRow = {
  id: number; kind: 'study' | 'private'; kind_label: string; status: string; status_label: string; starts_at: string; ends_at: string; date: string; start_time: string; end_time: string; duration: number
  topic: string | null; capacity: number; students_count: number; teacher: { id: number; name: string; color: string } | null; subject: { id: number; name: string; color: string } | null; classroom: Ref | null
  fee: string | null; notes: string | null; teacher_id: number; subject_id: number | null; classroom_id: number | null
}
export type StudyDetailData = StudyRow & {
  requested_by: string | null; approved_by: string | null
  students: { id: number; student_no: string; full_name: string; class_group: string; photo_url: string | null; attendance: string | null; phone: string | null }[]
}
export type FreeSlots = { date: string; weekday: number; defined: boolean; on_leave: boolean; windows: [string, string][]; busy: { start: string; end: string; label: string }[]; free: [string, string][] }
export type AvailabilityData = {
  teacher: { id: number; name: string; color: string }
  slots: { id: number; weekday: number; starts_at: string; ends_at: string }[]
  lessons: { id: number; weekday: number; starts_at: string; ends_at: string; subject: string; color: string; class_group: string }[]
  weekdays: Record<string, string>
}

// ------------------------------------------------------------------ ödev
export type HomeworkRow = {
  id: number; title: string; assigned_at: string | null; due_at: string; is_open: boolean; teacher_id: number; subject_id: number; class_group_id: number | null; topic_id: number | null
  teacher: string | null; subject: { id: number; name: string; color: string } | null; class_group: string | null; topic: string | null
  total_count: number; done_count: number; missed_count: number; graded_count: number; completion: number
}
export type SubmissionRow = { id: number; student_id: number; student_no: string; full_name: string; photo_url: string | null; class_group: string; status: string; status_label: string; seen_at: string | null; submitted_at: string | null; score: number | null; teacher_note: string | null }
export type HomeworkDetailData = {
  homework: HomeworkRow & { description: string | null }
  submissions: SubmissionRow[]
  documents: { id: number; title: string; mime_type: string | null; size: number; created_at: string | null }[]
  stats: Record<string, number>
  avg_score: string | null
}

// ------------------------------------------------------------------ sabitler
export const studyStatusTone: Record<string, Tone> = { requested: 'warning', approved: 'success', rejected: 'danger', completed: 'primary', cancelled: 'neutral' }
export const submissionTone: Record<string, Tone> = { assigned: 'neutral', seen: 'info', submitted: 'success', late: 'warning', missed: 'danger' }
export const sessionStatusLabel: Record<string, string> = { scheduled: 'Planlandı', in_progress: 'Sürüyor', completed: 'Tamamlandı', cancelled: 'İptal' }
export const phaseTone: Record<string, Tone> = { upcoming: 'neutral', in_progress: 'success', done: 'primary', cancelled: 'danger' }
export const phaseLabel: Record<string, string> = { upcoming: 'Yaklaşan', in_progress: 'Sürüyor', done: 'Bitti', cancelled: 'İptal' }

export const WEEKDAY_SHORT = ['', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz']
export const WEEKDAYS = ['', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar']

/**
 * Kayıt renkleri (indigo, rose…) tasarım token'larına eşlenir; ham hex kullanılmaz.
 * Grafik serileri açık/koyu temada ayrı tanımlıdır, bu yüzden tema uyumludur.
 */
const colorVars: Record<string, string> = {
  indigo: 'var(--rec-indigo)', violet: 'var(--rec-indigo)', purple: 'var(--rec-indigo)',
  sky: 'var(--rec-sky)', blue: 'var(--rec-sky)', cyan: 'var(--rec-sky)',
  teal: 'var(--rec-teal)', emerald: 'var(--rec-teal)',
  green: 'var(--rec-green)', lime: 'var(--rec-green)',
  rose: 'var(--rec-rose)', red: 'var(--rec-rose)',
  pink: 'var(--rec-pink)',
  amber: 'var(--rec-amber)', yellow: 'var(--rec-amber)',
  orange: 'var(--rec-orange)',
  slate: 'var(--rec-slate)', gray: 'var(--rec-slate)',
}
export const COLOR_OPTIONS = [
  { value: 'indigo', label: 'Çivit' }, { value: 'sky', label: 'Gök mavisi' }, { value: 'teal', label: 'Deniz yeşili' }, { value: 'green', label: 'Yeşil' },
  { value: 'rose', label: 'Gül' }, { value: 'pink', label: 'Pembe' }, { value: 'amber', label: 'Kehribar' }, { value: 'orange', label: 'Turuncu' }, { value: 'slate', label: 'Gri' },
]
/** Kayıt rengi — tam doygun ton (yalnız renk seçicide). */
export function vividColorOf(name?: string | null): string {
  return colorVars[name ?? ''] ?? 'var(--ink-3)'
}
/** Kayıt rengi: sol çizgi / nokta; metin daima ink tonlarında kalır. */
export function colorOf(name?: string | null): string {
  return vividColorOf(name)
}
/** Kayıt rengine hafif boyanmış yüzey (ders kartı arka planı). */
export function tintOf(name?: string | null, strength = 10): string {
  return `color-mix(in srgb, ${vividColorOf(name)} ${strength}%, var(--surface))`
}
/** Renkli etiket yüzeyi: hafif renkli zemin, koyulaştırılmış renkli metin. */
export function softStyle(name?: string | null, strength = 13) {
  const c = vividColorOf(name)
  return { background: `color-mix(in srgb, ${c} ${strength}%, var(--surface))`, color: `color-mix(in srgb, ${c} 72%, var(--ink))`, borderColor: `color-mix(in srgb, ${c} 28%, var(--surface))` }
}

export const toMinutes = (t: string) => {
  const [h, m] = t.split(':').map(Number)
  return (h ?? 0) * 60 + (m ?? 0)
}
export const toTime = (min: number) => `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`
export const addDays = (iso: string, n: number) => {
  const [y, m, d] = iso.split('-').map(Number)
  const dt = new Date(y!, m! - 1, d! + n)
  return `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`
}
export const fmtBytes = (n: number) => (n > 1024 * 1024 ? `${(n / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)
