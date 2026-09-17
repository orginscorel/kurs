import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarClock, Inbox, MessageSquareReply } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Textarea } from '@/components/ui/form'
import { Tabs } from '@/components/ui/layout'
import { PortalTitle } from '@/modules/portal/ui'
import { requestTone, TP, useReadOnly, useTeacherQuery } from '../api'

type Row = {
  id: number; kind: string; kind_label: string; subject: string; body: string; preferred_times: string | null
  status: 'open' | 'answered' | 'closed'; status_label: string; response: string | null; responded_at: string | null; created_at: string
  student: { id: number; full_name: string; student_no: string } | null; guardian: string | null
}
type Data = { data: Row[]; counts: Record<string, number> }
type Status = 'open' | 'answered' | 'closed'

export default function TeacherRequests() {
  const [params, setParams] = useSearchParams()
  const status = (['open', 'answered', 'closed'].includes(params.get('durum') ?? '') ? params.get('durum') : 'open') as Status
  const { data, isLoading } = useTeacherQuery<Data>(['requests'], '/requests', { status })

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Veli talepleri" description="Velilerin portal üzerinden gönderdiği mesaj ve görüşme talepleri" />
      <Alert tone="info">Yanıtınız velinin portalında görünür; SMS ya da WhatsApp gönderilmez. Görüşme için saat belirlediyseniz yanıtınızda yazın.</Alert>
      <Tabs value={status} onChange={(s) => setParams({ durum: s }, { replace: true })} tabs={[
        { value: 'open', label: 'Yanıt bekleyen', count: data?.counts.open ?? 0 },
        { value: 'answered', label: 'Yanıtlanan', count: data?.counts.answered ?? 0 },
        { value: 'closed', label: 'Kapatılan', count: data?.counts.closed ?? 0 },
      ]} />
      {isLoading || !data ? <Skeleton className="h-48" /> : data.data.length === 0 ? (
        <EmptyState icon={<Inbox />} title={status === 'open' ? 'Yanıt bekleyen talep yok' : 'Kayıt yok'} />
      ) : (
        <ul className="flex flex-col gap-3">
          {data.data.map((r) => <RequestCard key={r.id} r={r} />)}
        </ul>
      )}
    </div>
  )
}

function RequestCard({ r }: { r: Row }) {
  const qc = useQueryClient()
  const readOnly = useReadOnly()
  const [open, setOpen] = useState(false)
  const [text, setText] = useState('')
  const respond = useMutation({
    mutationFn: (status: 'answered' | 'closed') => api.post<{ message: string }>(`${TP}/requests/${r.id}/respond`, { status, response: status === 'answered' ? text : null }),
    onSuccess: (res) => {
      toast.success(res.message)
      setOpen(false)
      qc.invalidateQueries({ queryKey: ['teacher-portal'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  return (
    <li className="rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-[14px] font-semibold">{r.subject}</p>
          <p className="text-[12.5px] text-ink-3">
            {r.guardian ?? 'Veli'}
            {r.student && <> · <Link to={`/ogretmen/ogrenci/${r.student.id}`} className="hover:underline">{r.student.full_name}</Link></>}
            {' · '}<span title={dateTime(r.created_at)}>{relative(r.created_at)}</span>
          </p>
        </div>
        <span className="flex gap-1.5">
          <Badge tone={r.kind === 'meeting' ? 'info' : 'neutral'}>{r.kind_label}</Badge>
          <Badge tone={requestTone[r.status] ?? 'neutral'} dot>{r.status_label}</Badge>
        </span>
      </div>
      <p className="mt-2 whitespace-pre-line text-[14.5px] text-ink-2">{r.body}</p>
      {r.preferred_times && <p className="mt-1.5 inline-flex items-center gap-1.5 text-[12.5px] text-ink-2"><CalendarClock className="size-3.5 text-primary" /> Uygun zaman: {r.preferred_times}</p>}
      {r.response && (
        <div className="mt-2 rounded-[var(--radius-sm)] bg-primary-soft/60 px-3 py-2 text-[14px]">
          <p className="text-[12.5px] font-medium text-primary-ink">Yanıtınız{r.responded_at ? ` · ${dateTime(r.responded_at)}` : ''}</p>
          <p className="whitespace-pre-line text-ink-2">{r.response}</p>
        </div>
      )}
      {!readOnly && r.status === 'open' && (
        open ? (
          <div className="mt-3 flex flex-col gap-2">
            <Textarea rows={3} maxLength={2000} autoFocus value={text} placeholder="Veliye yanıtınız…" onChange={(e) => setText(e.target.value)} aria-label="Yanıt" />
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="ghost" onClick={() => setOpen(false)}>Vazgeç</Button>
              <Button variant="primary" loading={respond.isPending && respond.variables === 'answered'} disabled={text.trim().length < 2} onClick={() => respond.mutate('answered')}>Yanıtı gönder</Button>
            </div>
          </div>
        ) : (
          <div className="mt-3 flex flex-wrap gap-2">
            <Button variant="primary" size="sm" icon={<MessageSquareReply className="size-4" />} onClick={() => setOpen(true)}>Yanıtla</Button>
            <Button variant="outline" size="sm" loading={respond.isPending && respond.variables === 'closed'} onClick={() => respond.mutate('closed')}>Yanıtsız kapat</Button>
          </div>
        )
      )}
    </li>
  )
}
