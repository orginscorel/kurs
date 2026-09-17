export type Risk = 'low' | 'medium' | 'high' | null

export type StudentCard = {
  id: number
  full_name: string
  student_no: string
  gender: string | null
  status: string
  status_label: string
  photo_url: string | null
  avg_net: number | null
  exam_count: number
  risk: Risk
  sibling: boolean
  pinned: boolean
  joined_on: string | null
  class_group_id: number | null
  section: string | null
  grade_mismatch: boolean
  other_class: { id: number; name: string } | null
}

export type WaitCard = StudentCard & {
  entry_id: number
  preferred_section: string | null
  source: string
  source_label: string
  reason: string | null
  waiting_since: string
}

export type SectionData = {
  section: string
  name: string
  track: string | null
  class_group: { id: number; name: string; capacity: number; program: string | null; track: string | null } | null
  count: number
  female: number
  male: number
  avg_net: number | null
  scored: number
  high_risk: number
  students: StudentCard[]
}

export type LevelData = {
  level: number
  label: string
  sectioned: boolean
  ready: boolean
  sections: SectionData[]
  unplaced: StudentCard[]
  waitlist: WaitCard[]
}

export type TermOption = { id: number; name: string; starts_on: string | null; ends_on: string | null; is_current: boolean }

export type RunRow = {
  id: number
  kind: 'auto' | 'promotion' | 'restructure'
  kind_label: string
  grade_levels: number[] | null
  summary: Record<string, unknown>
  applied_by: string | null
  created_at: string
  reverted_at: string | null
  can_revert: boolean
}

export type ClassSettings = { capacity: number; sections: string[]; levels: number[]; siblings_apart: boolean }

export type Overview = {
  term: TermOption
  terms: TermOption[]
  settings: ClassSettings
  customized: boolean
  /** en az bir seviye tanımlı mı (false → "Sınıf yapınızı tanımlayın") */
  configured?: boolean
  missing: string[]
  levels: LevelData[]
  runs: RunRow[]
}

export type SectionMetric = {
  section: string
  capacity: number
  size: number
  female: number
  male: number
  other: number
  avg_score: number | null
  scored: number
  high_risk: number
  sibling_pairs: number
}

export type PreviewChange = {
  student_id: number
  full_name: string
  student_no: string
  gender: string | null
  avg_net: number | null
  risk: Risk
  from: string | null
  to: string | null
  to_waitlist: boolean
  kind: 'moved' | 'placed' | 'waitlist'
}

export type PreviewLevel = {
  level: number
  skipped: boolean
  warnings: string[]
  candidates?: number
  moved?: number
  placed?: number
  waitlisted?: number
  before?: SectionMetric[]
  after?: SectionMetric[]
  score_before?: number | null
  score_after?: number
  changes?: PreviewChange[]
}

export type PreviewData = {
  term_id: number
  mode: 'unplaced' | 'redistribute'
  siblings_apart: boolean
  hash: string
  levels: PreviewLevel[]
  totals: { moved: number; placed: number; waitlisted: number }
}

export type ClassOption = { class_group_id: number; name: string; section: string; capacity: number; count: number; full: boolean; is_current: boolean }

export type HistoryRow = {
  id: number
  class_group_id: number
  class_name: string
  term: string | null
  joined_on: string
  left_on: string | null
  reason: string | null
  changed_by: string | null
  pinned: boolean
  is_current: boolean
  class_active: boolean
}

export type StudentPanelData = {
  term: { id: number; name: string; starts_on: string | null } | null
  student?: { id: number; full_name: string; school_grade: string | null; status: string }
  current: { class_group_id: number; name: string; section: string; grade_level: number; joined_on: string; pinned: boolean } | null
  unstructured_class?: { class_group_id: number; name: string } | null
  level: number | null
  options: ClassOption[]
  waitlist: { id: number; preferred_section: string | null; reason: string | null; source_label: string; created_at: string } | null
  history: HistoryRow[]
}

export type SwapSuggestion = {
  student_id: number
  full_name: string
  student_no: string
  gender: string | null
  avg_net: number | null
  risk: Risk
  delta: number
  effect: string
}

export type ChangePreview = {
  target: { class_group_id: number; name: string; section: string; capacity: number; count: number }
  current: { class_group_id: number; name: string; joined_on: string } | null
  full: boolean
  requires_reason: boolean
  suggestions: SwapSuggestion[]
  term: { id: number; starts_on: string | null }
}

export type PromotionPreview = {
  from: { id: number; name: string; ends_on: string | null }
  to: { id: number; name: string; starts_on: string | null }
  levels: { from: number; to: string; count: number; unplaced: number }[]
  graduates: { level: number; count: number; students: { id: number; full_name: string; class: string | null }[] }
  overflow: { id: number; full_name: string; class: string }[]
  skipped: { count: number; students: { id: number; full_name: string; reason: string }[] }
  targets: Record<string, number>
  missing_classes: string[]
  hash: string
}

export function genderShort(g: string | null): string {
  return g === 'female' ? 'Kız' : g === 'male' ? 'Erkek' : '—'
}

export function runSummary(r: RunRow): string {
  const s = r.summary as Record<string, number | string | undefined>
  if (r.kind === 'promotion') return `${s.promoted ?? 0} öğrenci üst sınıfa · ${s.graduated ?? 0} mezun`
  return `${s.moved ?? 0} taşındı · ${s.placed ?? 0} yerleşti · ${s.waitlisted ?? 0} bekleme listesine`
}

/** 13 = Mezun */
export const levelLabel = (level: number) => (level === 13 ? 'Mezun' : `${level}. sınıf`)
/** Şube görünen adı: "10-A", şubesiz "11", mezun "Mezun-A" */
export const sectionName = (level: number, section: string) => {
  const prefix = level === 13 ? 'Mezun' : String(level)
  return section === '-' ? prefix : `${prefix}-${section}`
}
export const TRACK_LABELS: Record<string, string> = { SAY: 'Sayısal', EA: 'Eşit ağırlık', SOZ: 'Sözel', DIL: 'Dil', TYT: 'TYT', LGS: 'LGS' }
