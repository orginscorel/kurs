import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { RotateCcw } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Drawer } from '@/components/ui/overlay'

/** Yerel kurulumda sunucunun reddettiği değişiklikler (App\Sync\Local\RejectedRetry::list) */
type RejectedRow = {
  id: number
  kind: 'row' | 'command'
  table: string
  table_label: string
  op_label: string
  label: string | null
  reason: string | null
  code: string | null
  attempts: number
  auto: boolean
  next_auto_at: string | null
  created_at: string | null
}

/**
 * "N reddedilen" ayrıntısı: satır başına ve toplu "Yeniden dene". Deneme bir sonraki eşitleme turunda
 * (istek anında tetiklenir) yapılır; liste birkaç saniyede bir tazelenir.
 */
export function RejectedChangesDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['sync', 'local-rejected'],
    queryFn: () => api.get<{ data: RejectedRow[] }>('/sync/local-rejected'),
    enabled: open,
    refetchInterval: open ? 4000 : false,
  })
  const retry = useMutation({
    mutationFn: (ids: number[] | null) => api.post<{ message: string }>('/sync/local-rejected/retry', ids ? { ids } : {}),
    onSuccess: (r) => {
      toast.success(r.message)
      setTimeout(() => {
        qc.invalidateQueries({ queryKey: ['sync', 'local-rejected'] })
        qc.invalidateQueries({ queryKey: ['sync', 'local-status'] })
      }, 2500)
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Yeniden deneme istenemedi.'),
  })
  const rows = data?.data ?? []
  const hasCommand = rows.some((r) => r.kind === 'command')

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={560}
      title="Reddedilen değişiklikler"
      description="Sunucunun kabul etmediği yerel değişiklikler. Satır değişiklikleri kendiliğinden yeniden denenir; hemen denemek için “Yeniden dene”."
    >
      <div className="flex flex-col gap-3">
        {rows.length > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="text-[12.5px] text-ink-3">{rows.length} değişiklik</span>
            <Button size="sm" variant="primary" icon={<RotateCcw className="size-3.5" />} loading={retry.isPending && retry.variables === null} onClick={() => retry.mutate(null)}>
              Tümünü yeniden dene
            </Button>
          </div>
        )}
        {hasCommand && (
          <Alert tone="warning">
            Finans işlemleri (tahsilat, iade, kayıt…) kendiliğinden yeniden denenmez: reddedildiğinde bu cihazda geri alınmıştır. Aynı işlemi elle yeniden girdiyseniz <b>yeniden denemeyin</b>; aksi hâlde iki kez işlenebilir.
          </Alert>
        )}
        {isLoading ? (
          <Skeleton className="h-32" />
        ) : rows.length === 0 ? (
          <EmptyState icon={<RotateCcw />} title="Reddedilen değişiklik yok" description="Tüm değişiklikler sunucuya ulaştı." />
        ) : (
          <ul className="-mx-5 divide-y divide-line border-y border-line">
            {rows.map((r) => (
              <li key={r.id} className="flex flex-wrap items-start gap-x-3 gap-y-2 px-5 py-3">
                <div className="min-w-0 flex-1 basis-[220px]">
                  <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                    <span className="font-medium text-ink">{r.table_label}</span>
                    <Badge tone={r.kind === 'command' ? 'warning' : 'neutral'}>{r.op_label}</Badge>
                    {r.attempts > 0 && <Badge tone="neutral">{r.attempts}. deneme</Badge>}
                  </div>
                  {r.label && <p className="mt-0.5 break-words text-[13px] text-ink-2">{r.label}</p>}
                  {r.reason && <p className="mt-1 break-words text-[12.5px] text-danger">{r.reason}</p>}
                  <p className="mt-1 text-[12px] text-ink-3">
                    {r.created_at ? dateTime(r.created_at) : '—'}
                    {r.auto && r.next_auto_at ? ` · kendiliğinden deneme ${relative(r.next_auto_at)}` : r.kind === 'command' ? ' · yalnız elle denenir' : ' · otomatik deneme sınırı doldu'}
                  </p>
                </div>
                <Button size="sm" variant="ghost" className="ml-auto" icon={<RotateCcw className="size-3.5" />} loading={retry.isPending && Array.isArray(retry.variables) && retry.variables[0] === r.id} onClick={() => retry.mutate([r.id])}>
                  Yeniden dene
                </Button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Drawer>
  )
}
