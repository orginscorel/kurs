/**
 * SMS karakter/parça hesabı — sunucudaki App\Services\Messaging\Sms\SmsLength ile aynı kurallar.
 * tr: Türkçe karakter tablosu 155/150 · unicode: 70/67 · ascii: Türkçe harfler dönüştürülür, 160/153.
 * GSM-7 genişletme karakterleri (^ { } \ [ ~ ] | €) 2 karakter sayılır.
 */
export type SmsMode = 'tr' | 'unicode' | 'ascii'
export type SmsEncoding = 'gsm7' | 'tr' | 'unicode'

const GSM_BASIC = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà"
const GSM_EXTENDED = '^{}\\[~]|€\f'
const TURKISH = 'çÇğĞıİöÖşŞüÜ'
const ASCII_MAP: Record<string, string> = {
  ç: 'c', Ç: 'C', ğ: 'g', Ğ: 'G', ı: 'i', İ: 'I', ö: 'o', Ö: 'O', ş: 's', Ş: 'S', ü: 'u', Ü: 'U',
  â: 'a', Â: 'A', î: 'i', Î: 'I', û: 'u', Û: 'U', '’': "'", '‘': "'", '“': '"', '”': '"', '–': '-', '—': '-', '…': '...',
}

export const SMS_LIMITS: Record<SmsEncoding, { single: number; multi: number }> = {
  gsm7: { single: 160, multi: 153 },
  tr: { single: 155, multi: 150 },
  unicode: { single: 70, multi: 67 },
}

export const ENCODING_LABEL: Record<SmsEncoding, string> = { gsm7: 'Standart (GSM)', tr: 'Türkçe karakterli', unicode: 'Unicode' }

export type SmsAnalysis = { encoding: SmsEncoding; length: number; parts: number; perPart: number; remaining: number; text: string }

export function analyzeSms(input: string, mode: SmsMode = 'tr'): SmsAnalysis {
  let text = input.replace(/\r\n/g, '\n')
  if (mode === 'ascii') text = Array.from(text).map((c) => ASCII_MAP[c] ?? c).join('')

  const chars = Array.from(text)
  let encoding: SmsEncoding = 'gsm7'
  if (mode === 'unicode') encoding = 'unicode'
  else {
    for (const c of chars) {
      if (GSM_BASIC.includes(c) || GSM_EXTENDED.includes(c)) continue
      if (mode === 'tr' && TURKISH.includes(c)) { encoding = 'tr'; continue }
      encoding = 'unicode'
      break
    }
  }

  // Unicode: UTF-16 kod birimi (emoji 2); GSM: genişletme karakteri 2
  const length = encoding === 'unicode' ? text.length : chars.reduce((n, c) => n + (GSM_EXTENDED.includes(c) ? 2 : 1), 0)
  const lim = SMS_LIMITS[encoding]
  if (length === 0) return { encoding, length, parts: 0, perPart: lim.single, remaining: lim.single, text }
  if (length <= lim.single) return { encoding, length, parts: 1, perPart: lim.single, remaining: lim.single - length, text }
  const parts = Math.ceil(length / lim.multi)
  return { encoding, length, parts, perPart: lim.multi, remaining: parts * lim.multi - length, text }
}

/** {ad} ve {{ad}} yazımları + eski şablon adları */
const ALIASES: Record<string, string> = { ogrenci_adi: 'ogrenci_ad', veli_adi: 'ad_soyad', adi: 'ad', adsoyad: 'ad_soyad' }

export function renderVars(text: string, vars: Record<string, string>, known: string[]): string {
  return text.replace(/\{\{?\s*([a-zA-Z_ğüşıöçĞÜŞİÖÇ]+)\s*\}?\}/gu, (m, k: string) => {
    const key = ALIASES[k.toLowerCase()] ?? k.toLowerCase()
    return known.includes(key) ? (vars[key] ?? '') : m
  })
}
