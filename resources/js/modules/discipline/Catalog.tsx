import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Lock, Pencil, Plus, Search } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Tabs } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Switch, Textarea } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import type { Behavior, DisciplineSettings, SanctionType } from './types'
import { SEVERITY_TONE, toneOf, useDisciplineOptions } from './ui'

type Tab = 'behaviors' | 'sanctions' | 'settings'

export default function Catalog() {
  const can = useCan()
  const manage = can('discipline.settings')
  const { data: opt, isLoading } = useDisciplineOptions(manage)
  const [tab, setTab] = useState<Tab>('behaviors')
  const [q, setQ] = useState('')
  const [kind, setKind] = useState<'negative' | 'positive'>('negative')
  const [editB, setEditB] = useState<Behavior | 'new' | null>(null)
  const [editT, setEditT] = useState<SanctionType | null>(null)

  const behaviors = useMemo(() => {
    const s = q.trim().toLocaleLowerCase('tr')
    return (opt?.behaviors ?? []).filter((b) => b.kind === kind && (!s || b.name.toLocaleLowerCase('tr').includes(s) || b.category_label.toLocaleLowerCase('tr').includes(s)))
  }, [opt, q, kind])

  return (
    <div className="animate-fade-in">
      <PageHeader title="Katalog ve ayarlar" description="Davranışlar, puanlar, yaptırım kademeleri, eşikler ve portal görünürlüğü."
        actions={manage && tab === 'behaviors' && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEditB('new')}>Davranış ekle</Button>} />
      {!manage && <Alert tone="info" className="mb-4" icon={<Lock />}>Katalog salt okunur. Değiştirmek için "Davranış kataloğu ve disiplin ayarları" yetkisi gerekir.</Alert>}
      <Tabs value={tab} onChange={setTab} className="mb-4" tabs={[
        { value: 'behaviors', label: 'Davranışlar', count: opt?.behaviors.length ?? null },
        { value: 'sanctions', label: 'Yaptırım kademeleri', count: opt?.sanction_types.length ?? null },
        { value: 'settings', label: 'Ayarlar', hidden: !manage },
      ]} />

      {isLoading ? <Skeleton className="h-64" /> : tab === 'behaviors' ? (
        <Panel flush>
          <div className="flex flex-wrap items-center gap-2 p-3">
            <Segmented value={kind} onChange={setKind} options={[{ value: 'negative', label: 'Olumsuz' }, { value: 'positive', label: 'Olumlu' }]} />
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Davranış ara" leading={<Search />} className="w-full sm:w-64" aria-label="Davranış ara" />
          </div>
          {behaviors.length === 0 ? <EmptyState compact title="Davranış yok" /> : (
            <div className="overflow-x-auto scroll-thin border-t border-line">
              <table className="tbl w-full min-w-[640px] text-[13px]">
                <thead className="text-left text-[12px] text-ink-3">
                  <tr><th className="text-left">Davranış</th><th className="text-center">Kategori</th>{kind === 'negative' && <th className="text-center">Ciddiyet</th>}<th className="text-center">Puan</th>{kind === 'negative' && <th className="text-center">Önerilen yaptırım</th>}<th className="text-center">Durum</th><th className="text-center" /></tr>
                </thead>
                <tbody>
                  {behaviors.map((b) => (
                    <tr key={b.id} className={cn(!b.is_active && 'opacity-55')}>
                      <td className="text-left"><p className="font-medium text-ink">{b.name}</p>{b.description && <p className="text-[12px] text-ink-3">{b.description}</p>}</td>
                      <td className="text-ink-2 text-center">{b.category_label}</td>
                      {kind === 'negative' && <td className="text-center"><Badge tone={SEVERITY_TONE[b.severity]}>{opt?.severities[b.severity]}</Badge></td>}
                      <td className={cn('font-medium tabular text-center', kind === 'negative' ? 'text-danger' : 'text-success')}>{kind === 'negative' ? '−' : '+'}{b.points}</td>
                      {kind === 'negative' && <td className="text-ink-2 text-center">{opt?.sanction_types.find((t) => t.code === b.suggested_sanction)?.name ?? '—'}</td>}
                      <td className="text-center">{b.is_active ? <Badge tone="success">Aktif</Badge> : <Badge>Pasif</Badge>}</td>
                      <td className="text-right">{manage && <Button size="icon-sm" variant="ghost" aria-label="Düzenle" onClick={() => setEditB(b)}><Pencil className="size-3.5" /></Button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      ) : tab === 'sanctions' ? (
        <Panel flush title="Kademeler" description="Kademe sırası sabittir. Kurul yetkisindeki yaptırımlar öneri olarak başlar, kurul kabulüyle yürürlüğe girer.">
          <ul className="divide-y divide-line border-t border-line">
            {(opt?.sanction_types ?? []).map((t) => (
              <li key={t.id} className={cn('flex flex-wrap items-center gap-3 px-4 py-3', !t.is_active && 'opacity-55')}>
                <span className="grid size-8 shrink-0 place-items-center rounded-full bg-surface-2 text-[13px] font-semibold tabular ring-1 ring-line">{t.level}</span>
                <span className="min-w-0 flex-1">
                  <span className="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-ink">
                    {t.name}
                    <Badge tone={t.authority === 'board' ? 'warning' : 'info'}>{t.authority === 'board' ? 'Kurul kararı' : 'Yetkili verir'}</Badge>
                    {t.is_suspension && <Badge tone="danger">Uzaklaştırma · yoklamaya işlenir</Badge>}
                    {t.has_duty && <Badge tone={toneOf(t.tone)}>Görevli</Badge>}
                  </span>
                  <span className="block text-[12.5px] text-ink-3">{t.description ?? ''}{t.expires_after_days ? ` · ${t.expires_after_days} gün sonra düşer` : ' · süresiz'}</span>
                </span>
                {manage && <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => setEditT(t)}>Düzenle</Button>}
              </li>
            ))}
          </ul>
        </Panel>
      ) : opt ? <SettingsForm initial={opt.settings} /> : null}

      <BehaviorDrawer value={editB} onClose={() => setEditB(null)} defaultKind={kind} />
      <TypeDrawer value={editT} onClose={() => setEditT(null)} />
    </div>
  )
}

function useSave(onDone: () => void) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (fn: () => Promise<{ message: string }>) => fn(),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['discipline'] }); onDone() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })
}

function BehaviorDrawer({ value, onClose, defaultKind }: { value: Behavior | 'new' | null; onClose: () => void; defaultKind: 'negative' | 'positive' }) {
  const { data: opt } = useDisciplineOptions(true)
  const [f, setF] = useState<Record<string, any>>({})
  const save = useSave(onClose)
  useEffect(() => {
    if (!value) return
    setF(value === 'new'
      ? { name: '', kind: defaultKind, category: defaultKind === 'positive' ? 'appreciation' : 'class_order', points: defaultKind === 'positive' ? 2 : 2, severity: 'low', suggested_sanction: '', description: '', is_active: true }
      : { ...value, suggested_sanction: value.suggested_sanction ?? '', description: value.description ?? '' })
  }, [value, defaultKind])
  const positive = f.kind === 'positive'
  const cats = Object.entries(opt?.categories ?? {}).filter(([k]) => (opt?.positive_categories ?? []).includes(k) === positive)
  const body = { ...f, suggested_sanction: f.suggested_sanction || null, points: Number(f.points) }
  return (
    <Drawer open={!!value} onClose={onClose} title={value === 'new' ? 'Davranış ekle' : 'Davranışı düzenle'} width={500}
      footer={
        <>
          {value && value !== 'new' && <Button variant="danger-soft" className="mr-auto" onClick={() => save.mutate(() => api.delete(`/discipline/behaviors/${value.id}`))}>Sil / pasife al</Button>}
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} disabled={!f.name} onClick={() => save.mutate(() => (value === 'new' ? api.post('/discipline/behaviors', body) : api.put(`/discipline/behaviors/${(value as Behavior).id}`, body)))}>Kaydet</Button>
        </>
      }>
      <div className="flex flex-col gap-4">
        <Field label="Davranış türü" required><Segmented value={f.kind ?? 'negative'} onChange={(v) => setF({ ...f, kind: v, category: v === 'positive' ? 'appreciation' : 'class_order' })} options={[{ value: 'negative', label: 'Olumsuz' }, { value: 'positive', label: 'Olumlu' }]} /></Field>
        <Field label="Ad" required><Input value={f.name ?? ''} onChange={(e) => setF({ ...f, name: e.target.value })} maxLength={120} /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Kategori" required><Select value={f.category ?? ''} onChange={(e) => setF({ ...f, category: e.target.value })} options={cats.map(([v, l]) => ({ value: v, label: l }))} /></Field>
          <Field label={positive ? 'Ödül puanı' : 'Ceza puanı'} required hint="0–50"><Input type="number" min={0} max={50} value={f.points ?? 0} onChange={(e) => setF({ ...f, points: e.target.value })} /></Field>
        </div>
        {!positive && (
          <div className="grid grid-cols-2 gap-3">
            <Field label="Ciddiyet"><Select value={f.severity ?? 'low'} onChange={(e) => setF({ ...f, severity: e.target.value })} options={Object.entries(opt?.severities ?? {}).map(([v, l]) => ({ value: v, label: l }))} /></Field>
            <Field label="Önerilen yaptırım" optional><Select value={f.suggested_sanction ?? ''} placeholder="Yok" onChange={(e) => setF({ ...f, suggested_sanction: e.target.value })} options={(opt?.sanction_types ?? []).map((t) => ({ value: t.code, label: t.name }))} /></Field>
          </div>
        )}
        <Field label="Açıklama" optional><Textarea rows={2} value={f.description ?? ''} onChange={(e) => setF({ ...f, description: e.target.value })} maxLength={500} /></Field>
        <Switch checked={!!f.is_active} onChange={(v) => setF({ ...f, is_active: v })} label="Aktif (kayıt ekranında görünür)" />
        {value !== 'new' && <p className="text-[12px] text-ink-3">Puan değişikliği geçmiş kayıtları etkilemez; yalnız yeni kayıtlara uygulanır.</p>}
      </div>
    </Drawer>
  )
}

function TypeDrawer({ value, onClose }: { value: SanctionType | null; onClose: () => void }) {
  const [f, setF] = useState<Record<string, any>>({})
  const save = useSave(onClose)
  useEffect(() => { if (value) setF({ name: value.name, authority: value.authority, expires_after_days: value.expires_after_days ?? '', description: value.description ?? '', is_active: value.is_active }) }, [value])
  return (
    <Drawer open={!!value} onClose={onClose} title="Yaptırım kademesi" description={value ? `${value.level}. kademe` : undefined} width={460}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate(() => api.put(`/discipline/sanction-types/${value!.id}`, { ...f, expires_after_days: f.expires_after_days === '' ? null : Number(f.expires_after_days) }))}>Kaydet</Button></>}>
      <div className="flex flex-col gap-4">
        <Field label="Ad" required><Input value={f.name ?? ''} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="Kim verir?" required hint={value?.is_suspension ? 'Uzaklaştırma yalnız kurul kararıyla verilir.' : undefined}>
          <Segmented value={f.authority ?? 'staff'} onChange={(v) => setF({ ...f, authority: v })}
            options={[{ value: 'staff', label: 'Yetkili tek başına' }, { value: 'board', label: 'Disiplin kurulu' }]} />
        </Field>
        <Field label="Düşme süresi (gün)" optional hint="Boş = süresiz. Süre dolunca yaptırım “düştü” olur ve puan göstergesinde yürürlükte sayılmaz.">
          <Input type="number" min={1} max={1095} value={f.expires_after_days ?? ''} onChange={(e) => setF({ ...f, expires_after_days: e.target.value })} />
        </Field>
        <Field label="Açıklama" optional><Textarea rows={2} value={f.description ?? ''} onChange={(e) => setF({ ...f, description: e.target.value })} /></Field>
        <Switch checked={!!f.is_active} onChange={(v) => setF({ ...f, is_active: v })} label="Aktif" />
      </div>
    </Drawer>
  )
}

function SettingsForm({ initial }: { initial: DisciplineSettings }) {
  const [f, setF] = useState<DisciplineSettings>(initial)
  const save = useSave(() => {})
  useEffect(() => setF(initial), [initial])
  const sw = (k: keyof DisciplineSettings, label: string, hint?: string) => (
    <div className="flex items-start justify-between gap-4 py-2.5">
      <div><p className="text-[13.5px] text-ink">{label}</p>{hint && <p className="text-[12px] text-ink-3">{hint}</p>}</div>
      <Switch checked={!!f[k]} onChange={(v) => setF({ ...f, [k]: v })} />
    </div>
  )
  const n = (k: keyof DisciplineSettings, label: string) => (
    <Field label={label}><Input type="number" min={1} max={500} value={Number(f[k])} onChange={(e) => setF({ ...f, [k]: Number(e.target.value) })} /></Field>
  )
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Panel title="Portal görünürlüğü">
        <div className="divide-y divide-line">
          {sw('portal_enabled', 'Veli ve öğrenci portalında disiplin bölümü', 'Yalnız sonuçlanmış ve "portalda göster" işaretli yaptırımlar görünür; olay ayrıntısı ve puan görünmez.')}
          {sw('portal_defense_requests', 'Savunma istemleri portalda görünsün')}
          {sw('portal_defense_submission', 'Öğrenci savunmasını portaldan yazabilsin', 'Yalnız öğrenci hesabı yazar; veli yalnız görür.')}
          {sw('teacher_portal_reporting', 'Öğretmenler portaldan olay bildirebilsin')}
        </div>
      </Panel>
      <Panel title="Puan ve eşikler" description="Dönem net puanı = ceza puanı − olumlu puan (açıksa).">
        <div className="grid grid-cols-3 gap-3">
          {n('threshold_watch', 'Dikkat')}
          {n('threshold_warning', 'Uyarı')}
          {n('threshold_critical', 'Kritik')}
        </div>
        <div className="mt-2 divide-y divide-line">
          {sw('merit_offsets_penalty', 'Olumlu puan ceza puanından düşülsün')}
        </div>
        <p className="mt-2 text-[12px] text-ink-3">Uyarı ve üstü öğrenciler panoda "Dikkat gerektirenler"de görünür; risk puanına en fazla 10 puan ekler (kritik eşikte tam).</p>
      </Panel>
      <Panel title="Süreç">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Varsayılan savunma süresi (gün)"><Input type="number" min={1} max={30} value={f.default_defense_days} onChange={(e) => setF({ ...f, default_defense_days: Number(e.target.value) })} /></Field>
          <Field label="Uzaklaştırma günlerinde otomatik yoklama" hint="Not olarak “Disiplin: geçici uzaklaştırma” yazılır; veliye “gelmedi” bildirimi üretilmez.">
            <Select value={f.suspension_attendance_status} onChange={(e) => setF({ ...f, suspension_attendance_status: e.target.value as 'excused' | 'absent' })}
              options={[{ value: 'excused', label: 'İzinli say' }, { value: 'absent', label: 'Gelmedi say' }]} />
          </Field>
        </div>
      </Panel>
      <div className="flex items-end justify-end lg:col-span-2">
        <Button variant="primary" loading={save.isPending} onClick={() => save.mutate(() => api.put('/discipline/settings', f))}>Ayarları kaydet</Button>
      </div>
    </div>
  )
}
