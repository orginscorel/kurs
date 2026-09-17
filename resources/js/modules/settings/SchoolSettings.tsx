import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { MapPin, Pencil, Plus, School as SchoolIcon, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { PageHeader } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Checkbox, Field, Input, Select, Switch } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { DataTable, type Column } from '@/components/ui/DataTable'

export type SchoolRow = { id: number; name: string; kind: string; city: string | null; district: string | null; is_active: boolean; sort_order: number; students: number }
export type Region = { key: string; label: string; count: number }
type Data = { data: SchoolRow[]; kinds: Record<string, string>; regions: Region[] }

/** Ayarlar › Okullar: öğrenci ve ön kayıt formlarında önerilen okul listesi. */
export default function SchoolSettings() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['schools', 'admin'], queryFn: () => api.get<Data>('/schools') })
  const [q, setQ] = useState('')
  const [edit, setEdit] = useState<SchoolRow | 'new' | null>(null)
  const [regionsOpen, setRegionsOpen] = useState(false)
  const refresh = () => { qc.invalidateQueries({ queryKey: ['schools'] }) }

  const toggle = useMutation({
    mutationFn: (s: SchoolRow) => api.put(`/schools/${s.id}`, { name: s.name, kind: s.kind, city: s.city, district: s.district, is_active: !s.is_active }),
    onSuccess: refresh,
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Güncellenemedi.'),
  })
  const remove = useMutation({
    mutationFn: (s: SchoolRow) => api.delete<{ message: string }>(`/schools/${s.id}`),
    onSuccess: (r) => { toast.success(r.message); refresh() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
  })

  const rows = useMemo(() => {
    const t = q.trim().toLocaleLowerCase('tr')
    return (data?.data ?? []).filter((s) => !t || `${s.name} ${s.district ?? ''} ${s.city ?? ''}`.toLocaleLowerCase('tr').includes(t))
  }, [data, q])

  const columns: Column<SchoolRow>[] = [
    {
      key: 'name', header: 'Okul',
      cell: (s) => (
        <div className="min-w-0">
          <p className={s.is_active ? 'font-medium' : 'font-medium text-ink-3 line-through'}>{s.name}</p>
          <p className="text-[12.5px] text-ink-3">{data?.kinds[s.kind] ?? 'Diğer'}</p>
        </div>
      ),
    },
    { key: 'place', header: 'İl / ilçe', cell: (s) => <span className="text-ink-2">{[s.city, s.district].filter(Boolean).join(' / ') || '—'}</span> },
    { key: 'students', header: 'Öğrenci sayısı', align: 'right', cell: (s) => <span className="tabular">{s.students || '—'}</span> },
    { key: 'active', header: 'Formlarda önerilsin', cell: (s) => <Switch checked={s.is_active} onChange={() => toggle.mutate(s)} /> },
    {
      key: 'actions', header: '',
      cell: (s) => (
        <div className="flex justify-end gap-1">
          <Button size="icon-sm" variant="ghost" aria-label="Düzenle" onClick={() => setEdit(s)}><Pencil className="size-4" /></Button>
          <Button size="icon-sm" variant="ghost" aria-label="Listeden çıkar" onClick={() => { if (confirm(`${s.name} listeden çıkarılsın mı? Öğrenci kayıtları değişmez.`)) remove.mutate(s) }}><Trash2 className="size-4 text-danger" /></Button>
        </div>
      ),
    },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Okullar"
        description="Öğrenci ve ön kayıt formlarında önerilen okullar. Bölgenizin liselerini tek tıkla ekleyin; başka yerlerden gelen öğrenciler için okul ekleyip çıkarın."
        actions={
          <>
            <Button icon={<MapPin className="size-4" />} onClick={() => setRegionsOpen(true)}>Bölgeden ekle</Button>
            <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEdit('new')}>Okul ekle</Button>
          </>
        }
      />
      {isLoading ? <Skeleton className="h-80" /> : (
        <DataTable
          columns={columns}
          rows={rows}
          rowKey={(r) => r.id}
          toolbar={<Input className="w-full sm:w-72" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Okul ya da ilçe ara" />}
          empty={<EmptyState icon={<SchoolIcon />} title="Listede okul yok" description="Bölgenizin okullarını ekleyerek başlayın." action={<Button variant="primary" onClick={() => setRegionsOpen(true)}>Bölgeden ekle</Button>} />}
        />
      )}
      {edit && data && <SchoolModal school={edit === 'new' ? null : edit} kinds={data.kinds} onClose={() => setEdit(null)} onSaved={refresh} />}
      {regionsOpen && data && <RegionModal regions={data.regions} onClose={() => setRegionsOpen(false)} onSaved={refresh} />}
    </div>
  )
}

function SchoolModal({ school, kinds, onClose, onSaved }: { school: SchoolRow | null; kinds: Record<string, string>; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState({ name: school?.name ?? '', kind: school?.kind ?? 'anadolu', city: school?.city ?? '', district: school?.district ?? '', is_active: school?.is_active ?? true })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const save = useMutation({
    mutationFn: () => (school ? api.put(`/schools/${school.id}`, form) : api.post('/schools', form)),
    onSuccess: () => { toast.success(school ? 'Okul güncellendi.' : 'Okul eklendi.'); onSaved(); onClose() },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })
  return (
    <Modal open onClose={onClose} title={school ? 'Okulu düzenle' : 'Okul ekle'}
      footer={<div className="flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button></div>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Okul adı" required error={errors.name?.[0]} className="sm:col-span-2"><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Örn. Niksar Anadolu Lisesi" autoFocus /></Field>
        <Field label="Okul türü" optional><Select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })} options={Object.entries(kinds).map(([value, label]) => ({ value, label }))} /></Field>
        <Field label="İl" optional><Input value={form.city} onChange={(e) => setForm({ ...form, city: e.target.value })} placeholder="Tokat" /></Field>
        <Field label="İlçe" optional><Input value={form.district} onChange={(e) => setForm({ ...form, district: e.target.value })} placeholder="Niksar" /></Field>
        <div className="flex items-end pb-2"><Switch checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} label="Formlarda önerilsin" /></div>
      </div>
    </Modal>
  )
}

export function RegionPicker({ regions, value, onChange }: { regions: Region[]; value: string[]; onChange: (v: string[]) => void }) {
  return (
    <div className="flex flex-col gap-2">
      {regions.map((r) => (
        <label key={r.key} className="flex cursor-pointer items-center justify-between gap-3 rounded-[var(--radius-sm)] border border-line px-3 py-2.5 hover:border-line-strong">
          <Checkbox checked={value.includes(r.key)} onChange={(on) => onChange(on ? [...value, r.key] : value.filter((x) => x !== r.key))} label={<span className="font-medium">{r.label}</span>} />
          <Badge>{r.count} lise</Badge>
        </label>
      ))}
    </div>
  )
}

function RegionModal({ regions, onClose, onSaved }: { regions: Region[]; onClose: () => void; onSaved: () => void }) {
  const [picked, setPicked] = useState<string[]>([])
  const save = useMutation({
    mutationFn: () => api.post<{ message: string }>('/schools/import-regions', { regions: picked }),
    onSuccess: (r) => { toast.success(r.message); onSaved(); onClose() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Eklenemedi.'),
  })
  return (
    <Modal open onClose={onClose} title="Bölgeden okul ekle" description="Seçilen bölgelerin liseleri listeye eklenir; listede olanlar atlanır."
      footer={<div className="flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" disabled={!picked.length} loading={save.isPending} onClick={() => save.mutate()}>Ekle</Button></div>}>
      <RegionPicker regions={regions} value={picked} onChange={setPicked} />
      <p className="mt-3 text-[12.5px] text-ink-3">Listede olmayan bir bölge için "Okul ekle" ile tek tek ekleyebilirsiniz.</p>
    </Modal>
  )
}
