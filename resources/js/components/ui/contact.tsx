import type { ReactNode } from 'react'
import { Mail, MapPin, MessageCircle, Phone, User } from 'lucide-react'
import { cn } from '@/lib/cn'

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
        <Phone className="size-3.5 shrink-0 text-success" aria-hidden />
        <span className="tabular">{value}</span>
      </span>
    )
  }
  return (
    <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap', muted ? 'text-[12.5px] text-ink-3' : 'text-ink-2', className)}>
      <Phone className="size-3.5 shrink-0 text-success" aria-hidden />
      <a href={`tel:${value}`} onClick={(e) => e.stopPropagation()} className="tabular hover:text-ink hover:underline">{formatPhone(value)}</a>
      {whatsapp && (
        <a href={waHref(value)} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()} title="WhatsApp" aria-label="WhatsApp" className="text-success hover:brightness-90">
          <MessageCircle className="size-3.5" />
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
