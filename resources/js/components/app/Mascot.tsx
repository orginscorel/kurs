import { cn } from '@/lib/cn'

/**
 * Kurum maskotu "Bilge" — kurum logosundaki mezuniyet kepini taşıyan baykuş.
 * Vektör (SVG) ve CSS ile canlandırılır: GIF yok, dosya küçük, takılmaz.
 * Durumlar: idle (hafif nefes), loading (sallanma + göz kırpma), empty (sakin), happy (zıplama).
 * Hareketi azaltılmış kip (prefers-reduced-motion) otomatik olarak durur — app.css'teki kural.
 */
export type MascotState = 'idle' | 'loading' | 'empty' | 'happy'

export function Mascot({ size = 96, state = 'idle', className }: { size?: number; state?: MascotState; className?: string }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 120 120"
      className={cn('mascot', `mascot-${state}`, className)}
      role="img"
      aria-label="Bilge"
    >
      {/* gövde */}
      <g className="mascot-body">
        <ellipse cx="60" cy="74" rx="31" ry="32" fill="var(--primary)" />
        <path d="M60 46c14 0 24 10 24 23 0 6-3 11-8 14-5 3-11 4-16 4s-11-1-16-4c-5-3-8-8-8-14 0-13 10-23 24-23z" fill="var(--primary-ink)" opacity=".18" />
        {/* kanatlar: gövdeden bir ton açık, dışa taşar — çırpınca görünür */}
        <path className="mascot-wing mascot-wing-l" d="M30 64c-7 5-10 17-6 26 3 7 9 10 13 8 3-2 2-6-1-11-3-7-4-15-2-22-1-2-3-2-4-1z" fill="var(--primary)" stroke="#fff" strokeOpacity=".28" strokeWidth="1.5" />
        <path className="mascot-wing mascot-wing-r" d="M90 64c7 5 10 17 6 26-3 7-9 10-13 8-3-2-2-6 1-11 3-7 4-15 2-22 1-2 3-2 4-1z" fill="var(--primary)" stroke="#fff" strokeOpacity=".28" strokeWidth="1.5" />
        {/* kulak püskülleri */}
        <path d="M40 48c-3-6-3-11-1-14 3 2 6 6 7 11z" fill="var(--primary)" />
        <path d="M80 48c3-6 3-11 1-14-3 2-6 6-7 11z" fill="var(--primary)" />
        {/* göğüs */}
        <ellipse cx="60" cy="80" rx="17" ry="19" fill="#fff" opacity=".92" />
        {/* gözler */}
        <g className="mascot-eyes">
          <circle cx="50" cy="62" r="10" fill="#fff" />
          <circle cx="70" cy="62" r="10" fill="#fff" />
          <circle className="mascot-pupil" cx="51" cy="63" r="4.4" fill="#0f1621" />
          <circle className="mascot-pupil" cx="71" cy="63" r="4.4" fill="#0f1621" />
        </g>
        {/* kaşlar */}
        <path d="M43 52c3-2 8-2 11 0M66 52c3-2 8-2 11 0" stroke="#fff" strokeWidth="2.4" strokeLinecap="round" opacity=".85" fill="none" />
        {/* gaga */}
        <path d="M60 68l5 7h-10l5-7z" fill="var(--warning)" />
        {/* ayaklar */}
        <path d="M52 105v4M68 105v4" stroke="var(--warning)" strokeWidth="3.5" strokeLinecap="round" />
      </g>
      {/* kep (logodaki biçim) */}
      <g className="mascot-cap">
        <path d="M60 30l22 9-22 9-22-9 22-9z" fill="var(--ink)" />
        <path d="M78 41v10" stroke="var(--ink)" strokeWidth="3" strokeLinecap="round" />
        <circle cx="78" cy="53" r="3" fill="var(--warning)" className="mascot-tassel" />
      </g>
    </svg>
  )
}
