import { useEffect, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Button } from './Button'

function useEscape(open: boolean, onClose: () => void) {
  useEffect(() => {
    if (!open) return
    const handler = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', handler)
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      window.removeEventListener('keydown', handler)
      document.body.style.overflow = overflow
    }
  }, [open, onClose])
}

export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
}: {
  open: boolean
  onClose: () => void
  title?: ReactNode
  description?: ReactNode
  children?: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg' | 'xl'
}) {
  useEscape(open, onClose)
  if (!open) return null
  const width = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }[size]

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
      <div className="absolute inset-0 bg-black/30 backdrop-blur-[2px] animate-fade-in" onClick={onClose} />
      <div
        role="dialog"
        aria-modal
        className={cn(
          'relative w-full bg-surface ring-1 ring-line shadow-[var(--shadow-pop)] animate-slide-up flex flex-col max-h-[92dvh]',
          'rounded-t-[var(--radius-xl)] sm:rounded-[var(--radius-xl)]',
          width,
        )}
      >
        {(title || description) && (
          <div className="flex items-start gap-3 px-5 pt-5 pb-3">
            <div className="flex-1 min-w-0">
              {title && <h2 className="text-[15.5px] font-semibold text-ink">{title}</h2>}
              {description && <p className="mt-0.5 text-[13px] text-ink-2">{description}</p>}
            </div>
            <Button variant="ghost" size="icon-sm" onClick={onClose} aria-label="Kapat">
              <X className="size-4" />
            </Button>
          </div>
        )}
        <div className="px-5 pb-5 overflow-y-auto scroll-thin">{children}</div>
        {footer && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-line px-5 py-3 bg-surface-2/50 rounded-b-[var(--radius-xl)]">{footer}</div>}
      </div>
    </div>,
    document.body,
  )
}

export function Drawer({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  width = 520,
}: {
  open: boolean
  onClose: () => void
  title?: ReactNode
  description?: ReactNode
  children?: ReactNode
  footer?: ReactNode
  width?: number
}) {
  useEscape(open, onClose)
  if (!open) return null

  return createPortal(
    <div className="fixed inset-0 z-50">
      <div className="absolute inset-0 bg-black/25 animate-fade-in" onClick={onClose} />
      <aside
        role="dialog"
        aria-modal
        className="absolute right-0 top-0 h-full w-full bg-surface ring-1 ring-line shadow-[var(--shadow-pop)] animate-slide-left flex flex-col"
        style={{ maxWidth: width }}
      >
        <div className="flex items-start gap-3 border-b border-line px-5 py-4">
          <div className="flex-1 min-w-0">
            {title && <h2 className="text-[15.5px] font-semibold">{title}</h2>}
            {description && <p className="mt-0.5 text-[13px] text-ink-2">{description}</p>}
          </div>
          <Button variant="ghost" size="icon-sm" onClick={onClose} aria-label="Kapat">
            <X className="size-4" />
          </Button>
        </div>
        <div className="flex-1 overflow-y-auto scroll-thin px-5 py-4">{children}</div>
        {footer && <div className="flex items-center justify-end gap-2 border-t border-line px-5 py-3">{footer}</div>}
      </aside>
    </div>,
    document.body,
  )
}

export type MenuItem = { label: ReactNode; icon?: ReactNode; onClick?: () => void; danger?: boolean; disabled?: boolean; hidden?: boolean } | 'divider'

/** Bağlamsal menü (satır sonu "…" gibi) */
export function Menu({ trigger, items, align = 'end', width = 200 }: { trigger: ReactNode; items: MenuItem[]; align?: 'start' | 'end'; width?: number }) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false)
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', esc)
    return () => {
      document.removeEventListener('mousedown', close)
      document.removeEventListener('keydown', esc)
    }
  }, [open])

  const visible = items.filter((i) => i === 'divider' || !i.hidden)

  return (
    <div ref={ref} className="relative inline-flex">
      <span
        onClick={(e) => {
          e.stopPropagation()
          setOpen((v) => !v)
        }}
      >
        {trigger}
      </span>
      {open && (
        <div
          className={cn('absolute top-full mt-1 z-40 rounded-[var(--radius-md)] bg-surface p-1 ring-1 ring-line shadow-[var(--shadow-pop)] animate-slide-up', align === 'end' ? 'right-0' : 'left-0')}
          style={{ width }}
        >
          {visible.map((item, i) =>
            item === 'divider' ? (
              <div key={i} className="my-1 h-px bg-line" />
            ) : (
              <button
                key={i}
                type="button"
                disabled={item.disabled}
                onClick={(e) => {
                  e.stopPropagation()
                  setOpen(false)
                  item.onClick?.()
                }}
                className={cn(
                  'flex w-full items-center gap-2.5 rounded-[6px] px-2.5 h-8 text-[13px] text-left transition-colors disabled:opacity-40',
                  item.danger ? 'text-danger hover:bg-danger-soft' : 'text-ink hover:bg-surface-2',
                  '[&_svg]:size-4 [&_svg]:text-current [&_svg]:opacity-70',
                )}
              >
                {item.icon}
                {item.label}
              </button>
            ),
          )}
        </div>
      )}
    </div>
  )
}

/** Onay penceresi (silme / iptal gibi geri alınamaz işlemler) */
export function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  description,
  confirmLabel = 'Onayla',
  danger,
  loading,
  children,
}: {
  open: boolean
  onClose: () => void
  onConfirm: () => void
  title: string
  description?: ReactNode
  confirmLabel?: string
  danger?: boolean
  loading?: boolean
  children?: ReactNode
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      description={description}
      size="sm"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Vazgeç
          </Button>
          <Button variant={danger ? 'danger' : 'primary'} loading={loading} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      {children}
    </Modal>
  )
}

export function Tooltip({ content, children, side = 'top' }: { content: ReactNode; children: ReactNode; side?: 'top' | 'bottom' | 'right' }) {
  const pos = { top: 'bottom-full mb-1.5 left-1/2 -translate-x-1/2', bottom: 'top-full mt-1.5 left-1/2 -translate-x-1/2', right: 'left-full ml-2 top-1/2 -translate-y-1/2' }[side]
  return (
    <span className="relative inline-flex group/tip">
      {children}
      <span
        role="tooltip"
        className={cn(
          'pointer-events-none absolute z-50 whitespace-nowrap rounded-[6px] bg-ink px-2 py-1 text-[11.5px] font-medium text-bg opacity-0 transition-opacity delay-150 group-hover/tip:opacity-100',
          pos,
        )}
      >
        {content}
      </span>
    </span>
  )
}
