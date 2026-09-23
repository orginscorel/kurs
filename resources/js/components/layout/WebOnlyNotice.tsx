import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Copy, ExternalLink, Globe, WifiOff } from 'lucide-react'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/feedback'
import { openOnWeb, serverUrl } from '@/lib/webOnly'

/** Masaüstünde "yalnız web" bölümü: boş liste yerine açık durum + "Web'de aç". Çevrimdışıysa nazikçe kısıtlar. */
export function WebOnlyNotice({ title, reason, path }: { title: string; reason: string; path: string }) {
  const [note, setNote] = useState<string | null>(null)
  const base = serverUrl()
  const url = base ? base + path : null

  // Üst çubuktaki eşitleme göstergesiyle AYNI önbellek anahtarı: ekstra istek atmadan çevrimiçi/çevrimdışı bilinir.
  const { data: status } = useQuery({
    queryKey: ['sync', 'local-status'],
    queryFn: () => api.get<{ phase?: string }>('/sync/local-status'),
    refetchInterval: 15_000,
    staleTime: 8_000,
  })
  const browserOnline = typeof navigator === 'undefined' || navigator.onLine
  const offline = !browserOnline || status?.phase === 'offline'

  if (offline) {
    return (
      <div className="rounded-[var(--radius-lg)] border border-line bg-surface">
        <EmptyState
          icon={<WifiOff />}
          title={`${title} için internet bağlantısı gerekiyor`}
          description={
            <>
              Bu bölüm çevrimiçiyken kurumun web adresinde yönetilir; {reason.charAt(0).toLocaleLowerCase('tr') + reason.slice(1)} Şu an bağlantı yok
              — internet gelince bu ekrandan “Web'de aç” ile ulaşabilirsiniz. Diğer ekranlar (öğrenci, yoklama, finans…) çevrimdışı da çalışır.
              {url && <span className="mt-2 block break-all text-[12px] text-ink-3">{url}</span>}
            </>
          }
        />
      </div>
    )
  }

  return (
    <div className="rounded-[var(--radius-lg)] border border-line bg-surface">
      <EmptyState
        icon={<Globe />}
        title={`${title} web'den yönetilir`}
        description={
          <>
            {reason} Bu bölümü kurumun web adresinde açın; masaüstü uygulamasındaki diğer ekranlar (öğrenci, yoklama, finans…) aynen çalışır.
            {url && <span className="mt-2 block break-all text-[12px] text-ink-3">{url}</span>}
            {note && <span className="mt-2 block text-[12.5px] text-ink">{note}</span>}
          </>
        }
        action={
          url ? (
            <div className="flex flex-wrap items-center justify-center gap-2">
              <Button
                variant="primary"
                icon={<ExternalLink className="size-4" />}
                onClick={async () => {
                  const r = await openOnWeb(path)
                  setNote(r === 'opened' ? null : r === 'copied' ? 'Adres panoya kopyalandı; tarayıcıya yapıştırın.' : 'Adres açılamadı; yukarıdaki adresi tarayıcıya yazın.')
                }}
              >
                Web'de aç
              </Button>
              <Button
                icon={<Copy className="size-4" />}
                onClick={async () => {
                  try {
                    await navigator.clipboard.writeText(url)
                    setNote('Adres panoya kopyalandı.')
                  } catch {
                    setNote('Kopyalanamadı; adresi elle seçip kopyalayın.')
                  }
                }}
              >
                Adresi kopyala
              </Button>
            </div>
          ) : undefined
        }
      />
    </div>
  )
}
