import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarClock, Clock, History, Pencil, Plug, Plus, Radio, Trash2, Zap } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Select, Switch } from '@/components/ui/form'
import { Drawer, ConfirmDialog, Modal } from '@/components/ui/overlay'
import type { AutomationAction, AutomationRuleRow, AutomationRunRow, AutomationWiring, ChannelStatus, RecipientKind } from './types'

const RUN_STATUS_TONE: Record<string, 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = { scheduled: 'info', done: 'success', skipped: 'neutral', failed: 'danger' }
const RUN_STATUS_LABEL: Record<string, string> = { scheduled: 'Planlandı', done: 'Tamamlandı', skipped: 'Atlandı', failed: 'Başarısız' }
const TO_LABEL: Record<RecipientKind, string> = { student: 'Öğrenci', guardian: 'Veli', teacher: 'Öğretmen', admin: 'Yönetici' }
const TYPE_LABEL: Record<string, string> = { whatsapp: 'WhatsApp', sms: 'SMS', email: 'E-posta', app: 'Uygulama bildirimi' }
const CHANNEL_TYPES = ['whatsapp', 'sms', 'email'] as const

/** Kuralın kullandığı ama bağlı olmayan kanallar. */
function missingChannels(rule: AutomationRuleRow, channels?: ChannelStatus): string[] {
  if (!channels) return []
  const used = new Set(rule.actions.map((a) => a.type))
  return CHANNEL_TYPES.filter((c) => used.has(c) && !channels[c])
}

export default function AutomationList() {
  const can = useCan()
  const qc = useQueryClient()
  const [editing, setEditing] = useState<AutomationRuleRow | 'new' | null>(null)
  const [deleting, setDeleting] = useState<AutomationRuleRow | null>(null)
  const [history, setHistory] = useState<AutomationRuleRow | null>(null)

  const [blocked, setBlocked] = useState<{ rule: string; channels: string[] } | null>(null)
  const { data, isLoading } = useQuery({ queryKey: ['automations'], queryFn: () => api.get<{ data: AutomationRuleRow[]; channels?: ChannelStatus }>('/automations') })
  const channels = data?.channels
  const whatsappDown = channels ? !channels.whatsapp : false
  const triggers = useQuery({ queryKey: ['automations', 'triggers'], queryFn: () => api.get<{ data: Record<string, string> }>('/automations/triggers') })
  const wiring = useQuery({ queryKey: ['automations', 'wiring'], queryFn: () => api.get<AutomationWiring>('/automation-wiring'), refetchInterval: 60_000 })

  const toggle = useMutation({
    mutationFn: (rule: AutomationRuleRow) => api.post<{ message: string; is_active: boolean }>(`/automations/${rule.id}/toggle`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['automations'] }),
    onError: (e, rule) => {
      if (e instanceof ApiError && (e.code === 'whatsapp_not_connected' || e.code === 'channel_not_connected')) {
        setBlocked({ rule: rule.name, channels: (e.context.channels as string[] | undefined) ?? ['whatsapp'] })
        qc.invalidateQueries({ queryKey: ['automations'] })
      } else {
        toast.error(e instanceof ApiError ? e.firstError() : 'Durum değiştirilemedi.')
      }
    },
  })

  /** Kapalı kuralı açarken kanal bağlı değilse istek atmadan açık uyarı gösterilir (sunucu da reddeder). */
  const onToggle = (rule: AutomationRuleRow) => {
    const missing = rule.is_active ? [] : missingChannels(rule, channels)
    if (missing.length) {
      setBlocked({ rule: rule.name, channels: missing })
      return
    }
    toggle.mutate(rule)
  }

  const del = useMutation({
    mutationFn: (id: number) => api.delete<{ message: string }>(`/automations/${id}`),
    onSuccess: (res) => { toast.success(res.message); qc.invalidateQueries({ queryKey: ['automations'] }); setDeleting(null) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
  })

  const columns: Column<AutomationRuleRow>[] = [
    {
      key: 'name', header: 'Kural', maxWidth: 290, cell: (r) => (
        <div className="min-w-0">
          <p className="text-ink font-medium truncate">{r.name}</p>
          <p className="text-[12px] text-ink-3 truncate">{r.description}</p>
        </div>
      ),
    },
    { key: 'trigger', header: 'Tetikleyici', hideable: true, cell: (r) => <Badge tone="info">{r.trigger_label}</Badge> },
    { key: 'run_count', header: 'Çalışma sayısı', align: 'right', cell: (r) => <button className="text-ink tabular hover:text-primary underline decoration-dotted" onClick={() => setHistory(r)}>{r.run_count}</button> },
    { key: 'last_run_at', header: 'Son çalıştığı zaman', hideable: true, cell: (r) => <span className="text-ink-2">{r.last_run_at ? relative(r.last_run_at) : '—'}</span> },
    {
      key: 'is_active', header: 'Durum', cell: (r) =>
        can('automations.manage') ? <Switch checked={r.is_active} onChange={() => onToggle(r)} /> : <Badge tone={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'Açık' : 'Kapalı'}</Badge>,
    },
    {
      key: 'actions', header: '', align: 'right', cell: (r) => (
        <div className="flex items-center justify-end gap-1">
          <Button size="icon-sm" variant="ghost" aria-label="Çalışma geçmişi" title="Çalışma geçmişi" onClick={() => setHistory(r)}><History className="size-4" /></Button>
          {can('automations.manage') && <Button size="icon-sm" variant="ghost" aria-label="Düzenle" title="Düzenle" onClick={() => setEditing(r)}><Pencil className="size-4" /></Button>}
          {can('automations.manage') && <Button size="icon-sm" variant="ghost" aria-label="Sil" title="Sil" onClick={() => setDeleting(r)}><Trash2 className="size-4 text-danger" /></Button>}
        </div>
      ),
    },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Otomasyonlar"
        description="Olay/zaman tetikleyicisi → koşul → eylem kuralları"
        actions={can('automations.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Yeni kural</Button>}
      />

      {whatsappDown && (
        <Alert
          tone="warning"
          className="mb-4"
          title="WhatsApp bağlı değil"
          action={can('integrations.manage') ? <ButtonLink size="sm" to="/ayarlar/entegrasyonlar" icon={<Plug className="size-4" />}>Entegrasyonlar</ButtonLink> : undefined}
        >
          WhatsApp kullanan kurallar bağlantı kurulana kadar açılamaz. Bağladıktan sonra Entegrasyonlar ekranından önerilen veli bildirimlerini tek tıkla açabilirsiniz.
        </Alert>
      )}

      <Panel flush>
        <DataTable
          storageKey="automation-rules"
          columns={columns}
          rows={data?.data}
          rowKey={(r) => r.id}
          loading={isLoading}
          empty={<EmptyState icon={<Zap />} title="Henüz otomasyon kuralı yok" description="İlk kuralınızı oluşturun." action={can('automations.manage') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Yeni kural</Button> : undefined} />}
        />
      </Panel>

      <WiringPanel wiring={wiring.data} loading={wiring.isLoading} />

      <AutomationFormDrawer open={editing !== null} rule={editing === 'new' ? undefined : editing ?? undefined} triggers={triggers.data?.data ?? {}}
        sources={Object.fromEntries((wiring.data?.data ?? []).map((w) => [w.trigger, w.source ?? '']))} onClose={() => setEditing(null)} />
      <RunHistoryModal rule={history} onClose={() => setHistory(null)} />

      <Modal
        open={!!blocked}
        onClose={() => setBlocked(null)}
        size="sm"
        title={blocked?.channels.includes('whatsapp') ? 'Önce WhatsApp\'ı bağlayın' : 'Önce kanal bağlantısını kurun'}
        description={blocked?.rule}
        footer={
          <>
            <Button variant="ghost" onClick={() => setBlocked(null)}>Kapat</Button>
            {can('integrations.manage') && <ButtonLink variant="primary" to="/ayarlar/entegrasyonlar" icon={<Plug className="size-4" />}>Entegrasyonlara git</ButtonLink>}
          </>
        }
      >
        <p className="text-[13.5px] text-ink-2">
          Bu kural {blocked?.channels.map((c) => TYPE_LABEL[c] ?? c).join(', ')} ile mesaj gönderiyor. Bağlantı kurulmadan açılırsa mesajlar gönderilemez;
          bu yüzden kural kapalı bırakıldı. <Link to="/ayarlar/entegrasyonlar" className="font-medium text-primary hover:underline">Ayarlar › Entegrasyonlar</Link> ekranından bağlantıyı kurup test edin.
        </p>
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={() => deleting && del.mutate(deleting.id)} loading={del.isPending} danger
        title="Kuralı sil" description={`"${deleting?.name}" otomasyon kuralını silmek istediğinize emin misiniz?`} />
    </div>
  )
}

const emptyAction = (): AutomationAction => ({ type: 'whatsapp', to: 'guardian', template: '' })

function AutomationFormDrawer({ open, rule, triggers, sources, onClose }: { open: boolean; rule?: AutomationRuleRow; triggers: Record<string, string>; sources: Record<string, string>; onClose: () => void }) {
  const qc = useQueryClient()
  const editing = !!rule
  const [name, setName] = useState('')
  const [trigger, setTrigger] = useState('')
  const [delayMinutes, setDelayMinutes] = useState(0)
  const [days, setDays] = useState<number | ''>('')
  const [minMissed, setMinMissed] = useState<number | ''>('')
  const [actions, setActions] = useState<AutomationAction[]>([emptyAction()])
  const [description, setDescription] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    if (!open) return
    setErrors({})
    if (rule) {
      setName(rule.name); setTrigger(rule.trigger); setDelayMinutes(rule.delay_minutes)
      setDays((rule.conditions as any)?.days ?? ''); setMinMissed((rule.conditions as any)?.min_missed_count ?? '')
      setActions(rule.actions.length ? rule.actions : [emptyAction()])
    } else {
      setName(''); setTrigger(''); setDelayMinutes(0); setDays(''); setMinMissed(''); setActions([emptyAction()])
    }
  }, [open, rule])

  const payload = () => ({
    name, trigger, delay_minutes: delayMinutes,
    conditions: days !== '' || (trigger === 'homework.missed' && minMissed !== '')
      ? { ...(days !== '' ? { days: Number(days) } : {}), ...(trigger === 'homework.missed' && minMissed !== '' ? { min_missed_count: Number(minMissed) } : {}) }
      : null,
    actions,
  })

  const describe = useMutation({
    mutationFn: () => api.post<{ description: string }>('/automations/describe', payload()),
    onSuccess: (res) => setDescription(res.description),
  })

  useEffect(() => {
    if (!open || !trigger || actions.length === 0) return
    const t = setTimeout(() => describe.mutate(), 300)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, trigger, delayMinutes, days, minMissed, JSON.stringify(actions)])

  const save = useMutation({
    mutationFn: () => (editing ? api.put<{ message: string }>(`/automations/${rule!.id}`, payload()) : api.post<{ message: string }>('/automations', payload())),
    onSuccess: (res) => { toast.success(res.message); qc.invalidateQueries({ queryKey: ['automations'] }); onClose() },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  const updateAction = (i: number, patch: Partial<AutomationAction>) => setActions((a) => a.map((x, idx) => (idx === i ? { ...x, ...patch } : x)))

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={620}
      title={editing ? 'Kuralı düzenle' : 'Yeni otomasyon kuralı'}
      description={editing ? rule!.name : 'Yeni kurallar güvenlik için PASİF oluşturulur; listeden açarsınız.'}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={!name || !trigger} onClick={() => save.mutate()}>Kaydet</Button></>}
    >
      <div className="flex flex-col gap-4">
        <Field label="Kural adı" required error={errors.name?.[0]}><Input value={name} onChange={(e) => setName(e.target.value)} /></Field>
        <Field label="Tetikleyici" required error={errors.trigger?.[0]}>
          <Select value={trigger} onChange={(e) => setTrigger(e.target.value)} placeholder="Ne olduğunda çalışsın?" options={Object.entries(triggers).map(([value, label]) => ({ value, label }))} />
        </Field>
        {trigger && sources[trigger] && (
          <p className="-mt-2 text-[12.5px] leading-relaxed text-ink-3"><span className="font-medium text-ink-2">Kaynak: </span>{sources[trigger]}</p>
        )}
        {trigger === 'homework.missed' && (
          <Field label="En az yapılmayan ödev (son 30 gün)" optional hint="Boş = her yapılmayan ödevde. Ör. 2 = tekrarlıyorsa (veli kuralı için)">
            <Input type="number" min={1} value={minMissed} onChange={(e) => setMinMissed(e.target.value === '' ? '' : Number(e.target.value))} />
          </Field>
        )}

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Kaç dakika sonra gönderilsin" hint="0 = hemen">
            <Input type="number" min={0} value={delayMinutes} onChange={(e) => setDelayMinutes(Number(e.target.value))} />
          </Field>
          <Field label="Gün sayısı" optional hint="Vadeye kalan / geciken gün koşulu için">
            <Input type="number" value={days} onChange={(e) => setDays(e.target.value === '' ? '' : Number(e.target.value))} />
          </Field>
        </div>

        <Field label="Ne yapılsın" required error={errors.actions?.[0]} hint="Her satır: gönderim kanalı · kime · mesaj şablonunun sistem anahtarı">
          <div className="flex flex-col gap-2">
            {actions.map((a, i) => (
              <div key={i} className="grid grid-cols-[1fr_1fr_auto] sm:grid-cols-[1fr_1fr_1.4fr_auto] gap-2 items-center rounded-[var(--radius-sm)] sm:ring-0 ring-1 ring-line p-1.5 sm:p-0">
                <Select value={a.type} onChange={(e) => updateAction(i, { type: e.target.value as AutomationAction['type'] })}
                  options={Object.entries(TYPE_LABEL).map(([value, label]) => ({ value, label }))} />
                <Select value={a.to} onChange={(e) => updateAction(i, { to: e.target.value as RecipientKind })}
                  options={Object.entries(TO_LABEL).map(([value, label]) => ({ value, label }))} />
                <Input aria-label="Şablon sistem anahtarı" className="col-span-2 sm:col-span-1 order-last sm:order-none" placeholder={a.type === 'app' ? 'Uygulama bildiriminde gerekmez' : 'Şablon anahtarı, ör. veli.devamsizlik'} value={a.template ?? ''} onChange={(e) => updateAction(i, { template: e.target.value })} disabled={a.type === 'app'} />
                <Button size="icon-sm" variant="ghost" aria-label="Eylemi kaldır" title="Eylemi kaldır" onClick={() => setActions((arr) => arr.filter((_, idx) => idx !== i))} disabled={actions.length === 1}>
                  <Trash2 className="size-4 text-danger" />
                </Button>
              </div>
            ))}
            <Button size="sm" icon={<Plus className="size-3.5" />} onClick={() => setActions((a) => [...a, emptyAction()])}>Eylem ekle</Button>
          </div>
        </Field>

        {trigger && actions.length > 0 && (
          <div className="rounded-[var(--radius-md)] bg-primary-soft px-3.5 py-3 text-[13.5px] text-primary-ink ring-1 ring-primary/15">
            {description || '…'}
          </div>
        )}
      </div>
    </Drawer>
  )
}

function RunHistoryModal({ rule, onClose }: { rule: AutomationRuleRow | null; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['automations', rule?.id, 'runs'],
    queryFn: () => api.get<Paginated<AutomationRunRow>>(`/automations/${rule!.id}/runs`),
    enabled: !!rule,
  })

  return (
    <Modal open={!!rule} onClose={onClose} size="lg" title="Çalışma geçmişi" description={rule?.name}>
      {isLoading ? (
        <div className="flex flex-col gap-2">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-10" />)}</div>
      ) : !data?.data.length ? (
        <EmptyState compact icon={<Clock />} title="Henüz çalışma yok" />
      ) : (
        <div className="overflow-x-auto">
          <table className="tbl w-full text-[13px]">
            <thead><tr className="text-left text-ink-3 border-b border-line"><th className="py-2 pr-3 text-left">Konu</th><th className="py-2 pr-3 text-center">Durum</th><th className="py-2 pr-3 text-center">Zaman</th><th className="py-2 text-center">Sonuç</th></tr></thead>
            <tbody>
              {data.data.map((r) => (
                <tr key={r.id} className="border-b border-line last:border-0">
                  <td className="py-2 pr-3 text-ink-2 text-left">{r.subject_type ? `${r.subject_type} #${r.subject_id}` : '—'}</td>
                  <td className="py-2 pr-3 text-center"><Badge tone={RUN_STATUS_TONE[r.status]}>{RUN_STATUS_LABEL[r.status]}</Badge></td>
                  <td className="py-2 pr-3 tabular text-ink-2 text-center">{dateTime(r.run_at)}</td>
                  <td className="py-2 text-ink-3 max-w-[220px] truncate text-center" title={r.result ?? ''}>{r.result ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  )
}

function WiringPanel({ wiring, loading }: { wiring?: AutomationWiring; loading: boolean }) {
  const [showChains, setShowChains] = useState(false)

  return (
    <Panel
      className="mt-6"
      title="Olay haritası"
      description="Her tetikleyicinin hangi olaydan beslendiği ve son 24 saatte kaç kez tetiklendiği (kural kapalı olsa da sayılır)"
      actions={<Button size="sm" variant="ghost" onClick={() => setShowChains((v) => !v)}>{showChains ? 'Tetikleyicileri göster' : 'Sabit bağlantılar'}</Button>}
      flush
    >
      {loading ? (
        <div className="flex flex-col gap-2 p-4">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-10" />)}</div>
      ) : !wiring ? (
        <EmptyState compact icon={<Radio />} title="Olay haritası yüklenemedi" />
      ) : showChains ? (
        <ul className="divide-y divide-line">
          {wiring.chains.map((c) => (
            <li key={c.event} className="flex flex-col gap-1.5 px-4 py-3 sm:flex-row sm:items-start sm:gap-4">
              <span className="text-[13.5px] font-medium text-ink sm:w-64 sm:shrink-0">{c.event}</span>
              <span className="flex flex-wrap gap-1.5">{c.effects.map((e) => <Badge key={e} tone="neutral">{e}</Badge>)}</span>
            </li>
          ))}
          <li className="px-4 py-3">
            <p className="mb-2 text-[12px] text-ink-3">Webhook olayları · son 24 saat teslimat</p>
            <span className="flex flex-wrap gap-1.5">
              {wiring.webhook_events.map((w) => <Badge key={w.event} tone={w.deliveries_24h > 0 ? 'primary' : 'neutral'}>{w.event} · {w.deliveries_24h}</Badge>)}
            </span>
          </li>
        </ul>
      ) : (
        <div className="overflow-x-auto">
          <table className="tbl w-full text-[13px]">
            <thead>
              <tr className="border-b border-line text-left text-ink-3">
                <th className="px-4 py-2 font-medium text-left">Tetikleyici</th>
                <th className="fill px-4 py-2 font-medium text-center">Beslendiği olay</th>
                <th className="px-4 py-2 font-medium whitespace-nowrap text-center">Kural</th>
                <th className="px-4 py-2 font-medium whitespace-nowrap text-center">24 saat</th>
              </tr>
            </thead>
            <tbody>
              {wiring.data.map((w) => (
                <tr key={w.trigger} className="border-b border-line last:border-0 align-top">
                  <td className="px-4 py-2.5 min-w-[180px] text-left">
                    <p className="font-medium text-ink">{w.label}</p>
                    <p className="text-[12px] text-ink-3 font-mono">{w.trigger}</p>
                  </td>
                  <td className="fill px-4 py-2.5 min-w-[260px] text-ink-2 text-center">
                    <span className="mr-1.5 inline-flex align-middle text-ink-3 [&_svg]:size-3.5" title={w.kind === 'schedule' ? 'Zamanlanmış' : 'Anlık olay'}>
                      {w.kind === 'schedule' ? <CalendarClock /> : <Zap />}
                    </span>
                    {w.source ?? 'Kaynak tanımı yok'}
                  </td>
                  <td className="px-4 py-2.5 tabular whitespace-nowrap text-center">
                    {w.rules_total === 0 ? <span className="text-ink-3">—</span> : <Badge tone={w.rules_active > 0 ? 'success' : 'neutral'}>{w.rules_active}/{w.rules_total} açık</Badge>}
                  </td>
                  <td className="px-4 py-2.5 tabular whitespace-nowrap text-center">
                    <p className="text-ink">{w.fired_24h}</p>
                    {(w.runs_24h.done + w.runs_24h.failed + w.runs_24h.skipped + w.runs_24h.scheduled) > 0 && (
                      <p className="text-[12px] text-ink-3">
                        {w.runs_24h.done} çalıştı{w.runs_24h.scheduled ? ` · ${w.runs_24h.scheduled} planlı` : ''}{w.runs_24h.skipped ? ` · ${w.runs_24h.skipped} atlandı` : ''}{w.runs_24h.failed ? ` · ${w.runs_24h.failed} hata` : ''}
                      </p>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  )
}
