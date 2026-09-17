import type { Tone } from '@/components/ui/feedback'
import { todayISO } from '@/lib/format'

/**
 * Fatura / muhasebe / mutabakat / takip ekranlarının ortak tipleri ve etiketleri.
 * Tutarlar sunucudan string gelir; toplam gerekiyorsa shared.ts içindeki kuruş aritmetiği kullanılır.
 */

export const INVOICE_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft: { label: 'Taslak', tone: 'warning' },
  issued: { label: 'Kesildi', tone: 'success' },
  cancelled: { label: 'İptal edildi', tone: 'danger' },
}

export const INVOICE_KIND: Record<string, { label: string; tone: Tone }> = {
  sales: { label: 'Satış', tone: 'info' },
  return: { label: 'İade', tone: 'accent' },
}

export const DOCUMENT_TYPES: Record<string, string> = { e_archive: 'e-Arşiv', e_invoice: 'e-Fatura', paper: 'Kâğıt' }
export const BUYER_TYPES: Record<string, string> = { guardian: 'Veli', student: 'Öğrenci', institution: 'Kurum', other: 'Diğer' }

export const INTEGRATOR_STATUS: Record<string, { label: string; tone: Tone }> = {
  not_sent: { label: 'GİB\'e iletilmedi', tone: 'neutral' },
  simulated: { label: 'Simülasyon', tone: 'info' },
  simulated_cancel: { label: 'Simülasyon iptal', tone: 'info' },
}

export const JOURNAL_EVENTS: Record<string, { label: string; tone: Tone }> = {
  payment: { label: 'Tahsilat', tone: 'success' },
  payment_void: { label: 'Tahsilat iptali', tone: 'danger' },
  entry: { label: 'Gelir/gider', tone: 'info' },
  entry_void: { label: 'Gelir/gider iptali', tone: 'danger' },
  transfer: { label: 'Transfer', tone: 'primary' },
  transfer_void: { label: 'Transfer iptali', tone: 'danger' },
  opening: { label: 'Açılış', tone: 'primary' },
  adjustment: { label: 'Sayım farkı', tone: 'warning' },
  invoice: { label: 'Fatura', tone: 'accent' },
  invoice_link: { label: 'Mahsup', tone: 'accent' },
  invoice_cancel: { label: 'Fatura iptali', tone: 'danger' },
  refund: { label: 'İade', tone: 'warning' },
  refund_void: { label: 'İade iptali', tone: 'danger' },
  manual: { label: 'Elle fiş', tone: 'neutral' },
}

export const LEDGER_TYPES: Record<string, string> = { asset: 'Varlık', liability: 'Yabancı kaynak', equity: 'Öz kaynak', income: 'Gelir', expense: 'Gider' }

export const NOTE_STATUS: Record<string, { label: string; tone: Tone }> = {
  open: { label: 'Bekliyor', tone: 'warning' },
  kept: { label: 'Tutuldu', tone: 'success' },
  broken: { label: 'Tutulmadı', tone: 'danger' },
  done: { label: 'Tamamlandı', tone: 'neutral' },
}

export const NOTE_KINDS: Record<string, string> = { promise: 'Ödeme sözü', note: 'Görüşme notu', reminder: 'Hatırlatma taslağı' }

/** Kaynak belge → ekran bağlantısı (yevmiye satırından belgeye gitmek için) */
export function sourceLink(type: string | null, id: number | null): string | null {
  if (!type || !id) return null
  switch (type) {
    case 'payment':
      return `/finans/tahsilatlar?detay=${id}`
    case 'finance_entry':
      return `/finans/gelir-gider?detay=${id}`
    case 'account_transfer':
      return '/finans/hesaplar'
    case 'invoice':
      return `/finans/faturalar/${id}`
    case 'refund':
      return '/finans/iadeler'
    default:
      return null
  }
}

export type InvoiceRow = {
  id: number
  label: string
  invoice_no: string | null
  kind: 'sales' | 'return'
  status: 'draft' | 'issued' | 'cancelled'
  document_type: string
  issue_date: string
  buyer_type: string
  buyer_id: number | null
  buyer_name: string
  buyer_tax_id_masked: string | null
  student: { id: number; full_name: string; student_no: string } | null
  net_total: string
  vat_total: string
  withholding_total: string
  payable_total: string
  integrator_status: string
  related_invoice_id: number | null
}

export type InvoiceLine = {
  id?: number
  description: string
  quantity: string
  unit: string
  unit_price: string
  discount_rate: string
  discount_amount: string
  vat_rate: string
  withholding_tenths: number
  net_amount?: string
  vat_amount?: string
  total_amount?: string
  gross_amount?: string
  withholding_amount?: string
}

export type InvoiceDetail = InvoiceRow & {
  buyer_tax_id: string | null
  buyer_tax_office: string | null
  buyer_address: string | null
  buyer_email: string | null
  buyer_phone: string | null
  enrollment_id: number | null
  notes: string | null
  prices_include_vat: boolean
  ettn: string | null
  gross_total: string
  discount_total: string
  grand_total: string
  amount_words: string
  lines: (InvoiceLine & { id: number; sequence: number })[]
  vat_breakdown: { rate: string; net: string; vat: string }[]
  payments: { id: number; receipt_no: string; paid_at: string; amount: string; linked: string; voided: boolean }[]
  linked_total: string
  open_amount: string
  related: { id: number; invoice_no: string; payable_total: string } | null
  returns: { id: number; invoice_no: string | null; status: string; payable_total: string; issue_date: string }[]
  journal: { id: number; entry_no: string; entry_date: string; source_event: string; total_debit: string }[]
  created_by: string | null
  issued_by: string | null
  cancelled_by: string | null
  cancel_reason: string | null
  cancelled_at: string | null
  issued_at: string | null
  created_at: string | null
}

export type InvoiceOptions = {
  vat_rates: string[]
  default_vat_rate: string
  prices_include_vat: boolean
  withholding_enabled: boolean
  default_unit: string
  default_document_type: string
  invoice_prefix: string
  return_prefix: string
  integrator: { key: string; label: string; connected: boolean }
  units: string[]
}

export type UnbilledRow = {
  id: number
  receipt_no: string
  paid_at: string
  amount: string
  available: string
  method: string
  method_label: string
  student_id: number
  student: string
  student_no: string
  program: string | null
}

export type JournalLineRow = { code: string; name: string; debit: string; credit: string; description: string | null; partner: string | null; partner_type: string | null; partner_id: number | null }

export type JournalRow = {
  id: number
  entry_no: string
  entry_date: string
  description: string
  event: string
  event_label: string
  source_type: string | null
  source_id: number | null
  total: string
  reversed: boolean
  is_reversal: boolean
  lines: JournalLineRow[]
}

export type RefundRow = {
  id: number
  refund_no: string
  amount: string
  from_credit: string
  from_installments: string
  invoiced_portion: string
  method: string
  method_label: string
  refunded_at: string
  reason: string
  payee_name: string | null
  reference: string | null
  student: { id: number; full_name: string; student_no: string } | null
  payment: { id: number; receipt_no: string; amount: string } | null
  account: string | null
  created_by: string | null
  voided_at: string | null
  void_reason: string | null
}

export type CollectionNoteRow = {
  id: number
  student_id: number
  kind: string
  kind_label: string
  status: string
  status_label: string
  promised_date: string | null
  promised_amount: string | null
  body: string | null
  channel: string | null
  responsible: string | null
  responsible_user_id: number | null
  created_by: string | null
  created_at: string | null
}

export type Cockpit = {
  unbilled: { count: number; amount: string }
  pos_pending: { count: number; net: string; late: number; late_amount: string }
  bank_unmatched: number
  draft_invoices: { count: number; amount: string }
  overdue_invoices: { count: number; amount: string }
  promises: { today: number; late: number; today_amount: string }
  refunds_month: { count: number; amount: string }
  last_closed_period: string | null
  suggest_close: boolean
  integrator: string
}

export type OverdueAlert = {
  date: string
  installments: { count: number; amount: string; students: number }
  invoices: { count: number; amount: string }
  due_today: { count: number; amount: string }
}

/** Analiz raporu (Rapor Merkezi) ortak biçimi */
export type AnalyticsColumn = { key: string; label: string; type: 'money' | 'text' | 'percent' | 'number' }
export type AnalyticsTable = { title: string; columns: AnalyticsColumn[]; rows: Record<string, string | number | null>[]; totals?: Record<string, string | number | null>; chart?: boolean }
export type AnalyticsReport = {
  key: string
  title: string
  from: string
  to: string
  description?: string
  notes?: string[]
  kpis?: { label: string; value: string | null; type: AnalyticsColumn['type']; tone?: Tone | null }[]
  chart?: { type: 'bar'; x: string; table?: number; series: { key: string; label: string }[] }
  tables: AnalyticsTable[]
}

/** "Bugün gizle" uyarıları için tarih anahtarlı yerel bayrak (gizli sekme / engelli depolamada sessizce çalışır) */
export function dismissedToday(key: string): boolean {
  try {
    return localStorage.getItem(`ebe-dismiss:${key}`) === todayISO()
  } catch {
    return false
  }
}

export function dismissToday(key: string) {
  try {
    localStorage.setItem(`ebe-dismiss:${key}`, todayISO())
  } catch {
    /* yok say */
  }
}
