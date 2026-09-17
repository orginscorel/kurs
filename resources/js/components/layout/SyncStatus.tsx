import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, CloudOff, Link2Off, RefreshCw } from 'lucide-react'
import { api, isLocalNode } from '@/lib/api'
import { cn } from '@/lib/cn'
import { dateTime, num, relative } from '@/lib/format'
import { Button } from '@/components/ui/Button'

/**
 * Yerel kurulum (KURS_NODE=local) eşitleme göstergesi: Çevrimdışı / Eşitleniyor / Eşitlendi · N bekleyen.
 * Sunucuda (web) hiç görünmez ve istek atmaz: düğüm türü sayfa kabuğundaki meta etiketinden okunur.
 */
export { isLocalNode }

type LocalStatus = {
  node: 'local' | 'server'
  phase?: 'idle' | 'syncing' | 'offline' | 'error' | 'revoked' | 'unpaired' | 'stale'
  paired?: boolean
  last_success_at?: string | null
  last_error?: string | null
  pending?: number
  rejected?: number
  open_conflicts?: number | null
  server_url?: string | null
  device_code?: string | null
  next_attempt_at?: string | null
}

export function SyncStatus() {
  if (!isLocalNode()) return null
  return <SyncStatusInner />
}

function SyncStatusInner() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const [, tick] = useState(0)

  const { data } = useQuery({
    queryKey: ['sync', 'local-status'],
    queryFn: () => api.get<LocalStatus>('/sync/local-status'),
    refetchInterval: 10_000,
    refetchIntervalInBackground: false,
  })
  const syncNow = useMutation({
    mutationFn: () => api.post<LocalStatus>('/sync/local-sync-now'),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['sync', 'local-status'] }),
  })

  // "2 dk önce" metni tazelensin
  useEffect(() => {
    const t = setInterval(() => tick((n) => n + 1), 30_000)
    return () => clearInterval(t)
  }, [])
  useEffect(() => {
    if (!open) return
    const onDoc = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [open])

  if (!data || data.node !== 'local') return null

  const pending = data.pending ?? 0
  const phase = data.phase ?? 'idle'
  const view = (() => {
    switch (phase) {
      case 'syncing':
        return { icon: <RefreshCw className="size-3.5 animate-spin" />, label: 'Eşitleniyor', tone: 'text-info bg-info-soft ring-info/25' }
      case 'offline':
      case 'stale':
        return { icon: <CloudOff className="size-3.5" />, label: 'Çevrimdışı', tone: 'text-warning bg-warning-soft ring-warning/30' }
      case 'error':
        return { icon: <AlertTriangle className="size-3.5" />, label: 'Eşitleme hatası', tone: 'text-danger bg-danger-soft ring-danger/25' }
      case 'revoked':
      case 'unpaired':
        return { icon: <Link2Off className="size-3.5" />, label: phase === 'revoked' ? 'Cihaz erişimi iptal' : 'Eşleştirilmedi', tone: 'text-danger bg-danger-soft ring-danger/25' }
      default:
        return {
          icon: <CheckCircle2 className="size-3.5" />,
          label: data.last_success_at ? `Eşitlendi · ${relative(data.last_success_at)}` : 'Eşitlendi',
          tone: 'text-success bg-success-soft ring-success/25',
        }
    }
  })()

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={cn('inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-[var(--radius-sm)] px-2 sm:px-2.5 text-[12.5px] font-medium ring-1 transition-colors', view.tone)}
        aria-label="Eşitleme durumu"
        title={view.label}
      >
        {view.icon}
        <span className="hidden max-w-[240px] truncate sm:inline">{view.label}</span>
        {pending > 0 && (
          <span className="rounded-[4px] bg-surface px-1.5 text-[11px] leading-[18px] tabular text-ink ring-1 ring-line">
            {num(pending)}<span className="hidden sm:inline"> bekleyen</span>
          </span>
        )}
      </button>
      {open && (
        <div className="fixed inset-x-3 top-[60px] z-40 rounded-[var(--radius-md)] bg-surface p-3.5 text-[13px] shadow-[var(--shadow-pop,0_8px_24px_rgb(0_0_0/0.12))] ring-1 ring-line sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-1.5 sm:w-[320px]">
          <div className="mb-1 flex items-center gap-1.5 font-medium sm:hidden">{view.icon}<span>{view.label}</span></div>
          <div className="mb-2 font-semibold text-ink">Yerel kurulum eşitlemesi</div>
          <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-[12.5px]">
            <dt className="text-ink-3">Son başarılı</dt>
            <dd className="text-ink">{data.last_success_at ? `${relative(data.last_success_at)} · ${dateTime(data.last_success_at)}` : 'Henüz yok'}</dd>
            <dt className="text-ink-3">Bekleyen</dt>
            <dd className="text-ink tabular">{num(pending)} değişiklik</dd>
            {(data.rejected ?? 0) > 0 && (
              <>
                <dt className="text-ink-3">Reddedilen</dt>
                <dd className="text-danger tabular">{num(data.rejected ?? 0)} değişiklik</dd>
              </>
            )}
            {data.open_conflicts != null && (
              <>
                <dt className="text-ink-3">Açık çakışma</dt>
                <dd className="text-ink tabular">{num(data.open_conflicts)}</dd>
              </>
            )}
            <dt className="text-ink-3">Cihaz</dt>
            <dd className="truncate text-ink">{data.device_code ?? '—'}</dd>
            {data.next_attempt_at && phase !== 'idle' && new Date(data.next_attempt_at).getTime() > Date.now() && (
              <>
                <dt className="text-ink-3">Yeniden deneme</dt>
                <dd className="text-ink">{relative(data.next_attempt_at)}</dd>
              </>
            )}
          </dl>
          {data.last_error && phase !== 'idle' && <p className="mt-2 rounded-[var(--radius-sm)] bg-surface-2 p-2 text-[12px] text-ink-2 ring-1 ring-line">{data.last_error}</p>}
          <p className="mt-2 text-[12px] leading-relaxed text-ink-3">
            İnternet yokken çalışmaya devam edebilirsiniz; bağlantı gelince değişiklikler otomatik gönderilir. Mesaj gönderimi ve fatura yalnız web'de yapılır.
          </p>
          <Button className="mt-3 w-full" size="sm" variant="primary" icon={<RefreshCw className="size-3.5" />} loading={syncNow.isPending} onClick={() => syncNow.mutate()} disabled={!data.paired}>
            Şimdi eşitle
          </Button>
        </div>
      )}
    </div>
  )
}
