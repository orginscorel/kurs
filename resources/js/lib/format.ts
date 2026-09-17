const TZ = 'Europe/Istanbul'

const moneyFmt = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const moneyShortFmt = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 })
const numberFmt = new Intl.NumberFormat('tr-TR')
const compactFmt = new Intl.NumberFormat('tr-TR', { notation: 'compact', maximumFractionDigits: 1 })

export function money(value: string | number | null | undefined, opts: { symbol?: boolean; short?: boolean } = {}): string {
  const n = Number(value ?? 0)
  const s = opts.short ? moneyShortFmt.format(n) : moneyFmt.format(n)
  return opts.symbol === false ? s : `${s} ₺`
}

/** Kısa tutar: "720,8 bin ₺", "7,9 mn ₺" (Intl'in "B"/"Mn" kısaltması milyarla karışıyor) */
export function compactMoney(value: string | number | null | undefined): string {
  const n = Number(value ?? 0)
  const abs = Math.abs(n)
  const one = (x: number) => x.toLocaleString('tr-TR', { maximumFractionDigits: x >= 100 ? 0 : 1 })
  if (abs >= 1_000_000_000) return `${one(n / 1_000_000_000)} mr ₺`
  if (abs >= 1_000_000) return `${one(n / 1_000_000)} mn ₺`
  if (abs >= 10_000) return `${one(n / 1_000)} bin ₺`
  return `${compactFmt.format(n)} ₺`
}

export function num(value: string | number | null | undefined, digits?: number): string {
  const n = Number(value ?? 0)
  return digits === undefined ? numberFmt.format(n) : n.toLocaleString('tr-TR', { minimumFractionDigits: digits, maximumFractionDigits: digits })
}

export function percent(value: number | null | undefined, digits = 0): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return `%${Number(value).toLocaleString('tr-TR', { maximumFractionDigits: digits })}`
}

function toDate(value: string | Date | null | undefined): Date | null {
  if (!value) return null
  if (value instanceof Date) return value
  // "2026-09-14" yerel tarih olarak yorumlansın (UTC kaymasın)
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const [y, m, d] = value.split('-').map(Number)
    return new Date(y!, m! - 1, d!)
  }
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? null : d
}

export function date(value: string | Date | null | undefined, style: 'short' | 'long' | 'day' = 'short'): string {
  const d = toDate(value)
  if (!d) return '—'
  const opts: Intl.DateTimeFormatOptions =
    style === 'long'
      ? { day: 'numeric', month: 'long', year: 'numeric', timeZone: TZ }
      : style === 'day'
        ? { day: 'numeric', month: 'long', weekday: 'long', timeZone: TZ }
        : { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: TZ }
  return d.toLocaleDateString('tr-TR', opts)
}

export function time(value: string | Date | null | undefined): string {
  const d = toDate(value)
  if (!d) return '—'
  return d.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', timeZone: TZ })
}

export function dateTime(value: string | Date | null | undefined): string {
  const d = toDate(value)
  if (!d) return '—'
  return `${date(d)} ${time(d)}`
}

export function relative(value: string | Date | null | undefined): string {
  const d = toDate(value)
  if (!d) return '—'
  const diff = (d.getTime() - Date.now()) / 1000
  const abs = Math.abs(diff)
  const rtf = new Intl.RelativeTimeFormat('tr', { numeric: 'auto' })
  if (abs < 45) return 'az önce'
  if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour')
  if (abs < 86400 * 30) return rtf.format(Math.round(diff / 86400), 'day')
  return date(d)
}

export function duration(minutes: number | null | undefined): string {
  const m = Math.max(0, Math.round(minutes ?? 0))
  const h = Math.floor(m / 60)
  const r = m % 60
  if (h === 0) return `${r} dk`
  return r === 0 ? `${h} sa` : `${h} sa ${r} dk`
}

export function initials(name: string | null | undefined): string {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/)
  const first = parts[0]?.[0] ?? ''
  const last = parts.length > 1 ? parts[parts.length - 1]![0] ?? '' : ''
  return (first + last).toLocaleUpperCase('tr-TR')
}

/** Bugünün tarihi (İstanbul) YYYY-MM-DD */
export function todayISO(): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
  return parts
}

export function phone(value: string | null | undefined): string {
  if (!value) return '—'
  const d = value.replace(/\D/g, '')
  const local = d.startsWith('90') ? d.slice(2) : d.startsWith('0') ? d.slice(1) : d
  if (local.length !== 10) return value
  return `0${local.slice(0, 3)} ${local.slice(3, 6)} ${local.slice(6, 8)} ${local.slice(8)}`
}
