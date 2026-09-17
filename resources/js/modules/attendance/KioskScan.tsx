import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Html5Qrcode } from 'html5-qrcode'
import { CheckCircle2, LogIn, LogOut, ScanLine, ShieldAlert, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader } from '@/components/ui/layout'
import { EmptyState, Spinner } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'

type ScanResult = { status: string; message: string; data?: { event_type?: 'ENTRY' | 'EXIT' } }

const SCAN_ELEMENT_ID = 'attendance-kiosk-qr-region'

/**
 * Kiosk QR okuma ekranı — ŞİMDİLİK yöneticinin oturumuyla çalışır (normal modül
 * rotası, AuthGate içinde). Gerçek kiosk dağıtımı için oturumsuz ayrı bir rota
 * gerekir; bkz. proje raporundaki "ortak router değişikliği" notu.
 */
export default function KioskScan() {
  const can = useCan()
  if (!can('presence.live')) {
    return (
      <div className="animate-fade-in">
        <PageHeader title="QR yoklama kioskü" breadcrumbs={[{ label: 'Yoklama' }, { label: 'Kiosk' }]} />
        <EmptyState
          icon={<ShieldAlert />}
          title="Bu ekran için yetkiniz yok"
          description="QR ile giriş/çıkış kioskü yalnızca “Canlı giriş/çıkış” yetkisi olan hesaplarla açılabilir. Yetki için kurum yöneticinize başvurun."
          action={<ButtonLink variant="primary" to="/">Panoya dön</ButtonLink>}
        />
      </div>
    )
  }
  return <KioskScanner />
}

function KioskScanner() {
  const navigate = useNavigate()
  const scannerRef = useRef<Html5Qrcode | null>(null)
  const busyRef = useRef(false)
  const lastValueRef = useRef<{ value: string; at: number } | null>(null)
  const [feedback, setFeedback] = useState<ScanResult | null>(null)
  const [cameraError, setCameraError] = useState<string | null>(null)
  const [starting, setStarting] = useState(true)

  useEffect(() => {
    const scanner = new Html5Qrcode(SCAN_ELEMENT_ID, { verbose: false });
    scannerRef.current = scanner

    scanner
      .start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: 260 },
        (decodedText) => void handleScan(decodedText),
        () => {},
      )
      .then(() => setStarting(false))
      .catch((err) => {
        setStarting(false)
        setCameraError(err instanceof Error ? err.message : 'Kameraya erişilemedi. Tarayıcı izinlerini kontrol edin.')
      })

    return () => {
      scanner.stop().catch(() => {}).finally(() => scanner.clear())
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function handleScan(value: string) {
    const now = Date.now()
    if (busyRef.current) return
    if (lastValueRef.current && lastValueRef.current.value === value && now - lastValueRef.current.at < 4000) return

    busyRef.current = true
    lastValueRef.current = { value, at: now }

    try {
      const res = await api.post<ScanResult>('/attendance/live/scan', { value })
      setFeedback(res)
    } catch (e) {
      setFeedback({ status: 'error', message: e instanceof ApiError ? e.firstError() : 'Okutma işlenemedi.' })
    } finally {
      setTimeout(() => {
        setFeedback(null)
        busyRef.current = false
      }, 2200)
    }
  }

  const tone =
    feedback?.status === 'accepted' && feedback.data?.event_type === 'ENTRY'
      ? 'success'
      : feedback?.status === 'accepted'
        ? 'neutral'
        : feedback
          ? 'warning'
          : null

  return (
    <div className="fixed inset-0 z-50 bg-[#0b0d12] text-white flex flex-col items-center justify-center p-6">
      <Button variant="ghost" size="icon" className="absolute top-4 right-4 text-white/70 hover:text-white hover:bg-white/10" onClick={() => navigate('/yoklama/canli')} aria-label="Kapat">
        <X className="size-5" />
      </Button>

      <div className="flex items-center gap-2 mb-6 text-white/60">
        <ScanLine className="size-5" />
        <p className="text-[14px] font-medium">Yoklama · QR ile Giriş/Çıkış</p>
      </div>

      <div className="relative w-full max-w-md aspect-square rounded-[28px] overflow-hidden ring-2 ring-white/15 bg-black">
        <div id={SCAN_ELEMENT_ID} className="w-full h-full [&_video]:!w-full [&_video]:!h-full [&_video]:object-cover" />

        {starting && (
          <div className="absolute inset-0 grid place-items-center bg-black/60">
            <Spinner className="size-8 border-white/30 border-t-white" />
          </div>
        )}

        {cameraError && (
          <div className="absolute inset-0 grid place-items-center bg-black/80 p-6 text-center">
            <p className="text-[13.5px] text-white/80">{cameraError}</p>
          </div>
        )}

        {feedback && (
          <div
            className={cn(
              'absolute inset-0 grid place-items-center p-6 text-center animate-fade-in',
              tone === 'success' && 'bg-emerald-600/95',
              tone === 'neutral' && 'bg-slate-700/95',
              tone === 'warning' && 'bg-amber-600/95',
            )}
          >
            <div>
              {feedback.status === 'accepted' ? (
                feedback.data?.event_type === 'ENTRY' ? <LogIn className="mx-auto mb-3 size-12" /> : <LogOut className="mx-auto mb-3 size-12" />
              ) : (
                <CheckCircle2 className="mx-auto mb-3 size-12 opacity-80" />
              )}
              <p className="text-[26px] font-semibold leading-tight">{feedback.message}</p>
            </div>
          </div>
        )}
      </div>

      <p className="mt-6 text-[13px] text-white/50">Öğrenci kartındaki QR kodu kameraya gösterin</p>
    </div>
  )
}
