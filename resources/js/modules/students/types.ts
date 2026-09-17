export type StudentRow = {
  id: number
  student_no: string
  full_name: string
  photo_url: string | null
  status: string
  status_label: string
  school_grade: string | null
  field: string | null
  phone: string | null
  class_groups: { id: number; name: string }[]
  guardian: { id: number; name: string; phone: string | null } | null
  tags: { id: number; name: string; color: string }[]
  open_balance: string | null
  overdue_balance: string | null
  risk_level: 'low' | 'medium' | 'high' | null
  is_inside: boolean
  registered_on: string | null
  /** Son 30 gün devam yüzdesi (yoklama yoksa null); yalnız liste ucunda hesaplanır */
  attendance_30?: number | null
  /** Son yayınlanan deneme neti ve tarihi; yalnız liste ucunda hesaplanır */
  last_exam?: { net: string; date: string } | null
  /** Güncel kaydın programı; yalnız liste ucunda hesaplanır */
  program?: string | null
}

export type StudentOptions = {
  class_groups: { id: number; name: string; program_id: number; capacity: number }[]
  programs: { id: number; name: string; code: string; kind: string }[]
  tags: { id: number; name: string; color: string }[]
  teachers: { id: number; first_name: string; last_name: string }[]
  terms: { id: number; name: string; is_current: boolean }[]
  packages: { id: number; name: string; program_id: number | null; list_price: string; default_installments: number }[]
  statuses: Record<string, string>
}

export type GuardianInput = {
  id?: number
  first_name: string
  last_name: string
  phone: string
  whatsapp_phone?: string
  email?: string
  occupation?: string
  relationship: string
  is_primary: boolean
  is_financially_responsible?: boolean
  whatsapp_consent?: boolean
  /** Ticari ileti onayı (kaynak: kayıt formu) */
  marketing_consents?: MarketingConsents | null
}

export type MarketingConsents = { sms: boolean; email: boolean; whatsapp: boolean }

export type ExamSummary = {
  id: number
  exam_id: number
  name: string
  exam_date: string
  type: string
  net: string
  score: string | null
  correct: number
  wrong: number
  blank: number
  institution_rank: number | null
  class_rank: number | null
  national_rank: number | null
  participant_count: number
  sections: { code: string; name: string; net: string; correct: number; wrong: number; blank: number }[]
}

export type StudentDetailData = {
  student: Omit<StudentRow, 'class_groups'> & {
    first_name: string
    last_name: string
    national_id_masked: string | null
    birth_date: string | null
    gender: string | null
    school_name: string | null
    target_university: string | null
    target_department: string | null
    whatsapp_phone: string | null
    email: string | null
    address: string | null
    medical_notes: string | null
    notes: string | null
    guidance_teacher: { id: number; name: string } | null
    guardians: (GuardianInput & { id: number; name: string; receives_notifications: boolean })[]
    class_groups: { id: number; name: string; program: string | null; program_color: string | null; advisor: string | null; joined_on: string }[]
    goals: { id: number; university: string | null; department: string | null; target_rank: number | null; target_tyt_net: string | null; target_ayt_net: string | null }[]
    tag_ids: number[]
    marketing_consents?: MarketingConsents
  }
  enrollments: { id: number; enrollment_no: string; program: string | null; term: string | null; class_group: string | null; status: string; enrolled_on: string; net_price: string | null }[]
  today: { first_entry_at: string | null; last_exit_at: string | null; is_inside: number; minutes_inside: number } | null
  attendance_30: { total: number; present: number; late: number; absent: number; excused: number }
  exams: ExamSummary[]
  topics: { strong: TopicStat[]; weak: TopicStat[] }
  upcoming_lessons: { id: number; starts_at: string; ends_at: string; subject: string; classroom: string; teacher: string }[]
  homework: { total: number; done: number; open: number; missed: number }
  risk: {
    score: number
    level: 'low' | 'medium' | 'high'
    factors: { key: string; label: string; value: string; points: number; weight: number }[]
    insights: { kind: string; tone: 'success' | 'warning' | 'danger' | 'info'; text: string }[]
    calculated_at: string
  }
  finance: null | {
    list_price: string
    discount: string
    total: string
    paid: string
    remaining: string
    overdue: string
    overdue_count: number
    next_installment: { id: number; due_date: string; amount: string; paid_amount: string; status: string; sequence: number } | null
  }
  counts: { guidance: number; documents: number; notes: number; messages: number; observations?: number }
}

export type TopicStat = { id: number; topic: string; subject: string; asked: number; correct: number; rate: number }

export const statusTone: Record<string, 'success' | 'warning' | 'danger' | 'neutral' | 'info' | 'primary' | 'accent'> = {
  active: 'success',
  enrolled: 'success',
  pending: 'info',
  lead: 'primary',
  interview: 'accent',
  offer: 'info',
  frozen: 'warning',
  withdrawn: 'danger',
  graduated: 'primary',
}

export const riskMeta = {
  low: { label: 'Düşük', tone: 'success' as const },
  medium: { label: 'Orta', tone: 'warning' as const },
  high: { label: 'Yüksek', tone: 'danger' as const },
}

export const fieldOptions = [
  { value: 'SAY', label: 'Sayısal' },
  { value: 'EA', label: 'Eşit Ağırlık' },
  { value: 'SOZ', label: 'Sözel' },
  { value: 'DIL', label: 'Dil' },
  { value: 'TYT', label: 'TYT' },
  { value: 'LGS', label: 'LGS' },
]

export const relationshipOptions = [
  { value: 'mother', label: 'Anne' },
  { value: 'father', label: 'Baba' },
  { value: 'guardian', label: 'Vasi' },
  { value: 'other', label: 'Diğer' },
]

/** Etiketler tek nötr tonla gösterilir (renk kalabalığı yok); anahtar yalnız geriye uyumluluk için */
export const tagColor: Record<string, string> = new Proxy({}, { get: () => 'neutral' }) as Record<string, string>

/** WhatsApp'ta sohbet açar (mesaj kuyruğu modülü gelmeden de gerçek işlev) */
export function waLink(phoneNumber: string | null | undefined, text?: string) {
  if (!phoneNumber || phoneNumber.includes('•')) return null
  let d = phoneNumber.replace(/\D/g, '')
  if (d.startsWith('0')) d = '90' + d.slice(1)
  if (d.length === 10) d = '90' + d
  return `https://wa.me/${d}${text ? `?text=${encodeURIComponent(text)}` : ''}`
}
