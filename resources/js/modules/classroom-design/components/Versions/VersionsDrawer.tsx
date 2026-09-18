import { useState } from 'react'
import { toast } from 'sonner'
import { History, RotateCcw } from 'lucide-react'
import { useRestoreVersion, useVersions } from '../../api'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { ConfirmDialog, Drawer } from '@/components/ui/overlay'

/** SÜRÜMLER — her kayıt bir sürüm (V1, V2…): ad, masa sayısı, tarih; geri yükleme yeni sürüm üretir (geçmiş silinmez). */
export function VersionsDrawer({ open, onClose, layoutId, dirty, canManage, onRestored }: { open: boolean; onClose: () => void; layoutId: number | null; dirty: boolean; canManage: boolean; onRestored: () => void }) {
  const q = useVersions(layoutId, open)
  const restore = useRestoreVersion(layoutId)
  const [target, setTarget] = useState<number | null>(null)
  const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '')
  return (
    <Drawer open={open} onClose={onClose} width={420} title="Sürümler" description="Her kaydetme yeni bir sürüm oluşturur. En fazla 50 sürüm saklanır.">
      {q.isLoading && <div className="space-y-2">{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-14" />)}</div>}
      {q.data && q.data.length === 0 && <EmptyState icon={<History />} title="Henüz sürüm yok" description="Tasarımı kaydettiğinizde sürümler burada listelenir." />}
      <ul className="space-y-2">
        {q.data?.map((v) => (
          <li key={v.id} className="flex items-center gap-3 rounded-[var(--radius-sm)] border border-line px-3 py-2.5">
            <span className="grid size-9 shrink-0 place-items-center rounded-[6px] bg-surface-2 text-[12.5px] font-semibold tabular-nums text-ink">V{v.version}</span>
            <div className="min-w-0 flex-1">
              <div className="truncate text-[13px] font-medium text-ink">{v.label || `Sürüm ${v.version}`}</div>
              <div className="truncate text-[11.5px] text-ink-3">
                {v.stats?.desks ?? 0} masa · {v.stats?.assigned ?? 0} atama · {fmt(v.created_at)}
                {v.created_by ? ` · ${v.created_by}` : ''}
              </div>
            </div>
            {v.is_current ? (
              <Badge tone="accent">Güncel</Badge>
            ) : (
              canManage && (
                <Button size="xs" variant="secondary" icon={<RotateCcw className="size-3.5" />} onClick={() => setTarget(v.version)}>
                  Geri yükle
                </Button>
              )
            )}
          </li>
        ))}
      </ul>
      <ConfirmDialog
        open={target !== null}
        onClose={() => setTarget(null)}
        loading={restore.isPending}
        onConfirm={() => {
          if (target === null) return
          restore.mutate(target, {
            onSuccess: (r) => {
              toast.success(r.message)
              setTarget(null)
              onClose()
              onRestored()
            },
            onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Geri yüklenemedi.'),
          })
        }}
        title={`V${target} geri yüklensin mi?`}
        description={dirty ? 'Kaydedilmemiş değişiklikleriniz kaybolacak. Geri yüklenen içerik yeni sürüm olarak kaydedilir.' : 'Geri yüklenen içerik yeni sürüm olarak kaydedilir; mevcut sürüm geçmişte kalır.'}
        confirmLabel="Geri yükle"
      />
    </Drawer>
  )
}
