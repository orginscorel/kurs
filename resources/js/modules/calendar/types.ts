import { addDays } from '../academic/types'

export type CalEventType = 'lesson' | 'study' | 'exam' | 'leave' | 'holiday'
export type CalView = 'month' | 'week' | 'day' | 'agenda'

export type LessonInfo = {
  schedule_id: number | null; makeup_of_id: number | null; holiday_id: number | null
  subject: { id: number; name: string | null; color: string | null }
  teacher: { id: number; name: string | null }
  classroom: { id: number; name: string | null }
  class_group: { id: number; name: string | null }
  attendance_taken: boolean; present_count: number; absent_count: number; topic_note: string | null; cancel_reason: string | null
}

export type CalEvent = {
  key: string; type: CalEventType; id: number; all_day: boolean
  date: string; end_date: string | null; starts_at: string | null; ends_at: string | null
  title: string; short: string | null; color: string | null; subtitle: string
  status: string | null; phase: string | null
  lesson?: LessonInfo
  holiday?: { kind: string; cancel_sessions: boolean; cancelled_count: number; notes: string | null }
  teacher_id?: number
}

export type CalData = {
  from: string; to: string; events: CalEvent[]
  summary: { lessons: number; cancelled: number; studies: number; exams: number; leaves: number; holidays: number }
}

export const TYPE_LABELS: Record<CalEventType, string> = { lesson: 'Ders', study: 'Etüt', exam: 'Sınav', leave: 'İzin', holiday: 'Tatil' }
export const ALL_TYPES: CalEventType[] = ['lesson', 'study', 'exam', 'leave', 'holiday']

export function weekStartOf(iso: string) {
  const [y, m, d] = iso.split('-').map(Number)
  const dt = new Date(y!, m! - 1, d!)
  return addDays(iso, -((dt.getDay() + 6) % 7))
}

/** Tüm gün olayı verilen günü kapsıyor mu (uçlar dahil). */
export const covers = (e: CalEvent, day: string) => day >= e.date && day <= (e.end_date ?? e.date)

export const isoWeekday = (iso: string) => ((new Date(`${iso}T12:00:00`).getDay() + 6) % 7) + 1
