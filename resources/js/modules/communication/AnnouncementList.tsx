import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Megaphone, Plus, Users } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useListState } from '@/hooks/useListState'
import { PageHeader, Panel } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Select, Textarea } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import type { AnnouncementRow } from './types'

const AUDIENCE_LABEL: Record<string, string> = { all_students: 'Tüm öğrenciler', class_group: 'Sınıf', program: 'Program', teachers: 'Öğretmenler', guardians: 'Veliler' }
const CHANNEL_LABELS: Record<string, string> = { app: 'Uygulama bildirimi', whatsapp: 'WhatsApp', email: 'E-posta', sms: 'SMS' }

type Options = { class_groups: { id: number; name: string }[]; programs: { id: number; name: string }[] }

export default function AnnouncementList() {
  const can = useCan()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: '-id' })
  const formOpen = params.get('yeni') === '1'

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['announcements', 'list', list.query],
    queryFn: () => api.get<Paginated<AnnouncementRow>>('/announcements', list.query),
    placeholderData: keepPreviousData,
  })

  const columns: Column<AnnouncementRow>[] = [
    { key: 'title', header: 'Duyuru', cell: (a) => <div><p className="text-ink font-medium">{a.title}</p><p className="text-[12.5px] text-ink-3 line-clamp-1">{a.body}</p></div> },
    {
      key: 'audience',
      header: 'Hedef kitle',
      cell: (a) => (
        <div className="flex flex-wrap items-center gap-1">
          <Badge tone="neutral">{AUDIENCE_LABEL[a.audience.type] ?? a.audience.type}</Badge>
          {(a.audience.type === 'class_group' || a.audience.type === 'program') && (
            <span className="text-[12px] text-ink-3 whitespace-nowrap">{a.audience.students_only ? 'yalnız öğrenciler' : '+ veliler'}</span>
          )}
        </div>
      ),
    },
    { key: 'channels', header: 'Gönderim kanalları', hideable: true, cell: (a) => <div className="flex flex-wrap gap-1">{a.channels.map((c) => <Badge key={c} tone="primary">{CHANNEL_LABELS[c] ?? c}</Badge>)}</div> },
    { key: 'recipient_count', header: 'Alıcı sayısı', align: 'right', cell: (a) => <span className="tabular text-ink">{a.recipient_count.toLocaleString('tr-TR')}</span> },
    { key: 'published_at', header: 'Yayım tarihi', sortKey: 'id', cell: (a) => <span className="text-ink-2 tabular">{dateTime(a.published_at)}</span> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Duyurular"
        description="Hedef kitle ve kanal seçerek toplu duyuru yayımlayın"
        actions={can('announcements.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Yeni duyuru</Button>}
      />

      <Panel flush>
        <DataTable
          storageKey="announcements"
          columns={columns}
          rows={data?.data}
          rowKey={(r) => r.id}
          loading={isLoading || isFetching}
          meta={data?.meta}
          onPage={(page) => list.update({ page })}
          empty={<EmptyState icon={<Megaphone />} title="Henüz duyuru yayımlanmadı" description="İlk duyurunuzu oluşturun." action={can('announcements.manage') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Yeni duyuru</Button> : undefined} />}
        />
      </Panel>

      <AnnouncementFormDrawer open={formOpen} onClose={() => setParams((p) => { p.delete('yeni'); return p })} onPublished={() => qc.invalidateQueries({ queryKey: ['announcements'] })} />
    </div>
  )
}

function AnnouncementFormDrawer({ open, onClose, onPublished }: { open: boolean; onClose: () => void; onPublished: () => void }) {
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [audienceType, setAudienceType] = useState('all_students')
  const [audienceId, setAudienceId] = useState<number | ''>('')
  const [studentsOnly, setStudentsOnly] = useState(false)
  const [channels, setChannels] = useState<string[]>(['app'])
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const options = useQuery({ queryKey: ['students', 'options'], queryFn: () => api.get<Options>('/students/options'), enabled: open, staleTime: 5 * 60_000 })

  useEffect(() => {
    if (!open) return
    setTitle(''); setBody(''); setAudienceType('all_students'); setAudienceId(''); setStudentsOnly(false); setChannels(['app']); setErrors({})
  }, [open])

  const targeted = audienceType === 'class_group' || audienceType === 'program'
  const toggleChannel = (c: string) => setChannels((cs) => (cs.includes(c) ? cs.filter((x) => x !== c) : [...cs, c]))

  const publish = useMutation({
    mutationFn: () =>
      api.post<{ message: string; recipient_count: number }>('/announcements', {
        title, body, channels, audience: { type: audienceType, id: audienceId || undefined, ...(targeted ? { students_only: studentsOnly } : {}) },
      }),
    onSuccess: (res) => { toast.success(`${res.message} (${res.recipient_count} alıcı)`); onPublished(); onClose() },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title="Yeni duyuru"
      description="Yayımladıktan sonra düzenlenemez"
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={publish.isPending} disabled={!title.trim() || !body.trim() || channels.length === 0 || (targeted && !audienceId)} onClick={() => publish.mutate()}>Yayımla</Button></>}
    >
      <div className="flex flex-col gap-4">
        <Field label="Başlık" required error={errors.title?.[0]}><Input value={title} onChange={(e) => setTitle(e.target.value)} /></Field>
        <Field label="Metin" required error={errors.body?.[0]}><Textarea rows={5} value={body} onChange={(e) => setBody(e.target.value)} /></Field>

        <Field label="Kime gidecek" required error={errors['audience.type']?.[0]}>
          <Select value={audienceType} onChange={(e) => { setAudienceType(e.target.value); setAudienceId('') }}
            options={Object.entries(AUDIENCE_LABEL).map(([value, label]) => ({ value, label }))} />
        </Field>
        {audienceType === 'class_group' && (
          <Field label="Sınıf" required error={errors['audience.id']?.[0]}>
            <Select value={audienceId} onChange={(e) => setAudienceId(e.target.value ? Number(e.target.value) : '')} placeholder="Sınıf seçin" options={(options.data?.class_groups ?? []).map((g) => ({ value: g.id, label: g.name }))} />
          </Field>
        )}
        {audienceType === 'program' && (
          <Field label="Program" required error={errors['audience.id']?.[0]}>
            <Select value={audienceId} onChange={(e) => setAudienceId(e.target.value ? Number(e.target.value) : '')} placeholder="Program seçin" options={(options.data?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
          </Field>
        )}
        {targeted && (
          <div className="flex flex-col gap-1">
            <Checkbox checked={studentsOnly} onChange={setStudentsOnly} label="Yalnız öğrencilere (veliler görmesin)" />
            <p className="text-[12px] text-ink-3">
              {studentsOnly ? 'Duyuru yalnız öğrenci portalında görünür.' : 'Duyuru bu öğrencilerin velilerinin portalında da görünür; "Uygulama bildirimi" seçiliyse velilere de bildirim düşer.'}
            </p>
          </div>
        )}

        <Field label="Gönderim kanalları" required hint="En az birini seçin" error={errors.channels?.[0]}>
          <div className="flex flex-col gap-2">
            {Object.entries(CHANNEL_LABELS).map(([value, label]) => (
              <Checkbox key={value} checked={channels.includes(value)} onChange={() => toggleChannel(value)} label={label} />
            ))}
          </div>
        </Field>

        <div className="flex items-center gap-2 rounded-[var(--radius-md)] bg-surface-2 px-3 py-2 text-[12.5px] text-ink-2 ring-1 ring-line">
          <Users className="size-3.5 shrink-0" /> Alıcı sayısı yayımlarken hesaplanır ve duyuru listesinde gösterilir.
        </div>
      </div>
    </Drawer>
  )
}
