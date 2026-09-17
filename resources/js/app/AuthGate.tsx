import { useEffect, type ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '@/app/auth'

export function AuthGate({ children }: { children: ReactNode }) {
  const status = useAuth((s) => s.status)
  const load = useAuth((s) => s.load)
  const location = useLocation()

  useEffect(() => {
    if (status === 'loading') void load()
  }, [status, load])

  if (status === 'loading') {
    return (
      <div className="grid min-h-dvh place-items-center bg-bg">
        <div className="flex flex-col items-center gap-3 animate-fade-in">
          <BrandMark size={40} />
          <span className="size-4 rounded-full border-2 border-line-strong border-t-primary animate-spin" />
        </div>
      </div>
    )
  }

  if (status === 'guest') {
    const next = location.pathname + location.search
    return <Navigate to={next && next !== '/' ? `/giris?next=${encodeURIComponent(next)}` : '/giris'} replace />
  }

  return <>{children}</>
}

export function BrandMark({ size = 32, className }: { size?: number; className?: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 64 64" className={className} aria-hidden>
      <rect width="64" height="64" rx="16" fill="var(--primary)" />
      <path d="M18 22l14-7 14 7-14 7-14-7z" fill="#fff" />
      <path d="M24 27v9c0 3 4 6 8 6s8-3 8-6v-9" fill="none" stroke="#fff" strokeWidth="3.5" strokeLinecap="round" />
      <path d="M46 22v12" stroke="#fff" strokeWidth="3" strokeLinecap="round" />
    </svg>
  )
}
