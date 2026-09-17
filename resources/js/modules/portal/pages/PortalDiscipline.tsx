import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ShieldCheck } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date } from '@/lib/format'
import { Button } from '@/components/ui/Button'
import { Alert, Badge, EmptyState, Skeleton, type Tone } from '@/components/ui/feedback'
import { Textarea } from '@/components/ui/form'
import { Panel } from '@/components/ui/layout'
import { usePortal, useVoice } from '../api'
import { ListCard, PortalTitle } from '../ui'

type Sanction = { id: number; type: string | null; tone: string | null; status: string; status_label: string; decided_at: string | null; occurred_at: string | null; behavior: string; starts_on: string | null; ends_on: string | null; days: number | null; duty_description: string | null; expires_on: string | null }
type Defense = { id: number; status: string; status_label: string; overdue: boolean; occurred_at: string | null; location: string | null; behavior: string; requested_at: string | null; due_on: string | null; request_note: string | null; statement: string | null; submitted_at: string | null; can_submit: boolean }
type Data = { data: { enabled: boolean; can_submit_defense?: boolean; sanctions: Sanction[]; defenses: Defense[] } }

const sanctionTone: Record<string, Tone> = { active: 'danger', completed: 'neutral', expired: 'neutral', appealed: 'warning', cancelled: 'neutral', proposed: 'warning' }

/** Öğrenci/veli portalı › Disiplin: yalnız sonuçlanmış ve portala açık yaptırımlar ile savunma istemleri. */
export default function PortalDiscipline() {
  const v = useVoice()
  const { data, isLoading } = usePortal<Data>('discipline', '/portal/discipline')
  const d = data?.data

  if (isLoading || !d) return <div className="flex flex-col gap-4"><Skeleton className="h-24" /><Skeleton className="h-48" /></div>

  if (!d.enabled) {
    return (
      <div className="animate-fade-in">
        <PortalTitle title="Disiplin" />
        <EmptyState icon={<ShieldCheck />} title="Bu bölüm kurum tarafından kapatılmış" />
      </div>
    )
  }

  const waiting = d.defenses.filter((x) => x.status === 'requested')
  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Disiplin" description={v('Hakkında verilen kararlar ve senden istenen savunmalar', 'Öğrenci hakkında verilen kararlar ve istenen savunmalar')} />

      {waiting.length > 0 && (
        <Alert tone={waiting.some((x) => x.overdue) ? 'danger' : 'warning'} title={v(`${waiting.length} savunma bekleniyor`, `Öğrenciden ${waiting.length} savunma bekleniyor`)}>
          {v('Aşağıdan yazılı savunmanı son tarihten önce gönder.', 'Savunmayı öğrenci kendi portal hesabından yazabilir ya da kuruma teslim edebilir.')}
        </Alert>
      )}

      {d.defenses.length > 0 && (
        <Panel title="Savunma istemleri">
          <div className="flex flex-col gap-3">
            {d.defenses.map((x) => <DefenseItem key={x.id} d={x} />)}
          </div>
        </Panel>
      )}

      <Panel title="Kararlar" flush>
        {d.sanctions.length === 0 ? (
          <EmptyState compact icon={<ShieldCheck />} title="Kayıtlı karar yok" description={v('Tebrikler, hakkında bir disiplin kararı bulunmuyor.', 'Öğrenci hakkında bir disiplin kararı bulunmuyor.')} />
        ) : (
          <ListCard className="rounded-none ring-0">
            {d.sanctions.map((s) => (
              <li key={s.id} className="flex flex-wrap items-start gap-x-3 gap-y-1 px-4 py-3">
                <div className="min-w-0 flex-1">
                  <p className="text-[14px] font-medium">{s.type ?? 'Yaptırım'}</p>
                  <p className="text-[12.5px] text-ink-3">
                    {s.behavior || '—'}{s.occurred_at ? ` · olay ${date(s.occurred_at)}` : ''}{s.decided_at ? ` · karar ${date(s.decided_at)}` : ''}
                  </p>
                  {s.starts_on && <p className="mt-0.5 text-[12.5px] text-ink-2">{date(s.starts_on)}{s.ends_on ? ` – ${date(s.ends_on)}` : ''}{s.days ? ` (${s.days} gün)` : ''}</p>}
                  {s.duty_description && <p className="mt-0.5 text-[12.5px] text-ink-2">Görev: {s.duty_description}</p>}
                  {s.expires_on && <p className="mt-0.5 text-[12.5px] text-ink-3">Kayıttan düşme: {date(s.expires_on)}</p>}
                </div>
                <Badge tone={sanctionTone[s.status] ?? 'neutral'}>{s.status_label}</Badge>
              </li>
            ))}
          </ListCard>
        )}
      </Panel>
    </div>
  )
}

function DefenseItem({ d }: { d: Defense }) {
  const qc = useQueryClient()
  const [text, setText] = useState('')
  const send = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/portal/discipline/defenses/${d.id}`, { statement: text }),
    onSuccess: (r) => { toast.success(r.message ?? 'Savunma gönderildi.'); qc.invalidateQueries({ queryKey: ['portal', 'discipline'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Gönderilemedi.'),
  })
  return (
    <div className="rounded-[var(--radius-md)] border border-line p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-[14px] font-medium">{d.behavior || 'Olay'}{d.occurred_at ? <span className="font-normal text-ink-3"> · {date(d.occurred_at)}</span> : null}</p>
        <Badge tone={d.status === 'requested' ? (d.overdue ? 'danger' : 'warning') : 'success'}>{d.overdue && d.status === 'requested' ? 'Süresi geçti' : d.status_label}</Badge>
      </div>
      {d.due_on && d.status === 'requested' && <p className="mt-0.5 text-[12.5px] text-ink-3">Son tarih: {date(d.due_on)}</p>}
      {d.request_note && <p className="mt-1.5 text-[14px] text-ink-2">{d.request_note}</p>}
      {d.statement && <blockquote className="mt-2 border-l-2 border-line-strong pl-3 text-[14px] text-ink-2">{d.statement}</blockquote>}
      {d.can_submit && (
        <div className="mt-3 flex flex-col gap-2">
          <Textarea rows={5} value={text} onChange={(e) => setText(e.target.value)} placeholder="Olayı kendi açından, açık ve saygılı bir dille anlat (en az 20 karakter)." maxLength={10000} />
          <div className="flex items-center justify-between gap-2">
            <span className="text-[12.5px] text-ink-3 tabular">{text.length} karakter · gönderdikten sonra değiştirilemez</span>
            <Button variant="primary" size="sm" disabled={text.trim().length < 20} loading={send.isPending} onClick={() => send.mutate()}>Savunmayı gönder</Button>
          </div>
        </div>
      )}
    </div>
  )
}
