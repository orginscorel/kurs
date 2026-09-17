import { useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  CalendarClock, Check, FileText, Gavel, MapPin, MessageCircle, MoreHorizontal, Paperclip, Pencil, Printer, Scale, ShieldQuestion, Trash2, Upload, UserRound,
} from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, dateTime, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Menu } from '@/components/ui/overlay'
import type { Participant, Sanction } from './types'
import { DEFENSE_TONE, INCIDENT_TONE, KindBadge, LevelBadge, SANCTION_TONE, SeverityBadge, StatusBadge, useDisciplineOptions } from './ui'
import { IncidentFormDrawer, useIncident } from './IncidentFormDrawer'
import {
  AppealDecideModal, AppealModal, DefenseRecordModal, DefenseRequestModal, DeleteModal, NotifyModal, PointsModal, SanctionModal, SanctionStatusModal, StatusModal, WaiveModal,
} from './actions'

const EVENT_TONE: Record<string, string> = {
  created: 'bg-info', sanction_decided: 'bg-danger', sanction_proposed: 'bg-warning', defense_requested: 'bg-warning', defense_submitted: 'bg-success',
  appeal_filed: 'bg-accent', appeal_decided: 'bg-accent', board_decision: 'bg-primary', status: 'bg-ink-3', guardian_notified: 'bg-success',
}

export default function IncidentDetail() {
  const { id } = useParams()
  const can = useCan()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { data: d, isLoading, isError } = useIncident(Number(id))
  const { data: opt } = useDisciplineOptions()
  const fileRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)

  const [edit, setEdit] = useState(false)
  const [status, setStatus] = useState<string | null>(null)
  const [del, setDel] = useState(false)
  const [defReq, setDefReq] = useState<Participant | null>(null)
  const [defRec, setDefRec] = useState<{ id: number; name: string } | null>(null)
  const [waive, setWaive] = useState<Participant | null>(null)
  const [sanctionFor, setSanctionFor] = useState<Participant | null>(null)
  const [sanStatus, setSanStatus] = useState<{ s: Sanction; to: 'completed' | 'cancelled' } | null>(null)
  const [appeal, setAppeal] = useState<Sanction | null>(null)
  const [appealDecide, setAppealDecide] = useState<Sanction | null>(null)
  const [points, setPoints] = useState<Participant | null>(null)
  const [notify, setNotify] = useState<{ student: Participant; kind: 'sanction' | 'defense' | 'positive'; sanctionId?: number } | null>(null)

  if (isLoading) return <div className="flex flex-col gap-4"><Skeleton className="h-10 w-72" /><Skeleton className="h-64" /></div>
  if (isError || !d) {
    return <EmptyState icon={<Gavel />} title="Kayıt bulunamadı" description="Silinmiş ya da erişiminiz olmayan bir kayıt olabilir." action={<ButtonLink to="/disiplin/olaylar">Olaylara dön</ButtonLink>} />
  }

  const negative = d.kind === 'negative'
  const workable = negative && ['open', 'review', 'decided'].includes(d.status)
  const involved = d.participants.filter((p) => p.role === 'involved')
  const others = d.participants.filter((p) => p.role !== 'involved')
  const defenseOf = (sid: number) => d.defenses.find((x) => x.student?.id === sid)
  const sanctionsOf = (sid: number) => d.sanctions.filter((s) => s.student?.id === sid)

  const upload = async (file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    setUploading(true)
    try {
      const r = await api.post<{ message: string }>(`/discipline/incidents/${d.id}/documents`, fd)
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['discipline', 'incident', d.id] })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.firstError() : 'Dosya yüklenemedi.')
    } finally {
      setUploading(false)
      if (fileRef.current) fileRef.current.value = ''
    }
  }
  const removeDoc = async (docId: number) => {
    try {
      await api.delete(`/discipline/incidents/${d.id}/documents/${docId}`)
      qc.invalidateQueries({ queryKey: ['discipline', 'incident', d.id] })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.')
    }
  }

  const statusActions = [
    { to: 'review', label: 'İncelemeye al', perm: 'discipline.create' },
    { to: 'decided', label: 'Karara bağlandı', perm: 'discipline.decide' },
    { to: 'closed', label: 'Olayı kapat', perm: 'discipline.decide' },
    { to: 'open', label: 'Yeniden aç', perm: 'discipline.decide' },
  ].filter((a) => d.transitions.includes(a.to) && can(a.perm))

  return (
    <div className="animate-fade-in">
      <PageHeader
        breadcrumbs={[{ label: 'Disiplin', to: '/disiplin' }, { label: 'Olaylar', to: '/disiplin/olaylar' }, { label: d.incident_no }]}
        title={<span className="flex flex-wrap items-center gap-2">{d.title || (negative ? 'Disiplin olayı' : 'Olumlu davranış')} <StatusBadge status={d.status} label={d.outcome === 'unfounded' ? 'Asılsız — kapandı' : d.status_label} map={INCIDENT_TONE} /></span>}
        description={<span className="tabular">{d.incident_no} · {dateTime(d.occurred_at)} · {relative(d.occurred_at)}</span>}
        actions={
          <>
            {statusActions.length > 0 && negative && (
              <Menu trigger={<Button icon={<Check className="size-4" />}>Durum</Button>}
                items={statusActions.map((a) => ({ label: a.label, onClick: () => setStatus(a.to) }))} />
            )}
            {can('discipline.create') && (d.status !== 'closed' || can('discipline.decide')) && <Button icon={<Pencil className="size-4" />} onClick={() => setEdit(true)}>Düzenle</Button>}
            {can('discipline.decide') && (
              <Menu trigger={<Button variant="ghost" size="icon" aria-label="Diğer"><MoreHorizontal className="size-4" /></Button>}
                items={[{ label: 'Kaydı sil', icon: <Trash2 className="size-4" />, danger: true, onClick: () => setDel(true) }]} />
            )}
          </>
        }
      />

      {d.status === 'appealed' && <Alert tone="info" className="mb-4" title="İtiraz bekleniyor">Yaptırımlardan birine itiraz edildi. Aşağıdan itirazı karara bağlayabilirsiniz.</Alert>}
      {d.source === 'teacher_portal' && d.status === 'open' && <Alert tone="warning" className="mb-4" title="Öğretmen bildirimi">Bu olay öğretmen portalından bildirildi; inceleyip süreci başlatın.</Alert>}

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="Olay bilgisi">
            <div className="grid grid-cols-1 gap-x-6 gap-y-3 text-[13px] sm:grid-cols-2">
              <Info icon={<CalendarClock />} label="Olay tarihi">{dateTime(d.occurred_at)}</Info>
              <Info icon={<MapPin />} label="Olay yeri">{d.location ?? '—'}</Info>
              <Info icon={<Scale />} label="Ciddiyet">{negative ? <SeverityBadge severity={d.severity} label={d.severity_label} /> : <KindBadge kind="positive" />}</Info>
              <Info icon={<UserRound />} label="Kaydeden personel">{d.reporter ?? '—'}{d.source === 'teacher_portal' && <Badge tone="info" className="ml-1.5">Öğretmen portalı</Badge>}</Info>
              {(d.class_group || d.subject || d.teacher) && <Info icon={<FileText />} label="Sınıf / ders / öğretmen">{[d.class_group, d.subject, d.teacher].filter(Boolean).join(' · ')}</Info>}
              {d.witnesses && <Info icon={<UserRound />} label="Tanıklar">{d.witnesses}</Info>}
            </div>
            {d.description && <p className="mt-4 whitespace-pre-line rounded-[var(--radius-md)] bg-surface-2 p-3 text-[13.5px] leading-relaxed text-ink">{d.description}</p>}
          </Panel>

          <Panel title={negative ? 'Olaya karışan öğrenciler' : 'Öğrenci'} description={negative ? 'Savunma, yaptırım ve veli bildirimi her öğrenci için ayrı yürür.' : undefined} flush>
            <ul className="divide-y divide-line border-t border-line">
              {involved.map((p) => {
                const def = defenseOf(p.student_id)
                const sans = sanctionsOf(p.student_id)
                return (
                  <li key={p.student_id} className="px-4 py-3.5">
                    <div className="flex flex-wrap items-start gap-3">
                      <div className="min-w-[220px] flex-1">
                        <Link to={`/ogrenciler/${p.student_id}`} className="font-medium text-ink hover:text-primary">{p.full_name}</Link>
                        <span className="ml-1.5 text-[12px] tabular text-ink-3">{p.student_no}{p.class_name ? ` · ${p.class_name}` : ''}</span>
                        <p className="mt-0.5 text-[13px] text-ink-2">
                          {p.behavior ?? 'Davranış seçilmedi'}
                          <span className={cn('ml-2 font-medium tabular', negative ? 'text-danger' : 'text-success')}>{negative ? `−${p.penalty_points}` : `+${p.merit_points}`} puan</span>
                        </p>
                        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                          {p.standing && <LevelBadge level={p.standing.level} label={`Dönem: ${p.standing.net} puan · ${p.standing.level_label}`} />}
                          {def && <Badge tone={def.overdue ? 'danger' : DEFENSE_TONE[def.status]}>{def.overdue ? 'Savunma süresi geçti' : def.status_label}{def.status === 'requested' ? ` · son tarih: ${date(def.due_on)}` : ''}</Badge>}
                          {sans.map((s) => <Badge key={s.id} tone={SANCTION_TONE[s.status]}>{s.type?.name}: {s.status_label}</Badge>)}
                        </div>
                      </div>
                      <div className="flex flex-wrap items-center gap-1.5">
                        {negative && workable && can('discipline.decide') && (
                          <Button size="sm" variant="danger-soft" icon={<Gavel className="size-3.5" />} onClick={() => setSanctionFor(p)}>Yaptırım</Button>
                        )}
                        {negative && can('discipline.create') && (
                          <Menu trigger={<Button size="sm" icon={<ShieldQuestion className="size-3.5" />}>Savunma</Button>}
                            items={[
                              { label: def?.status === 'requested' ? 'Son tarihi değiştir' : 'Yazılı savunma iste', onClick: () => setDefReq(p), hidden: !workable || def?.status === 'submitted' },
                              { label: 'Savunmayı kaydet', onClick: () => def && setDefRec({ id: def.id, name: p.full_name }), hidden: def?.status !== 'requested' },
                              { label: 'İstem yazısı (PDF)', icon: <Printer className="size-4" />, onClick: () => def && window.open(`/api/v1/discipline/defenses/${def.id}/pdf`, '_blank'), hidden: !def },
                              { label: 'Savunma alınmadı', onClick: () => setWaive(p), hidden: !workable || def?.status === 'submitted' || def?.status === 'waived' },
                            ]} />
                        )}
                        <Menu trigger={<Button size="sm" variant="ghost" aria-label="Öğrenci işlemleri"><MoreHorizontal className="size-4" /></Button>}
                          items={[
                            { label: 'Veli bildirimi taslağı', icon: <MessageCircle className="size-4" />, onClick: () => setNotify({ student: p, kind: !negative ? 'positive' : sans.some((s) => ['active', 'appealed', 'completed'].includes(s.status)) ? 'sanction' : 'defense' }) },
                            { label: 'Puanı düzelt', onClick: () => setPoints(p), hidden: !can('discipline.decide') },
                          ]} />
                      </div>
                    </div>
                    {def?.statement && (
                      <blockquote className="mt-3 whitespace-pre-line border-l-2 border-success/50 bg-success-soft/40 px-3 py-2 text-[13px] text-ink">
                        <span className="mb-1 block text-[12px] text-ink-3">Savunma · Teslim: {date(def.submitted_at)}{def.submitted_via === 'portal' ? ' · öğrenci portalından' : ''}</span>
                        {def.statement}
                      </blockquote>
                    )}
                  </li>
                )
              })}
              {others.length > 0 && (
                <li className="px-4 py-3 text-[13px] text-ink-2">
                  {others.map((o) => <span key={o.student_id} className="mr-3"><Link to={`/ogrenciler/${o.student_id}`} className="text-ink hover:text-primary">{o.full_name}</Link> <span className="text-ink-3">({o.role_label})</span></span>)}
                </li>
              )}
            </ul>
          </Panel>

          {negative && (
            <Panel title="Yaptırımlar" flush>
              {d.sanctions.length === 0 ? (
                <EmptyState compact icon={<Gavel />} title="Henüz yaptırım yok" description={workable && can('discipline.decide') ? 'Öğrenci satırındaki "Yaptırım" düğmesiyle verin ya da kurula önerin.' : undefined} />
              ) : (
                <ul className="divide-y divide-line border-t border-line">
                  {d.sanctions.map((s) => {
                    const pending = s.appeals.find((a) => a.status === 'pending')
                    const boardType = s.type?.authority === 'board'
                    const canChange = boardType ? can('discipline.board') : can('discipline.decide')
                    return (
                      <li key={s.id} className="flex flex-wrap items-start gap-3 px-4 py-3.5">
                        <div className="min-w-0 flex-1">
                          <p className="flex flex-wrap items-center gap-2 text-[13.5px]">
                            <span className="font-medium text-ink">{s.type?.name}</span>
                            <StatusBadge status={s.status} label={s.status_label} map={SANCTION_TONE} />
                            <span className="text-ink-2">{s.student?.full_name}</span>
                          </p>
                          <p className="mt-0.5 text-[12.5px] tabular text-ink-3">
                            {s.sanction_no}
                            {s.starts_on && ` · ${date(s.starts_on)} – ${date(s.ends_on)} (${s.days} gün)`}
                            {s.decided_at && ` · ${s.board_meeting_no ? `${s.board_meeting_no} kurul kararı` : s.decided_by ?? ''} ${date(s.decided_at)}`}
                            {s.expires_on && ` · düşme ${date(s.expires_on)}`}
                            {!s.visible_to_portal && ' · portalda gizli'}
                          </p>
                          {s.duty_description && <p className="mt-1 text-[13px] text-ink-2">Görev: {s.duty_description}</p>}
                          {s.decision_note && <p className="mt-1 whitespace-pre-line text-[13px] text-ink-2">{s.decision_note}</p>}
                          {s.cancel_reason && <p className="mt-1 text-[12.5px] text-ink-3">İptal: {s.cancel_reason}</p>}
                          {s.appeals.map((a) => (
                            <div key={a.id} className="mt-2 rounded-[var(--radius-sm)] bg-accent-soft/50 px-3 py-2 text-[12.5px] ring-1 ring-accent/20">
                              <span className="font-medium">{a.appellant === 'student' ? 'Öğrenci' : 'Veli'} itirazı · {date(a.appealed_on)}</span> — {a.status_label}
                              <p className="text-ink-2">{a.reason}</p>
                              {a.result_note && <p className="mt-0.5 text-ink">Karar: {a.result_note}</p>}
                            </div>
                          ))}
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                          {s.status === 'proposed' && (
                            <ButtonLink size="sm" variant="warning" to={s.board_meeting_id ? `/disiplin/kurul/${s.board_meeting_id}` : '/disiplin/kurul?yeni=1'}>{s.board_meeting_id ? 'Kurul gündeminde' : 'Kurula al'}</ButtonLink>
                          )}
                          {pending && (can('discipline.decide') || can('discipline.board')) && <Button size="sm" variant="info" onClick={() => setAppealDecide(s)}>İtirazı karara bağla</Button>}
                          <Menu trigger={<Button size="sm" variant="ghost" aria-label="Yaptırım işlemleri"><MoreHorizontal className="size-4" /></Button>}
                            items={[
                              { label: 'Veli bildirimi taslağı', icon: <MessageCircle className="size-4" />, hidden: !['active', 'appealed', 'completed'].includes(s.status),
                                onClick: () => { const p = d.participants.find((x) => x.student_id === s.student?.id); if (p) setNotify({ student: p, kind: 'sanction', sanctionId: s.id }) } },
                              { label: 'İtiraz kaydet', hidden: s.status !== 'active' || !can('discipline.create'), onClick: () => setAppeal(s) },
                              { label: 'Tamamlandı say', hidden: s.status !== 'active' || !canChange, onClick: () => setSanStatus({ s, to: 'completed' }) },
                              { label: 'İptal et', danger: true, hidden: !['active', 'proposed'].includes(s.status) || !(s.status === 'proposed' ? can('discipline.decide') : canChange), onClick: () => setSanStatus({ s, to: 'cancelled' }) },
                            ]} />
                        </div>
                      </li>
                    )
                  })}
                </ul>
              )}
            </Panel>
          )}

          <Panel title="Ek dosyalar" description="Fotoğraf (JPG/PNG/WEBP) ya da PDF, en fazla 10 MB."
            actions={can('discipline.create') && (
              <>
                <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className="hidden" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
                <Button size="sm" icon={<Upload className="size-3.5" />} loading={uploading} onClick={() => fileRef.current?.click()}>Dosya ekle</Button>
              </>
            )}>
            {d.documents.length === 0 ? <p className="text-[13px] text-ink-3">Ek dosya yok.</p> : (
              <ul className="flex flex-col gap-1.5">
                {d.documents.map((doc) => (
                  <li key={doc.id} className="flex items-center gap-2 text-[13px]">
                    <Paperclip className="size-4 shrink-0 text-ink-3" />
                    <a href={`/api/v1/discipline/incidents/${d.id}/documents/${doc.id}`} target="_blank" rel="noreferrer" className="min-w-0 flex-1 truncate text-ink hover:text-primary">{doc.title}</a>
                    <span className="shrink-0 text-[12px] text-ink-3">{doc.uploaded_by} · {date(doc.created_at)}</span>
                    {can('discipline.create') && <Button size="icon-sm" variant="ghost" aria-label="Dosyayı sil" onClick={() => removeDoc(doc.id)}><Trash2 className="size-3.5" /></Button>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </div>

        <Panel title="Zaman çizelgesi" className="self-start xl:sticky xl:top-16">
          {d.board_items.length > 0 && (
            <div className="mb-3 flex flex-col gap-1.5">
              {d.board_items.map((b) => (
                <Link key={b.id} to={`/disiplin/kurul/${b.meeting_id}`} className="flex items-center justify-between rounded-[var(--radius-sm)] bg-primary-soft/60 px-2.5 py-1.5 text-[12.5px] text-primary-ink hover:bg-primary-soft">
                  <span>{b.meeting_no} · {dateTime(b.scheduled_at)}</span>
                  <span>{b.result === 'pending' ? 'Gündemde' : b.result === 'accepted' ? 'Kabul' : b.result === 'rejected' ? 'Ret' : 'Ertelendi'}</span>
                </Link>
              ))}
            </div>
          )}
          <ol className="relative flex flex-col gap-3.5 before:absolute before:bottom-1 before:left-[5px] before:top-1 before:w-px before:bg-line">
            {d.events.map((e) => (
              <li key={e.id} className="relative pl-5">
                <span className={cn('absolute left-0 top-1.5 size-[11px] rounded-full ring-2 ring-surface', EVENT_TONE[e.type] ?? 'bg-ink-3')} />
                <p className="text-[13px] leading-snug text-ink">{e.message}</p>
                <p className="mt-0.5 text-[12px] text-ink-3">{e.user ?? 'Sistem'} · {dateTime(e.created_at)}</p>
              </li>
            ))}
          </ol>
          {d.guardian_notified_at && <p className="mt-3 text-[12px] text-success">Veli bilgilendirildi · {dateTime(d.guardian_notified_at)}</p>}
        </Panel>
      </div>

      <IncidentFormDrawer open={edit} incident={d} onClose={() => setEdit(false)} />
      <StatusModal incident={d} to={status} onClose={() => setStatus(null)} />
      <DeleteModal incident={d} open={del} onClose={() => setDel(false)} onDone={() => navigate('/disiplin/olaylar')} />
      <DefenseRequestModal incident={d} student={defReq} opt={opt} onClose={() => setDefReq(null)} />
      <DefenseRecordModal defenseId={defRec?.id ?? null} name={defRec?.name} onClose={() => setDefRec(null)} />
      <WaiveModal incident={d} student={waive} onClose={() => setWaive(null)} />
      <SanctionModal incident={d} student={sanctionFor} opt={opt} onClose={() => setSanctionFor(null)} />
      <SanctionStatusModal sanction={sanStatus?.s ?? null} to={sanStatus?.to ?? 'completed'} onClose={() => setSanStatus(null)} />
      <AppealModal sanction={appeal} onClose={() => setAppeal(null)} />
      <AppealDecideModal sanction={appealDecide} opt={opt} onClose={() => setAppealDecide(null)} />
      <PointsModal incident={d} student={points} onClose={() => setPoints(null)} />
      <NotifyModal incident={d} target={notify} onClose={() => setNotify(null)} />
    </div>
  )
}

function Info({ icon, label, children }: { icon: ReactNode; label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 items-start gap-2.5">
      <span className="mt-0.5 text-ink-3 [&_svg]:size-4">{icon}</span>
      <div className="min-w-0">
        <p className="text-[12px] text-ink-3">{label}</p>
        <div className="text-ink">{children}</div>
      </div>
    </div>
  )
}
