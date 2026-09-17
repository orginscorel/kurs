import type { Tone } from '@/components/ui/feedback'

// ------------------------------------------------------------------ program botu
export type WeightKey = 'spread' | 'block' | 'gaps' | 'balance' | 'homeroom' | 'hard_early' | 'consistency' | 'target'

export type BotClass = {
  id: number; name: string; level: number | null; level_label: string | null; section: string | null; track: string | null; structured: boolean
  program: string | null; homeroom: string | null
  size: number; capacity: number; weekly_hours: number; custom_hours: number; templates: { id: number; name: string }[]; template_slots: number; lessons: number; locked: number
  /** [ders, saat, sabit öğretmen] */
  demand: [number, number, number | null][]
}

export type BotTeacher = {
  id: number; name: string; employment_type: string; subjects: { id: number; name: string }[]
  max_weekly_hours: number | null; target_weekly_hours: number | null; in_timetable: boolean
  current_load: number; subject_demand: number; fixed_demand: number
}

export type BotOptions = {
  terms: { id: number; name: string; starts_on: string; ends_on: string; is_current: boolean }[]
  term_id: number | null
  classes: BotClass[]
  teachers: BotTeacher[]
  tracks: Record<string, string>
  weights: Record<WeightKey, number>
  weight_labels: Record<WeightKey, string>
  defaults: { weights: Record<WeightKey, number>; max_per_day: number; time_limit: number; seed: number }
}

export type RunStatus = 'queued' | 'running' | 'completed' | 'failed' | 'applied' | 'discarded' | 'rolled_back'

export type RunSummary = {
  id: number; status: RunStatus; status_label: string; progress: number; academic_term_id: number; class_group_ids: number[]; classes: string[]
  penalty: number | null; quality: number | null; required: number; placed: number; unplaced: number; hard_violations: number; duration_ms: number | null
  apply_from: string | null; created_by: string | null; created_at: string; finished_at: string | null; applied_at: string | null; rolled_back_at: string | null
}

export type Ref = { id: number; name: string | null }
export type ProposalItem = { weekday: number; start: string; end: string; subject: Ref; teacher: Ref; room: Ref; diff?: 'added' | 'changed' | 'same'; before?: ProposalItem | null; class_name?: string | null; external?: boolean }
export type Unplaced = { class: number; subject: number; hours: number; code: string; message: string; suggestions: string[]; class_name?: string | null; subject_name?: string | null }

export type PreviewClass = {
  id: number; name: string; size: number | null; slots: number | null
  items: ProposalItem[]; locked: ProposalItem[]; removed: ProposalItem[]
  counts: { added: number; changed: number; same: number; removed: number }
  unplaced: Unplaced[]
}

export type RunDetail = {
  run: RunSummary & {
    settings: { weights: Record<WeightKey, number>; max_per_day: number; time_limit: number; seed: number }
    log: { t: string; m: string }[]; error: string | null; started_at: string | null
    snapshot: { created: number; ended: number; removed_sessions: number } | null
  }
  preview: null | {
    classes: PreviewClass[]
    unplaced: Unplaced[]
    teacher_loads: TeacherLoad[]
    external: ExternalLesson[]
    components: Record<WeightKey, { label: string; raw: number; weight: number; weighted: number }>
    stats: { iterations: number; accepted: number; ejections: number; repairs: number; construction_ms: number; search_ms: number; initial_penalty: number; total_ms: number }
    warnings: string[]
    violations: string[]
  }
}

export type TeacherLoad = { teacher: number; name: string | null; units: number; fixed: number; total: number; max: number; target: number | null; per_day: Record<string, number>; gap_hours: number }
export type ExternalLesson = { teacher: number; weekday: number; start: string; end: string; class_name: string | null; subject_name: string | null; room_name: string | null }

export const runTone: Record<RunStatus, Tone> = {
  queued: 'neutral', running: 'info', completed: 'primary', failed: 'danger', applied: 'success', discarded: 'neutral', rolled_back: 'neutral',
}

// ------------------------------------------------------------------ zaman şablonları
export type Period = [string, string]
export type TemplateDays = Record<string, Period[]>
export type TimeTemplateRow = {
  id: number; name: string; description: string | null; days: TemplateDays; levels: number[]; is_active: boolean; slot_count: number
  generator: GeneratorInput | null
  classes: { id: number; name: string }[]
}
export type TemplateClass = { id: number; name: string; level: number | null; template_ids: number[] }
export type GeneratorInput = { weekdays: number[]; start: string; lesson_minutes: number; break_minutes: number; count: number; lunch_after: number | null; lunch_minutes: number | null }

// ------------------------------------------------------------------ yedek öğretmen / telafi
export type SubstituteCandidate = { id: number; name: string; competent: boolean; score: number; reasons: string[]; day_load: number; week_load: number; max_week: number }
export type SubstituteData = {
  session: { id: number; date: string; starts_at: string; ends_at: string; subject: string | null; class_group: string | null; classroom: string | null; teacher: string | null; teacher_on_leave: boolean }
  candidates: SubstituteCandidate[]
  others: SubstituteCandidate[]
  excluded: { busy: number; leave: number; unavailable: number; max: number }
}
export type FreeSlot = { date: string; weekday: number; starts_at: string; ends_at: string; classroom_id: number; classroom: string; same_room: boolean; class_load: number; note: string | null }
export type LeaveImpact = {
  leaves: { id: number; teacher_id: number; teacher: string; starts_on: string; ends_on: string; kind: string; reason: string | null }[]
  sessions: { id: number; date: string; starts_at: string; ends_at: string; subject: string | null; class_group: string | null; classroom: string | null; teacher_id: number; teacher: string | null; attendance_taken: boolean; on_leave: boolean }[]
}

// ------------------------------------------------------------------ yardımcılar
export const pad = (n: number) => String(n).padStart(2, '0')
export const minutesOf = (t: string) => {
  const [h, m] = t.split(':').map(Number)
  return (h ?? 0) * 60 + (m ?? 0)
}
export const timeOf = (min: number) => `${pad(Math.floor(min / 60))}:${pad(min % 60)}`

/** Hızlı oluşturucu — backend TimeTemplateService::generate ile aynı kural. */
export function generatePeriods(g: GeneratorInput): TemplateDays {
  const len = Math.max(20, Math.min(120, g.lesson_minutes))
  const brk = Math.max(0, Math.min(60, g.break_minutes))
  const count = Math.max(1, Math.min(14, g.count))
  const periods: Period[] = []
  let t = minutesOf(g.start)
  for (let k = 1; k <= count; k++) {
    if (t + len > 24 * 60) break
    periods.push([timeOf(t), timeOf(t + len)])
    t += len + (g.lunch_after && k === g.lunch_after && g.lunch_minutes ? g.lunch_minutes : brk)
  }
  const out: TemplateDays = {}
  ;[...new Set(g.weekdays)].sort().forEach((wd) => (out[String(wd)] = periods.map((p) => [...p] as Period)))
  return out
}

/** "12-A" → 12 */
export const levelOf = (name: string): number | null => {
  const m = name.match(/^\s*(\d{1,2})\b/)
  return m ? Number(m[1]) : null
}

// ------------------------------------------------------------------ sınıf yapısı
export type SectionSpec = {
  code: string; track: string | null; capacity: number; program_id: number | null; time_template_ids: number[]
  homeroom_classroom_id: number | null; advisor_teacher_id: number | null; short_name: string | null; color: string | null
}
export type LevelSpec = { grade: number; label?: string; sectioned: boolean; sections: SectionSpec[] }
export type Structure = { default_capacity: number; track_defaults: Record<string, string>; levels: LevelSpec[] }
export type PlanChange = { field: string; label: string; from: unknown; to: unknown }
export type PlanRow = {
  key: string; name: string; grade: number; section: string; track: string | null; capacity: number
  action: 'create' | 'update' | 'ok' | 'reactivate'; class_group_id: number | null; current_name: string | null; changes: PlanChange[]
  current: null | {
    id: number; name: string; size: number; capacity: number; track: string | null; program: string | null; program_id: number; is_active: boolean
    weekly_hours: number; custom_hours: number; template_slots: number; templates: { id: number; name: string }[]
  }
}
export type StructurePayload = {
  term: { id: number; name: string }
  terms: { id: number; name: string; is_current: boolean }[]
  customized: boolean
  /** en az bir seviye tanımlı mı (false → boş durum + "Seviye ekle") */
  configured?: boolean
  structure: Structure
  plan: PlanRow[]
  unstructured: { id: number; name: string; track: string | null }[]
  options: {
    tracks: Record<string, string>
    colors: string[]
    programs: { id: number; name: string; code: string }[]
    templates: { id: number; name: string; slots: number; levels: number[] }[]
    classrooms: { id: number; name: string; capacity: number }[]
    teachers: { id: number; name: string }[]
    subjects: { id: number; name: string; code: string }[]
    level_programs: Record<string, string>
    track_programs: Record<string, string>
  }
  track_curricula: Record<string, Record<string, number>>
}
export type CurriculumRow = {
  subject_id: number; name: string; code: string; is_hard: boolean; program_hours: number | null; hours: number; overridden: boolean
  teacher_id: number | null; max_per_day: number | null; block_size: number | null; suggested: number | null; in_program: boolean
  teachers: { id: number; name: string }[]
}
export type CurriculumData = {
  class: { id: number; name: string; grade_level: number | null; section: string | null; track: string | null; track_label: string | null; program: string | null; program_id: number; templates: { id: number; name: string }[] }
  slots: number; rows: CurriculumRow[]; total: number; program_total: number; suggestion_total: number; overrides: number
}

export const levelLabel = (grade: number) => (grade === 13 ? 'Mezun' : `${grade}. sınıf`)
export const sectionName = (grade: number, code: string) => {
  const prefix = grade === 13 ? 'Mezun' : String(grade)
  return code === '-' ? prefix : `${prefix}-${code}`
}
