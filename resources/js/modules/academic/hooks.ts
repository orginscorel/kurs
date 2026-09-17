import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { AcademicOptions } from './types'

export function useAcademicOptions(enabled = true) {
  return useQuery({ queryKey: ['academic', 'options'], queryFn: () => api.get<AcademicOptions>('/academic/options'), staleTime: 5 * 60_000, enabled })
}

/** Ders seçildiyse branşı uyan öğretmenler önce; yoksa alfabetik. */
export function teacherOptions(options: AcademicOptions | undefined, subjectId?: number | null) {
  const list = (options?.teachers ?? []).filter((t) => t.is_active)
  if (!subjectId) return list.map((t) => ({ value: t.id, label: t.name }))
  const fit = list.filter((t) => t.subject_ids.includes(subjectId))
  const rest = list.filter((t) => !t.subject_ids.includes(subjectId))
  return [...fit.map((t) => ({ value: t.id, label: t.name })), ...rest.map((t) => ({ value: t.id, label: `${t.name} · branş dışı` }))]
}

/** Programın ders listesi (tanımlıysa), yoksa tüm dersler. */
export function subjectOptions(options: AcademicOptions | undefined, programId?: number | null) {
  const all = (options?.subjects ?? []).filter((s) => s.is_active)
  const program = options?.programs.find((p) => p.id === programId)
  const ids = program?.subject_ids ?? []
  const list = ids.length ? all.filter((s) => ids.includes(s.id)) : all
  return list.map((s) => ({ value: s.id, label: s.name }))
}
