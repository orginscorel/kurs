import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { BookOpen, DoorOpen, GraduationCap, Plus, Search, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader, Tabs } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState, ProgressBar } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Switch, Textarea } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import { ColorChip, ColorPicker } from './ui'
import type { ClassroomRow, ProgramRow, SubjectRow } from './types'

type TabKey = 'programlar' | 'dersler' | 'derslikler'

export default function AcademicStructure() {
  const [params, setParams] = useSearchParams()
  const tab = (params.get('sekme') as TabKey) || 'programlar'
  const can = useCan()
  const [newOpen, setNewOpen] = useState(() => params.get('yeni') === '1' && can('academic.manage'))

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Programlar ve dersler"
        description="Eğitim programları, dersler ve kazanımlar, derslikler."
        actions={can('academic.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setNewOpen(true)}>{tab === 'programlar' ? 'Yeni program' : tab === 'dersler' ? 'Yeni ders' : 'Yeni derslik'}</Button>}
      />
      <Tabs value={tab} onChange={(t) => setParams({ sekme: t })} tabs={[{ value: 'programlar', label: 'Programlar' }, { value: 'dersler', label: 'Dersler ve konular' }, { value: 'derslikler', label: 'Derslikler' }]} className="mb-4" />
      {tab === 'programlar' && <ProgramsTab newOpen={newOpen} onCloseNew={() => setNewOpen(false)} onNew={can('academic.manage') ? () => setNewOpen(true) : undefined} />}
      {tab === 'dersler' && <SubjectsTab newOpen={newOpen} onCloseNew={() => setNewOpen(false)} onNew={can('academic.manage') ? () => setNewOpen(true) : undefined} />}
      {tab === 'derslikler' && <ClassroomsTab newOpen={newOpen} onCloseNew={() => setNewOpen(false)} onNew={can('academic.manage') ? () => setNewOpen(true) : undefined} />}
    </div>
  )
}

function useSearchBox(list: ReturnType<typeof useListState>) {
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])
  return { search, setSearch }
}

function SearchInput({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder: string }) {
  return <Input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} leading={<Search />} trailing={value ? <button onClick={() => onChange('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined} className="w-full sm:w-[240px]" />
}

// ------------------------------------------------------------------ Programlar
type ProgramsResponse = Paginated<ProgramRow> & { meta: { kinds: Record<string, string>; tracks: Record<string, string> } }

function ProgramsTab({ newOpen, onCloseNew, onNew }: { newOpen: boolean; onCloseNew: () => void; onNew?: () => void }) {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const list = useListState({ sort: 'name', filters: { status: 'active' } })
  const { search, setSearch } = useSearchBox(list)
  const { data, isLoading, isFetching } = useQuery({ queryKey: ['programs', 'list', list.query], queryFn: () => api.get<ProgramsResponse>('/programs', list.query), placeholderData: keepPreviousData })

  const columns = useMemo<Column<ProgramRow>[]>(
    () => [
      { key: 'name', header: 'Program', sortKey: 'name', cell: (p) => <div className="flex items-center gap-2.5 min-w-0"><ColorChip>{p.code}</ColorChip><span className="font-medium">{p.name}</span></div> },
      { key: 'kind', header: 'Tür', sortKey: 'kind', cell: (p) => <span className="text-ink-2">{p.kind_label}{p.track_label ? ` · ${p.track_label}` : ''}</span> },
      { key: 'subjects', header: 'Ders', align: 'center', cell: (p) => <span className="tabular">{p.subjects_count}</span> },
      { key: 'hours', header: 'Haftalık saat', align: 'center', cell: (p) => <span className="tabular">{p.weekly_hours}</span> },
      { key: 'groups', header: 'Sınıf', sortKey: 'class_groups_count', align: 'center', cell: (p) => <span className="tabular">{p.class_groups_count}</span> },
      { key: 'students', header: 'Öğrenci', sortKey: 'students_count', align: 'center', cell: (p) => <span className="tabular">{p.students_count}</span> },
      { key: 'active', header: 'Durum', cell: (p) => <Badge tone={p.is_active ? 'success' : 'neutral'} dot>{p.is_active ? 'Aktif' : 'Pasif'}</Badge> },
    ],
    [],
  )

  return (
    <>
      <DataTable
        storageKey="programs" columns={columns} rows={data?.data} rowKey={(r) => r.id} loading={isLoading || isFetching} meta={data?.meta} sort={list.sort}
        onSort={(sort) => list.update({ sort })} onPage={(page) => list.update({ page })} onRowClick={(r) => navigate(`/akademik/programlar/${r.id}`)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <SearchInput value={search} onChange={setSearch} placeholder="Program adı ya da kodu" />
            <Segmented size="sm" value={list.filters.status ?? 'active'} onChange={(status) => list.update({ filters: { status } })} options={[{ value: 'active', label: 'Aktif' }, { value: 'all', label: 'Tümü' }]} />
            <Select value={list.filters.kind ?? ''} onChange={(e) => list.update({ filters: { kind: e.target.value } })} placeholder="Tüm türler" options={Object.entries(data?.meta.kinds ?? {}).map(([value, label]) => ({ value, label }))} className="w-[150px]" />
          </div>
        }
        empty={<EmptyState icon={<GraduationCap />} title="Program yok" description="TYT, AYT, LGS gibi eğitim programlarını tanımlayın." action={onNew && <Button variant="primary" icon={<Plus className="size-4" />} onClick={onNew}>Yeni program</Button>} />}
      />
      <ProgramFormDrawer open={newOpen} onClose={onCloseNew} kinds={data?.meta.kinds} tracks={data?.meta.tracks} onSaved={(id) => { qc.invalidateQueries({ queryKey: ['programs'] }); qc.invalidateQueries({ queryKey: ['academic', 'options'] }); navigate(`/akademik/programlar/${id}`) }} />
    </>
  )
}

export function ProgramFormDrawer({ open, onClose, onSaved, program, kinds, tracks }: { open: boolean; onClose: () => void; onSaved: (id: number) => void; program?: ProgramRow | null; kinds?: Record<string, string>; tracks?: Record<string, string> }) {
  const editing = !!program
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  useEffect(() => {
    if (!open) return
    setErrors({})
    setForm(program ? { code: program.code, name: program.name, kind: program.kind, exam_track: program.exam_track ?? '', color: program.color, description: program.description ?? '', is_active: program.is_active } : { code: '', name: '', kind: 'group', exam_track: '', color: 'indigo', description: '', is_active: true })
  }, [open, program])
  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]
  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, exam_track: form.exam_track || null, description: form.description || null }
      return editing ? api.put<{ message: string }>(`/programs/${program!.id}`, payload).then((r) => ({ ...r, id: program!.id })) : api.post<{ message: string; id: number }>('/programs', payload)
    },
    onSuccess: (r) => { toast.success(r.message); onClose(); onSaved(r.id) },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })
  const kindOpts = Object.entries(kinds ?? { group: 'Grup dersi', private: 'Birebir', study: 'Etüt' }).map(([value, label]) => ({ value, label }))
  const trackOpts = Object.entries(tracks ?? { TYT: 'TYT', AYT_SAY: 'AYT Sayısal', AYT_EA: 'AYT Eşit Ağırlık', AYT_SOZ: 'AYT Sözel', AYT_DIL: 'AYT Dil', LGS: 'LGS', NONE: 'Sınav hedefi yok' }).map(([value, label]) => ({ value, label }))
  return (
    <Drawer open={open} onClose={onClose} title={editing ? 'Programı düzenle' : 'Yeni program'} description={editing ? program?.name : 'Örn. TYT Hazırlık, AYT Sayısal, LGS, Birebir Ders'} footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{editing ? 'Kaydet' : 'Oluştur'}</Button></>}>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Kod" required error={err('code')} hint="Büyük harf, rakam, tire"><Input value={form.code ?? ''} onChange={(e) => set('code', e.target.value.toLocaleUpperCase('tr-TR').replace(/[^A-Z0-9_\-]/g, ''))} placeholder="AYT_SAY" /></Field>
        <Field label="Ad" required error={err('name')}><Input value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} placeholder="AYT Sayısal" /></Field>
        <Field label="Tür" required error={err('kind')}><Select value={form.kind ?? 'group'} onChange={(e) => set('kind', e.target.value)} options={kindOpts} /></Field>
        <Field label="Sınav hedefi" optional error={err('exam_track')}><Select value={form.exam_track ?? ''} onChange={(e) => set('exam_track', e.target.value)} placeholder="Belirtilmedi" options={trackOpts} /></Field>
        <Field label="Renk" className="sm:col-span-2"><ColorPicker value={form.color ?? 'indigo'} onChange={(v) => set('color', v)} /></Field>
        <Field label="Açıklama" optional error={err('description')} className="sm:col-span-2"><Textarea rows={3} value={form.description ?? ''} onChange={(e) => set('description', e.target.value)} /></Field>
        {editing && <Switch checked={!!form.is_active} onChange={(v) => set('is_active', v)} label="Aktif program" />}
      </div>
    </Drawer>
  )
}

// ------------------------------------------------------------------ Dersler
function SubjectsTab({ newOpen, onCloseNew, onNew }: { newOpen: boolean; onCloseNew: () => void; onNew?: () => void }) {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const list = useListState({ sort: 'name', filters: { status: 'active' } })
  const { search, setSearch } = useSearchBox(list)
  const { data, isLoading, isFetching } = useQuery({ queryKey: ['subjects', 'list', list.query], queryFn: () => api.get<Paginated<SubjectRow>>('/subjects', list.query), placeholderData: keepPreviousData })
  const columns = useMemo<Column<SubjectRow>[]>(
    () => [
      { key: 'name', header: 'Ders', sortKey: 'name', cell: (s) => <div className="flex items-center gap-2.5 min-w-0"><ColorChip color={s.color}>{s.short_name ?? s.code}</ColorChip><span className="font-medium">{s.name}</span></div> },
      { key: 'topics', header: 'Konu / kazanım', sortKey: 'topics_count', align: 'center', cell: (s) => <span className="tabular">{s.topics_count}</span> },
      { key: 'teachers', header: 'Öğretmen', sortKey: 'teachers_count', align: 'center', cell: (s) => <span className="tabular">{s.teachers_count}</span> },
      { key: 'lessons', header: 'Haftalık ders', sortKey: 'weekly_lessons', align: 'center', cell: (s) => <span className="tabular">{s.weekly_lessons}</span> },
      { key: 'active', header: 'Durum', cell: (s) => <Badge tone={s.is_active ? 'success' : 'neutral'} dot>{s.is_active ? 'Aktif' : 'Pasif'}</Badge> },
    ],
    [],
  )
  return (
    <>
      <DataTable storageKey="subjects" columns={columns} rows={data?.data} rowKey={(r) => r.id} loading={isLoading || isFetching} meta={data?.meta} sort={list.sort} onSort={(sort) => list.update({ sort })} onPage={(page) => list.update({ page })} onRowClick={(r) => navigate(`/akademik/dersler/${r.id}`)}
        toolbar={<div className="flex w-full flex-wrap items-center gap-2"><SearchInput value={search} onChange={setSearch} placeholder="Ders adı ya da kodu" /><Segmented size="sm" value={list.filters.status ?? 'active'} onChange={(status) => list.update({ filters: { status } })} options={[{ value: 'active', label: 'Aktif' }, { value: 'all', label: 'Tümü' }]} /></div>}
        empty={<EmptyState icon={<BookOpen />} title="Ders yok" description="Matematik, Türkçe, Fizik gibi dersleri tanımlayın." action={onNew && <Button variant="primary" icon={<Plus className="size-4" />} onClick={onNew}>Yeni ders</Button>} />}
      />
      <SubjectFormDrawer open={newOpen} onClose={onCloseNew} onSaved={(id) => { qc.invalidateQueries({ queryKey: ['subjects'] }); qc.invalidateQueries({ queryKey: ['academic', 'options'] }); navigate(`/akademik/dersler/${id}`) }} />
    </>
  )
}

export function SubjectFormDrawer({ open, onClose, onSaved, subject }: { open: boolean; onClose: () => void; onSaved: (id: number) => void; subject?: { id: number; code: string; name: string; short_name: string | null; color: string; is_active: boolean } | null }) {
  const editing = !!subject
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  useEffect(() => {
    if (!open) return
    setErrors({})
    setForm(subject ? { code: subject.code, name: subject.name, short_name: subject.short_name ?? '', color: subject.color, is_active: subject.is_active } : { code: '', name: '', short_name: '', color: 'indigo', is_active: true })
  }, [open, subject])
  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]
  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, short_name: form.short_name || null }
      return editing ? api.put<{ message: string }>(`/subjects/${subject!.id}`, payload).then((r) => ({ ...r, id: subject!.id })) : api.post<{ message: string; id: number }>('/subjects', payload)
    },
    onSuccess: (r) => { toast.success(r.message); onClose(); onSaved(r.id) },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })
  return (
    <Drawer open={open} onClose={onClose} title={editing ? 'Dersi düzenle' : 'Yeni ders'} footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{editing ? 'Kaydet' : 'Oluştur'}</Button></>}>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Kod" required error={err('code')}><Input value={form.code ?? ''} onChange={(e) => set('code', e.target.value.toLocaleUpperCase('tr-TR').replace(/[^A-Z0-9_\-]/g, ''))} placeholder="MAT" /></Field>
        <Field label="Kısa ad" optional error={err('short_name')} hint="Ders programında görünür"><Input value={form.short_name ?? ''} onChange={(e) => set('short_name', e.target.value)} placeholder="Mat" maxLength={20} /></Field>
        <Field label="Ad" required error={err('name')} className="sm:col-span-2"><Input value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} placeholder="Matematik" /></Field>
        <Field label="Renk" className="sm:col-span-2"><ColorPicker value={form.color ?? 'indigo'} onChange={(v) => set('color', v)} /></Field>
        {editing && <Switch checked={!!form.is_active} onChange={(v) => set('is_active', v)} label="Aktif ders" />}
      </div>
    </Drawer>
  )
}

// ------------------------------------------------------------------ Derslikler
type ClassroomsResponse = Paginated<ClassroomRow> & { meta: { kinds: Record<string, string> } }

function ClassroomsTab({ newOpen, onCloseNew, onNew }: { newOpen: boolean; onCloseNew: () => void; onNew?: () => void }) {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const list = useListState({ sort: 'name', filters: { status: 'active' } })
  const { search, setSearch } = useSearchBox(list)
  const { data, isLoading, isFetching } = useQuery({ queryKey: ['classrooms', 'list', list.query], queryFn: () => api.get<ClassroomsResponse>('/classrooms', list.query), placeholderData: keepPreviousData })
  const columns = useMemo<Column<ClassroomRow>[]>(
    () => [
      { key: 'name', header: 'Derslik', sortKey: 'name', cell: (c) => <div className="min-w-0"><p className="font-medium">{c.name}</p><p className="text-[12px] text-ink-3">{c.kind_label}{c.floor ? ` · ${c.floor}` : ''}</p></div> },
      { key: 'capacity', header: 'Kapasite', sortKey: 'capacity', align: 'center', cell: (c) => <span className="tabular">{c.capacity}</span> },
      { key: 'occupancy', header: 'Haftalık doluluk', sortKey: 'weekly_minutes', width: 180, cell: (c) => <div><div className="flex justify-between text-[12px] tabular"><span>{Math.round(c.weekly_minutes / 60)} saat</span><span className="text-ink-3">%{c.occupancy}</span></div><ProgressBar value={c.occupancy} tone={c.occupancy >= 80 ? 'warning' : 'primary'} className="mt-1" /></div> },
      { key: 'today', header: 'Bugün', align: 'center', cell: (c) => <span className="tabular">{c.today_sessions} ders</span> },
      { key: 'now', header: 'Şu an', cell: (c) => (c.current_session ? <Badge tone="success" dot>{c.current_session}</Badge> : <span className="text-ink-3">Boş</span>) },
      { key: 'active', header: 'Durum', hideable: true, cell: (c) => <Badge tone={c.is_active ? 'success' : 'neutral'} dot>{c.is_active ? 'Aktif' : 'Pasif'}</Badge> },
    ],
    [],
  )
  return (
    <>
      <DataTable storageKey="classrooms" columns={columns} rows={data?.data} rowKey={(r) => r.id} loading={isLoading || isFetching} meta={data?.meta} sort={list.sort} onSort={(sort) => list.update({ sort })} onPage={(page) => list.update({ page })} onRowClick={(r) => navigate(`/akademik/derslikler/${r.id}`)}
        toolbar={<div className="flex w-full flex-wrap items-center gap-2"><SearchInput value={search} onChange={setSearch} placeholder="Derslik adı" /><Segmented size="sm" value={list.filters.status ?? 'active'} onChange={(status) => list.update({ filters: { status } })} options={[{ value: 'active', label: 'Aktif' }, { value: 'all', label: 'Tümü' }]} /><Select value={list.filters.kind ?? ''} onChange={(e) => list.update({ filters: { kind: e.target.value } })} placeholder="Tüm türler" options={Object.entries(data?.meta.kinds ?? {}).map(([value, label]) => ({ value, label }))} className="w-[150px]" /></div>}
        empty={<EmptyState icon={<DoorOpen />} title="Derslik yok" description="Derslik, etüt odası ve salonları tanımlayın." action={onNew && <Button variant="primary" icon={<Plus className="size-4" />} onClick={onNew}>Yeni derslik</Button>} />}
      />
      <ClassroomFormDrawer open={newOpen} onClose={onCloseNew} kinds={data?.meta.kinds} onSaved={(id) => { qc.invalidateQueries({ queryKey: ['classrooms'] }); qc.invalidateQueries({ queryKey: ['academic', 'options'] }); navigate(`/akademik/derslikler/${id}`) }} />
    </>
  )
}

export function ClassroomFormDrawer({ open, onClose, onSaved, classroom, kinds }: { open: boolean; onClose: () => void; onSaved: (id: number) => void; classroom?: { id: number; name: string; kind: string; capacity: number; floor: string | null; features: string | null; is_active: boolean } | null; kinds?: Record<string, string> }) {
  const editing = !!classroom
  const [form, setForm] = useState<Record<string, any>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  useEffect(() => {
    if (!open) return
    setErrors({})
    setForm(classroom ? { name: classroom.name, kind: classroom.kind, capacity: classroom.capacity, floor: classroom.floor ?? '', features: classroom.features ?? '', is_active: classroom.is_active } : { name: '', kind: 'classroom', capacity: 24, floor: '', features: '', is_active: true })
  }, [open, classroom])
  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k: string) => errors[k]?.[0]
  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, capacity: Number(form.capacity), floor: form.floor || null, features: form.features || null }
      return editing ? api.put<{ message: string }>(`/classrooms/${classroom!.id}`, payload).then((r) => ({ ...r, id: classroom!.id })) : api.post<{ message: string; id: number }>('/classrooms', payload)
    },
    onSuccess: (r) => { toast.success(r.message); onClose(); onSaved(r.id) },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })
  const kindOpts = Object.entries(kinds ?? { classroom: 'Derslik', study: 'Etüt odası', hall: 'Salon', lab: 'Laboratuvar' }).map(([value, label]) => ({ value, label }))
  return (
    <Drawer open={open} onClose={onClose} title={editing ? 'Dersliği düzenle' : 'Yeni derslik'} footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{editing ? 'Kaydet' : 'Oluştur'}</Button></>}>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Ad" required error={err('name')} className="sm:col-span-2"><Input value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} placeholder="Derslik A" autoFocus /></Field>
        <Field label="Tür" required error={err('kind')}><Select value={form.kind ?? 'classroom'} onChange={(e) => set('kind', e.target.value)} options={kindOpts} /></Field>
        <Field label="Kapasite" required error={err('capacity')}><Input type="number" min={1} max={1000} value={form.capacity ?? 24} onChange={(e) => set('capacity', e.target.value)} /></Field>
        <Field label="Kat" optional error={err('floor')}><Input value={form.floor ?? ''} onChange={(e) => set('floor', e.target.value)} placeholder="Zemin, 1. Kat" /></Field>
        <Field label="Donanım" optional error={err('features')} className="sm:col-span-2"><Input value={form.features ?? ''} onChange={(e) => set('features', e.target.value)} placeholder="Akıllı tahta, projeksiyon, klima" /></Field>
        {editing && <Switch checked={!!form.is_active} onChange={(v) => set('is_active', v)} label="Aktif derslik" />}
      </div>
    </Drawer>
  )
}
