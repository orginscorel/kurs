import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, BellOff, CalendarClock, CheckCheck, CheckCircle2, CreditCard, GraduationCap, Info, Megaphone, UserX } from 'lucide-react'
import { api } from '@/lib/api'
import { relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Button } from '@/components/ui/Button'
import { EmptyState, Skeleton } from '@/components/ui/feedback'

type Notification = {
  id: number
  type: 'info' | 'success' | 'warning' | 'critical' | 'payment' | 'academic' | 'attendance' | 'lesson' | 'announcement'
  title: string
  body: string | null
  action_url: string | null
  read_at: string | null
  created_at: string
}

const icons: Record<Notification['type'], { icon: ReactNode; className: string }> = {
  info: { icon: <Info />, className: 'bg-info-soft text-info' },
  success: { icon: <CheckCircle2 />, className: 'bg-success-soft text-success' },
  warning: { icon: <AlertTriangle />, className: 'bg-warning-soft text-warning' },
  critical: { icon: <AlertTriangle />, className: 'bg-danger-soft text-danger' },
  payment: { icon: <CreditCard />, className: 'bg-success-soft text-success' },
  academic: { icon: <GraduationCap />, className: 'bg-primary-soft text-primary-ink' },
  attendance: { icon: <UserX />, className: 'bg-warning-soft text-warning' },
  lesson: { icon: <CalendarClock />, className: 'bg-info-soft text-info' },
  announcement: { icon: <Megaphone />, className: 'bg-accent-soft text-accent' },
}

/**
 * Üst çubuk bildirim zili (yönetim paneli ve portallar).
 * `mapUrl`: bildirimin bağlantısını bu kabukta açılabilecek adrese çevirir; null dönerse yalnız okundu işaretlenir.
 */
export function NotificationCenter({ trigger, mapUrl, className }: { trigger: ReactNode; mapUrl?: (url: string) => string | null; className?: string }) {
  const [open, setOpen] = useState(false)
  const [filter, setFilter] = useState<'all' | 'unread'>('all')
  const [top, setTop] = useState(64) // dar ekranda panel zilin hemen altında açılır
  const ref = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()
  const qc = useQueryClient()

  const counts = useQuery({
    queryKey: ['notifications', 'count'],
    queryFn: () => api.get<{ unread: number }>('/notifications/unread-count'),
    refetchInterval: 60_000,
  })

  const list = useQuery({
    queryKey: ['notifications', 'list', filter],
    queryFn: () => api.get<{ data: Notification[] }>('/notifications', { filter }),
    enabled: open,
  })

  const markRead = useMutation({
    mutationFn: (ids: number[] | 'all') => api.post('/notifications/read', ids === 'all' ? { all: true } : { ids }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false)
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])

  const unread = counts.data?.unread ?? 0

  return (
    <div ref={ref} className={cn('relative', className)}>
      <Button variant="ghost" size="icon" onClick={(e) => { setTop(Math.round(e.currentTarget.getBoundingClientRect().bottom + 8)); setOpen((v) => !v) }} aria-label={unread > 0 ? `Bildirimler, ${unread} okunmamış` : 'Bildirimler'} aria-expanded={open} className="relative">
        {trigger}
        {unread > 0 && (
          <span className="absolute right-0.5 top-0.5 grid min-w-[18px] h-[18px] place-items-center rounded-full bg-danger px-1 text-[11px] font-semibold leading-none text-white tabular ring-2 ring-surface">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </Button>

      {open && (
        <div style={{ '--nc-top': `${top}px` } as React.CSSProperties} className="fixed inset-x-3 top-[var(--nc-top)] z-40 rounded-[var(--radius-lg)] sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[400px] bg-surface ring-1 ring-line shadow-[var(--shadow-pop)] animate-slide-up overflow-hidden">
          <div className="flex items-center justify-between gap-2 px-4 py-3 border-b border-line">
            <p className="text-[14px] font-semibold">Bildirimler</p>
            <div className="flex items-center gap-1">
              {(['all', 'unread'] as const).map((f) => (
                <button
                  key={f}
                  type="button"
                  onClick={() => setFilter(f)}
                  className={cn('h-7 rounded-full px-2.5 text-[12px] font-medium', filter === f ? 'bg-surface-2 text-ink' : 'text-ink-3 hover:text-ink')}
                >
                  {f === 'all' ? 'Tümü' : 'Okunmamış'}
                </button>
              ))}
              {unread > 0 && (
                <Button size="icon-sm" variant="ghost" onClick={() => markRead.mutate('all')} aria-label="Tümünü okundu işaretle" title="Tümünü okundu işaretle">
                  <CheckCheck className="size-4" />
                </Button>
              )}
            </div>
          </div>
          <div className="max-h-[min(440px,calc(100dvh-160px))] overflow-y-auto scroll-thin">
            {list.isLoading ? (
              <div className="space-y-3 p-4">
                {Array.from({ length: 4 }).map((_, i) => (
                  <Skeleton key={i} className="h-12" />
                ))}
              </div>
            ) : (list.data?.data.length ?? 0) === 0 ? (
              <EmptyState compact icon={<BellOff />} title={filter === 'unread' ? 'Okunmamış bildirim yok' : 'Henüz bildirim yok'} />
            ) : (
              list.data!.data.map((n) => {
                const meta = icons[n.type] ?? icons.info
                return (
                  <button
                    key={n.id}
                    type="button"
                    onClick={() => {
                      if (!n.read_at) markRead.mutate([n.id])
                      const target = n.action_url ? (mapUrl ? mapUrl(n.action_url) : n.action_url) : null
                      if (target) {
                        setOpen(false)
                        navigate(target)
                      }
                    }}
                    className={cn('flex w-full gap-3 px-4 py-3 text-left border-b border-line last:border-0 hover:bg-surface-2 transition-colors', !n.read_at && 'bg-primary-soft/30')}
                  >
                    <span className={cn('grid size-8 shrink-0 place-items-center rounded-full [&_svg]:size-4', meta.className)}>{meta.icon}</span>
                    <span className="min-w-0 flex-1">
                      <span className="flex items-start justify-between gap-2">
                        <span className="text-[13.5px] font-medium text-ink">{n.title}</span>
                        {!n.read_at && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-primary" />}
                      </span>
                      {n.body && <span className="mt-0.5 block text-[12.5px] text-ink-2 line-clamp-2">{n.body}</span>}
                      <span className="mt-1 block text-[12px] text-ink-3">{relative(n.created_at)}</span>
                    </span>
                  </button>
                )
              })
            )}
          </div>
        </div>
      )}
    </div>
  )
}
