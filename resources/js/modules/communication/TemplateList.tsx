import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { FileText, Pencil, Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import { ConfirmDialog } from '@/components/ui/overlay'
import { CHANNEL_LABEL, type MessageTemplateRow } from './types'

const SAMPLE_VARS: Record<string, string> = {
  ogrenci_adi: 'Ahmet Yılmaz', veli_adi: 'Ayşe Yılmaz', saat: '08:15', ders_saati: '14:00', ders_adi: 'Matematik',
  gec_dakika: '10', vade_tarihi: '15.10.2026', tutar: '1.250,00', gecikme_gun: '3', sinav_adi: 'TYT Deneme 3',
  toplam_net: '78,25', kurum_sirasi: '4', ders_netleri: 'Türkçe: 32 net\nMatematik: 28 net', ders_listesi: '09:00 Matematik\n10:00 Fizik',
  dakika: '10', derslik: 'A-101', tarih: '16.09.2026', aciklama: 'Öğretmen izinli.', odev_adi: 'Sayfa 45-50', teslim_tarihi: '17.09.2026',
  baslik: 'Ara Tatil Duyurusu', metin: 'Ara tatil 20-24 Ekim tarihleri arasındadır.',
  eski_sinif: '11-A', yeni_sinif: '11-B',
}

function renderPreview(body: string): string {
  return body.replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi, (_, key) => SAMPLE_VARS[key.toLowerCase()] ?? `[${key}]`)
}

export default function TemplateList() {
  const can = useCan()
  const qc = useQueryClient()
  const [editing, setEditing] = useState<MessageTemplateRow | 'new' | null>(null)
  const [deleting, setDeleting] = useState<MessageTemplateRow | null>(null)

  const { data, isLoading } = useQuery({ queryKey: ['message-templates'], queryFn: () => api.get<{ data: MessageTemplateRow[]; known_vars: string[] }>('/message-templates') })

  const del = useMutation({
    mutationFn: (id: number) => api.delete<{ message: string }>(`/message-templates/${id}`),
    onSuccess: (res) => { toast.success(res.message); qc.invalidateQueries({ queryKey: ['message-templates'] }); setDeleting(null) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
  })

  const columns: Column<MessageTemplateRow>[] = [
    { key: 'name', header: 'Şablon adı', cell: (t) => <div className="min-w-0"><p className="truncate text-ink font-medium" title={t.name}>{t.name}</p><p className="truncate text-[12px] text-ink-3">Sistem anahtarı: <span className="font-mono">{t.key}</span></p></div> },
    { key: 'channel', header: 'Kanal', cell: (t) => <Badge tone="neutral">{CHANNEL_LABEL[t.channel] ?? t.channel}</Badge> },
    { key: 'body', header: 'Mesaj metni', hideable: true, cell: (t) => <span className="text-ink-2 line-clamp-2 max-w-[360px]">{t.body}</span> },
    { key: 'status', header: 'Durum', cell: (t) => <Badge tone={t.is_active ? 'success' : 'neutral'} dot>{t.is_active ? 'Aktif' : 'Pasif'}</Badge> },
    {
      key: 'actions', header: '', align: 'right', cell: (t) => can('templates.manage') ? (
        <div className="flex items-center justify-end gap-1" onClick={(e) => e.stopPropagation()}>
          <Button size="icon-sm" variant="ghost" aria-label="Düzenle" title="Düzenle" onClick={() => setEditing(t)}><Pencil className="size-4" /></Button>
          <Button size="icon-sm" variant="ghost" aria-label="Sil" title="Sil" onClick={() => setDeleting(t)}><Trash2 className="size-4 text-danger" /></Button>
        </div>
      ) : null,
    },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Mesaj şablonları"
        description="WhatsApp, SMS ve e-posta metinleri; {{ogrenci_adi}} gibi alanlar gönderimde otomatik doldurulur"
        actions={can('templates.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Yeni şablon</Button>}
      />

      <Panel flush>
        <DataTable
          storageKey="message-templates"
          columns={columns}
          rows={data?.data}
          rowKey={(r) => r.id}
          loading={isLoading}
          onRowClick={can('templates.manage') ? (t) => setEditing(t) : undefined}
          empty={<EmptyState icon={<FileText />} title="Henüz şablon yok" description="İlk mesaj şablonunuzu oluşturun." action={can('templates.manage') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Yeni şablon</Button> : undefined} />}
        />
      </Panel>

      <TemplateFormDrawer open={editing !== null} template={editing === 'new' ? undefined : editing ?? undefined} knownVars={data?.known_vars ?? []} onClose={() => setEditing(null)} />

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={() => deleting && del.mutate(deleting.id)} loading={del.isPending} danger
        title="Şablonu sil" description={`"${deleting?.name}" şablonunu silmek istediğinize emin misiniz?`} />
    </div>
  )
}

function TemplateFormDrawer({ open, template, knownVars, onClose }: { open: boolean; template?: MessageTemplateRow; knownVars: string[]; onClose: () => void }) {
  const qc = useQueryClient()
  const editing = !!template
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!open) return
    setErrors({})
    setForm(template ? { ...template } : { channel: 'whatsapp', language: 'tr', is_active: true, key: '', name: '', body: '', subject: '', provider_template: '' })
  }, [open, template])

  const preview = useMemo(() => renderPreview(form.body ?? ''), [form.body])

  const save = useMutation({
    mutationFn: () => (editing ? api.put<{ message: string }>(`/message-templates/${template!.id}`, form) : api.post<{ message: string }>('/message-templates', form)),
    onSuccess: (res) => { toast.success(res.message); qc.invalidateQueries({ queryKey: ['message-templates'] }); onClose() },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={editing ? 'Şablonu düzenle' : 'Yeni şablon'}
      description={editing ? `Sistem anahtarı: ${template!.key}` : 'Sistem anahtarı ve kanal birlikte benzersiz olmalı.'}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button></>}
    >
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Sistem anahtarı" required hint="Küçük harf, rakam ve nokta; ör. veli.devamsizlik" error={err('key')}><Input value={form.key ?? ''} onChange={(e) => set('key', e.target.value)} placeholder="guardian.absent" disabled={editing} /></Field>
          <Field label="Kanal" required error={err('channel')}>
            <Select value={form.channel ?? 'whatsapp'} onChange={(e) => set('channel', e.target.value)} disabled={editing}
              options={[{ value: 'whatsapp', label: 'WhatsApp' }, { value: 'sms', label: 'SMS' }, { value: 'email', label: 'E-posta' }, { value: 'push', label: 'Push / Uygulama' }]} />
          </Field>
        </div>
        <Field label="Şablon adı" required error={err('name')}><Input value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} /></Field>
        {form.channel === 'email' && <Field label="E-posta konusu" optional error={err('subject')}><Input value={form.subject ?? ''} onChange={(e) => set('subject', e.target.value)} /></Field>}
        <Field label="Mesaj metni" required error={err('body')} hint="Değişkenleri aşağıdaki listeden tıklayarak ekleyin.">
          <Textarea rows={6} value={form.body ?? ''} onChange={(e) => set('body', e.target.value)} />
        </Field>
        {form.channel === 'whatsapp' && (
          <Field label="Meta onaylı şablon adı" optional hint="24 saat penceresi dışında serbest metin yerine kullanılır." error={err('provider_template')}>
            <Input value={form.provider_template ?? ''} onChange={(e) => set('provider_template', e.target.value)} />
          </Field>
        )}
        <Switch checked={form.is_active ?? true} onChange={(v) => set('is_active', v)} label="Kullanımda (gönderimlerde seçilebilir)" />

        <div>
          <p className="mb-1.5 text-[12.5px] font-medium text-ink-2">Canlı önizleme</p>
          <div className="rounded-[var(--radius-md)] bg-surface-2 p-3 text-[13px] text-ink whitespace-pre-wrap ring-1 ring-line min-h-[48px]">{preview || '—'}</div>
        </div>

        <div>
          <p className="mb-1.5 text-[12.5px] font-medium text-ink-2">Kullanılabilir değişkenler</p>
          <div className="flex flex-wrap gap-1.5">
            {knownVars.map((v) => (
              <button key={v} type="button" onClick={() => set('body', `${form.body ?? ''}{{${v}}}`)} className="rounded-full bg-surface-2 px-2 h-6 text-[12px] font-mono text-ink-2 ring-1 ring-line hover:bg-primary-soft hover:text-primary-ink">
                {`{{${v}}}`}
              </button>
            ))}
          </div>
        </div>
      </div>
    </Drawer>
  )
}
