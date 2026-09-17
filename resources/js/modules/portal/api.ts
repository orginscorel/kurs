import { useQuery } from '@tanstack/react-query'
import { create } from 'zustand'
import { api } from '@/lib/api'
import { useAuth } from '@/app/auth'
import type { Tone } from '@/components/ui/feedback'

export type PortalRole = 'student' | 'guardian'

export type PortalChild = {
  id: number; full_name: string; first_name: string; student_no: string; photo_url: string | null
  class_groups: string[]; relationship: string | null; is_primary: boolean | null; status_label: string
}

export type PortalContext = {
  role: PortalRole
  guardian: { full_name: string; first_name: string; last_name: string } | null
  selected_student_id: number
  students: PortalChild[]
}

const storageKey = (userId: number) => `portal.student.${userId}`

function readStored(userId: number): number | null {
  try {
    const v = Number(sessionStorage.getItem(storageKey(userId)))
    return Number.isInteger(v) && v > 0 ? v : null
  } catch {
    return null
  }
}

/**
 * Veli portalında seçili öğrenci (kardeşler arasında geçiş). Sunucu `student_id`'yi yalnız velinin
 * kendi çocukları arasından kabul eder; burada tutulan değer yalnız bir tercihtir. Tercih oturumdaki
 * kullanıcıya bağlıdır (aynı sekmede başka hesapla girişte taşınmaz).
 */
type StudentStore = {
  userId: number | null
  studentId: number | null
  init: (userId: number) => void
  set: (userId: number, id: number | null) => void
  reset: () => void
}

export const usePortalStudent = create<StudentStore>((set, get) => ({
  userId: null,
  studentId: null,
  init: (userId) => {
    if (get().userId !== userId) set({ userId, studentId: readStored(userId) })
  },
  set: (userId, id) => {
    try {
      if (id) sessionStorage.setItem(storageKey(userId), String(id))
      else sessionStorage.removeItem(storageKey(userId))
    } catch {
      /* depolama kapalı olabilir */
    }
    set({ userId, studentId: id })
  },
  reset: () => set({ userId: null, studentId: null }),
}))

export function usePortalRole(): PortalRole {
  return useAuth((s) => (s.me?.user.user_type === 'guardian' ? 'guardian' : 'student'))
}

/** Metin sesi: öğrenciye "sen", veliye öğrenci hakkında üçüncü şahıs. */
export function useVoice() {
  const role = usePortalRole()
  return (student: string, guardian: string) => (role === 'guardian' ? guardian : student)
}

/** Oturumdaki kullanıcıya ait seçili öğrenci (veli değilse null). */
export function useSelectedStudentId(): number | null {
  const role = usePortalRole()
  const meId = useAuth((s) => s.me?.user.id ?? null)
  const { userId, studentId } = usePortalStudent()
  if (role !== 'guardian' || !meId) return null
  // Depo henüz bu kullanıcı için kurulmadıysa kayıtlı tercih okunur (depo, kabuğun effect'inde kurulur)
  return userId === meId ? studentId : readStored(meId)
}

export function usePortalContext(enabled = true) {
  const role = usePortalRole()
  const studentId = useSelectedStudentId()
  return useQuery({
    queryKey: ['portal', 'context', studentId],
    queryFn: () => api.get<PortalContext>('/portal/context', studentId ? { student_id: studentId } : undefined),
    staleTime: 60_000,
    // Bağlam (kardeş listesi) yalnız veli portalında gerekir
    enabled: enabled && role === 'guardian',
    retry: false,
  })
}

export type ClassGroupRow = { id: number; name: string; program: string | null; classroom: string | null; advisor: string | null }
export type Identity = { full_name: string; first_name: string; student_no: string; photo_url: string | null; class_groups: ClassGroupRow[]; counselor: string | null }

export type LessonRow = {
  id: number; date: string; starts_at: string; ends_at: string; status: string; cancel_reason: string | null; topic_note: string | null
  subject: string; subject_color: string | null; classroom: string | null; class_group: string | null; teacher: string | null
  attendance: string | null; attendance_label: string | null
}
export type StudyRow = { id: number; kind: string; starts_at: string; ends_at: string; topic: string | null; status: string; subject: string | null; classroom: string | null; teacher: string | null }

export type ExamRow = {
  id: number; name: string; exam_date: string; publisher: string | null; type: string; type_name: string
  net: string; score: string | null; correct: number; wrong: number; blank: number
  institution_rank: number | null; class_rank: number | null; participant_count: number
  sections: { code: string; name: string; net: string; correct: number; wrong: number; blank: number }[]
}

export type FinanceTotals = {
  total: string; paid: string; remaining: string; overdue: string; overdue_count: number
  next_installment: { sequence: number; due_date: string; status: string; remaining: string } | null
}

export type Announcement = { id: number; title: string; body: string; published_at: string }

export type Summary = {
  student: Identity
  today: { date: string; lessons: LessonRow[]; presence: { first_entry_at: string | null; last_exit_at: string | null; is_inside: number; minutes_inside: number } | null }
  attendance: {
    last: { date: string; status: string; status_label: string; late_minutes: number | null; starts_at: string; subject: string } | null
    total_30: number; attended_30: number; absent_30: number; late_30: number
  }
  last_exam: ExamRow | null
  previous_exam_net: string | null
  homework: { open_count: number; next: { id: number; title: string; due_at: string; subject: string }[] }
  finance: FinanceTotals
  announcements: Announcement[]
}

export function usePortal<T>(key: string, path: string, query?: Record<string, string | number | undefined>) {
  const role = usePortalRole()
  const sid = useSelectedStudentId()
  return useQuery({
    queryKey: ['portal', key, sid, query ?? {}],
    queryFn: () => api.get<T>(path, { ...query, student_id: sid ?? undefined }),
    staleTime: 60_000,
    // Veli: seçili öğrenci belirlenmeden istek atılmaz (kabuk bağlamı yükler)
    enabled: role === 'student' || sid !== null,
  })
}

/** PDF gibi indirmelerde seçili öğrenci parametresi. */
export function usePortalQuery(): Record<string, number> {
  const sid = useSelectedStudentId()
  return sid ? { student_id: sid } : {}
}

export const attendanceTone: Record<string, Tone> = { present: 'success', late: 'warning', absent: 'danger', excused: 'info', medical: 'info' }
export const homeworkTone: Record<string, Tone> = { assigned: 'primary', seen: 'primary', submitted: 'success', late: 'warning', missed: 'danger' }
export const installmentTone: Record<string, Tone> = { pending: 'neutral', partial: 'warning', paid: 'success', overdue: 'danger', cancelled: 'neutral' }
