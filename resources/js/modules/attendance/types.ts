export type AttendanceStatus = 'present' | 'absent' | 'late' | 'excused' | 'medical'

export const STATUS_LABEL: Record<AttendanceStatus, string> = {
  present: 'Var',
  absent: 'Yok',
  late: 'Geç',
  excused: 'İzinli',
  medical: 'Raporlu',
}

export const STATUS_TONE: Record<AttendanceStatus, 'success' | 'danger' | 'warning' | 'info' | 'neutral' | 'accent'> = {
  present: 'success',
  absent: 'danger',
  late: 'warning',
  excused: 'info',
  medical: 'accent',
}

export const STATUS_KEY: Record<AttendanceStatus, string> = { present: 'V', absent: 'Y', late: 'G', excused: 'İ', medical: 'R' }

// ------------------------------------------------------------ canlı giriş/çıkış

export type InsideStudent = {
  id: number
  full_name: string
  student_no: string
  photo_path: string | null
  first_entry_at: string
  minutes_inside: number
  class_group: string | null
}

export type LiveFeedItem = {
  id: number
  event_type: 'ENTRY' | 'EXIT'
  source: string
  occurred_at: string
  is_matched: boolean
  student: { id: number; full_name: string; photo_path: string | null } | null
  device: string | null
}

export type UnmatchedEvent = {
  id: number
  device_id: number | null
  event_type: string
  source: string
  occurred_at: string
  raw_identifier: string
  device?: { id: number; name: string } | null
}

// ------------------------------------------------------------ yoklama alma

export type SessionRow = {
  id: number
  starts_at: string
  ends_at: string
  status: string
  subject: string
  subject_color: string | null
  class_group: string
  class_group_id: number
  classroom: string
  teacher: string
  roster: number
  taken: number
  present: number
  late: number
  absent: number
  excused: number
  attendance_taken_at: string | null
}

export type RosterStudent = {
  student_id: number
  full_name: string
  student_no: string
  photo_path: string | null
  status: AttendanceStatus | null
  late_minutes: number | null
  note: string | null
  method: string | null
  first_entry_at: string | null
  /** Disiplin: o gün uzaklaştırmadaysa açıklama (ör. "Disiplin: geçici uzaklaştırma (YPT-…)") */
  discipline?: string | null
}

export type RosterResponse = {
  session: {
    id: number
    date: string
    starts_at: string
    ends_at: string
    subject: string
    class_group: string
    classroom: string
    teacher: string
    attendance_taken_at: string | null
    attendance_taken_by: number | null
  }
  students: RosterStudent[]
}

// ------------------------------------------------------------ devamsızlık

export type AbsenceRow = {
  id: number
  student_id: number
  date: string
  status: AttendanceStatus
  late_minutes: number | null
  method: string
  note: string | null
  full_name: string
  student_no: string
  subject: string
  class_group: string
}

export type AbsenceSummaryRow = {
  student_id: number
  full_name: string
  student_no: string
  total: number
  present: number
  late: number
  absent: number
  excused: number
  medical: number
  rate: number
}

export type OverThresholdRow = { student_id: number; full_name: string; student_no: string; absent_count: number }

// ------------------------------------------------------------ cihazlar

export type DeviceKind = 'fingerprint' | 'rfid' | 'qr' | 'face' | 'gateway'

export type DeviceRow = {
  id: number
  name: string
  kind: DeviceKind
  location: string | null
  direction: 'entry' | 'exit' | 'both'
  serial_no: string | null
  is_active: boolean
  is_online: boolean
  last_seen_at: string | null
  firmware: string | null
  api_token_prefix: string
  has_token: boolean
  last_event_at: string | null
  event_count: number
}

export type IdentityRow = {
  id: number
  person_type: string
  person_id: number
  kind: 'fingerprint' | 'card' | 'qr'
  identifier: string
  is_active: boolean
  full_name: string
  student_no: string
}

export const DEVICE_KIND_LABEL: Record<DeviceKind, string> = {
  fingerprint: 'Parmak izi',
  rfid: 'Kart (RFID)',
  qr: 'QR okuyucu',
  face: 'Yüz tanıma',
  gateway: 'Köprü',
}

export const IDENTITY_KIND_LABEL: Record<IdentityRow['kind'], string> = { fingerprint: 'Parmak izi', card: 'Kart UID', qr: 'QR' }
