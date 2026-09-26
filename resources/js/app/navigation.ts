import type { LucideIcon } from 'lucide-react'
import {
  BarChart3, BookUser, CalendarDays, Contact, Gavel, ClipboardCheck, Compass, GraduationCap, LayoutDashboard, MessageCircle, Settings, Target, UserPlus, Users, Wallet,
} from 'lucide-react'
import { modules, type ModuleNavItem, type SectionKey } from '@/app/modules'

export type NavItem = Omit<ModuleNavItem, 'section'> & { category?: string }

export type NavGroup = {
  key: SectionKey
  label: string
  icon: LucideIcon
  items: NavItem[]
}

/**
 * BİLGİ MİMARİSİ
 *
 * Kenar çubuğunda yalnız ana alanlar görünür; bir alanın sayfaları, alana girince üstte
 * sekme olarak listelenir. Modüller menü öğesini `section` ile bildirir; hangi alanda
 * duracağına ve sırasına burada karar verilir (modül koduna dokunmadan).
 */
const GROUPS: { key: SectionKey; label: string; icon: LucideIcon }[] = [
  { key: 'main', label: 'Genel Bakış', icon: LayoutDashboard },
  { key: 'crm', label: 'Ön Kayıt', icon: UserPlus },
  // "Kişiler" tek çatısı ayrıldı: her rol kenar çubuğunda kendi net başlığı ve ikonuyla durur.
  { key: 'students', label: 'Öğrenciler', icon: Users },
  { key: 'guardians', label: 'Veliler', icon: Contact },
  { key: 'teachers', label: 'Öğretmenler', icon: BookUser },
  { key: 'attendance', label: 'Yoklama', icon: ClipboardCheck },
  { key: 'academic', label: 'Akademik', icon: CalendarDays },
  { key: 'coaching', label: 'Koçluk', icon: Target },
  { key: 'exams', label: 'Sınavlar', icon: GraduationCap },
  { key: 'guidance', label: 'Rehberlik', icon: Compass },
  { key: 'discipline', label: 'Disiplin', icon: Gavel },
  { key: 'finance', label: 'Finans', icon: Wallet },
  { key: 'communication', label: 'İletişim', icon: MessageCircle },
  { key: 'reports', label: 'Raporlar ve çıktılar', icon: BarChart3 },
  { key: 'settings', label: 'Ayarlar', icon: Settings },
]

/**
 * Menü sırası (alan içinde yukarıdan aşağı). Burada olmayan yeni sayfa, modülün verdiği `order` ile en sona düşer.
 * Sık kullanılan iş önce, tanım/analiz sayfaları sonra.
 */
const ORDER: string[] = [
  // Kişiler
  '/ogrenciler', '/veliler', '/ogretmenler', '/personel',
  // Yoklama
  '/pdks', '/yoklama', '/yoklama/canli', '/yoklama/devamsizlik',
  // Akademik
  '/akademik/kurulum', '/ders-programi', '/takvim', '/siniflar', '/derslik-tasarimi', '/yerlestirme', '/odevler', '/etut', '/program-botu',
  // Sınavlar
  '/sinavlar', '/optik-okuma', '/sinav-sonuclari', '/sinav-analizleri', '/kazanim-analizi',
  // Rehberlik
  '/rehberlik/gorusmeler', '/rehberlik/riskli-ogrenciler', '/rehberlik/hedefler',
  // Disiplin
  '/disiplin', '/disiplin/olaylar', '/disiplin/dikkat', '/disiplin/kurul', '/disiplin/katalog',
  // Finans
  '/finans', '/finans/tahsilat', '/finans/tahsilatlar', '/finans/alacaklar', '/finans/kayitlar', '/finans/faturalar', '/finans/senetler',
  '/finans/gelir-gider', '/finans/hesaplar', '/finans/muhasebe', '/finans/envanter', '/finans/raporlar',
  // İletişim
  '/iletisim/duyurular', '/iletisim/toplu-gonderim', '/iletisim/whatsapp', '/iletisim/ileti-izinleri',
  // Raporlar ve çıktılar
  '/raporlar', '/ogrenciler/liste-ciktisi',
]
const orderRank = (to: string) => {
  const i = ORDER.indexOf(to)
  return i === -1 ? 1000 : i
}

/** Yapılandırma niteliğindeki sayfalar günlük iş alanlarından Ayarlar'a taşınır. */
const MOVE_TO: Record<string, SectionKey> = {
  // Kişiler tek çatısını üç ayrı ana alana böl (modül kodları hâlâ section:'people' bildirir).
  '/ogrenciler': 'students',
  '/veliler': 'guardians',
  '/ogretmenler': 'teachers',
  '/personel': 'teachers',
  '/yoklama/cihazlar': 'settings',
  // Programlar ve dersler Akademik altında kalır (kendi section:'academic'); ayrıca ALSO_IN ile Finans'ta da görünür.
  '/finans/paketler': 'finance',
  '/finans/ayarlar': 'settings',
  '/iletisim/sablonlar': 'settings',
  '/iletisim/otomasyonlar': 'settings',
  '/yerlestirme': 'academic',
  // Çıktılar tek yerde: Raporlar ve çıktılar
  '/ogrenciler/liste-ciktisi': 'reports',
}

/**
 * Bir sayfayı birden çok ana alanda göster (kendi/MOVE_TO alanına EK olarak). Örn. "Programlar ve dersler"
 * hem Akademik'te (kendi alanı) hem Finans'ta listelenir — kullanıcı iki taraftan da erişebilsin.
 */
const ALSO_IN: Record<string, SectionKey[]> = {
  '/akademik': ['finance'],
}

/** Başka yerden zaten erişilen sayfalar menüde tekrarlanmaz (bildirimler üst çubukta). */
const HIDDEN = new Set(['/iletisim/bildirimler', '/gorevlerim',
  // Finans alt sayfaları: ilgili ekrandan ve kokpit kutularından açılır (menü kalabalıklaşmasın)
  '/finans/iadeler', '/finans/takip', '/finans/mutabakat',
  // Yoklama çizelgesi çıktısı "Liste ve çizelge çıktıları" sayfasında seçilir
  '/ogrenciler/liste-ciktisi?tur=yoklama'])

/** Sekme etiketi kısaltmaları: alan başlığı zaten bağlamı veriyor. */
const SHORT_LABEL: Record<string, string> = {
  '/': 'Pano',
  '/finans': 'Özet',
  '/finans/tahsilat': 'Tahsilat al',
  '/finans/alacaklar': 'Taksit ve alacaklar',
  '/finans/kayitlar': 'Kayıt ve sözleşmeler',
  '/finans/gelir-gider': 'Muhasebe ve raporlar',
  '/finans/hesaplar': 'Kasa ve banka',
  '/finans/envanter': 'Kitap ve materyal',
  '/finans/raporlar': 'Finans raporları',
  '/yoklama': 'Yoklama al',
  '/yoklama/canli': 'Canlı giriş/çıkış',
  '/sinavlar': 'Denemeler',
  '/optik-okuma': 'Optik okuma',
  '/kazanim-analizi': 'Kazanım analizi',
  '/rehberlik/gorusmeler': 'Görüşmeler',
  '/rehberlik/riskli-ogrenciler': 'Riskli öğrenciler',
  '/rehberlik/hedefler': 'Hedefler',
  '/yerlestirme': 'Yerleştirme',
  '/disiplin': 'Genel bakış',
  '/raporlar': 'Rapor Merkezi',
  '/ogrenciler/liste-ciktisi': 'Liste ve çizelge çıktıları',
  '/sinav-analizleri': 'Analizler',
  '/sinav-sonuclari': 'Sonuçlar',
  '/finans/paketler': 'Eğitim paketleri',
  '/iletisim/sablonlar': 'Mesaj şablonları',
  '/iletisim/whatsapp': 'Mesaj geçmişi',
  '/iletisim/duyurular': 'Duyurular',
  '/iletisim/toplu-gonderim': 'Toplu gönderim',
  '/iletisim/ileti-izinleri': 'İleti izinleri',
  '/iletisim/otomasyonlar': 'Otomasyonlar',
  '/yoklama/devamsizlik': 'Devamsızlık',
  '/yoklama/cihazlar': 'Yoklama terminalleri',
}

/**
 * Ayarlar sayfaları üstte tek sıra sekme yerine solda başlıklı alt menüde gösterilir.
 * Sıra buradaki dizilişe göredir; listede olmayan yeni sayfa "Diğer" altına düşer.
 */
export const SETTINGS_CATEGORIES: { label: string; paths: string[] }[] = [
  { label: 'Kurum', paths: ['/ayarlar/kurum', '/ayarlar/okullar', '/ayarlar/kullanicilar', '/ayarlar/roller'] },
  { label: 'Eğitim', paths: ['/akademik', '/finans/paketler'] },
  { label: 'Finans', paths: ['/finans/ayarlar'] },
  { label: 'İletişim', paths: ['/ayarlar/mesaj-kanallari', '/iletisim/sablonlar', '/iletisim/otomasyonlar', '/ayarlar/entegrasyonlar', '/ayarlar/webhooklar'] },
  { label: 'Sistem', paths: ['/yoklama/cihazlar', '/ayarlar/bagli-cihazlar', '/ayarlar/esitleme-cakismalari', '/uygulamalar', '/ayarlar/denetim-kayitlari', '/ayarlar/sistem-sagligi'] },
]

/**
 * Ayarlar öğe sırası. `/ayarlar` (genel bakış / hub) her zaman en başta gelir; böylece kenar çubuğunun
 * altındaki "Ayarlar" bağlantısı hub'a gider. Hub ve sol alt menü bu sırayı ve kategorileri paylaşır.
 * `SETTINGS_LANDING` = hub'un adresi (hub kartlarında ve sol menüde kendini listelememek için ayrılır).
 */
export const SETTINGS_LANDING = '/ayarlar'
const settingsOrder: { path: string; category?: string }[] = [
  { path: SETTINGS_LANDING, category: undefined },
  ...SETTINGS_CATEGORIES.flatMap((c) => c.paths.map((p) => ({ path: p, category: c.label }))),
]
const settingsRank = (to: string) => {
  const i = settingsOrder.findIndex((s) => s.path === to)
  return i === -1 ? 999 : i
}

const allItems = modules.flatMap((m) => m.nav ?? [])

export const navigation: NavGroup[] = GROUPS.map((g) => ({
  ...g,
  items: allItems
    .filter((i) => !HIDDEN.has(i.to) && ((MOVE_TO[i.to] ?? i.section) === g.key || (ALSO_IN[i.to]?.includes(g.key) ?? false)))
    .sort((a, b) => (g.key === 'settings' ? settingsRank(a.to) - settingsRank(b.to) : orderRank(a.to) - orderRank(b.to)) || (a.order ?? 50) - (b.order ?? 50))
    .map(({ section: _section, ...rest }) => ({
      ...rest,
      label: SHORT_LABEL[rest.to] ?? rest.label,
      category: g.key === 'settings' ? (settingsOrder.find((s) => s.path === rest.to)?.category ?? 'Diğer') : undefined,
    })),
})).filter((g) => g.items.length > 0)

/** Yolun bir menü öğesine uyup uymadığı (en uzun eşleşme kazanır; "/finans" ≠ "/finans/tahsilat"). */
export function matchScore(pathname: string, item: NavItem): number {
  if (item.to === '/') return pathname === '/' ? 1 : 0
  if (pathname === item.to) return item.to.length + 1
  return pathname.startsWith(item.to + '/') ? item.to.length : 0
}

export function activeGroupFor(pathname: string, groups: NavGroup[]): { group: NavGroup; item: NavItem } | null {
  let best: { group: NavGroup; item: NavItem; score: number } | null = null
  for (const group of groups) {
    for (const item of group.items) {
      const score = matchScore(pathname, item)
      if (score > 0 && (!best || score > best.score)) best = { group, item, score }
    }
  }
  return best ? { group: best.group, item: best.item } : null
}
