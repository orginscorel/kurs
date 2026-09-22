import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Check, GraduationCap, Inbox, Package, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, dateTime } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Segmented, Select, Textarea } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'

type Row = {
  id: number; kind: string; kind_label: string; status: string; status_label: string; note: string | null; decision_note: string | null
  student: { id: number; full_name: string; student_no: string } | null
  package: { id: number; name: string; has_coaching: boolean } | null
  requested_by: string | null; handled_by: string | null; handled_at: string | null; created_at: string
}
type ListResp = { data: Row[]; meta: { pending_count?: number } }

const statusTone: Record<string, 'warning' | 'success' | 'danger' | 'neutral'> = { pending: 'warning', approved: 'success', rejected: 'danger' }

export default function PackageRequests() {
  const can = useCan()
  const [status, setStatus] = useState('pending')
  const [action, setAction] = useState<{ row: Row; mode: 'approve' | 'reject' } | null>(null)
  const { data, isLoading } = useQuery({
    queryKey: ['finance', 'package-requests', status],
    queryFn: () => api.get<ListResp>('/package-requests', { status: status === 'all' ? undefined : status }),
  })
  const manage = can('package_requests.manage')

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Paket talepleri"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Paket talepleri' }]}
        description="Öğrenci ve velilerin portaldan oluşturduğu paket ve koçluk talepleri. Onayladığınızda kaydı/koçluğu siz düzenlersiniz; portaldan ödeme alınmaz."
      />

      <div className="mb-3">
        <Segmented value={status} onChange={setStatus} options={[
          { value: 'pending', label: `Bekleyen${data?.meta.pending_count ? ` (${data.meta.pending_count})` : ''}` },
          { value: 'approved', label: 'Onaylanan' },
          { value: 'rejected', label: 'Reddedilen' },
          { value: 'all', label: 'Tümü' },
        ]} />
      </div>

      {isLoading ? (
        <div className="flex flex-col gap-2">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-20" />)}</div>
      ) : !data?.data.length ? (
        <EmptyState icon={<Inbox />} title="Talep yok" description={status === 'pending' ? 'Bekleyen paket/koçluk talebi bulunmuyor.' : 'Bu durumda talep bulunmuyor.'} />
      ) : (
        <Panel flush>
          <ul>
            {data.data.map((r) => (
              <li key={r.id} className="flex flex-wrap items-start justify-between gap-3 border-t border-line px-4 py-3 first:border-t-0">
                <div className="min-w-0 flex-1 basis-[18rem]">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="inline-flex items-center gap-1.5 text-[14.5px] font-semibold">
                      {r.kind === 'coaching' ? <GraduationCap className="size-4 text-primary" /> : <Package className="size-4 text-ink-3" />}
                      {r.student?.full_name ?? '—'}
                    </span>
                    {r.student?.student_no && <span className="text-[12.5px] text-ink-3">No {r.student.student_no}</span>}
                    <Badge tone={statusTone[r.status] ?? 'neutral'} dot>{r.status_label}</Badge>
                  </div>
                  <p className="mt-0.5 text-[13px] text-ink-2">
                    {r.kind === 'coaching' ? 'Koçluk talebi' : `Paket: ${r.package?.name ?? '—'}`}
                    {r.package?.has_coaching && <span className="ml-1 text-primary">· koçluk dahil</span>}
                  </p>
                  <p className="text-[12px] text-ink-3">{r.requested_by ? `${r.requested_by} · ` : ''}{dateTime(r.created_at)}</p>
                  {r.note && <p className="mt-1 whitespace-pre-line text-[13px] text-ink-2">“{r.note}”</p>}
                  {r.status !== 'pending' && (r.handled_by || r.decision_note) && (
                    <p className="mt-1 text-[12.5px] text-ink-3">{r.handled_by ? `${r.handled_by}` : ''}{r.handled_at ? ` · ${date(r.handled_at)}` : ''}{r.decision_note ? ` — ${r.decision_note}` : ''}</p>
                  )}
                </div>
                {manage && r.status === 'pending' && (
                  <div className="flex shrink-0 gap-2">
                    <Button size="sm" variant="outline" icon={<Check className="size-4" />} onClick={() => setAction({ row: r, mode: 'approve' })}>Onayla</Button>
                    <Button size="sm" variant="ghost" icon={<X className="size-4" />} onClick={() => setAction({ row: r, mode: 'reject' })}>Reddet</Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        </Panel>
      )}

      {action && <ActionModal action={action} canCoach={can('coaching.manage')} onClose={() => setAction(null)} />}
    </div>
  )
}

function ActionModal({ action, canCoach, onClose }: { action: { row: Row; mode: 'approve' | 'reject' }; canCoach: boolean; onClose: () => void }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const { row, mode } = action
  const isApprove = mode === 'approve'
  const isCoaching = row.kind === 'coaching' || !!row.package?.has_coaching
  const [decisionNote, setDecisionNote] = useState('')
  const [coachId, setCoachId] = useState('')
  const coaches = useQuery({
    queryKey: ['package-requests', 'coaches'],
    queryFn: () => api.get<{ coaches: { id: number; name: string }[] }>('/package-requests/coaches'),
    enabled: isApprove && isCoaching && canCoach,
    staleTime: 5 * 60_000,
  })

  const run = useMutation({
    mutationFn: () => api.post<{ message: string; enroll_to?: string | null }>(`/package-requests/${row.id}/${mode}`, {
      decision_note: decisionNote.trim() || null,
      ...(isApprove && coachId ? { coach_id: Number(coachId) } : {}),
    }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['finance', 'package-requests'] })
      onClose()
      if (r.enroll_to) navigate(r.enroll_to)
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.'),
  })

  return (
    <Modal
      open
      onClose={onClose}
      title={isApprove ? 'Talebi onayla' : 'Talebi reddet'}
      footer={<>
        <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
        <Button variant={isApprove ? 'primary' : 'danger'} loading={run.isPending} onClick={() => run.mutate()}>{isApprove ? 'Onayla' : 'Reddet'}</Button>
      </>}
    >
      <div className="flex flex-col gap-3">
        <p className="text-[14px] text-ink-2">
          <span className="font-medium">{row.student?.full_name}</span> · {row.kind === 'coaching' ? 'Koçluk talebi' : `Paket: ${row.package?.name ?? '—'}`}
        </p>
        {isApprove && row.kind === 'package' && (
          <p className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 text-[13px] text-ink-2">Onaydan sonra kayıt/ödeme planı ekranına yönlendirilirsiniz; ücret ve taksitleri orada belirlersiniz.</p>
        )}
        {isApprove && isCoaching && canCoach && (
          <Field label="Koç ata" optional hint="Seçerseniz onayla birlikte koç atanır; boş bırakırsanız yalnız talep onaylanır">
            <Select value={coachId} placeholder="Koç seçin (opsiyonel)" onChange={(e) => setCoachId(e.target.value)}
              options={(coaches.data?.coaches ?? []).map((c) => ({ value: c.id, label: c.name }))} />
          </Field>
        )}
        <Field label={isApprove ? 'Karar notu' : 'Ret nedeni'} optional>
          <Textarea rows={3} maxLength={500} value={decisionNote} onChange={(e) => setDecisionNote(e.target.value)}
            placeholder={isApprove ? 'Talep sahibine iletilecek not (opsiyonel)' : 'Talep sahibine iletilecek ret nedeni (opsiyonel)'} />
        </Field>
      </div>
    </Modal>
  )
}
