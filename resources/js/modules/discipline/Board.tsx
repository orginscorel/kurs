import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarPlus, Check, Gavel, Plus, Printer, Scale, Search, Trash2, Users, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date, dateTime } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Checkbox, Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Drawer, Modal } from '@/components/ui/overlay'
import type { Meeting, MeetingItem } from './types'
import { BOARD_TONE, DEFENSE_TONE, SEVERITY_TONE, StatusBadge, toLocalInput, useDisciplineOptions } from './ui'

type Candidate = {
  sanction_id: number; sanction_no: string; incident_id: number; incident_no: string; occurred_at: string; severity: string
  student: { id: number; full_name: string; student_no: string } | null; type: string; days: number | null; board_meeting_id: number | null; defense_status: string | null
}
type MemberRow = { user_id: number; role: string; present: boolean }

export function BoardList() {
  const can = useCan()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [open, setOpen] = useState(params.get('yeni') === '1')
  const list = useQuery({ queryKey: ['discipline', 'board', 'list'], queryFn: () => api.get<Paginated<Meeting> & { meta: { awaiting: number } }>('/discipline/board', { per_page: 50 }), placeholderData: keepPreviousData })
  const cands = useQuery({ queryKey: ['discipline', 'board', 'candidates'], queryFn: () => api.get<{ data: Candidate[] }>('/discipline/board/candidates').then((r) => r.data) })
  const waiting = (cands.data ?? []).filter((c) => !c.board_meeting_id)

  const close = () => {
    setOpen(false)
    if (params.has('yeni')) setParams((p) => { p.delete('yeni'); return p }, { replace: true })
  }

  return (
    <div className="animate-fade-in">
      <PageHeader title="Disiplin kurulu" description="Kurul kararı gerektiren yaptırımlar toplantıda oylanır; karar tutanağı PDF olarak basılır."
        actions={can('discipline.board') && <Button variant="primary" icon={<CalendarPlus className="size-4" />} onClick={() => setOpen(true)}>Toplantı planla</Button>} />

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_400px]">
        <Panel title="Toplantılar" flush>
          {list.isLoading ? <div className="p-4"><Skeleton className="h-40" /></div> : !list.data?.data.length ? (
            <EmptyState compact icon={<Scale />} title="Henüz kurul toplantısı yok" description="Kurul kararı bekleyen öneriler için toplantı planlayın."
              action={can('discipline.board') ? <Button variant="primary" onClick={() => setOpen(true)}>Toplantı planla</Button> : undefined} />
          ) : (
            <ul className="divide-y divide-line border-t border-line">
              {list.data.data.map((m) => (
                <li key={m.id}>
                  <button type="button" onClick={() => navigate(`/disiplin/kurul/${m.id}`)} className="flex w-full flex-wrap items-center gap-3 px-4 py-3 text-left hover:bg-surface-2">
                    <span className="grid size-10 shrink-0 place-items-center rounded-[var(--radius-md)] bg-primary-soft text-center leading-none text-primary-ink ring-1 ring-primary/20">
                      <span className="text-[15px] font-semibold tabular">{new Date(m.scheduled_at).getDate()}</span>
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[13.5px] font-medium text-ink">{m.title}</span>
                      <span className="block text-[12.5px] tabular text-ink-2">{m.meeting_no} · {dateTime(m.scheduled_at)}{m.location ? ` · ${m.location}` : ''}</span>
                    </span>
                    <span className="text-[12.5px] text-ink-2">{m.items_count ?? 0} madde{m.pending_count ? ` · ${m.pending_count} bekliyor` : ''}</span>
                    <StatusBadge status={m.status} label={m.status_label} map={BOARD_TONE} />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel title="Kurul kararı bekleyen öneriler" description={waiting.length ? `${waiting.length} öneri henüz bir toplantı gündeminde değil` : 'Tüm öneriler gündemde'} flush>
          {cands.isLoading ? <div className="p-4"><Skeleton className="h-24" /></div> : !(cands.data ?? []).length ? (
            <p className="border-t border-line px-4 py-4 text-[13px] text-ink-3">Kurul bekleyen yaptırım önerisi yok.</p>
          ) : (
            <ul className="divide-y divide-line border-t border-line">
              {cands.data!.map((c) => (
                <li key={c.sanction_id} className="px-4 py-2.5">
                  <Link to={`/disiplin/olaylar/${c.incident_id}`} className="block hover:text-primary">
                    <span className="flex items-center gap-2">
                      <span className="min-w-0 flex-1 truncate text-[13.5px] font-medium">{c.student?.full_name}</span>
                      <Badge tone="warning">{c.type}{c.days ? ` · ${c.days} gün` : ''}</Badge>
                    </span>
                    <span className="mt-0.5 flex flex-wrap items-center gap-1.5 text-[12px] text-ink-3">
                      {c.incident_no} · {date(c.occurred_at)}
                      <Badge tone={c.defense_status ? DEFENSE_TONE[c.defense_status] : 'danger'}>{c.defense_status === 'submitted' ? 'Savunma var' : c.defense_status === 'waived' ? 'Savunma alınmadı' : c.defense_status === 'requested' ? 'Savunma bekleniyor' : 'Savunma istenmedi'}</Badge>
                      {c.board_meeting_id && <Badge tone="info">Gündemde</Badge>}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <MeetingDrawer open={open} onClose={close} candidates={waiting} onCreated={(id) => navigate(`/disiplin/kurul/${id}`)} />
    </div>
  )
}

function MembersEditor({ value, onChange }: { value: MemberRow[]; onChange: (v: MemberRow[]) => void }) {
  const { data: opt } = useDisciplineOptions()
  const [pick, setPick] = useState('')
  const names = new Map((opt?.staff ?? []).map((s) => [s.id, s.name]))
  return (
    <div className="flex flex-col gap-2">
      {value.map((m, i) => (
        <div key={m.user_id} className="flex flex-wrap items-center gap-2 rounded-[var(--radius-sm)] bg-surface-2 px-2.5 py-1.5 ring-1 ring-line">
          <span className="min-w-0 flex-1 truncate text-[13px] font-medium">{names.get(m.user_id) ?? `#${m.user_id}`}</span>
          <Select aria-label="Görev" className="w-28" value={m.role} onChange={(e) => onChange(value.map((x, xi) => (xi === i ? { ...x, role: e.target.value } : x)))}
            options={Object.entries(opt?.board_roles ?? {}).map(([v, l]) => ({ value: v, label: l }))} />
          <Checkbox checked={m.present} onChange={(v) => onChange(value.map((x, xi) => (xi === i ? { ...x, present: v } : x)))} label="Katıldı" />
          <Button size="icon-sm" variant="ghost" aria-label="Üyeyi çıkar" onClick={() => onChange(value.filter((_, xi) => xi !== i))}><X className="size-3.5" /></Button>
        </div>
      ))}
      <Select aria-label="Üye ekle" value={pick} placeholder="+ Üye ekle"
        onChange={(e) => { const id = Number(e.target.value); if (id && !value.some((v) => v.user_id === id)) onChange([...value, { user_id: id, role: value.length === 0 ? 'chair' : 'member', present: true }]); setPick('') }}
        options={(opt?.staff ?? []).filter((s) => !value.some((v) => v.user_id === s.id)).map((s) => ({ value: s.id, label: s.name }))} />
    </div>
  )
}

function MeetingDrawer({ open, onClose, candidates, meeting, onCreated }: { open: boolean; onClose: () => void; candidates?: Candidate[]; meeting?: Meeting; onCreated?: (id: number) => void }) {
  const qc = useQueryClient()
  const [f, setF] = useState({ title: '', scheduled_at: '', location: '', notes: '' })
  const [members, setMembers] = useState<MemberRow[]>([])
  const [incidents, setIncidents] = useState<number[]>([])
  useEffect(() => {
    if (!open) return
    if (meeting) {
      setF({ title: meeting.title, scheduled_at: toLocalInput(meeting.scheduled_at), location: meeting.location ?? '', notes: meeting.notes ?? '' })
      setMembers(meeting.members.map((m) => ({ user_id: m.user_id, role: m.role, present: m.present })))
    } else {
      const d = new Date()
      d.setDate(d.getDate() + 2)
      d.setHours(16, 0, 0, 0)
      setF({ title: `Disiplin kurulu — ${d.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long' })}`, scheduled_at: toLocalInput(d), location: 'Müdür odası', notes: '' })
      setMembers([])
      setIncidents([...new Set((candidates ?? []).map((c) => c.incident_id))])
    }
  }, [open, meeting, candidates])
  const save = useMutation({
    mutationFn: () => meeting
      ? api.put<{ message: string; id?: number }>(`/discipline/board/${meeting.id}`, { ...f, members })
      : api.post<{ message: string; id: number }>('/discipline/board', { ...f, members, incident_ids: incidents }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['discipline'] })
      onClose()
      if (!meeting && r.id) onCreated?.(r.id)
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })
  const uniq = [...new Map((candidates ?? []).map((c) => [c.incident_id, c])).values()]
  return (
    <Drawer open={open} onClose={onClose} width={560} title={meeting ? 'Toplantıyı düzenle' : 'Kurul toplantısı planla'}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={!f.title || !f.scheduled_at} onClick={() => save.mutate()}>Kaydet</Button></>}>
      <div className="flex flex-col gap-4">
        <Field label="Başlık" required><Input value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></Field>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Toplantı tarihi ve saati" required><Input type="datetime-local" value={f.scheduled_at} onChange={(e) => setF({ ...f, scheduled_at: e.target.value })} /></Field>
          <Field label="Toplantı yeri" optional><Input value={f.location} onChange={(e) => setF({ ...f, location: e.target.value })} /></Field>
        </div>
        <Field label="Kurul üyeleri" hint="İlk eklenen başkan olur; oy sayısı katılan üye sayısını aşamaz."><MembersEditor value={members} onChange={setMembers} /></Field>
        {!meeting && uniq.length > 0 && (
          <Field label="Gündem (kurul bekleyen olaylar)">
            <div className="flex flex-col gap-1.5 rounded-[var(--radius-md)] p-2.5 ring-1 ring-line">
              {uniq.map((c) => (
                <Checkbox key={c.incident_id} checked={incidents.includes(c.incident_id)} onChange={(v) => setIncidents(v ? [...incidents, c.incident_id] : incidents.filter((x) => x !== c.incident_id))}
                  label={<span className="text-[13px]">{c.incident_no} · {(candidates ?? []).filter((x) => x.incident_id === c.incident_id).map((x) => `${x.student?.full_name} (${x.type})`).join(', ')}</span>} />
              ))}
            </div>
          </Field>
        )}
        <Field label="Notlar / görüşme özeti" optional><Textarea rows={4} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
      </div>
    </Drawer>
  )
}

export function BoardDetail() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const { data: opt } = useDisciplineOptions()
  const { data: m, isLoading } = useQuery({
    queryKey: ['discipline', 'board', Number(id)],
    queryFn: () => api.get<{ data: Meeting & { items: MeetingItem[] } }>(`/discipline/board/${id}`).then((r) => r.data),
  })
  const [edit, setEdit] = useState(false)
  const [decide, setDecide] = useState<MeetingItem | null>(null)
  const [adding, setAdding] = useState(false)
  const [cancel, setCancel] = useState(false)
  const [q, setQ] = useState('')
  const dq = useDebounced(q, 250)
  const search = useQuery({
    queryKey: ['discipline', 'board', 'incident-search', dq],
    queryFn: () => api.get<{ data: { id: number; incident_no: string; occurred_at: string; students: string; status_label: string }[] }>('/discipline/board/incident-search', { q: dq }).then((r) => r.data),
    enabled: adding,
  })
  const act = useMutation({
    mutationFn: (fn: () => Promise<{ message: string }>) => fn(),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['discipline'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.'),
  })

  if (isLoading) return <Skeleton className="h-64" />
  if (!m) return <EmptyState icon={<Scale />} title="Toplantı bulunamadı" action={<ButtonLink to="/disiplin/kurul">Kurula dön</ButtonLink>} />
  const manage = can('discipline.board') && m.status !== 'cancelled'
  const present = m.members.filter((x) => x.present).length

  return (
    <div className="animate-fade-in">
      <PageHeader breadcrumbs={[{ label: 'Disiplin', to: '/disiplin' }, { label: 'Kurul', to: '/disiplin/kurul' }, { label: m.meeting_no }]}
        title={<span className="flex flex-wrap items-center gap-2">{m.title} <StatusBadge status={m.status} label={m.status_label} map={BOARD_TONE} /></span>}
        description={`${m.meeting_no} · ${dateTime(m.scheduled_at)}${m.location ? ` · ${m.location}` : ''}`}
        actions={
          <>
            <Button icon={<Printer className="size-4" />} onClick={() => window.open(`/api/v1/discipline/board/${m.id}/pdf`, '_blank')}>Karar tutanağı</Button>
            {manage && <Button onClick={() => setEdit(true)}>Düzenle</Button>}
            {manage && m.status === 'planned' && <Button variant="success" icon={<Check className="size-4" />} onClick={() => act.mutate(() => api.post(`/discipline/board/${m.id}/status`, { status: 'held' }))}>Yapıldı</Button>}
            {manage && m.status === 'planned' && <Button variant="danger-soft" onClick={() => setCancel(true)}>İptal</Button>}
          </>
        } />

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
        <Panel title="Gündem" description={`${m.items.length} madde · karar için oy çokluğu gerekir`}
          actions={manage && <Button size="sm" icon={<Plus className="size-3.5" />} onClick={() => setAdding((v) => !v)}>Olay ekle</Button>} flush>
          {adding && (
            <div className="border-t border-line bg-surface-2/50 p-3">
              <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Öğrenci adı ya da olay no" leading={<Search />} autoFocus aria-label="Olay ara" />
              <ul className="mt-2 max-h-56 overflow-y-auto scroll-thin">
                {(search.data ?? []).map((r) => (
                  <li key={r.id} className="flex items-center gap-2 py-1.5 text-[13px]">
                    <span className="min-w-0 flex-1 truncate">{r.incident_no} · {date(r.occurred_at)} · {r.students}</span>
                    <Button size="xs" onClick={() => act.mutate(() => api.post(`/discipline/board/${m.id}/items`, { incident_id: r.id }))}>Ekle</Button>
                  </li>
                ))}
                {search.data?.length === 0 && <li className="py-2 text-[13px] text-ink-3">Uygun olay yok.</li>}
              </ul>
            </div>
          )}
          {m.items.length === 0 ? <EmptyState compact icon={<Gavel />} title="Gündem boş" description="Kurula alınacak olayları ekleyin." /> : (
            <ol className="divide-y divide-line border-t border-line">
              {m.items.map((it, i) => (
                <li key={it.id} className="px-4 py-3.5">
                  <div className="flex flex-wrap items-start gap-3">
                    <span className="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-[12px] font-semibold text-white">{i + 1}</span>
                    <div className="min-w-0 flex-1">
                      <p className="flex flex-wrap items-center gap-2">
                        <Link to={`/disiplin/olaylar/${it.incident?.id}`} className="font-medium text-ink hover:text-primary">{it.incident?.incident_no}</Link>
                        <span className="text-[12.5px] text-ink-3">{date(it.incident?.occurred_at)}</span>
                        {it.incident && <Badge tone={SEVERITY_TONE[it.incident.severity]}>{it.incident.severity_label}</Badge>}
                      </p>
                      <p className="mt-0.5 text-[13px] text-ink-2">
                        {it.sanction ? <><b className="text-ink">{it.sanction.student?.full_name}</b> için önerilen: <b className="text-ink">{it.sanction.type?.name}</b>{it.sanction.days ? ` (${it.sanction.days} gün, ${date(it.sanction.starts_on)})` : ''}</>
                          : <>Genel görüşme: {it.involved.map((x) => x.full_name).join(', ')}</>}
                      </p>
                      <p className="mt-1 flex flex-wrap gap-1.5">
                        {it.involved.filter((x) => !it.student || x.student_id === it.student.id).map((x) => (
                          <Badge key={x.student_id} tone={x.defense_status ? DEFENSE_TONE[x.defense_status] : 'danger'}>
                            {x.full_name}: {x.behavior ?? '—'} · {x.defense_status === 'submitted' ? 'savunma var' : x.defense_status === 'waived' ? 'savunma alınmadı' : x.defense_status === 'requested' ? 'savunma bekleniyor' : 'savunma istenmedi'}
                          </Badge>
                        ))}
                      </p>
                      {it.defense?.statement && <p className="mt-2 line-clamp-3 border-l-2 border-success/50 pl-2.5 text-[12.5px] text-ink-2">{it.defense.statement}</p>}
                      {it.result !== 'pending' && (
                        <p className={cn('mt-2 rounded-[var(--radius-sm)] px-2.5 py-1.5 text-[12.5px]', it.result === 'accepted' ? 'bg-success-soft text-success' : it.result === 'rejected' ? 'bg-danger-soft text-danger' : 'bg-surface-2 text-ink-2')}>
                          <b>{it.result === 'accepted' ? 'Kabul' : it.result === 'rejected' ? 'Ret' : 'Ertelendi'}</b> · {it.votes_for} kabul, {it.votes_against} ret, {it.votes_abstain} çekimser{it.decision ? ` — ${it.decision}` : ''}
                        </p>
                      )}
                    </div>
                    {manage && ['pending', 'postponed'].includes(it.result) && (
                      <div className="flex gap-1.5">
                        <Button size="sm" variant="primary" icon={<Gavel className="size-3.5" />} onClick={() => setDecide(it)}>Karar</Button>
                        {it.result === 'pending' && <Button size="icon-sm" variant="ghost" aria-label="Gündemden çıkar" onClick={() => act.mutate(() => api.delete(`/discipline/board/${m.id}/items/${it.id}`))}><Trash2 className="size-3.5" /></Button>}
                      </div>
                    )}
                  </div>
                </li>
              ))}
            </ol>
          )}
        </Panel>

        <div className="flex flex-col gap-4">
          <Panel title="Üyeler" description={`${present} / ${m.members.length} katılım`} actions={<Users className="size-4 text-ink-3" />}>
            {m.members.length === 0 ? <p className="text-[13px] text-ink-3">Üye eklenmedi.</p> : (
              <ul className="flex flex-col gap-1.5">
                {m.members.map((x) => (
                  <li key={x.user_id} className="flex items-center gap-2 text-[13px]">
                    <span className={cn('size-2 rounded-full', x.present ? 'bg-success' : 'bg-ink-3')} />
                    <span className="min-w-0 flex-1 truncate">{x.name}</span>
                    <Badge tone={x.role === 'chair' ? 'primary' : 'neutral'}>{x.role_label}</Badge>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          {m.notes && <Panel title="Notlar"><p className="whitespace-pre-line text-[13px] text-ink-2">{m.notes}</p></Panel>}
          {m.status === 'planned' && present === 0 && <Alert tone="warning">Karar girmeden önce toplantıya katılan üyeleri işaretleyin (Düzenle).</Alert>}
        </div>
      </div>

      <MeetingDrawer open={edit} meeting={m} onClose={() => setEdit(false)} />
      <DecideModal meetingId={m.id} item={decide} present={present} opt={opt} onClose={() => setDecide(null)} />
      <ConfirmDialog open={cancel} onClose={() => setCancel(false)} title="Toplantı iptal edilsin mi?" description="Gündemdeki öneriler kurul bekleyenler listesine döner."
        confirmLabel="İptal et" danger onConfirm={() => { setCancel(false); act.mutate(() => api.post(`/discipline/board/${m.id}/status`, { status: 'cancelled' })) }} />
    </div>
  )
}

function DecideModal({ meetingId, item, present, opt, onClose }: { meetingId: number; item: MeetingItem | null; present: number; opt?: ReturnType<typeof useDisciplineOptions>['data']; onClose: () => void }) {
  const qc = useQueryClient()
  const [f, setF] = useState<Record<string, any>>({})
  const [err, setErr] = useState<string | null>(null)
  useEffect(() => {
    if (!item) return
    setErr(null)
    setF({
      result: 'accepted', votes_for: present || 1, votes_against: 0, votes_abstain: 0, decision: '',
      sanction_type_id: item.sanction?.type?.id ?? '', student_id: item.student?.id ?? item.involved[0]?.student_id ?? '',
      starts_on: item.sanction?.starts_on ?? '', days: item.sanction?.days ?? 1, duty_description: item.sanction?.duty_description ?? '',
    })
  }, [item, present])
  const type = opt?.sanction_types.find((t) => String(t.id) === String(f.sanction_type_id))
  const save = useMutation({
    mutationFn: () => api.post<{ message: string }>(`/discipline/board/${meetingId}/items/${item!.id}/decide`, {
      ...f, sanction_type_id: f.result === 'accepted' ? f.sanction_type_id || null : null, student_id: item?.sanction ? null : f.student_id || null,
      starts_on: type?.is_suspension ? f.starts_on : null, days: type?.is_suspension ? Number(f.days) : null, duty_description: type?.has_duty ? f.duty_description : null,
    }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['discipline'] }); onClose() },
    onError: (e) => setErr(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })
  const num = (k: string) => <Input type="number" min={0} max={30} value={f[k] ?? 0} onChange={(e) => setF({ ...f, [k]: Number(e.target.value) })} />
  return (
    <Modal open={!!item} onClose={onClose} size="lg" title="Kurul kararı" description={item ? `${item.incident?.incident_no} · ${item.sanction?.student?.full_name ?? 'genel madde'}` : undefined}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Kararı kaydet</Button></>}>
      <div className="flex flex-col gap-3.5">
        <Segmented value={f.result ?? 'accepted'} onChange={(v) => setF({ ...f, result: v })}
          options={[{ value: 'accepted', label: 'Kabul' }, { value: 'rejected', label: 'Ret' }, { value: 'postponed', label: 'Ertele' }]} />
        <div className="grid grid-cols-3 gap-3">
          <Field label="Kabul oyu">{num('votes_for')}</Field>
          <Field label="Ret oyu">{num('votes_against')}</Field>
          <Field label="Çekimser oy">{num('votes_abstain')}</Field>
        </div>
        <p className="-mt-1 text-[12px] text-ink-3">Katılan üye: {present || 'işaretlenmedi'}</p>
        {f.result === 'accepted' && (
          <>
            {!item?.sanction && (
              <Field label="Öğrenci" required>
                <Select value={f.student_id} onChange={(e) => setF({ ...f, student_id: e.target.value })} options={(item?.involved ?? []).map((x) => ({ value: x.student_id, label: x.full_name }))} />
              </Field>
            )}
            <Field label="Yaptırım" hint={item?.sanction ? 'Kurul öneriyi değiştirebilir.' : undefined} required={!item?.sanction}>
              <Select value={f.sanction_type_id} onChange={(e) => setF({ ...f, sanction_type_id: e.target.value })} placeholder="Seçin"
                options={(opt?.sanction_types ?? []).map((t) => ({ value: t.id, label: `${t.level}. ${t.name}` }))} />
            </Field>
            {type?.is_suspension && (
              <div className="grid grid-cols-2 gap-3">
                <Field label="Başlangıç tarihi" required><Input type="date" value={f.starts_on} onChange={(e) => setF({ ...f, starts_on: e.target.value })} /></Field>
                <Field label="Gün sayısı" required><Input type="number" min={1} max={30} value={f.days} onChange={(e) => setF({ ...f, days: e.target.value })} /></Field>
              </div>
            )}
            {type?.has_duty && <Field label="Görev açıklaması" required><Input value={f.duty_description} onChange={(e) => setF({ ...f, duty_description: e.target.value })} /></Field>}
          </>
        )}
        <Field label="Karar metni" optional hint="Karar tutanağına yazılır."><Textarea rows={3} value={f.decision ?? ''} onChange={(e) => setF({ ...f, decision: e.target.value })} placeholder="Örn. Öğrencinin savunması dinlendi; oy çokluğuyla…" /></Field>
        {err && <Alert tone="danger">{err}</Alert>}
      </div>
    </Modal>
  )
}
