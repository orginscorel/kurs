import { isLocalNode } from '@/lib/api'

/**
 * YALNIZ WEB BÖLÜMLERİ — masaüstü (yerel kurulum) uygulamasında bu sayfaların verisi yoktur (düğüme özel tablolar:
 * sağlayıcı sırları, gönderim kuyruğu, otomasyonlar, çalıştırma geçmişleri) ya da yapılan işlem web'e hiç ulaşmaz.
 * Masaüstünde boş liste yerine "Bu bölüm web'den yönetilir" + "Web'de aç" gösterilir, menüde "web" rozeti çıkar.
 * Kaynak: app/Sync/SyncRegistry.php (LOCAL tablolar), docs/SYNC.md › "Masaüstünde yalnız web bölümleri".
 */
export const WEB_ONLY: { prefix: string; title: string; reason: string }[] = [
  { prefix: '/iletisim/whatsapp', title: 'WhatsApp mesajları', reason: 'Mesaj gönderimi ve geçmişi sunucudadır; masaüstünden gönderilen mesaj yola çıkmaz.' },
  { prefix: '/iletisim/toplu-gonderim', title: 'Toplu gönderim', reason: 'Kampanyalar sağlayıcı bağlantısıyla sunucudan gönderilir.' },
  { prefix: '/iletisim/sablonlar', title: 'Mesaj şablonları', reason: 'Şablonlar gönderimde sunucuda kullanılır.' },
  { prefix: '/iletisim/otomasyonlar', title: 'Otomasyonlar', reason: 'Otomasyon kuralları yalnız sunucuda çalışır.' },
  { prefix: '/iletisim/ileti-izinleri', title: 'İleti izinleri', reason: 'Ret listesi gönderim tarafında (sunucuda) tutulur; buradaki değişiklik web\'e ulaşmaz.' },
  { prefix: '/ayarlar/entegrasyonlar', title: 'Entegrasyonlar', reason: 'Sağlayıcı API anahtarları güvenlik gereği masaüstüne indirilmez.' },
  { prefix: '/ayarlar/mesaj-kanallari', title: 'Mesaj kanalları', reason: 'SMS / e-posta sağlayıcı bilgileri güvenlik gereği masaüstüne indirilmez.' },
  { prefix: '/ayarlar/webhooklar', title: 'Webhooklar', reason: 'Webhook imza sırları güvenlik gereği masaüstüne indirilmez.' },
  { prefix: '/raporlar/iletisim', title: 'İletişim raporu', reason: 'Gönderim kayıtları sunucudadır.' },
  { prefix: '/program-botu', title: 'Program botu', reason: 'Program botu çalıştırmaları ve geri alma kayıtları sunucuda tutulur.' },
  { prefix: '/optik-okuma', title: 'Optik okuma', reason: 'Optik dosyalar ve sınav sonuçları sunucuda işlenir.' },
]

export function webOnlyFor(pathname: string) {
  if (!isLocalNode()) return null
  return WEB_ONLY.find((w) => pathname === w.prefix || pathname.startsWith(w.prefix + '/')) ?? null
}

/** Kurum web adresi (yerel kurulumda sayfa kabuğundaki meta etiketinden). */
export function serverUrl(): string | null {
  const v = typeof document !== 'undefined' ? document.querySelector('meta[name="kurs-server-url"]')?.getAttribute('content') : null
  return v ? v.replace(/\/+$/, '') : null
}

/**
 * Sayfayı kurumun web adresinde açar. Masaüstü kabuğu yeni pencere isteğini sistem tarayıcısına verir; olmazsa
 * adres panoya kopyalanır (çağıran bunu kullanıcıya söyler).
 */
export async function openOnWeb(path: string): Promise<'opened' | 'copied' | 'failed'> {
  const base = serverUrl()
  if (!base) return 'failed'
  const url = base + (path.startsWith('/') ? path : '/' + path)
  try {
    const w = window.open(url, '_blank', 'noopener,noreferrer')
    if (w !== null) return 'opened'
  } catch {
    /* yeni pencere engellendi */
  }
  try {
    await navigator.clipboard.writeText(url)
    return 'copied'
  } catch {
    return 'failed'
  }
}
