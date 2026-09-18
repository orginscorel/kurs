import { useState } from 'react'
import { Building2 } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Modal } from '@/components/ui/overlay'

/** Yazılımın sahibi/üreticisi. Yasal künye; kurumun kendi bilgileriyle karıştırılmamalı. */
export const VENDOR = {
  name: 'Bogahost Bilişim ve Telekomünikasyon Hiz. San. ve Tic. Ltd. Şti.',
  short: 'Bogahost Bilişim',
  taxOffice: 'Erbaa Vergi Dairesi',
  taxNo: '1791380583',
  mersis: '0179138058300001',
  tradeRegistryNo: '2334',
  chamber: 'Erbaa Ticaret Odası',
  trademarkFileNo: '2018 / 71776',
  address: 'Fevzipaşa Mahallesi, Erek Caddesi, 127/E Erbaa/TOKAT',
} as const

const ROWS: [string, string][] = [
  ['Ticari unvan', VENDOR.name],
  ['Vergi dairesi', VENDOR.taxOffice],
  ['Vergi numarası', VENDOR.taxNo],
  ['MERSİS numarası', VENDOR.mersis],
  ['Ticaret sicil no', VENDOR.tradeRegistryNo],
  ['Ticaret odası kaydı', VENDOR.chamber],
  ['Marka tescil dosya no', VENDOR.trademarkFileNo],
  ['Adres', VENDOR.address],
]

/** Alt bilgi satırı: tıklayınca yasal künyeyi açar. */
export function VendorInfo({ className, compact = false }: { className?: string; compact?: boolean }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className={cn(
          'flex w-full items-center justify-center gap-1.5 rounded-[var(--radius-sm)] px-2 py-1 text-[11.5px] leading-tight text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink-2',
          className,
        )}
      >
        <Building2 className="size-3.5 shrink-0" aria-hidden />
        <span className="min-w-0 truncate">
          Yazılım: <span className="font-medium text-ink-2">{compact ? VENDOR.short : VENDOR.name}</span>
        </span>
      </button>
      <Modal open={open} onClose={() => setOpen(false)} title="Yazılım sahibi" description="Bu yazılımın üreticisi ve yasal künyesi.">
        <dl className="flex flex-col gap-2.5">
          {ROWS.map(([label, value]) => (
            <div key={label} className="grid gap-0.5 sm:grid-cols-[190px_minmax(0,1fr)] sm:gap-3">
              <dt className="text-[12.5px] text-ink-3">{label}</dt>
              <dd className="min-w-0 break-words text-[14px]">{value}</dd>
            </div>
          ))}
        </dl>
        <p className="mt-4 border-t border-line pt-3 text-[12.5px] text-ink-3">
          Yazılım ve tüm hakları {VENDOR.short}'e aittir. Kurum verileri kuruma aittir.
        </p>
      </Modal>
    </>
  )
}
