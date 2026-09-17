import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CheckCheck, Megaphone } from 'lucide-react'
import { api } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useAuth } from '@/app/auth'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { usePortal, usePortalQuery, type Announcement, useVoice } from '../api'
import { PortalTitle } from '../ui'

type Row = Announcement & { is_read?: boolean }

export default function PortalAnnouncements() {
  const { data, isLoading } = usePortal<{ data: Row[]; unread: number }>('announcements', '/portal/announcements')
  const v = useVoice()
  const qc = useQueryClient()
  const imp = useAuth((s) => s.me?.impersonation)
  const extra = usePortalQuery()
  const read = useMutation({
    mutationFn: (ids: number[]) => Promise.all(ids.map((id) => api.post(`/portal/announcements/${id}/read${extra.student_id ? `?student_id=${extra.student_id}` : ''}`))),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['portal', 'announcements'] }),
  })
  const unread = (data?.data ?? []).filter((a) => a.is_read === false)

  return (
    <div className="animate-fade-in">
      <PortalTitle
        title="Duyurular"
        description={v('Kurumun sana ve sınıfına yaptığı duyurular', 'Kurumun velilere yaptığı duyurular')}
        actions={!imp && unread.length > 0 && (
          <Button variant="outline" size="sm" icon={<CheckCheck className="size-4" />} loading={read.isPending} onClick={() => read.mutate(unread.map((a) => a.id))}>Tümünü okundu say</Button>
        )}
      />
      {isLoading ? (
        <Skeleton className="h-48" />
      ) : !data?.data.length ? (
        <EmptyState icon={<Megaphone />} title="Duyuru yok" />
      ) : (
        <ul className="flex flex-col gap-2">
          {data.data.map((a) => (
            <li key={a.id} className={cn('rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line', a.is_read === false && 'ring-primary/40')}>
              <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <h2 className="flex items-center gap-2 text-[14.5px] font-semibold">{a.title}{a.is_read === false && <Badge tone="primary">Yeni</Badge>}</h2>
                <span className="text-[12.5px] text-ink-3" title={dateTime(a.published_at)}>{relative(a.published_at)}</span>
              </div>
              <p className="mt-1 whitespace-pre-line text-[14.5px] text-ink-2">{a.body}</p>
              {a.is_read === false && !imp && (
                <button className="mt-2 text-[12.5px] font-medium text-primary hover:underline" onClick={() => read.mutate([a.id])}>Okundu olarak işaretle</button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
