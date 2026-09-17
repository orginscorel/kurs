import type { Tone } from '@/components/ui/feedback'

export type ExamStatus = 'draft' | 'answer_key_ready' | 'results_published'

export type ExamRow = {
  id: number
  name: string
  publisher: string | null
  scope: 'institution' | 'national'
  exam_date: string
  status: ExamStatus
  status_label: string
  published_at: string | null
  booklets: string[]
  participant_count: number
  type: { id: number; code: string; name: string } | null
  avg_net: number | null
  result_count: number | null
  question_total: number | null
  key_count: number | null
  /** Kurumdaki en yüksek net; liste ucunda hesaplanır */
  max_net?: number | null
  /** Bağlı dönem adı; liste ucunda hesaplanır */
  term?: string | null
}

export type ExamSectionDef = { id?: number; code: string; name: string; subject_id?: number | null; subject_code?: string | null; question_count: number; coefficient: number; questions_count?: number }

export type ExamDetailData = {
  exam: ExamRow & {
    wrong_penalty_ratio: number
    base_score: number
    academic_term_id: number | null
    created_by: string | null
    created_at: string | null
    sections: ExamSectionDef[]
    key: { total: number; cancelled: number; with_topic: number; expected: number }
  }
  overview: ExamOverview
  imports: OpticalImport[]
}

export type ExamOverview = {
  participants: number
  avg_net: number
  max_net: number
  min_net: number
  avg_score: number | null
  max_score: number | null
  avg_correct: number
  avg_wrong: number
  avg_blank: number
  sections: { id: number; code: string; name: string; question_count: number; avg_net: number; max_net: number; avg_correct: number; avg_wrong: number; avg_blank: number }[]
  classes: { id: number; name: string; participants: number; avg_net: number; max_net: number }[]
  distribution: { label: string; count: number }[]
  previous: { id: number; name: string; exam_date: string; avg_net: number } | null
}

export type ExamOptions = {
  types: { id: number; code: string; name: string; wrong_penalty_ratio: string; base_score: string; sections: { code: string; name: string; subject_code: string | null; question_count: number; coefficient: number }[] }[]
  class_groups: { id: number; name: string; program_id: number }[]
  subjects: { id: number; code: string; name: string; short_name: string | null; color: string; topics: { id: number; parent_id: number | null; name: string; outcome_code: string | null }[] }[]
  publishers: string[]
  terms: { id: number; name: string; is_current: boolean }[]
  statuses: Record<string, string>
}

export type OpticalImport = {
  id: number
  exam_id: number
  format: string
  original_name: string | null
  status: 'pending' | 'queued' | 'processing' | 'completed' | 'failed'
  total_rows: number
  matched_rows: number
  processed_rows: number
  unmatched: { row: number; student_no: string | null; name: string | null }[]
  unmatched_count: number
  report: { errors: { row: number; student_no: string | null; message: string }[]; booklets: Record<string, number>; source: string } | null
  error: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string | null
  created_by: string | null
  progress: number
  exam?: { id: number; name: string; exam_date: string; status: ExamStatus } | null
}

export type Source = { col?: number; start?: number; length?: number; key?: string; fixed?: string } | null

export type Mapping = {
  answers_mode: 'combined' | 'per_section'
  student_no: Source
  name: Source
  booklet: Source
  combined: Source
  sections: Record<string, Source>
}

export type UploadDescription = {
  import: OpticalImport
  kind: 'delimited' | 'fixed' | 'json'
  delimiter: string | null
  has_header: boolean
  headers: string[]
  sample_rows: (string[] | string | Record<string, unknown>)[]
  total_rows: number
  suggested_mapping: Mapping
  sections: { code: string; name: string; question_count: number }[]
  total_questions: number
  booklets: string[]
  layouts: { id: number; name: string; format: string; mapping: Mapping }[]
}

export type PreviewData = {
  total: number
  matched: number
  unmatched_count: number
  unmatched: { row: number; student_no: string | null; name: string | null }[]
  booklets: Record<string, number>
  blank_rows: number
  rows: { row: number; student_no: string | null; name: string | null; student: { id: number; name: string } | null; booklet: string; booklet_detected: boolean; filled: number; answers: Record<string, string> }[]
  will_queue: boolean
}

export type ResultRow = {
  id: number
  student_id: number
  student_no: string
  student_name: string
  class_group: string | null
  class_group_id: number | null
  booklet: string
  correct: number
  wrong: number
  blank: number
  net: number
  score: number | null
  institution_rank: number | null
  class_rank: number | null
  national_rank: number | null
  source: string
  sections: Record<string, { net: number; correct: number; wrong: number; blank: number }>
}

export type ResultsMeta = {
  page: number
  per_page: number
  total: number
  last_page: number
  sections: { id: number; code: string; name: string; question_count: number }[]
  summary: { participants: number; avg_net: number; max_net: number; avg_score: number | null }
  class_groups: { id: number; name: string; n: number }[]
  exam: { id: number; name: string; status: ExamStatus; exam_date: string; booklets: string[]; scope: string }
}

export type ResultDetail = {
  result: {
    id: number
    student: { id: number; no: string; name: string }
    class_group: string | null
    booklet: string
    source: string
    correct: number
    wrong: number
    blank: number
    net: number
    score: number | null
    institution_rank: number | null
    class_rank: number | null
    national_rank: number | null
    participants: number
    class_total: number | null
    updated_at: string | null
  }
  sections: { id: number; code: string; name: string; net: number; correct: number; wrong: number; blank: number; questions: { id: number; number: number; given: string; key: string; state: 'c' | 'w' | 'b' | 'x'; booklet_no: number; topic: { id: number; name: string } | null }[] }[]
  topics: { weak: TopicAgg[]; strong: TopicAgg[] }
  history: { id: number; name: string; exam_date: string; net: number; score: number | null; institution_rank: number | null }[]
}

export type TopicAgg = { id: number; name: string; section?: string; subject?: string; asked: number; correct: number; wrong: number; blank?: number; rate: number | null }

export type QuestionAnalysis = {
  participants: number
  sections: {
    id: number
    code: string
    name: string
    question_count: number
    success_pct: number
    questions: {
      id: number
      number: number
      key: string
      is_cancelled: boolean
      booklet_no: Record<string, number>
      topic: { id: number; name: string } | null
      correct: number
      wrong: number
      blank: number
      correct_pct: number
      wrong_pct: number
      blank_pct: number
      most_common_wrong: string | null
      most_common_wrong_pct: number
      distribution: Record<string, number>
    }[]
  }[]
  highlights: { tone: Tone; text: string }[]
}

export type ExamTopicAnalysis = {
  participants: number
  topics: (TopicAgg & { outcome_code: string | null; section_code: string; questions: number; numbers: string })[]
  hardest: TopicAgg[]
  easiest: TopicAgg[]
  classes: { id: number; name: string; rates: Record<string, number | null> }[]
  untagged_questions: number
}

export type CumulativeTopics = {
  topics: (TopicAgg & { outcome_code: string | null; subject_id: number; subject: string; students: number; last_exam_at: string | null })[]
  subjects: { id: number; name: string; topics: number; asked: number; correct: number; rate: number | null }[]
  hardest: TopicAgg[]
  easiest: TopicAgg[]
  classes: { id: number; name: string; students: number; subjects: Record<string, number | null>; rate: number | null }[]
  student: { strong: TopicAgg[]; weak: TopicAgg[] } | null
}

export type DashboardData = {
  generated_at: string
  kpis: Record<'TYT' | 'AYT' | 'LGS', { avg_net: number; participants: number; exam: string; exam_id: number; delta: number | null; exams: number } | null>
  totals: { published: number; results: number; students_tested: number }
  trend: { id: number; name: string; exam_date: string; scope: string; type_code: string; type_group: string; type_name: string; participants: number; avg_net: number; avg_score: number | null; max_net: number }[]
  classes: { id: number; name: string; types: Record<string, { avg_net: number; exams: number; results: number }> }[]
  movers: { up: Mover[]; down: Mover[] }
  topics: { hardest: TopicAgg[]; easiest: TopicAgg[]; subjects: { id: number; name: string; rate: number | null; asked: number }[] }
  upcoming: { id: number; name: string; exam_date: string; status: ExamStatus; type_code: string | null; publisher: string | null }[]
}

export type Mover = { student_id: number; name: string; class_name: string | null; type_group: string; from: number; to: number; delta: number; exams: number; nets: number[] }

export const statusTone: Record<ExamStatus, Tone> = { draft: 'warning', answer_key_ready: 'info', results_published: 'success' }
export const statusLabel: Record<ExamStatus, string> = { draft: 'Taslak', answer_key_ready: 'Anahtar hazır', results_published: 'Yayımlandı' }
export const importStatusMeta: Record<OpticalImport['status'], { label: string; tone: Tone }> = {
  pending: { label: 'Bekliyor', tone: 'neutral' },
  queued: { label: 'Kuyrukta', tone: 'info' },
  processing: { label: 'İşleniyor', tone: 'warning' },
  completed: { label: 'Tamamlandı', tone: 'success' },
  failed: { label: 'Başarısız', tone: 'danger' },
}
export const formatLabel: Record<string, string> = { csv: 'CSV', xlsx: 'Excel', txt: 'TXT', json: 'JSON', api: 'API' }

/** Başarı oranına göre ton: <50 zayıf, <70 orta, ≥70 güçlü */
export function rateTone(rate: number | null | undefined): Tone {
  if (rate === null || rate === undefined) return 'neutral'
  return rate < 50 ? 'danger' : rate < 70 ? 'warning' : 'success'
}

export function net(value: number | string | null | undefined, digits = 2): string {
  if (value === null || value === undefined) return '—'
  return Number(value).toLocaleString('tr-TR', { minimumFractionDigits: digits, maximumFractionDigits: digits })
}
