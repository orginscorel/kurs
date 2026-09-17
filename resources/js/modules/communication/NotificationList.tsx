import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Bell, Check } from 'lucide-react'
import { api } from '@/lib/api'
import { relative } from '@/lib/format'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'

type NotificationRow = { id: number; type: string; title: string; body: string; action_url: string | null; read_at: string | null; created_at: string }

/** Uygulama bildirimleri tam sayfa geçmişi (üst çubuktaki NotificationCenter'ın açılır listesinin genişletilmiş hâli). */
export default function NotificationList() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['notifications', 'list'], queryFn: () => api.get<{ data: NotificationRow[] }>('/notifications', { limit: 100 }) })

  const markAll = useMutation({
    mutationFn: () => api.post('/notifications/read', { all: true }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const items = data?.data ?? []
  const unread = items.filter((n) => !n.read_at).length

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Bildirimler"
        description="Sistem ve otomasyon bildirimlerinin tam geçmişi"
        actions={unread > 0 && <Button icon={<Check className="size-4" />} onClick={() => markAll.mutate()} loading={markAll.isPending}>Tümünü okundu işaretle</Button>}
      />

      <Panel flush>
        {isLoading ? (
          <div className="p-4 flex flex-col gap-3">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-12" />)}</div>
        ) : items.length === 0 ? (
          <EmptyState icon={<Bell />} title="Henüz bildirim yok" description="Otomasyonlar ve sistem olayları burada listelenir." />
        ) : (
          <ul className="divide-y divide-line">
            {items.map((n) => {
              const row = (
                <div className="flex items-start gap-3 px-4 py-3.5 hover:bg-surface-2 transition-colors">
                  {!n.read_at && <span className="mt-1.5 size-2 rounded-full bg-primary shrink-0" />}
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <p className="text-[13.5px] font-medium text-ink truncate">{n.title}</p>
                      <Badge tone="neutral">{n.type}</Badge>
                    </div>
                    <p className="mt-0.5 text-[13px] text-ink-2 line-clamp-2">{n.body}</p>
                    <p className="mt-1 text-[12px] text-ink-3">{relative(n.created_at)}</p>
                  </div>
                </div>
              )
              return <li key={n.id}>{n.action_url ? <Link to={n.action_url}>{row}</Link> : row}</li>
            })}
          </ul>
        )}
      </Panel>
    </div>
  )
}
