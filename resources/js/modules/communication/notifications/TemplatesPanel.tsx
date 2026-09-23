import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Eye, Pencil, Plus } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useCan } from '@/app/auth'
import { Panel } from '@/components/ui/layout'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import { renderSample, type Catalog, type EventTemplate } from './types'

/**
 * Şablonlar: olay seçilir, o olayın her kitlesi (öğrenci/veli/öğretmen/yönetici) için ayrı WhatsApp metni
 * düzenlenir. Değişken paletinden tıkla-ekle + canlı önizleme (sunucu render'ı, düşerse yerel örnek).
 */
export default function TemplatesPanel({ catalog }: { catalog?: Catalog }) {
  const can = useCan()
  const qc = useQueryClient()
  const editable = can('templates.manage')

  const events = catalog?.events ?? []
  const [eventType, setEventType] = useState('')
  useEffect(() => {
    if (!eventType && events.length) setEventType(events[0]!.event_type)
  }, [events, eventType])

  const { data, isLoading } = useQuery({
    queryKey: ['notif', 'templates', eventType],
    queryFn: () => api.get<{ data: EventTemplate[]; variables: string[] }>('/notifications/templates', { event_type: eventType, channel: 'whatsapp' }),
    enabled: !!eventType,
  })

  const [editing, setEditing] = useState<EventTemplate | null>(null)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <Field label="Olay" className="w-full max-w-xs">
          <Select value={eventType} onChange={(e) => setEventType(e.target.value)} options={events.map((ev) => ({ value: ev.event_type, label: ev.label }))} />
        </Field>
      </div>

      {isLoading ? (
        <Skeleton className="h-56" />
      ) : !data?.data.length ? (
        <EmptyState icon={<Plus />} title="Bu olay için şablon yok" description="Varsayılan şablonlar kurulumda gelir." />
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {data.data.map((t) => (
            <Panel key={t.id} title={t.audience_label} flush actions={<Badge tone={t.is_active ? 'success' : 'neutral'} dot>{t.is_active ? 'Aktif' : 'Pasif'}</Badge>}>
              <div className="px-4 pb-3.5">
                <p className="whitespace-pre-wrap break-words rounded-[var(--radius-sm)] bg-surface-2 p-3 text-[13px] leading-relaxed text-ink-2 line-clamp-6">{t.body}</p>
                {editable && (
                  <div className="mt-2.5 flex justify-end">
                    <Button size="sm" variant="soft" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(t)}>Düzenle</Button>
                  </div>
                )}
              </div>
            </Panel>
          ))}
        </div>
      )}

      {editing && (
        <TemplateEditor
          template={editing}
          variables={data?.variables ?? []}
          onClose={() => setEditing(null)}
          onSaved={() => {
            qc.invalidateQueries({ queryKey: ['notif', 'templates', eventType] })
            setEditing(null)
          }}
        />
      )}
    </div>
  )
}

function TemplateEditor({ template, variables, onClose, onSaved }: { template: EventTemplate; variables: string[]; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(template.name)
  const [body, setBody] = useState(template.body)
  const [active, setActive] = useState(template.is_active)
  const bodyRef = useRef<HTMLTextAreaElement>(null)

  // Canlı önizleme: sunucudan iste, ulaşılamazsa yerel örnekle göster.
  const preview = useQuery({
    queryKey: ['notif', 'tpl-preview', body],
    queryFn: () => api.post<{ body: string }>('/notifications/templates/preview', { body }).then((r) => r.body).catch(() => renderSample(body)),
    enabled: body.trim().length > 0,
    staleTime: 30_000,
  })

  const save = useMutation({
    mutationFn: () => api.put<{ data: EventTemplate }>(`/notifications/templates/${template.id}`, { name, body, is_active: active }),
    onSuccess: () => {
      toast.success('Şablon kaydedildi.')
      onSaved()
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Şablon kaydedilemedi.'),
  })

  function insertVar(v: string) {
    const el = bodyRef.current
    const token = `{{${v}}}`
    if (!el) {
      setBody((b) => b + token)
      return
    }
    const start = el.selectionStart ?? body.length
    const end = el.selectionEnd ?? body.length
    const next = body.slice(0, start) + token + body.slice(end)
    setBody(next)
    requestAnimationFrame(() => {
      el.focus()
      const pos = start + token.length
      el.setSelectionRange(pos, pos)
    })
  }

  return (
    <Drawer
      open
      onClose={onClose}
      title={`${template.audience_label} şablonu`}
      description="WhatsApp metni — {{degisken}} alanları gönderimde otomatik doldurulur."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()} disabled={!body.trim()}>Kaydet</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label="Şablon adı">
          <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Örn. Ders programı — Veli" />
        </Field>

        <Field label="Mesaj metni" hint="İmleç konumuna eklemek için aşağıdaki alanlara tıklayın.">
          <Textarea ref={bodyRef} rows={7} value={body} onChange={(e) => setBody(e.target.value)} />
        </Field>

        {variables.length > 0 && (
          <div>
            <p className="mb-1.5 text-[12px] text-ink-3">Kullanılabilir alanlar</p>
            <div className="flex flex-wrap gap-1.5">
              {variables.map((v) => (
                <button
                  key={v}
                  type="button"
                  onClick={() => insertVar(v)}
                  className="rounded-[var(--radius-xs)] border border-line bg-surface-2 px-2 py-1 font-mono text-[12px] text-ink-2 transition-colors hover:border-primary/40 hover:bg-primary-soft/40 hover:text-primary-ink"
                >
                  {`{{${v}}}`}
                </button>
              ))}
            </div>
          </div>
        )}

        <div className="rounded-[var(--radius-sm)] border border-line bg-surface-2/60 p-3">
          <div className="mb-1.5 flex items-center gap-1.5 text-[12px] font-medium text-ink-3"><Eye className="size-3.5" /> Önizleme</div>
          <p className="whitespace-pre-wrap break-words text-[13px] leading-relaxed text-ink">{preview.data ?? renderSample(body)}</p>
        </div>

        <Switch checked={active} onChange={setActive} label="Aktif (gönderimlerde kullanılır)" />
      </div>
    </Drawer>
  )
}
