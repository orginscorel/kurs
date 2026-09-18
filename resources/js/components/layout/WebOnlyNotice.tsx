import { useState } from 'react'
import { Copy, ExternalLink, Globe } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/feedback'
import { openOnWeb, serverUrl } from '@/lib/webOnly'

/** Masaüstünde "yalnız web" bölümü: boş liste yerine açık durum + "Web'de aç". */
export function WebOnlyNotice({ title, reason, path }: { title: string; reason: string; path: string }) {
  const [note, setNote] = useState<string | null>(null)
  const base = serverUrl()
  const url = base ? base + path : null

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
