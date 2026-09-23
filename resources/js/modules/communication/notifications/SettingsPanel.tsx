import { useMemo } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { BellOff, Bell } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useCan } from '@/app/auth'
import { Panel } from '@/components/ui/layout'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Checkbox, Switch } from '@/components/ui/form'
import type { Catalog, SettingRow } from './types'

/**
 * Bildirim Ayarları: her olay için aç/kapa, "onay gerekli", ve hangi kitlelere gideceğini seçtirir.
 * Ayarlar web.only (409) döndürebilir; masaüstünde düzenleme kilitli, okuma serbest.
 */
export default function SettingsPanel({ catalog }: { catalog?: Catalog }) {
  const can = useCan()
  const qc = useQueryClient()
  const editable = can('templates.manage')

  const { data, isLoading } = useQuery({ queryKey: ['notif', 'settings'], queryFn: () => api.get<{ data: SettingRow[] }>('/notifications/settings') })

  const save = useMutation({
    mutationFn: (p: { event_type: string; patch: Partial<Pick<SettingRow, 'enabled' | 'require_approval' | 'channels' | 'audiences'>> }) =>
      api.put<{ data: SettingRow }>(`/notifications/settings/${encodeURIComponent(p.event_type)}`, p.patch),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notif', 'settings'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Ayar kaydedilemedi.'),
  })

  // Olayları gruplara ayır (Program / Rehberlik / Ödeme ...)
  const groups = useMemo(() => {
    const rows = data?.data ?? []
    const byGroup = new Map<string, SettingRow[]>()
    for (const r of rows) {
      const g = r.group || 'Diğer'
      if (!byGroup.has(g)) byGroup.set(g, [])
      byGroup.get(g)!.push(r)
    }
    return [...byGroup.entries()]
  }, [data])

  const audienceLabels = catalog?.audiences ?? {}

  if (isLoading) return <Skeleton className="h-64" />
  if (!data?.data.length) return <EmptyState icon={<BellOff />} title="Bildirim olayı bulunamadı" description="Katalog boş görünüyor." />

  return (
    <div className="flex flex-col gap-4">
      {!editable && (
        <p className="text-[12.5px] text-ink-3">Ayarları değiştirmek için şablon yönetimi yetkisi gerekir. Yalnızca görüntülüyorsunuz.</p>
      )}
      {groups.map(([group, rows]) => (
        <Panel key={group} title={group} flush>
          <div className="divide-y divide-line">
            {rows.map((r) => {
              const saving = save.isPending && save.variables?.event_type === r.event_type
              return (
                <div key={r.event_type} className="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-start sm:justify-between">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <span className={r.enabled ? 'text-ink' : 'text-ink-3'}>
                        {r.enabled ? <Bell className="size-4 text-primary" /> : <BellOff className="size-4" />}
                      </span>
                      <p className="font-medium text-ink truncate" title={r.label}>{r.label}</p>
                      {!r.enabled && <Badge tone="neutral">Kapalı</Badge>}
                    </div>
                    <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1.5">
                      {(r.available_audiences ?? []).map((aud) => (
                        <Checkbox
                          key={aud}
                          checked={(r.audiences ?? []).includes(aud)}
                          disabled={!editable || !r.enabled || saving}
                          label={audienceLabels[aud] ?? aud}
                          onChange={(on) => {
                            const next = on ? [...new Set([...(r.audiences ?? []), aud])] : (r.audiences ?? []).filter((a) => a !== aud)
                            save.mutate({ event_type: r.event_type, patch: { audiences: next } })
                          }}
                        />
                      ))}
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-5">
                    <Switch
                      checked={r.require_approval}
                      disabled={!editable || !r.enabled || saving}
                      label={<span className="text-[12.5px] text-ink-2">Onay gerekli</span>}
                      onChange={(v) => save.mutate({ event_type: r.event_type, patch: { require_approval: v } })}
                    />
                    <Switch
                      checked={r.enabled}
                      disabled={!editable || saving}
                      label={<span className="text-[12.5px] text-ink-2">Aktif</span>}
                      onChange={(v) => save.mutate({ event_type: r.event_type, patch: { enabled: v } })}
                    />
                  </div>
                </div>
              )
            })}
          </div>
        </Panel>
      ))}
    </div>
  )
}
