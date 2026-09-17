import type { ButtonHTMLAttributes, ReactNode } from 'react'

/** Küçük, bağımlılıksız simge seti (lucide çizgi dili: 24 kutu, 2 px çizgi). */
const paths = {
  monitor: 'M3 4h18v12H3z M8 20h8 M12 16v4',
  cloud: 'M17.5 19a4.5 4.5 0 1 0-1.4-8.78A6 6 0 0 0 4.5 12.5 3.25 3.25 0 0 0 6 19z',
  check: 'M20 6 9 17l-5-5',
  alert: 'M12 9v4 M12 17h.01 M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  info: 'M12 16v-4 M12 8h.01 M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',
  x: 'M18 6 6 18 M6 6l12 12',
  refresh: 'M3 12a9 9 0 0 1 15-6.7L21 8 M21 3v5h-5 M21 12a9 9 0 0 1-15 6.7L3 16 M3 21v-5h5',
  sparkles: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z M19 17v4 M17 19h4',
  folder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
  arrowLeft: 'M19 12H5 M12 19l-7-7 7-7',
  wifiOff: 'M2 2l20 20 M8.5 16.4a5 5 0 0 1 7 0 M5 12.9a10 10 0 0 1 5.2-2.8 M19 12.9a10 10 0 0 0-2.2-1.6 M2 8.8a15 15 0 0 1 4.2-2.6 M22 8.8A15 15 0 0 0 11 5 M12 20h.01',
  database: 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3z M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6 M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3',
} as const

export type IconName = keyof typeof paths

export function Icon({ name, size = 20, className }: { name: IconName; size?: number; className?: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}
      strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
      {paths[name].split(' M').map((d, i) => <path key={i} d={i === 0 ? d : `M${d}`} />)}
    </svg>
  )
}

export function Spinner({ size = 18 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" className="spin" aria-label="Yükleniyor">
      <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" strokeOpacity=".2" strokeWidth="3" />
      <path d="M21 12a9 9 0 0 0-9-9" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  )
}

type BtnProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  loading?: boolean
  icon?: ReactNode
}

export function Button({ variant = 'secondary', loading, icon, children, disabled, ...rest }: BtnProps) {
  return (
    <button type="button" className={`btn btn-${variant}`} disabled={disabled || loading} {...rest}>
      {loading ? <Spinner size={16} /> : icon}
      {children}
    </button>
  )
}

export function Alert({ tone = 'info', title, children }: { tone?: 'info' | 'warning' | 'danger' | 'success'; title?: string; children?: ReactNode }) {
  const icon: IconName = tone === 'success' ? 'check' : tone === 'info' ? 'info' : 'alert'
  return (
    <div className={`alert ${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
      <Icon name={icon} size={17} />
      <div>
        {title && <strong>{title}</strong>}
        {title && children ? <br /> : null}
        {children}
      </div>
    </div>
  )
}

export function Field({ label, hint, error, optional, htmlFor, children }: {
  label: string; hint?: string; error?: string | null; optional?: boolean; htmlFor: string; children: ReactNode
}) {
  return (
    <div className="field">
      <label htmlFor={htmlFor}>{label} {optional && <span className="optional">(isteğe bağlı)</span>}</label>
      {children}
      {error ? <span className="error">{error}</span> : hint ? <span className="hint">{hint}</span> : null}
    </div>
  )
}

export type StepKey = 'mode' | 'server' | 'account' | 'install'

const STEP_LABELS: { key: StepKey; label: string }[] = [
  { key: 'mode', label: 'Kullanım biçimi' },
  { key: 'server', label: 'Kurum sunucusu' },
  { key: 'account', label: 'Eşleştirme ve giriş' },
  { key: 'install', label: 'Kurulum' },
]

/** Sol lacivert şerit + sağ içerik. `steps` verilmezse yalnız kimlik görünür. */
export function Shell({ active, steps = STEP_LABELS.map((s) => s.key), version, children }: {
  active?: StepKey; steps?: StepKey[]; version?: string; children: ReactNode
}) {
  const visible = STEP_LABELS.filter((s) => steps.includes(s.key))
  const activeIndex = visible.findIndex((s) => s.key === active)
  return (
    <div className="shell">
      <aside className="brand">
        <div className="logo">
          <div className="logo-mark">EB</div>
          <div className="logo-text">Erbaa Bilgi Eğitim<small>Kurum uygulaması</small></div>
        </div>
        {active && (
          <ol className="steps">
            {visible.map((s, i) => (
              <li key={s.key} className={i === activeIndex ? 'active' : i < activeIndex ? 'done' : ''}>
                <span className="dot">{i < activeIndex ? '✓' : i + 1}</span>
                {s.label}
              </li>
            ))}
          </ol>
        )}
        <div className="foot">{version ? `Sürüm ${version}` : ''}</div>
      </aside>
      <main className="main">
        <div className="titlebar" data-tauri-drag-region />
        <div className="content">{children}</div>
      </main>
    </div>
  )
}

export function ProgressBar({ value }: { value: number }) {
  const v = Math.max(0, Math.min(100, value))
  return <div className="bar" role="progressbar" aria-valuenow={Math.round(v)} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${v}%` }} /></div>
}
