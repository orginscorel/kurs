import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, ArrowRight, Search, Send, Users, X } from 'lucide-react'
import { api, ApiError, isLocalNode, type Paginated } from '@/lib/api'
import { openOnWeb } from '@/lib/webOnly'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Segmented, Select } from '@/components/ui/form'
import type { BatchDetail, Catalog, CatalogEvent } from './types'

/** Kitle başına otomatik dolan (öğrenciye özel) alanlar — wizard'da sorulmaz. */
const AUTO_VARS = new Set(['ogrenci_adi', 'veli_adi', 'okul_no', 'sinif'])

type StudentHit = { id: number; full_name: string; student_no: string }
type ClassGroup = { id: number; name: string }

export default function NotificationWizard() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const local = isLocalNode()

  const { data: catalog } = useQuery({ queryKey: ['notif', 'catalog'], queryFn: () => api.get<Catalog>('/notifications/catalog'), staleTime: 10 * 60_000 })
  const events = catalog?.events ?? []
  const audienceLabels = catalog?.audiences ?? {}

  // Ön-dolu parametreler (tetik düğmelerinden gelir)
  const [eventType, setEventType] = useState(params.get('event') ?? '')
  const [mode, setMode] = useState<'class' | 'students'>(params.get('class_group') ? 'class' : 'students')
  const [classGroupId, setClassGroupId] = useState(params.get('class_group') ?? '')
  const [students, setStudents] = useState<StudentHit[]>([])
  const [teacherId] = useState(params.get('teacher') ?? '')
  const [audiences, setAudiences] = useState<string[]>([])
  const [title, setTitle] = useState('')
  const [vars, setVars] = useState<Record<string, string>>({})
  const [draft, setDraft] = useState<BatchDetail | null>(null)

  const event: CatalogEvent | undefined = useMemo(() => events.find((e) => e.event_type === eventType), [events, eventType])

  // Olay seçilince varsayılan kitleleri işaretle
  useEffect(() => {
    if (event) setAudiences(event.audiences.map((a) => a.key))
  }, [event])

  const manualVars = useMemo(() => (event?.variables ?? []).filter((v) => !AUTO_VARS.has(v)), [event])

  // Sınıf listesi (şube modu)
  const classes = useQuery({
    queryKey: ['notif', 'class-groups'],
    queryFn: () => api.get<{ data: ClassGroup[] }>('/class-groups').then((r) => r.data ?? []),
    staleTime: 5 * 60_000,
  })

  // Öğrenci arama (çoklu seçim modu)
  const [q, setQ] = useState('')
  const [debounced, setDebounced] = useState('')
  useEffect(() => {
    const t = setTimeout(() => setDebounced(q), 250)
    return () => clearTimeout(t)
  }, [q])
  const search = useQuery({
    queryKey: ['notif', 'student-search', debounced],
    queryFn: () => api.get<Paginated<StudentHit>>('/students', { q: debounced, per_page: 8 }),
    enabled: mode === 'students' && debounced.trim().length >= 2,
  })

  const build = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = { event_type: eventType, audiences, channel: 'whatsapp', vars }
      if (title.trim()) body.title = title.trim()
      if (teacherId) body.teacher_id = Number(teacherId)
      if (mode === 'class') body.class_group_id = Number(classGroupId)
      else body.student_ids = students.map((s) => s.id)
      return api.post<{ data: BatchDetail }>('/notifications/batches', body).then((r) => r.data)
    },
    onSuccess: (d) => setDraft(d),
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Taslak oluşturulamadı.'),
  })

  const approve = useMutation({
    mutationFn: () => api.post<{ data: BatchDetail }>(`/notifications/batches/${draft!.id}/approve`, {}).then((r) => r.data),
    onSuccess: (d) => {
      toast.success('Bildirimler onaylandı ve gönderildi.')
      navigate(`/iletisim/bildirim-merkezi/gonderim/${d.id}`)
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Onaylanamadı.'),
  })

  const cancelDraft = useMutation({
    mutationFn: () => api.delete(`/notifications/batches/${draft!.id}`),
    onSettled: () => setDraft(null),
  })

  const recipientReady = mode === 'class' ? !!classGroupId : students.length > 0
  const canBuild = !!eventType && audiences.length > 0 && recipientReady

  // ---- Adım 2: taslak önizleme ----
  if (draft) {
    return (
      <div className="animate-fade-in">
        <PageHeader
          title="Taslağı gözden geçirin"
          description="Onaylamadan önce kitlelere gidecek mesajları kontrol edin. Onaylayınca gönderilir."
          breadcrumbs={[{ label: 'İletişim' }, { label: 'Bildirim Merkezi', to: '/iletisim/bildirim-merkezi' }, { label: 'Yeni gönderim' }]}
          actions={
            <>
              <Button variant="ghost" icon={<X className="size-4" />} onClick={() => cancelDraft.mutate()} loading={cancelDraft.isPending}>Taslağı iptal et</Button>
              <Button variant="outline" onClick={() => api.download(`/notifications/batches/${draft.id}/pdf`, undefined, 'bildirim.pdf').catch(() => toast.error('PDF alınamadı.'))}>PDF önizle</Button>
            </>
          }
        />

        <div className="mb-4 flex flex-wrap items-center gap-2 text-[13px] text-ink-2">
          <Badge tone="info">{draft.event_label}</Badge>
          <span>Toplam <b className="text-ink">{draft.total}</b> mesaj</span>
          <span className="text-ink-3">·</span>
          <span>{draft.audiences.map((a) => audienceLabels[a] ?? a).join(', ')}</span>
        </div>

        {local && (
          <Alert tone="warning" className="mb-4">
            Onay ve gönderim yalnızca web üzerinden yapılır. <button className="underline" onClick={() => openOnWeb(`/iletisim/bildirim-merkezi`)}>Web'de aç</button>.
          </Alert>
        )}

        <div className="flex flex-col gap-4">
          {draft.groups.map((g) => (
            <Panel key={g.audience} title={<span className="flex items-center gap-2"><Users className="size-4 text-primary" />{g.audience_label}</span>} description={`${g.count} alıcı`} flush>
              <div className="max-h-72 divide-y divide-line overflow-y-auto scroll-thin">
                {g.messages.slice(0, 30).map((m) => (
                  <div key={m.id} className="px-4 py-2.5">
                    <p className="text-[12px] text-ink-3">{m.to}</p>
                    <p className="mt-0.5 whitespace-pre-wrap break-words text-[13px] leading-relaxed text-ink">{m.body}</p>
                  </div>
                ))}
                {g.count > 30 && <p className="px-4 py-2 text-[12px] text-ink-3">…ve {g.count - 30} mesaj daha</p>}
              </div>
            </Panel>
          ))}
        </div>

        <div className="mt-5 flex items-center justify-end gap-2">
          <Button variant="ghost" icon={<ArrowLeft className="size-4" />} onClick={() => cancelDraft.mutate()} loading={cancelDraft.isPending}>Geri</Button>
          <Button variant="primary" icon={<Send className="size-4" />} loading={approve.isPending} disabled={local} onClick={() => approve.mutate()}>Onayla ve gönder</Button>
        </div>
      </div>
    )
  }

  // ---- Adım 1: yapılandırma ----
  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Yeni bildirim gönderimi"
        description="Olayı, alıcıları ve kitleleri seçin; sonra taslağı önizleyip onaylayın."
        breadcrumbs={[{ label: 'İletişim' }, { label: 'Bildirim Merkezi', to: '/iletisim/bildirim-merkezi' }, { label: 'Yeni gönderim' }]}
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="1 · Olay">
          <Field label="Hangi olay için bildirim gönderilecek?">
            <Select value={eventType} onChange={(e) => setEventType(e.target.value)} placeholder="Olay seçin" options={events.map((ev) => ({ value: ev.event_type, label: ev.label }))} />
          </Field>
          <Field label="Başlık" className="mt-3" optional>
            <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Örn. Eylül 4. hafta ders programı" />
          </Field>
        </Panel>

        <Panel title="2 · Alıcılar">
          <Segmented value={mode} onChange={setMode} options={[{ value: 'class', label: 'Şube' }, { value: 'students', label: 'Öğrenci seç' }]} className="mb-3" />
          {mode === 'class' ? (
            classes.isLoading ? <Skeleton className="h-9" /> : (
              <Field label="Şube">
                <Select value={classGroupId} onChange={(e) => setClassGroupId(e.target.value)} placeholder="Şube seçin" options={(classes.data ?? []).map((c) => ({ value: c.id, label: c.name }))} />
              </Field>
            )
          ) : (
            <div>
              <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Öğrenci ara (en az 2 harf)" leading={<Search />} />
              {search.data?.data.length ? (
                <div className="mt-2 flex flex-col gap-1 rounded-[var(--radius-sm)] border border-line p-1">
                  {search.data.data.map((s) => (
                    <button key={s.id} type="button" className="flex items-center justify-between rounded-[var(--radius-xs)] px-2 py-1.5 text-left text-[13px] hover:bg-surface-2"
                      onClick={() => { setStudents((prev) => prev.some((p) => p.id === s.id) ? prev : [...prev, s]); setQ('') }}>
                      <span>{s.full_name}</span><span className="text-[12px] text-ink-3 tabular">{s.student_no}</span>
                    </button>
                  ))}
                </div>
              ) : null}
              {students.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {students.map((s) => (
                    <span key={s.id} className="inline-flex items-center gap-1 rounded-full bg-primary-soft px-2 py-0.5 text-[12px] text-primary-ink">
                      {s.full_name}
                      <button type="button" onClick={() => setStudents((prev) => prev.filter((p) => p.id !== s.id))} aria-label="Kaldır"><X className="size-3" /></button>
                    </span>
                  ))}
                </div>
              )}
            </div>
          )}
        </Panel>

        <Panel title="3 · Kitleler">
          {!event ? (
            <EmptyState compact title="Önce olay seçin" />
          ) : (
            <div className="flex flex-wrap gap-x-5 gap-y-2">
              {event.audiences.map((a) => (
                <Checkbox key={a.key} checked={audiences.includes(a.key)} label={a.label}
                  onChange={(on) => setAudiences((prev) => on ? [...new Set([...prev, a.key])] : prev.filter((x) => x !== a.key))} />
              ))}
            </div>
          )}
        </Panel>

        <Panel title="4 · Ek bilgiler" description="Tüm alıcılarda aynı olan alanlar (öğrenci adı, sınıf vb. otomatik doldurulur).">
          {manualVars.length === 0 ? (
            <p className="text-[13px] text-ink-3">Bu olay için ek bilgi gerekmez.</p>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2">
              {manualVars.map((v) => (
                <Field key={v} label={v} optional>
                  <Input value={vars[v] ?? ''} onChange={(e) => setVars((prev) => ({ ...prev, [v]: e.target.value }))} placeholder={`{{${v}}}`} />
                </Field>
              ))}
            </div>
          )}
        </Panel>
      </div>

      <div className="mt-5 flex items-center justify-between">
        <Button variant="ghost" icon={<ArrowLeft className="size-4" />} onClick={() => navigate('/iletisim/bildirim-merkezi')}>Vazgeç</Button>
        <Button variant="primary" iconRight={<ArrowRight className="size-4" />} loading={build.isPending} disabled={!canBuild} onClick={() => build.mutate()}>Taslağı oluştur ve önizle</Button>
      </div>
    </div>
  )
}
