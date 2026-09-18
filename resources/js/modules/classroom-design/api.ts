import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Paginated } from '@/lib/api'
import type { LayoutData, LayoutDetail, LayoutListItem, LayoutStats, LayoutVersion, Roster } from './types'

/** Sunucu uçları: routes/api/classroom-design.php */

export function useLayouts(params: { classroom_id?: number | null; q?: string } = {}) {
  return useQuery({
    queryKey: ['classroom-layouts', params],
    queryFn: () => api.get<Paginated<LayoutListItem>>('/classroom-layouts', { per_page: 100, classroom_id: params.classroom_id ?? undefined, q: params.q }),
    placeholderData: keepPreviousData,
  })
}

export function useLayout(id: number | null) {
  return useQuery({
    queryKey: ['classroom-layouts', 'detail', id],
    queryFn: () => api.get<{ data: LayoutDetail }>(`/classroom-layouts/${id}`).then((r) => r.data),
    enabled: !!id,
    staleTime: Infinity,
    refetchOnWindowFocus: false,
  })
}

export function useVersions(id: number | null, enabled: boolean) {
  return useQuery({
    queryKey: ['classroom-layouts', 'versions', id],
    queryFn: () => api.get<{ data: LayoutVersion[] }>(`/classroom-layouts/${id}/versions`).then((r) => r.data),
    enabled: !!id && enabled,
  })
}

export function useRoster(classroomId: number | null, groupIds: number[] | null) {
  return useQuery({
    queryKey: ['classroom-layouts', 'roster', classroomId, groupIds],
    queryFn: () => api.get<Roster>('/classroom-layouts/roster', { classroom_id: classroomId ?? undefined, group_ids: groupIds ?? undefined }),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  })
}

export type ClassroomOption = { id: number; name: string; floor: string | null; capacity: number; kind: string; is_active: boolean }

export function useClassroomOptions() {
  return useQuery({
    queryKey: ['classroom-layouts', 'classrooms'],
    queryFn: () => api.get<Paginated<ClassroomOption>>('/classrooms', { per_page: 200, status: 'all' }).then((r) => r.data),
    staleTime: 5 * 60_000,
  })
}

export type SavePayload = {
  name: string
  classroom_id: number | null
  data: LayoutData
  stats: LayoutStats
  thumbnail?: string | null
  label?: string | null
  base_version?: number
}

export function useSaveLayout() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...body }: SavePayload & { id: number | null }) =>
      id
        ? api.put<{ message: string; version: number }>(`/classroom-layouts/${id}`, body).then((r) => ({ ...r, id }))
        : api.post<{ message: string; version: number; id: number }>('/classroom-layouts', body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['classroom-layouts'], predicate: (q) => q.queryKey[1] !== 'detail' })
    },
  })
}

export function useRestoreVersion(id: number | null) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (version: number) => api.post<{ message: string; version: number }>(`/classroom-layouts/${id}/versions/${version}/restore`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['classroom-layouts'] }),
  })
}

export function useDeleteLayout() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api.delete<{ message: string }>(`/classroom-layouts/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['classroom-layouts'] }),
  })
}

export function saveThumbnail(id: number, thumbnail: string) {
  return api.put<{ message: string }>(`/classroom-layouts/${id}/thumbnail`, { thumbnail })
}
