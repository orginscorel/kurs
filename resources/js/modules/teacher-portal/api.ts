import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useAuth } from '@/app/auth'
import type { Tone } from '@/components/ui/feedback'

/** Öğretmen portalı istemcisi — tüm uçlar /teacher-portal altında, öğretmen oturumdan çözülür. */
export const TP = '/teacher-portal'

export function useTeacherQuery<T>(key: (string | number | undefined | null)[], path: string, query?: Record<string, string | number | undefined>, enabled = true) {
  return useQuery({
    queryKey: ['teacher-portal', ...key, query ?? {}],
    queryFn: () => api.get<T>(`${TP}${path}`, query),
    staleTime: 30_000,
    enabled,
    retry: (count, err: any) => count < 1 && !(err?.status >= 400 && err?.status < 500),
  })
}

/** Önizlemede (personel "öğretmen olarak giriş") yazma işlemleri sunucuda kapalıdır; düğmeler de gizlenir. */
export function useReadOnly(): boolean {
  return useAuth((s) => !!s.me?.impersonation)
}

export function useTeacherCan() {
  const perms = useAuth((s) => s.permissionSet)
  const readOnly = useReadOnly()
  return (p: 'attendance' | 'homework' | 'observations') => !readOnly && perms.has(`teacher_portal.${p}`)
}

export type TeacherIdentity = { id: number; full_name: string; first_name: string; title: string | null; specialty: string | null; color: string | null; avatar_url: string | null }

export type SessionRow = {
  id: number; date: string; starts_at: string; ends_at: string; status: string; cancel_reason: string | null; topic_note: string | null
  class_group_id: number; subject: string; subject_color: string | null; classroom: string | null; class_group: string | null
  attendance_taken_at: string | null; roster: number; absent: number; late: number; recorded: number; can_take_attendance: boolean
}

export type StudyRow = { id: number; kind: string; starts_at: string; ends_at: string; topic: string | null; status: string; capacity: number; subject: string | null; classroom: string | null; student_count: number }

export type PendingRow = { id: number; date: string; starts_at: string; ends_at: string; subject: string; class_group: string | null }

export type ExamLite = { id: number; name: string; exam_date: string; publisher?: string | null; type: string; type_name?: string }

export type AnnouncementRow = { id: number; title: string; body: string; published_at: string; is_read?: boolean }

export type Summary = {
  teacher: TeacherIdentity
  today: { date: string; lessons: SessionRow[] }
  counts: { classes: number; students: number; week_lessons: number; open_homework: number; to_grade: number; open_requests: number; unread_announcements: number; observations_week: number }
  pending_attendance: PendingRow[]
  homework_to_grade: { id: number; title: string; due_at: string; subject: string; class_group: string | null; pending_count: number }[]
  upcoming_exams: ExamLite[]
  announcements: AnnouncementRow[]
}

export type GroupMeta = {
  id: number; name: string; grade_level: number | null; program: string | null; classroom: string | null; is_advisor: boolean; student_count: number
  subjects: { id: number; name: string; color: string | null; weekly: number }[]
}

export type HomeworkRow = {
  id: number; title: string; assigned_at: string | null; due_at: string; is_open: boolean
  subject_id: number; subject: string | null; subject_color: string | null; class_group_id: number | null; class_group: string | null
  total_count: number; done_count: number; missed_count: number; to_grade_count: number; completion: number
}

export type DocRow = { id: number; title: string; mime_type: string; size: number; created_at?: string | null }

export type ObservationRow = {
  id: number; kind: 'positive' | 'improve' | 'note'; kind_label: string; category: string; category_label: string; points: number; body: string
  visible_to_guardian: boolean; visible_to_student: boolean; subject: string | null; class_group: string | null; teacher: string | null
  is_mine: boolean; created_at: string; student: { id: number; full_name: string; student_no: string } | null
}

export type ObservationOptions = { kinds: Record<string, string>; categories: Record<string, string> }

export const homeworkTone: Record<string, Tone> = { assigned: 'neutral', seen: 'info', submitted: 'success', late: 'warning', missed: 'danger' }
export const attendanceTone: Record<string, Tone> = { present: 'success', late: 'warning', absent: 'danger', excused: 'info', medical: 'info' }
export const observationTone: Record<string, Tone> = { positive: 'success', improve: 'warning', note: 'neutral' }
export const requestTone: Record<string, Tone> = { open: 'warning', answered: 'success', closed: 'neutral' }

export function fileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / 1024 / 1024).toLocaleString('tr-TR', { maximumFractionDigits: 1 })} MB`
}

export function shiftDay(iso: string, days: number) {
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(Date.UTC(y!, m! - 1, d! + days)).toISOString().slice(0, 10)
}
