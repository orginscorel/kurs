/**
 * Bildirim omurgası (olay×kitle WhatsApp) — arayüz tipleri.
 * Kaynak: app/Http/Controllers/Api/Notifications/NotificationController.php sözleşmesi.
 */

export type Audience = 'student' | 'parent' | 'teacher' | 'admin'

/** Olay tipleri — backend NotificationCatalog ile birebir. */
export type EventType =
  | 'schedule.published'
  | 'guidance.meeting'
  | 'study.plan'
  | 'coaching.session'
  | 'lesson.one_to_one'
  | 'payment.reminder'
  | 'payment.receipt'
  | 'attendance.mark'

export type CatalogEvent = {
  event_type: string
  label: string
  group: string
  audiences: { key: string; label: string }[]
  variables: string[]
}

export type Catalog = {
  events: CatalogEvent[]
  audiences: Record<string, string>
}

export type SettingRow = {
  event_type: string
  label: string
  group: string
  enabled: boolean
  require_approval: boolean
  channels: string[]
  audiences: string[]
  available_audiences: string[]
}

export type EventTemplate = {
  id: number
  event_type: string
  audience: string
  audience_label: string
  channel: string
  name: string
  subject: string | null
  body: string
  is_active: boolean
}

export type BatchStatus = 'draft' | 'pending' | 'approved' | 'sent' | 'cancelled' | string

export type BatchJson = {
  id: number
  event_type: string
  event_label: string
  title: string
  status: BatchStatus
  status_label: string
  audiences: string[]
  total: number
  sent: number
  pdf_available: boolean
  created_at: string
  approved_at: string | null
}

export type BatchMessage = { id: number; to: string; status: string; body: string; student_id: number | null }
export type BatchGroup = { audience: string; audience_label: string; count: number; messages: BatchMessage[] }
export type BatchDetail = BatchJson & { groups: BatchGroup[] }

/** Durum → görsel ton eşlemesi (Badge). */
export function statusTone(status: string): 'neutral' | 'warning' | 'info' | 'success' | 'danger' {
  switch (status) {
    case 'sent':
      return 'success'
    case 'approved':
      return 'info'
    case 'pending':
    case 'draft':
      return 'warning'
    case 'cancelled':
      return 'danger'
    default:
      return 'neutral'
  }
}

/** Örnek değişken değerleri — şablon canlı önizlemesi sunucuya gitmeden önce yerelde de gösterebilsin diye. */
export const SAMPLE_VARS: Record<string, string> = {
  ogrenci_adi: 'Ahmet Yılmaz',
  veli_adi: 'Ayşe Yılmaz',
  okul_no: '2026201',
  sinif: '12-SAY-A',
  tarih: '23.09.2026',
  ogretmen_adi: 'Mehmet Demir',
  rehber_adi: 'Zeynep Kaya',
  koc_adi: 'Elif Şahin',
  ders_adi: 'Matematik',
  ders_listesi: '09:00 Matematik\n10:00 Fizik\n11:00 Kimya',
  hafta: '23–29 Eylül',
  plan_ozeti: 'Türev tekrarı + 40 soru',
  konu: 'Limit ve Süreklilik',
  yer: 'Rehberlik Odası',
  saat: '14:30',
  derslik: 'A-101',
  tutar: '1.250,00 ₺',
  vade_tarihi: '30.09.2026',
  gecikme_gun: '3',
  makbuz_no: 'MKB-2026-0142',
  durum: 'Geldi',
  ders_saati: '14:00',
}

/** Yer tutucuları örnek değerlerle doldurur; bilinmeyen değişkeni [ad] olarak gösterir. */
export function renderSample(body: string, vars: Record<string, string> = SAMPLE_VARS): string {
  return body.replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi, (_, key) => vars[key.toLowerCase()] ?? `[${key}]`)
}
