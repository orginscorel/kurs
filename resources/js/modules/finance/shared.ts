import type { Tone } from '@/components/ui/feedback'

/**
 * Finans modülü ortak sabitleri ve kuruş aritmetiği.
 * Para her zaman sunucudan string gelir; istemcide hesap gerekirse TAM SAYI KURUŞ ile yapılır (float yok),
 * sunucuya yine "1500.50" biçiminde string gönderilir. Kesin hesap ve doğrulama sunucudadır.
 */

export const METHODS: Record<string, string> = {
  cash: 'Nakit',
  credit_card: 'Kredi kartı',
  bank_transfer: 'Havale',
  eft: 'EFT',
  pos: 'POS',
  online: 'Online ödeme',
  cheque: 'Çek',
  other: 'Diğer',
}

/** Yönteme göre önerilen hesap türü */
export const METHOD_ACCOUNT_KIND: Record<string, 'cash' | 'pos' | 'bank'> = {
  cash: 'cash',
  credit_card: 'pos',
  pos: 'pos',
  bank_transfer: 'bank',
  eft: 'bank',
  online: 'bank',
  cheque: 'bank',
  other: 'cash',
}

export const ACCOUNT_KINDS: Record<string, string> = { cash: 'Kasa', bank: 'Banka', pos: 'POS' }

export const INSTALLMENT_STATUS: Record<string, { label: string; tone: Tone }> = {
  pending: { label: 'Ödeme bekliyor', tone: 'warning' },
  partial: { label: 'Kısmen ödendi', tone: 'info' },
  overdue: { label: 'Gecikti', tone: 'danger' },
  paid: { label: 'Ödendi', tone: 'success' },
  cancelled: { label: 'İptal edildi', tone: 'neutral' },
}

export const AGING_BUCKETS: { key: string; label: string }[] = [
  { key: 'current', label: 'Vadesi gelmemiş' },
  { key: 'd0_30', label: '0-30 gün' },
  { key: 'd31_60', label: '31-60 gün' },
  { key: 'd61_90', label: '61-90 gün' },
  { key: 'd90_plus', label: '90+ gün' },
]

export const SOURCE_LABELS: Record<string, string> = {
  payment: 'Tahsilat',
  finance_entry: 'Gelir/gider',
  account_transfer: 'Transfer',
  refund: 'İade',
}

// ------------------------------------------------------------------ kuruş aritmetiği

/** "1.500,50" | "1500.5" | "1500" → 150050 (geçersizse null). */
export function toCents(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined) return null
  let s = String(value).trim().replace(/\s/g, '').replace('₺', '')
  if (s === '') return null
  const negative = s.startsWith('-')
  if (negative) s = s.slice(1)
  if (/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(s) || s.includes(',')) s = s.replace(/\./g, '').replace(',', '.')
  if (!/^\d+(\.\d{1,2})?$/.test(s)) return null
  const [int, dec = ''] = s.split('.')
  const cents = Number(int) * 100 + Number((dec + '00').slice(0, 2))
  return negative ? -cents : cents
}

/** 150050 → "1500.50" (sunucuya gönderim biçimi) */
export function fromCents(cents: number): string {
  const sign = cents < 0 ? '-' : ''
  const abs = Math.abs(Math.round(cents))
  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}

export function sumCents(values: (string | number | null | undefined)[]): number {
  return values.reduce<number>((s, v) => s + (toCents(v) ?? 0), 0)
}

/** Tutarı eşit parçalara böler; kuruş artığı son parçaya (sunucudaki plan kuralıyla aynı). */
export function splitCents(total: number, parts: number): number[] {
  if (parts < 1) return []
  const each = Math.floor(total / parts)
  return Array.from({ length: parts }, (_, i) => (i === parts - 1 ? total - each * (parts - 1) : each))
}

/** Tahsilat önizlemesi: tutar seçili taksitlere en eski vadeden başlayarak dağıtılır (PaymentService ile aynı sıra). */
export function allocate<T extends { id: number; remaining: string; due_date: string; sequence: number }>(rows: T[], amountCents: number) {
  const sorted = [...rows].sort((a, b) => (a.due_date === b.due_date ? a.sequence - b.sequence : a.due_date < b.due_date ? -1 : 1))
  let left = amountCents
  const result = new Map<number, number>()
  for (const r of sorted) {
    if (left <= 0) break
    const portion = Math.min(left, toCents(r.remaining) ?? 0)
    if (portion > 0) result.set(r.id, portion)
    left -= portion
  }
  return result
}

export function addMonthsISO(iso: string, months: number): string {
  const [y, m, d] = iso.split('-').map(Number)
  const base = new Date(y!, m! - 1 + months, 1)
  const last = new Date(base.getFullYear(), base.getMonth() + 1, 0).getDate()
  base.setDate(Math.min(d!, last))
  return `${base.getFullYear()}-${String(base.getMonth() + 1).padStart(2, '0')}-${String(base.getDate()).padStart(2, '0')}`
}

export function addDaysISO(iso: string, days: number): string {
  const [y, m, d] = iso.split('-').map(Number)
  const dt = new Date(y!, m! - 1, d! + days)
  return `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`
}

/** PDF'i yeni sekmede aç (yazdırmak için). Açılır pencere engellenmesin diye pencere önce senkron açılır. */
export async function openPdf(path: string) {
  const win = window.open('', '_blank')
  try {
    const res = await fetch(`/api/v1${path}${path.includes('?') ? '&' : '?'}inline=1`, { credentials: 'same-origin', headers: { Accept: 'application/pdf' } })
    if (!res.ok) throw new Error('Belge oluşturulamadı.')
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    if (win) win.location.href = url
    else window.location.href = url
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (e) {
    win?.close()
    throw e
  }
}

// ------------------------------------------------------------------ tipler

export type PaymentRow = {
  id: number
  receipt_no: string
  amount: string
  method: string
  method_label: string
  paid_at: string
  student: { id: number; full_name: string; student_no: string } | null
  account: { id: number; name: string } | null
  received_by: string | null
  payer_name: string | null
  reference: string | null
  voided_at: string | null
  void_reason: string | null
  /** Bağlı kaydın programı ve kayıt no (liste ucunda) */
  program?: string | null
  enrollment_no?: string | null
  /** Tahsilatın düştüğü taksit sıra numaraları (liste ucunda) */
  installment_sequences?: number[] | null
}

export type AccountRow = {
  id: number
  kind: 'cash' | 'bank' | 'pos'
  kind_label: string
  name: string
  bank_name: string | null
  iban: string | null
  balance: string
  opening_balance: string
  is_active: boolean
  today_inflow: string
  today_outflow: string
  today_count: number
  last_movement_at: string | null
  transaction_count: number
}

export type InstallmentRow = {
  id: number
  student_id: number
  student: string
  student_no: string
  guardian: string | null
  enrollment_id: number
  enrollment_no: string
  program: string | null
  sequence: number
  due_date: string
  amount: string
  paid_amount: string
  remaining: string
  status: string
  effective_status: string
  days_overdue: number
  bucket: string | null
}

export type Category = { id: number; direction: 'income' | 'expense'; code: string; name: string; is_system: boolean; usage: number; selectable: boolean }
