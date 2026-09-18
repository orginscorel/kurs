import { useRef, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Check, Copy, Fingerprint, Nfc, Plus, QrCode, ScanFace, ScanLine, Trash2, Upload, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { relative } from '@/lib/format'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader, Panel, Tabs } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/form'
import { ConfirmDialog, Drawer, Menu, Modal } from '@/components/ui/overlay'
import { StudentSearch, type StudentHit } from './LivePresence'
import ZkBridge from './ZkBridge'
import { DEVICE_KIND_LABEL, IDENTITY_KIND_LABEL, type DeviceKind, type DeviceRow, type IdentityRow } from './types'

const KIND_ICON: Record<DeviceKind, ReactNode> = {
  fingerprint: <Fingerprint className="size-4" />,
  rfid: <Nfc className="size-4" />,
  qr: <QrCode className="size-4" />,
  face: <ScanFace className="size-4" />,
  gateway: <ScanLine className="size-4" />,
}

const emptyForm = { id: 0, name: '', kind: 'fingerprint' as DeviceKind, location: '', direction: 'both' as 'entry' | 'exit' | 'both', serial_no: '', is_active: true }

function DevicesTab() {
  const qc = useQueryClient()
  const [form, setForm] = useState<typeof emptyForm | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<DeviceRow | null>(null)
  const [tokenValue, setTokenValue] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  const { data, isLoading } = useQuery({ queryKey: ['attendance', 'devices'], queryFn: () => api.get<{ data: DeviceRow[] }>('/attendance/devices'), refetchInterval: 20_000 })

  const saveMutation = useMutation({
    mutationFn: () => (form!.id ? api.put(`/attendance/devices/${form!.id}`, form) : api.post<{ id: number }>('/attendance/devices', form)),
    onSuccess: () => { toast.success('Kaydedildi.'); setForm(null); qc.invalidateQueries({ queryKey: ['attendance', 'devices'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const deleteMutation = useMutation({
    mutationFn: () => api.delete(`/attendance/devices/${deleteTarget!.id}`),
    onSuccess: () => { toast.success('Cihaz silindi.'); setDeleteTarget(null); qc.invalidateQueries({ queryKey: ['attendance', 'devices'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
  })

  const tokenMutation = useMutation({
    mutationFn: (device: DeviceRow) => api.post<{ token: string }>(`/attendance/devices/${device.id}/token`),
    onSuccess: (res) => { setTokenValue(res.token); qc.invalidateQueries({ queryKey: ['attendance', 'devices'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Jeton üretilemedi.'),
  })

  return (
    <div>
      <div className="flex justify-end mb-3">
        <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setForm(emptyForm)}>Yeni cihaz</Button>
      </div>

      {isLoading ? (
        <div className="space-y-2">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-20 rounded-[var(--radius-lg)]" />)}</div>
      ) : !data?.data.length ? (
        <EmptyState icon={<ScanLine />} title="Henüz cihaz eklenmedi" description="Parmak izi, RFID, QR ya da yüz tanıma terminali ekleyin." action={<Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setForm(emptyForm)}>Yeni cihaz</Button>} />
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          {data.data.map((d) => (
            <Panel key={d.id} className={!d.is_active ? 'opacity-60' : undefined}>
              <div className="flex items-start justify-between gap-2">
                <div className="flex items-center gap-2.5 min-w-0">
                  <div className="grid size-9 shrink-0 place-items-center rounded-[var(--radius-sm)] bg-surface-2 text-ink-2">{KIND_ICON[d.kind]}</div>
                  <div className="min-w-0">
                    <p className="truncate text-[14px] font-semibold">{d.name}</p>
                    <p className="text-[12px] text-ink-3">{DEVICE_KIND_LABEL[d.kind]} · {d.location ?? 'konum belirtilmemiş'}</p>
                  </div>
                </div>
                <Menu
                  trigger={<Button size="icon-sm" variant="ghost">⋯</Button>}
                  items={[
                    { label: 'Düzenle', onClick: () => setForm({ id: d.id, name: d.name, kind: d.kind, location: d.location ?? '', direction: d.direction, serial_no: d.serial_no ?? '', is_active: d.is_active }) },
                    { label: 'Jeton üret/yenile', icon: <ScanLine className="size-4" />, onClick: () => tokenMutation.mutate(d) },
                    'divider',
                    { label: 'Sil', icon: <Trash2 className="size-4" />, danger: true, onClick: () => setDeleteTarget(d) },
                  ]}
                />
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-1.5">
                {d.is_online ? <Badge tone="success" dot>Çevrim içi</Badge> : <Badge tone="neutral" dot>Çevrim dışı</Badge>}
                <Badge tone="neutral">{d.direction === 'both' ? 'Giriş + Çıkış' : d.direction === 'entry' ? 'Yalnız giriş' : 'Yalnız çıkış'}</Badge>
                {!d.is_active && <Badge tone="warning">Pasif</Badge>}
              </div>
              <p className="mt-2.5 text-[12px] text-ink-3">
                {d.last_seen_at ? `Son görülme ${relative(d.last_seen_at)}` : 'Hiç bağlanmadı'} · {d.event_count.toLocaleString('tr-TR')} olay
                {d.last_event_at && ` · son olay ${relative(d.last_event_at)}`}
              </p>
              {d.has_token && <p className="mt-1 text-[12px] font-mono text-ink-3">{d.api_token_prefix}…</p>}
            </Panel>
          ))}
        </div>
      )}

      <Drawer open={form !== null} onClose={() => setForm(null)} title={form?.id ? 'Cihazı düzenle' : 'Yeni cihaz'} footer={
        <>
          <Button variant="ghost" onClick={() => setForm(null)}>Vazgeç</Button>
          <Button variant="primary" disabled={!form?.name} loading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>Kaydet</Button>
        </>
      }>
        {form && (
          <div className="space-y-3.5">
            <Field label="Ad" required><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Ana Giriş Parmak İzi" /></Field>
            <Field label="Tür" required>
              <Select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value as DeviceKind })} options={Object.entries(DEVICE_KIND_LABEL).map(([value, label]) => ({ value, label }))} />
            </Field>
            <Field label="Konum" optional><Input value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} placeholder="Ana giriş" /></Field>
            <Field label="Yön" required>
              <Select value={form.direction} onChange={(e) => setForm({ ...form, direction: e.target.value as typeof form.direction })} options={[{ value: 'both', label: 'Giriş + Çıkış' }, { value: 'entry', label: 'Yalnız giriş' }, { value: 'exit', label: 'Yalnız çıkış' }]} />
            </Field>
            <Field label="Seri numarası" optional><Input value={form.serial_no} onChange={(e) => setForm({ ...form, serial_no: e.target.value })} /></Field>
            {form.id > 0 && (
              <Field label="Durum">
                <Select value={form.is_active ? '1' : '0'} onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })} options={[{ value: '1', label: 'Aktif' }, { value: '0', label: 'Pasif' }]} />
              </Field>
            )}
          </div>
        )}
      </Drawer>

      <ConfirmDialog open={deleteTarget !== null} onClose={() => setDeleteTarget(null)} onConfirm={() => deleteMutation.mutate()} title="Cihazı sil" danger loading={deleteMutation.isPending} description={`"${deleteTarget?.name}" cihazını silmek istediğinize emin misiniz?`}>
        Bu cihaza ait geçmiş olaylar saklanır; yalnız cihaz kaydı silinir.
      </ConfirmDialog>

      <Modal open={tokenValue !== null} onClose={() => { setTokenValue(null); setCopied(false) }} title="Yeni erişim jetonu" description="Bu değeri şimdi kopyalayın; bir daha gösterilmeyecek.">
        <div className="flex items-center gap-2 rounded-[var(--radius-md)] bg-surface-2 px-3 py-2.5">
          <code className="flex-1 min-w-0 truncate text-[12.5px] font-mono">{tokenValue}</code>
          <Button
            size="sm"
            variant={copied ? 'soft' : 'secondary'}
            icon={copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
            onClick={() => { navigator.clipboard?.writeText(tokenValue ?? ''); setCopied(true) }}
          >
            {copied ? 'Kopyalandı' : 'Kopyala'}
          </Button>
        </div>
        <p className="mt-3 text-[12.5px] text-ink-3">Bu jetonu köprü (gateway) yapılandırma dosyasına (config.json) yazın. Eski jeton artık geçersizdir.</p>
      </Modal>
    </div>
  )
}

function IdentitiesTab() {
  const qc = useQueryClient()
  const list = useListState({})
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [addOpen, setAddOpen] = useState(false)
  const [addStudent, setAddStudent] = useState<StudentHit | null>(null)
  const [addKind, setAddKind] = useState<IdentityRow['kind']>('fingerprint')
  const [addIdentifier, setAddIdentifier] = useState('')
  const [importResult, setImportResult] = useState<{ imported: number; skipped: number; errors: string[] } | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['attendance', 'identities', { ...list.query, q: debounced }],
    queryFn: () => api.get<Paginated<IdentityRow>>('/attendance/identities', { ...list.query, q: debounced }),
    placeholderData: keepPreviousData,
  })

  const addMutation = useMutation({
    mutationFn: () => api.post('/attendance/identities', { student_id: addStudent!.id, kind: addKind, identifier: addIdentifier }),
    onSuccess: () => { toast.success('Kimlik eşlemesi kaydedildi.'); setAddOpen(false); setAddStudent(null); setAddIdentifier(''); qc.invalidateQueries({ queryKey: ['attendance', 'identities'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/attendance/identities/${id}`),
    onSuccess: () => { toast.success('Silindi.'); qc.invalidateQueries({ queryKey: ['attendance', 'identities'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
  })

  const importMutation = useMutation({
    mutationFn: (file: File) => { const fd = new FormData(); fd.append('file', file); return api.post<{ imported: number; skipped: number; errors: string[] }>('/attendance/identities/import', fd) },
    onSuccess: (res) => { setImportResult(res); qc.invalidateQueries({ queryKey: ['attendance', 'identities'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İçe aktarılamadı.'),
  })

  const columns: Column<IdentityRow>[] = [
    { key: 'student', header: 'Öğrenci', cell: (r) => <span><span className="font-medium">{r.full_name}</span> <span className="text-ink-3 text-[12px] tabular">· Öğrenci no: {r.student_no}</span></span> },
    { key: 'kind', header: 'Tür', cell: (r) => <Badge tone="neutral">{IDENTITY_KIND_LABEL[r.kind]}</Badge> },
    { key: 'identifier', header: 'Tanımlayıcı', cell: (r) => <code className="text-[12px]">{r.identifier}</code> },
    { key: 'status', header: 'Durum', cell: (r) => (r.is_active ? <Badge tone="success">Aktif</Badge> : <Badge tone="neutral">Pasif</Badge>) },
    { key: 'actions', header: '', align: 'right', cell: (r) => <Button size="icon-sm" variant="ghost" onClick={() => deleteMutation.mutate(r.id)} aria-label="Sil"><Trash2 className="size-3.5" /></Button> },
  ]

  return (
    <div>
      <DataTable
        storageKey="device-identities"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        toolbar={
          <div className="flex flex-1 flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => { setSearch(e.target.value); list.update({ q: e.target.value }) }} placeholder="Öğrenci adı, no ya da tanımlayıcı" className="w-[260px]" />
            <div className="ml-auto flex items-center gap-1.5">
              <input ref={fileRef} type="file" accept=".csv,text/csv" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) importMutation.mutate(f); e.target.value = '' }} />
              <Button size="sm" icon={<Upload className="size-3.5" />} loading={importMutation.isPending} onClick={() => fileRef.current?.click()}>CSV içe aktar</Button>
              <Button size="sm" variant="primary" icon={<Plus className="size-3.5" />} onClick={() => setAddOpen(true)}>Ekle</Button>
            </div>
          </div>
        }
        empty={<EmptyState icon={<Fingerprint />} title="Henüz eşleme yok" description="Öğrenciyi cihaz kullanıcı numarası ya da kart UID'siyle eşleyin." />}
      />

      <Modal open={addOpen} onClose={() => setAddOpen(false)} title="Kimlik eşlemesi ekle" footer={
        <>
          <Button variant="ghost" onClick={() => setAddOpen(false)}>Vazgeç</Button>
          <Button variant="primary" disabled={!addStudent || !addIdentifier} loading={addMutation.isPending} onClick={() => addMutation.mutate()}>Kaydet</Button>
        </>
      }>
        <div className="space-y-3">
          {addStudent ? (
            <div className="flex items-center gap-3 rounded-[var(--radius-md)] ring-1 ring-line px-3 py-2.5">
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[13.5px] font-medium">{addStudent.full_name}</span>
                <span className="block text-[12px] text-ink-3 tabular">Öğrenci no: {addStudent.student_no}</span>
              </span>
              <Button size="icon-sm" variant="ghost" onClick={() => setAddStudent(null)} aria-label="Değiştir"><X className="size-4" /></Button>
            </div>
          ) : (
            <StudentSearch onPick={setAddStudent} />
          )}
          <Field label="Tür" required>
            <Select value={addKind} onChange={(e) => setAddKind(e.target.value as IdentityRow['kind'])} options={Object.entries(IDENTITY_KIND_LABEL).map(([value, label]) => ({ value, label }))} />
          </Field>
          <Field label="Tanımlayıcı (cihaz kullanıcı no / kart UID)" required>
            <Input value={addIdentifier} onChange={(e) => setAddIdentifier(e.target.value)} placeholder="ör. 1042" />
          </Field>
        </div>
      </Modal>

      <Modal open={importResult !== null} onClose={() => setImportResult(null)} title="CSV içe aktarma sonucu">
        {importResult && (
          <div className="space-y-2">
            <p className="text-[13.5px]"><strong>{importResult.imported}</strong> eşleme içe aktarıldı, <strong>{importResult.skipped}</strong> satır atlandı.</p>
            {importResult.errors.length > 0 && (
              <ul className="list-disc pl-5 text-[12.5px] text-danger space-y-0.5 max-h-48 overflow-y-auto scroll-thin">
                {importResult.errors.map((e, i) => <li key={i}>{e}</li>)}
              </ul>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}

export default function Devices() {
  const [tab, setTab] = useState<'devices' | 'identities' | 'bridge'>('devices')

  return (
    <div className="animate-fade-in">
      <PageHeader title="Yoklama terminalleri" description="Parmak izi, RFID, QR terminalleri ve öğrenci kimlik eşlemeleri" />
      <Tabs
        className="mb-4"
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'devices', label: 'Cihazlar' },
          { value: 'identities', label: 'Kimlik Eşlemeleri' },
          { value: 'bridge', label: 'Terminal Köprüsü' },
        ]}
      />
      {tab === 'devices' ? <DevicesTab /> : tab === 'identities' ? <IdentitiesTab /> : <ZkBridge />}
    </div>
  )
}
