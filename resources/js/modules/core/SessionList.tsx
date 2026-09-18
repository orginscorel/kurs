import { Apple, Globe, Hourglass, Laptop, Smartphone } from 'lucide-react'
import { relative, dateTime } from '@/lib/format'
import { Badge } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'

/** Açık oturum satırı — /auth/sessions ve /admin-users/{id} yanıtlarıyla aynı biçim (App\Services\Auth\SessionDirectory) */
export type SessionRow = {
  id: string
  kind: 'web' | 'app' | 'mobile'
  kind_label?: string
  device: string | null
  device_code?: string | null
  detail?: string | null
  ip_address: string | null
  last_active_at: string | null
  device_last_seen_at?: string | null
  is_current: boolean
  status?: 'active' | 'closing'
  local?: boolean
}

function KindIcon({ row }: { row: SessionRow }) {
  if (row.kind === 'mobile') return <Smartphone className="size-4" />
  if (row.kind === 'app') return row.kind_label?.startsWith('Mac') ? <Apple className="size-4" /> : <Laptop className="size-4" />
  return <Globe className="size-4" />
}

const KIND_LABEL: Record<SessionRow['kind'], string> = { web: 'Tarayıcı', app: 'Mac uygulaması', mobile: 'Mobil uygulama' }

/**
 * Tek liste: tarayıcı + Mac uygulaması + mobil. "Kapatılıyor" satırında düğme yoktur (cihaz bağlanınca kapanır).
 */
export function SessionList({
  rows,
  onRevoke,
  pendingId,
  canRevoke = true,
  emptyText = 'Açık oturum yok.',
}: {
  rows: SessionRow[]
  onRevoke?: (row: SessionRow) => void
  pendingId?: string | null
  canRevoke?: boolean
  emptyText?: string
}) {
  if (!rows.length) return <p className="border-t border-line px-4 py-4 text-[13px] text-ink-3">{emptyText}</p>

  return (
    <ul>
      {rows.map((s) => {
        const closing = s.status === 'closing'
        return (
          <li key={s.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-line px-4 py-3">
            <span className="grid size-9 shrink-0 place-items-center rounded-[var(--radius-sm)] bg-surface-2 text-ink-2">
              <KindIcon row={s} />
            </span>
            <div className="min-w-0 flex-1 basis-[180px]">
              <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                <span className="min-w-0 break-words text-[13.5px] font-medium text-ink">{s.device || 'Bilinmeyen cihaz'}</span>
                <Badge tone={s.kind === 'app' ? 'primary' : 'neutral'}>{s.kind_label ?? KIND_LABEL[s.kind]}</Badge>
                {s.is_current && <Badge tone="success">Bu cihaz</Badge>}
                {closing && (
                  <span title={s.device_last_seen_at ? `Cihaz son görülme: ${dateTime(s.device_last_seen_at)}` : undefined}>
                    <Badge tone="warning"><Hourglass className="size-3" />Kapatılıyor (cihaz bağlanınca)</Badge>
                  </span>
                )}
              </div>
              <p className="mt-0.5 break-words text-[12px] text-ink-3">
                {[s.detail, s.ip_address ? `IP ${s.ip_address}` : null, `son etkinlik ${relative(s.last_active_at)}`].filter(Boolean).join(' · ')}
              </p>
            </div>
            {canRevoke && onRevoke && !s.is_current && !closing && (
              <Button size="sm" variant="ghost" className="ml-auto" loading={pendingId === s.id} onClick={() => onRevoke(s)}>
                Oturumu kapat
              </Button>
            )}
          </li>
        )
      })}
    </ul>
  )
}
