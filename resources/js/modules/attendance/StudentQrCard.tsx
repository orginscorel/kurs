import { useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import QRCode from 'qrcode'
import { toast } from 'sonner'
import { Printer, RefreshCw } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Avatar, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/overlay'

type QrData = { student: { id: number; full_name: string; student_no: string; photo_url: string | null }; value: string }

export default function StudentQrCard() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const [confirmRegen, setConfirmRegen] = useState(false)

  const { data, isLoading, error } = useQuery({ queryKey: ['attendance', 'student-qr', id], queryFn: () => api.get<QrData>(`/attendance/students/${id}/qr`) })

  useEffect(() => {
    if (data?.value && canvasRef.current) {
      QRCode.toCanvas(canvasRef.current, data.value, { width: 240, margin: 1, color: { dark: '#141414', light: '#ffffff' } }).catch(() => {})
    }
  }, [data?.value])

  const regenMutation = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/attendance/students/${id}/qr/regenerate`),
    onSuccess: (res) => { toast.success(res.message); setConfirmRegen(false); qc.invalidateQueries({ queryKey: ['attendance', 'student-qr', id] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Yenilenemedi.'),
  })

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Öğrenci QR Kartı"
        description="Kiosk ekranında okutarak giriş/çıkış kaydı için kullanılır"
        actions={
          <div className="print:hidden flex gap-2">
            {can('attendance.override') && (
              <Button icon={<RefreshCw className="size-4" />} onClick={() => setConfirmRegen(true)}>Yenile</Button>
            )}
            <Button variant="primary" icon={<Printer className="size-4" />} onClick={() => window.print()}>Yazdır</Button>
          </div>
        }
      />

      {error ? (
        <EmptyState title="Öğrenci bulunamadı" description={error instanceof ApiError && error.status !== 404 ? error.message : 'Kayıt silinmiş ya da adres hatalı olabilir.'} action={<ButtonLink to="/ogrenciler">Öğrencilere dön</ButtonLink>} />
      ) : isLoading || !data ? (
        <Skeleton className="h-80 max-w-sm rounded-[var(--radius-lg)]" />
      ) : (
        <Panel className="max-w-sm print:ring-0 print:shadow-none">
          <div className="flex flex-col items-center text-center gap-3 py-2">
            <Avatar name={data.student.full_name} src={data.student.photo_url} size={64} />
            <div>
              <p className="text-[16px] font-semibold">{data.student.full_name}</p>
              <p className="text-[13px] text-ink-3 tabular">Öğrenci No {data.student.student_no}</p>
            </div>
            <canvas ref={canvasRef} className="rounded-[var(--radius-md)]" />
            <p className="text-[12px] text-ink-3">Bu kartı yoklama kiosk ekranında okutun.</p>
          </div>
        </Panel>
      )}

      <ConfirmDialog
        open={confirmRegen}
        onClose={() => setConfirmRegen(false)}
        onConfirm={() => regenMutation.mutate()}
        title="QR kodu yenile"
        danger
        loading={regenMutation.isPending}
        confirmLabel="Yenile"
        description="Eski QR kart artık çalışmaz; yeni bir kart yazdırmanız gerekir. Devam edilsin mi?"
      />
    </div>
  )
}
