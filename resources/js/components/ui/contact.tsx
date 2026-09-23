import type { ReactNode } from 'react'
import { Mail, MapPin, Phone, User } from 'lucide-react'
import { cn } from '@/lib/cn'

/** Tanınabilir WhatsApp logosu (lucide'de marka ikonu yok). */
function WhatsAppGlyph({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden>
      <path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.47-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.06 2.87 1.21 3.07.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.62.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.18-1.41-.07-.13-.27-.2-.57-.35M12.05 21.79h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.82 9.82 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.89 9.88m8.41-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.69 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.16-3.48-8.41" />
    </svg>
  )
}

/** 05321234567 / 905321234567 → 0532 123 45 67 */
export function formatPhone(p?: string | null): string {
  let d = (p ?? '').replace(/\D+/g, '')
  if (d.length === 12 && d.startsWith('90')) d = `0${d.slice(2)}`
  if (d.length === 10) d = `0${d}`
  return d.length === 11 ? `${d.slice(0, 4)} ${d.slice(4, 7)} ${d.slice(7, 9)} ${d.slice(9)}` : (p ?? '')
}

const waHref = (p: string) => {
  let d = p.replace(/\D+/g, '')
  if (d.startsWith('0')) d = `9${d}`
  else if (d.length === 10) d = `90${d}`
  return `https://wa.me/${d}`
}

const empty = <span className="text-ink-3">—</span>

/** Simgeli, tıklanabilir telefon (tablo hücresinde satır tıklamasını tetiklemez). */
export function PhoneText({ value, whatsapp, className, muted }: { value?: string | null; whatsapp?: boolean; className?: string; muted?: boolean }) {
  if (!value) return empty
  // Yetkisiz kullanıcıya maskeli gelen numara (0532 ••• •• 67): simgeli ama bağlantısız
  if (value.includes('•')) {
    return (
      <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap', muted ? 'text-[12.5px] text-ink-3' : 'text-ink-2', className)}>
        <Phone className="size-3.5 shrink-0 text-ink-3" aria-hidden />
        <span className="tabular">{value}</span>
      </span>
    )
  }
  return (
    <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap', muted ? 'text-[12.5px] text-ink-3' : 'text-ink-2', className)}>
      <Phone className="size-3.5 shrink-0 text-ink-3" aria-hidden />
      <a href={`tel:${value}`} onClick={(e) => e.stopPropagation()} className="tabular hover:text-ink hover:underline">{formatPhone(value)}</a>
      {whatsapp && (
        <a href={waHref(value)} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()} title={`WhatsApp'ta yaz: ${formatPhone(value)}`} aria-label="WhatsApp'ta yaz"
          className="ml-0.5 inline-flex items-center gap-1 rounded-full bg-success/10 px-1.5 py-0.5 text-[11px] font-medium text-success hover:bg-success/20">
          <WhatsAppGlyph className="size-3" />
          WhatsApp
        </a>
      )}
    </span>
  )
}

export function MailText({ value, className }: { value?: string | null; className?: string }) {
  if (!value) return empty
  return (
    <span className={cn('inline-flex min-w-0 items-center gap-1.5 text-ink-2', className)}>
      <Mail className="size-3.5 shrink-0 text-ink-3" aria-hidden />
      <a href={`mailto:${value}`} onClick={(e) => e.stopPropagation()} className="truncate hover:text-ink hover:underline">{value}</a>
    </span>
  )
}

/** Kişi adı + simge (ör. veli). */
export function PersonText({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <span className={cn('inline-flex min-w-0 items-center gap-1.5', className)}>
      <User className="size-3.5 shrink-0 text-ink-3" aria-hidden />
      <span className="truncate">{children}</span>
    </span>
  )
}

/** Simgeli adres (uzun adres satır kırar). */
export function AddressText({ value, className }: { value?: string | null; className?: string }) {
  if (!value) return empty
  return (
    <span className={cn('inline-flex min-w-0 items-start gap-1.5 text-ink-2', className)}>
      <MapPin className="mt-0.5 size-3.5 shrink-0 text-ink-3" aria-hidden />
      <span className="break-words">{value}</span>
    </span>
  )
}
