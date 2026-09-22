import { Link } from 'react-router-dom'
import { Building2, ChevronRight, GraduationCap, MessageCircle, Server, SlidersHorizontal, Wallet, type LucideIcon } from 'lucide-react'
import { navigation, SETTINGS_LANDING, type NavItem } from '@/app/navigation'
import { useCan } from '@/app/auth'
import { PageHeader } from '@/components/ui/layout'
import { EmptyState } from '@/components/ui/feedback'
import { webOnlyFor } from '@/lib/webOnly'

/**
 * AYARLAR HUB — /ayarlar
 *
 * Tek bakışta tüm ayar sayfaları. Kategoriler ve sıra `navigation`in "settings" grubundan gelir
 * (kaynak: navigation.ts › SETTINGS_CATEGORIES); böylece sol alt menü ile hub aynı düzeni paylaşır.
 * Yetkisi olmayan kart görünmez (nav öğesinin `permission` alanı + useCan). Kartın kendisi (hub) listelenmez.
 */

/** Kart açıklamaları (kısa, kurumsal). Eksikse genel bir ifadeye düşer. */
const DESCRIPTIONS: Record<string, string> = {
  '/ayarlar/kurum': 'Kurum kimliği, logo, iletişim bilgileri ve portal tercihleri',
  '/ayarlar/okullar': 'Öğrenci kaynağı okullar ve bölge tanımları',
  '/ayarlar/kullanicilar': 'Personel hesapları, erişim ve durum yönetimi',
  '/ayarlar/roller': 'Rol tabanlı yetkiler ve izin matrisi',
  '/akademik': 'Programlar, dersler ve akademik yapı',
  '/finans/paketler': 'Eğitim paketleri ve fiyatlandırma',
  '/finans/ayarlar': 'Fatura, tahsilat ve muhasebe tercihleri',
  '/ayarlar/mesaj-kanallari': 'SMS ve e-posta gönderim sağlayıcıları',
  '/iletisim/sablonlar': 'SMS, e-posta ve WhatsApp mesaj şablonları',
  '/iletisim/otomasyonlar': 'Olaya bağlı otomatik bildirim kuralları',
  '/ayarlar/entegrasyonlar': 'WhatsApp ve dış servis bağlantıları',
  '/ayarlar/webhooklar': 'Dış sistemlere olay bildirimleri',
  '/yoklama/cihazlar': 'PDKS turnike ve yoklama terminalleri',
  '/ayarlar/bagli-cihazlar': 'Masaüstü ve mobil eşitleme cihazları',
  '/ayarlar/esitleme-cakismalari': 'Çevrimdışı eşitleme çakışmalarının çözümü',
  '/uygulamalar': 'Masaüstü ve mobil uygulama indirmeleri',
  '/ayarlar/denetim-kayitlari': 'Kullanıcı işlemleri ve değişiklik geçmişi',
  '/ayarlar/sistem-sagligi': 'Yedekler, kuyruk ve servis durumu',
}

/** Kategori başlığı ikonları. */
const CATEGORY_ICON: Record<string, LucideIcon> = {
  Kurum: Building2,
  Eğitim: GraduationCap,
  Finans: Wallet,
  İletişim: MessageCircle,
  Sistem: Server,
}

export default function SettingsHub() {
  const can = useCan()
  const settings = navigation.find((g) => g.key === 'settings')
  const items = (settings?.items ?? []).filter((i) => i.to !== SETTINGS_LANDING && can(i.permission))

  // Kategoriye göre grupla; sıra `navigation` (settingsRank) tarafından belirlendiği için ekleme sırası korunur.
  const groups: { category: string; items: NavItem[] }[] = []
  for (const item of items) {
    const category = item.category ?? 'Diğer'
    let group = groups.find((g) => g.category === category)
    if (!group) {
      group = { category, items: [] }
      groups.push(group)
    }
    group.items.push(item)
  }

  return (
    <div>
      <PageHeader
        title="Ayarlar"
        description="Kurum yapılandırmasını kategorilere göre yönetin. Yalnızca yetkili olduğunuz bölümler listelenir."
      />

      {groups.length === 0 ? (
        <EmptyState
          icon={<SlidersHorizontal />}
          title="Görüntülenecek ayar yok"
          description="Bu bölümdeki ayarları görüntülemek için yetkiniz bulunmuyor. Bir yöneticiden erişim isteyebilirsiniz."
        />
      ) : (
        <div className="flex flex-col gap-8">
          {groups.map((group) => {
            const CategoryIcon = CATEGORY_ICON[group.category] ?? SlidersHorizontal
            return (
              <section key={group.category}>
                <div className="mb-3 flex items-center gap-2">
                  <CategoryIcon className="size-4 text-ink-3" strokeWidth={1.8} />
                  <h2 className="text-[11px] font-medium uppercase tracking-[0.06em] text-ink-3">{group.category}</h2>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                  {group.items.map((item) => (
                    <SettingsCard key={item.to} item={item} />
                  ))}
                </div>
              </section>
            )
          })}
        </div>
      )}
    </div>
  )
}

function SettingsCard({ item }: { item: NavItem }) {
  const Icon = item.icon ?? SlidersHorizontal
  const web = webOnlyFor(item.to)
  return (
    <Link
      to={item.to}
      className="group flex items-start gap-3 rounded-[var(--radius-lg)] bg-surface p-4 ring-1 ring-line transition-colors hover:bg-surface-2 hover:ring-line-strong"
    >
      <span className="grid size-9 shrink-0 place-items-center rounded-[var(--radius-md)] bg-surface-2 text-ink-2 ring-1 ring-line transition-colors group-hover:bg-primary-soft group-hover:text-primary [&_svg]:size-[18px]">
        <Icon strokeWidth={1.8} />
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-1.5">
          <span className="truncate text-[14px] font-medium text-ink">{item.label}</span>
          {web && (
            <span
              title="Bu bölüm web'den yönetilir"
              className="shrink-0 rounded-[4px] border border-line px-1 text-[10px] font-medium uppercase leading-[15px] tracking-wide text-ink-3"
            >
              web
            </span>
          )}
        </span>
        <span className="mt-0.5 block text-[12.5px] leading-snug text-ink-3">
          {DESCRIPTIONS[item.to] ?? 'Ayarları görüntüle ve düzenle'}
        </span>
      </span>
      <ChevronRight className="mt-0.5 size-4 shrink-0 text-ink-3 opacity-0 transition-opacity group-hover:opacity-100" />
    </Link>
  )
}
