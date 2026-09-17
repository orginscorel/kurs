import { Component, type ErrorInfo, type ReactNode } from 'react'
import { AlertTriangle, RotateCw } from 'lucide-react'

/**
 * İstemci hatasını sunucu loguna gönderir (kullanıcıya teknik ayrıntı gösterilmez).
 * Aynı hata kısa sürede tekrar gönderilmez.
 */
const reported = new Set<string>()
export function reportClientError(error: unknown, context: string, componentStack?: string | null) {
  try {
    const err = error instanceof Error ? error : new Error(String(error))
    const key = `${context}|${err.message}`
    if (reported.has(key)) return
    reported.add(key)
    const xsrf = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1]
    void fetch('/api/v1/client-errors', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) },
      body: JSON.stringify({
        context,
        message: err.message.slice(0, 500),
        stack: (err.stack ?? '').slice(0, 3000),
        component_stack: (componentStack ?? '').slice(0, 2000),
        url: location.href,
      }),
    }).catch(() => {})
  } catch {
    /* raporlama asla uygulamayı bozmamalı */
  }
}

/** Bölüm bazlı hata sınırı: tek bir bileşen çökerse sayfanın geri kalanı çalışmaya devam eder. */
export class ErrorBoundary extends Component<{ children: ReactNode; name: string; fallback?: ReactNode; className?: string }, { error: Error | null }> {
  state = { error: null as Error | null }

  static getDerivedStateFromError(error: Error) {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error(`[${this.props.name}]`, error)
    reportClientError(error, `boundary:${this.props.name}`, info.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children
    if (this.props.fallback) return this.props.fallback
    return (
      <div className={this.props.className ?? 'flex min-h-[140px] flex-col items-center justify-center gap-2 rounded-[var(--radius-lg)] bg-surface px-4 py-6 text-center ring-1 ring-line'}>
        <AlertTriangle className="size-5 text-warning" />
        <p className="text-[13.5px] font-medium text-ink">Bu bölüm yüklenemedi</p>
        <button
          type="button"
          onClick={() => this.setState({ error: null })}
          className="inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] px-2.5 h-8 text-[13px] text-ink-2 hover:bg-surface-2"
        >
          <RotateCw className="size-3.5" /> Yeniden dene
        </button>
      </div>
    )
  }
}
