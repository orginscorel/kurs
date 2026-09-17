import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Send, Users } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Segmented, Select, Input } from '@/components/ui/form'
import { Alert, Skeleton } from '@/components/ui/feedback'
import type { Channel, MessageTemplateRow, RecipientKind } from './types'

type Target = 'all' | 'class_group' | 'program' | 'ids'

type Props = {
  open: boolean
  onClose: () => void
  /** Belirli öğrenci id'leri (öğrenci profili / toplu işlemlerden çağrıldığında). Boşsa hedef seçilir. */
  studentIds?: number[]
  /** Öğrenci id listesi verildiğinde başlıkta gösterilecek etiket, ör. "3 öğrenci". */
  studentsLabel?: string
  classGroupId?: number
  programId?: number
}

type Options = { class_groups: { id: number; name: string }[]; programs: { id: number; name: string }[] }

const KNOWN_AUTO_VARS = ['ogrenci_adi', 'veli_adi']

/**
 * Yeniden kullanılabilir WhatsApp/SMS/E-posta gönderim penceresi. Öğrenci profili ve toplu
 * işlemlerden `studentIds` ile açılır; İletişim > WhatsApp ekranından hedef kitle seçilerek de açılır.
 */
export function SendMessageDialog({ open, onClose, studentIds, studentsLabel, classGroupId, programId }: Props) {
  const qc = useQueryClient()
  const fixedIds = studentIds && studentIds.length > 0

  const [channel, setChannel] = useState<Channel>('whatsapp')
  const [recipientType, setRecipientType] = useState<RecipientKind>('guardian')
  const [target, setTarget] = useState<Target>(fixedIds ? 'ids' : classGroupId ? 'class_group' : programId ? 'program' : 'all')
  const [targetId, setTargetId] = useState<number | ''>(classGroupId ?? programId ?? '')
  const [templateKey, setTemplateKey] = useState('')
  const [customVars, setCustomVars] = useState<Record<string, string>>({})

  useEffect(() => {
    if (!open) return
    setChannel('whatsapp')
    setRecipientType('guardian')
    setTarget(fixedIds ? 'ids' : classGroupId ? 'class_group' : programId ? 'program' : 'all')
    setTargetId(classGroupId ?? programId ?? '')
    setTemplateKey('')
    setCustomVars({})
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const options = useQuery({ queryKey: ['students', 'options'], queryFn: () => api.get<Options>('/students/options'), staleTime: 5 * 60_000, enabled: open })
  const templates = useQuery({
    queryKey: ['message-templates', channel],
    queryFn: () => api.get<{ data: MessageTemplateRow[] }>('/message-templates', { channel }),
    enabled: open,
  })
  const activeTemplates = (templates.data?.data ?? []).filter((t) => t.is_active)
  const selectedTemplate = activeTemplates.find((t) => t.key === templateKey)

  const customVarKeys = useMemo(() => {
    if (!selectedTemplate) return []
    const found = new Set<string>()
    const re = /\{\{\s*([a-z0-9_]+)\s*\}\}/gi
    let m: RegExpExecArray | null
    // eslint-disable-next-line no-cond-assign
    while ((m = re.exec(selectedTemplate.body))) {
      if (!KNOWN_AUTO_VARS.includes(m[1]!.toLowerCase())) found.add(m[1]!.toLowerCase())
    }
    return [...found]
  }, [selectedTemplate])

  const payloadBase = () => ({
    channel,
    recipient_type: recipientType,
    target,
    target_id: target === 'class_group' || target === 'program' ? Number(targetId) || undefined : undefined,
    ids: target === 'ids' ? studentIds : undefined,
  })

  const preview = useQuery({
    queryKey: ['messages', 'preview', channel, recipientType, target, targetId, studentIds?.join(',')],
    queryFn: () => api.post<{ recipient_count: number; no_address_count: number; no_consent_count: number; sendable_count: number }>('/messages/preview', payloadBase()),
    enabled: open && (target !== 'class_group' && target !== 'program' ? true : !!targetId),
  })

  const send = useMutation({
    mutationFn: () =>
      api.post<{ message: string; queued: number; failed: number }>('/messages/send', {
        ...payloadBase(),
        template_key: templateKey,
        vars: customVars,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      if (res.failed > 0) toast.warning(`${res.failed} alıcı için mesaj oluşturulamadı (numara/izin eksik).`)
      qc.invalidateQueries({ queryKey: ['messages'] })
      onClose()
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Gönderilemedi.'),
  })

  const canSend = !!templateKey && (preview.data?.sendable_count ?? 0) > 0 && customVarKeys.every((k) => (customVars[k] ?? '').trim() !== '')

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title="WhatsApp / SMS / E-posta gönder"
      description={fixedIds ? (studentsLabel ?? `${studentIds!.length} öğrenci seçili`) : 'Hedef kitle seçip şablon ile gönderin'}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" icon={<Send className="size-4" />} loading={send.isPending} disabled={!canSend} onClick={() => send.mutate()}>
            Gönder
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-5">
        <Field label="Gönderim kanalı" required>
          <Segmented value={channel} onChange={(v) => { setChannel(v); setTemplateKey('') }} options={[
            { value: 'whatsapp', label: 'WhatsApp' }, { value: 'sms', label: 'SMS' }, { value: 'email', label: 'E-posta' },
          ]} />
        </Field>

        <Field label="Kime gönderilsin" required>
          <Segmented value={recipientType} onChange={setRecipientType} options={[
            { value: 'guardian', label: 'Veli' }, { value: 'student', label: 'Öğrencinin kendisi' },
          ]} />
        </Field>

        {!fixedIds && (
          <Field label="Hangi öğrenciler" required>
            <Select
              value={target}
              onChange={(e) => { setTarget(e.target.value as Target); setTargetId('') }}
              options={[
                { value: 'all', label: 'Tüm aktif öğrenciler' },
                { value: 'class_group', label: 'Sınıf' },
                { value: 'program', label: 'Program' },
              ]}
            />
          </Field>
        )}

        {!fixedIds && target === 'class_group' && (
          <Field label="Sınıf" required>
            <Select value={targetId} onChange={(e) => setTargetId(e.target.value ? Number(e.target.value) : '')} placeholder="Sınıf seçin" options={(options.data?.class_groups ?? []).map((g) => ({ value: g.id, label: g.name }))} />
          </Field>
        )}
        {!fixedIds && target === 'program' && (
          <Field label="Program" required>
            <Select value={targetId} onChange={(e) => setTargetId(e.target.value ? Number(e.target.value) : '')} placeholder="Program seçin" options={(options.data?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
          </Field>
        )}

        <Field label="Mesaj şablonu" required hint={!templates.isLoading && activeTemplates.length === 0 ? 'Bu kanal için etkin şablon yok; İletişim › Şablonlar ekranından ekleyin.' : undefined}>
          {templates.isLoading ? <Skeleton className="h-9" /> : (
            <Select value={templateKey} onChange={(e) => { setTemplateKey(e.target.value); setCustomVars({}) }} placeholder="Şablon seçin" options={activeTemplates.map((t) => ({ value: t.key, label: t.name }))} />
          )}
        </Field>

        {selectedTemplate && (
          <div className="rounded-[var(--radius-md)] bg-surface-2 p-3 text-[13px] text-ink-2 whitespace-pre-wrap ring-1 ring-line">
            {selectedTemplate.body}
          </div>
        )}

        {customVarKeys.length > 0 && (
          <div className="flex flex-col gap-2">
            <p className="text-[12.5px] text-ink-3">Öğrenci ve veli adı otomatik doldurulur; şablondaki diğer boşlukları siz girin.</p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {customVarKeys.map((k) => (
                <Field key={k} label={k.replace(/_/g, ' ').replace(/^./, (c) => c.toLocaleUpperCase('tr-TR'))} required>
                  <Input value={customVars[k] ?? ''} onChange={(e) => setCustomVars((v) => ({ ...v, [k]: e.target.value }))} />
                </Field>
              ))}
            </div>
          </div>
        )}

        {!preview.isLoading && preview.data && (
          <Alert tone={preview.data.sendable_count > 0 ? 'info' : 'warning'} title={<span className="inline-flex items-center gap-1.5"><Users className="size-3.5" /> {preview.data.recipient_count} alıcı bulundu</span>}>
            {preview.data.sendable_count} kişiye gönderilecek
            {preview.data.no_address_count > 0 && `, ${preview.data.no_address_count} kişide numara/adres yok`}
            {preview.data.no_consent_count > 0 && `, ${preview.data.no_consent_count} kişi izin vermemiş`}.
          </Alert>
        )}
      </div>
    </Drawer>
  )
}
