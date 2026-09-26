import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarRange, GraduationCap, LayoutGrid, Pencil, Plus, Rows3, Star, Trash2, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { todayISO } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Tabs } from '@/components/ui/layout'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import { Field, Input, Select } from '@/components/ui/form'

/**
 * Akademik kurulum: sınıf açmak için gereken her şey TEK yerde, sekmeli ve basit.
 * Program → Sınıf (şube). Her öğe eklenebilir, DÜZENLENEBİLİR ve (onaylı) SİLİNEBİLİR.
 */
type Prog = { id: number; code?: string; name: string; kind: string; is_active: boolean }
type Term = { id: number; name: string; is_current: boolean; starts_on?: string | null; ends_on?: string | null }
type Group = { id: number; name: string; program_id: number | null; academic_term_id?: number | null; capacity: number; is_active: boolean }
type Opt = { programs: Prog[]; terms: Term[]; class_groups: Group[]; sections?: string[] }
type Tab = 'programs' | 'sections' | 'classes' | 'terms'
const KIND_LABEL: Record<string, string> = { group: 'Grup dersi', private: 'Birebir', study: 'Etüt' }

function toCode(name: string): string {
  const tr: Record<string, string> = { ç: 'C', Ç: 'C', ğ: 'G', Ğ: 'G', ı: 'I', İ: 'I', ö: 'O', Ö: 'O', ş: 'S', Ş: 'S', ü: 'U', Ü: 'U' }
  return name.replace(/[çÇğĞıİöÖşŞüÜ]/g, (c) => tr[c] ?? c).toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 20) || 'PRG'
}

export default function AcademicSetup() {
  const can = useCan()
  const qc = useQueryClient()
  const manage = can('academic.manage')
  const [tab, setTab] = useState<Tab>('programs')
  const { data, isLoading } = useQuery({ queryKey: ['academic', 'options'], queryFn: () => api.get<Opt>('/academic/options') })
  const refresh = () => qc.invalidateQueries({ queryKey: ['academic', 'options'] })
  const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')

  if (!can('academic.view')) return <EmptyState icon={<LayoutGrid />} title="Bu sayfa için yetkiniz yok" />

  const programs = data?.programs.filter((p) => p.is_active) ?? []
  const groups = data?.class_groups.filter((c) => c.is_active) ?? []
  const sections = data?.sections ?? ['A', 'B']

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Akademik kurulum"
        breadcrumbs={[{ label: 'Akademik', to: '/akademik' }, { label: 'Kurulum' }]}
        description="Sınıf açmak için gerekenler tek yerde. Önce program, sonra sınıf (şube). Eklediklerinizi düzenleyip silebilirsiniz."
      />

      <div className="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-2">
        <StepCard n={1} label="Program" done={programs.length} icon={<GraduationCap className="size-4" />} active={tab === 'programs'} onClick={() => setTab('programs')} />
        <StepCard n={2} label="Şube" done={sections.length} icon={<Rows3 className="size-4" />} active={tab === 'sections'} onClick={() => setTab('sections')} />
        <StepCard n={3} label="Sınıf" done={groups.length} icon={<LayoutGrid className="size-4" />} active={tab === 'classes'} onClick={() => setTab('classes')} />
      </div>

      <Tabs
        value={tab}
        onChange={setTab}
        className="mb-4"
        tabs={[
          { value: 'programs', label: 'Programlar', count: programs.length },
          { value: 'sections', label: 'Şubeler', count: sections.length },
          { value: 'classes', label: 'Sınıflar', count: groups.length },
          { value: 'terms', label: 'Dönemler', count: data?.terms.length ?? 0 },
        ]}
      />

      {isLoading ? <Skeleton className="h-72" /> : (
        <>
          {tab === 'programs' && <ProgramsTab items={programs} manage={manage} onDone={refresh} fail={fail} />}
          {tab === 'sections' && <SectionsTab items={sections} manage={manage} onDone={refresh} fail={fail} />}
          {tab === 'classes' && <ClassesTab data={data} programs={programs} sections={sections} manage={manage} onDone={refresh} fail={fail} goProgram={() => setTab('programs')} />}
          {tab === 'terms' && <TermsTab items={data?.terms ?? []} manage={manage} onDone={refresh} fail={fail} />}
        </>
      )}
    </div>
  )
}

function StepCard({ n, label, done, icon, active, onClick }: { n: number; label: string; done: number; icon: React.ReactNode; active: boolean; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className={`flex items-center gap-2.5 rounded-[var(--radius-md)] px-3 py-2.5 text-left ring-1 transition-colors ${active ? 'bg-primary-soft ring-primary/30' : 'bg-surface ring-line hover:ring-line-strong'}`}>
      <span className={`grid size-7 shrink-0 place-items-center rounded-full text-[12px] font-semibold ${done > 0 ? 'bg-success-soft text-success' : 'bg-surface-2 text-ink-3'}`}>{done > 0 ? '✓' : n}</span>
      <span className="min-w-0 flex-1"><span className="flex items-center gap-1.5 text-[13.5px] font-medium">{icon}{label}</span><span className="text-[12px] text-ink-3">{done} tanımlı</span></span>
    </button>
  )
}

/** Liste satırı + (yetkiliyse) düzenle/sil kısayolları. */
function ListRow({ title, meta, badge, onEdit, onDelete, extra }: { title: string; meta?: string; badge?: string; onEdit?: () => void; onDelete?: () => void; extra?: React.ReactNode }) {
  return (
    <li className="flex items-center gap-3 border-t border-line px-4 py-2.5 first:border-0">
      <span className="min-w-0 flex-1"><span className="block truncate font-medium text-[13.5px]">{title}</span>{meta && <span className="block text-[12px] text-ink-3">{meta}</span>}</span>
      {badge && <Badge tone="neutral">{badge}</Badge>}
      {extra}
      {onEdit && <Button size="icon-sm" variant="ghost" aria-label="Düzenle" title="Düzenle" onClick={onEdit}><Pencil className="size-3.5" /></Button>}
      {onDelete && <Button size="icon-sm" variant="ghost" aria-label="Sil" title="Sil" onClick={onDelete}><Trash2 className="size-3.5 text-danger" /></Button>}
    </li>
  )
}

function ProgramsTab({ items, manage, onDone, fail }: { items: Prog[]; manage: boolean; onDone: () => void; fail: (e: unknown) => void }) {
  const [f, setF] = useState({ name: '', kind: 'group' })
  const [edit, setEdit] = useState<Prog | null>(null)
  const [del, setDel] = useState<Prog | null>(null)
  const add = useMutation({
    mutationFn: () => api.post('/programs', { name: f.name.trim(), code: toCode(f.name), kind: f.kind }),
    onSuccess: () => { toast.success('Program eklendi.'); setF({ name: '', kind: 'group' }); onDone() },
    onError: fail,
  })
  const save = useMutation({
    mutationFn: (p: Prog) => api.put(`/programs/${p.id}`, { name: p.name.trim(), code: p.code || toCode(p.name), kind: p.kind }),
    onSuccess: () => { toast.success('Program güncellendi.'); setEdit(null); onDone() },
    onError: fail,
  })
  const remove = useMutation({
    mutationFn: (p: Prog) => api.delete(`/programs/${p.id}`),
    onSuccess: () => { toast.success('Program silindi.'); setDel(null); onDone() },
    onError: (e) => { fail(e); setDel(null) },
  })
  return (
    <Panel title="Programlar" description="Kurs türü / eğitim programı. Sınıflar ve paketler buna bağlanır. Örn. TYT-AYT Hazırlık, LGS." flush>
      {manage && (
        <div className="flex flex-wrap items-end gap-2 border-b border-line p-3">
          <Field label="Program adı" className="min-w-[200px] flex-1"><Input value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} placeholder="Örn. TYT-AYT Hazırlık" /></Field>
          <Field label="Tür" className="w-40"><Select value={f.kind} onChange={(e) => setF((s) => ({ ...s, kind: e.target.value }))} options={Object.entries(KIND_LABEL).map(([value, label]) => ({ value, label }))} /></Field>
          <Button variant="primary" icon={<Plus className="size-4" />} loading={add.isPending} disabled={f.name.trim().length < 2} onClick={() => add.mutate()}>Ekle</Button>
        </div>
      )}
      {items.length === 0 ? <EmptyState compact icon={<GraduationCap />} title="Henüz program yok" description="Yukarıdan ilk programı ekleyin." /> : (
        <ul>{items.map((p) => <ListRow key={p.id} title={p.name} badge={KIND_LABEL[p.kind] ?? p.kind} onEdit={manage ? () => setEdit({ ...p }) : undefined} onDelete={manage ? () => setDel(p) : undefined} />)}</ul>
      )}

      <Modal open={!!edit} onClose={() => setEdit(null)} title="Programı düzenle"
        footer={<><Button variant="ghost" onClick={() => setEdit(null)}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={!edit || edit.name.trim().length < 2} onClick={() => edit && save.mutate(edit)}>Kaydet</Button></>}>
        {edit && (
          <div className="grid gap-3">
            <Field label="Program adı" required><Input value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
            <Field label="Tür"><Select value={edit.kind} onChange={(e) => setEdit({ ...edit, kind: e.target.value })} options={Object.entries(KIND_LABEL).map(([value, label]) => ({ value, label }))} /></Field>
          </div>
        )}
      </Modal>
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} onConfirm={() => del && remove.mutate(del)} loading={remove.isPending} danger
        title="Programı sil" confirmLabel="Sil" description={del ? `"${del.name}" programı silinecek. Bu programa bağlı sınıf varsa silinemez.` : undefined} />
    </Panel>
  )
}

function SectionsTab({ items, manage, onDone, fail }: { items: string[]; manage: boolean; onDone: () => void; fail: (e: unknown) => void }) {
  const [list, setList] = useState<string[]>(items)
  const [val, setVal] = useState('')
  useEffect(() => { setList(items) }, [items])
  const save = useMutation({
    mutationFn: (next: string[]) => api.put('/class-sections', { sections: next }),
    onSuccess: () => { toast.success('Şubeler kaydedildi.'); onDone() },
    onError: fail,
  })
  const add = () => {
    const v = val.trim().toLocaleUpperCase('tr')
    if (!v || list.includes(v)) { setVal(''); return }
    const next = [...list, v]; setList(next); setVal(''); save.mutate(next)
  }
  const remove = (s: string) => { const next = list.filter((x) => x !== s); setList(next); save.mutate(next) }
  return (
    <Panel title="Şubeler" description="Şube listesi (A, B, …). Önce şubeleri tanımlayın; sınıf açarken buradan seçilir." flush>
      {manage && (
        <div className="flex flex-wrap items-end gap-2 border-b border-line p-3">
          <Field label="Yeni şube" className="w-40"><Input value={val} onChange={(e) => setVal(e.target.value)} maxLength={8} placeholder="Ör. C" onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); add() } }} /></Field>
          <Button variant="primary" icon={<Plus className="size-4" />} loading={save.isPending} disabled={!val.trim()} onClick={add}>Ekle</Button>
        </div>
      )}
      {list.length === 0 ? <EmptyState compact icon={<Rows3 />} title="Şube yok" description="A ve B ekleyin." /> : (
        <div className="flex flex-wrap gap-2 p-3">
          {list.map((s) => (
            <span key={s} className="inline-flex items-center gap-1.5 rounded-[8px] bg-surface-2 px-3 py-1.5 text-[13px] font-medium ring-1 ring-line">
              {s} şubesi
              {manage && <button type="button" className="text-ink-3 hover:text-danger" onClick={() => remove(s)} aria-label="Kaldır"><X className="size-3.5" /></button>}
            </span>
          ))}
        </div>
      )}
    </Panel>
  )
}

function ClassesTab({ data, programs, sections, manage, onDone, fail, goProgram }: { data?: Opt; programs: Prog[]; sections: string[]; manage: boolean; onDone: () => void; fail: (e: unknown) => void; goProgram: () => void }) {
  const current = data?.terms.find((t) => t.is_current) ?? data?.terms[0]
  const [f, setF] = useState({ name: '', section: sections[0] ?? 'A', program_id: '', academic_term_id: '', capacity: '24' })
  const [edit, setEdit] = useState<Group | null>(null)
  const [del, setDel] = useState<Group | null>(null)
  const termId = f.academic_term_id || (current ? String(current.id) : '')
  // Sınıf adı = taban ad + şube (12-EA + A → 12-EA-A). Şube ayrı alandan gelir.
  const fullName = (base: string, section: string) => { const b = base.trim().replace(new RegExp(`-${section}$`, 'i'), '').trim(); return section ? `${b}-${section}` : b }
  const add = useMutation({
    mutationFn: () => api.post('/class-groups', { name: fullName(f.name, f.section), section: f.section, program_id: Number(f.program_id), academic_term_id: Number(termId), capacity: Number(f.capacity) || 24, is_active: true }),
    onSuccess: () => { toast.success('Sınıf oluşturuldu.'); setF({ name: '', section: sections[0] ?? 'A', program_id: '', academic_term_id: '', capacity: '24' }); onDone() },
    onError: fail,
  })
  const save = useMutation({
    mutationFn: (g: Group) => api.put(`/class-groups/${g.id}`, { name: g.name.trim(), program_id: Number(g.program_id), academic_term_id: Number(g.academic_term_id), capacity: Number(g.capacity) || 24, is_active: g.is_active }),
    onSuccess: () => { toast.success('Sınıf güncellendi.'); setEdit(null); onDone() },
    onError: fail,
  })
  const remove = useMutation({
    mutationFn: (g: Group) => api.delete(`/class-groups/${g.id}`),
    onSuccess: (r: any) => { toast.success(r?.message ?? 'Sınıf silindi.'); setDel(null); onDone() },
    onError: (e) => { fail(e); setDel(null) },
  })
  const groups = data?.class_groups.filter((c) => c.is_active) ?? []
  const progName = (id: number | null) => programs.find((p) => p.id === id)?.name ?? '—'

  return (
    <Panel title="Sınıflar" description="Sınıf adı (ör. 12-EA) + program + şube. Örn. 12-EA + şube A → 12-EA-A." flush>
      {manage && programs.length === 0 ? (
        <div className="p-3"><EmptyState compact icon={<GraduationCap />} title="Önce program gerekir" description="Sınıf bir programa bağlıdır." action={<Button size="sm" onClick={goProgram}>Programlar sekmesine git</Button>} /></div>
      ) : manage && (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 border-b border-line p-3">
          <Field label="Sınıf adı" required hint="Ör. 12-EA, 12-SAY, 9-Genel (şubesiz)"><Input value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Şube" required hint="Şubeler sekmesinden yönetilir"><Select value={f.section} onChange={(e) => setF((s) => ({ ...s, section: e.target.value }))} options={sections.map((x) => ({ value: x, label: `${x} şubesi` }))} /></Field>
          <Field label="Program" required><Select value={f.program_id} onChange={(e) => setF((s) => ({ ...s, program_id: e.target.value }))} placeholder="Program seçin" options={programs.map((p) => ({ value: p.id, label: p.name }))} /></Field>
          <Field label="Dönem" required><Select value={termId} onChange={(e) => setF((s) => ({ ...s, academic_term_id: e.target.value }))} options={(data?.terms ?? []).map((t) => ({ value: t.id, label: `${t.name}${t.is_current ? ' (güncel)' : ''}` }))} /></Field>
          <Field label="Kontenjan" required><Input type="number" min={1} max={500} value={f.capacity} onChange={(e) => setF((s) => ({ ...s, capacity: e.target.value }))} /></Field>
          <div className="flex items-end"><Button variant="primary" className="w-full sm:w-auto" icon={<Plus className="size-4" />} loading={add.isPending} disabled={f.name.trim().length < 1 || !f.program_id || !termId} onClick={() => add.mutate()}>Sınıfı oluştur{f.name.trim() ? ` (${fullName(f.name, f.section)})` : ''}</Button></div>
        </div>
      )}
      {groups.length === 0 ? <EmptyState compact icon={<LayoutGrid />} title="Henüz sınıf yok" description="Program hazırsa yukarıdan sınıf (şube) oluşturun." /> : (
        <ul>{groups.map((g) => <ListRow key={g.id} title={g.name} meta={progName(g.program_id)} badge={`${g.capacity} kişi`} onEdit={manage ? () => setEdit({ ...g, academic_term_id: g.academic_term_id ?? current?.id ?? null }) : undefined} onDelete={manage ? () => setDel(g) : undefined} />)}</ul>
      )}

      <Modal open={!!edit} onClose={() => setEdit(null)} title="Sınıfı düzenle"
        footer={<><Button variant="ghost" onClick={() => setEdit(null)}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={!edit || edit.name.trim().length < 1 || !edit.program_id} onClick={() => edit && save.mutate(edit)}>Kaydet</Button></>}>
        {edit && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Field label="Sınıf / şube adı" required className="sm:col-span-2"><Input value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
            <Field label="Program" required><Select value={String(edit.program_id ?? '')} onChange={(e) => setEdit({ ...edit, program_id: Number(e.target.value) })} options={programs.map((p) => ({ value: p.id, label: p.name }))} /></Field>
            <Field label="Dönem" required><Select value={String(edit.academic_term_id ?? '')} onChange={(e) => setEdit({ ...edit, academic_term_id: Number(e.target.value) })} options={(data?.terms ?? []).map((t) => ({ value: t.id, label: t.name }))} /></Field>
            <Field label="Kontenjan" required><Input type="number" min={1} max={500} value={String(edit.capacity)} onChange={(e) => setEdit({ ...edit, capacity: Number(e.target.value) })} /></Field>
          </div>
        )}
      </Modal>
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} onConfirm={() => del && remove.mutate(del)} loading={remove.isPending} danger
        title="Sınıfı sil" confirmLabel="Sil" description={del ? `"${del.name}" sınıfı silinecek. Kayıtlı öğrenci varsa çıkarılır (öğrenci kayıtları korunur).` : undefined} />
    </Panel>
  )
}

function TermsTab({ items, manage, onDone, fail }: { items: Term[]; manage: boolean; onDone: () => void; fail: (e: unknown) => void }) {
  const [f, setF] = useState({ name: '', starts_on: todayISO(), ends_on: '', is_current: false })
  const [edit, setEdit] = useState<Term | null>(null)
  const add = useMutation({
    mutationFn: () => api.post('/academic-terms', { name: f.name.trim(), starts_on: f.starts_on, ends_on: f.ends_on, is_current: f.is_current }),
    onSuccess: () => { toast.success('Dönem eklendi.'); setF({ name: '', starts_on: todayISO(), ends_on: '', is_current: false }); onDone() },
    onError: fail,
  })
  const save = useMutation({
    mutationFn: (t: Term) => api.put(`/academic-terms/${t.id}`, { name: t.name.trim(), starts_on: t.starts_on, ends_on: t.ends_on }),
    onSuccess: () => { toast.success('Dönem güncellendi.'); setEdit(null); onDone() },
    onError: fail,
  })
  const setCurrent = useMutation({
    mutationFn: (t: Term) => api.post(`/academic-terms/${t.id}/set-current`),
    onSuccess: () => { toast.success('Güncel dönem güncellendi.'); onDone() },
    onError: fail,
  })
  return (
    <Panel title="Dönemler" description="Eğitim-öğretim dönemi. Sınıflar ve kayıtlar döneme bağlanır. Genelde bir kez tanımlanır." flush>
      {manage && (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 border-b border-line p-3">
          <Field label="Dönem adı" required hint="Örn. 2026-2027"><Input value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} /></Field>
          <div />
          <Field label="Başlangıç" required><Input type="date" value={f.starts_on} onChange={(e) => setF((s) => ({ ...s, starts_on: e.target.value }))} /></Field>
          <Field label="Bitiş" required><Input type="date" value={f.ends_on} onChange={(e) => setF((s) => ({ ...s, ends_on: e.target.value }))} /></Field>
          <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={f.is_current} onChange={(e) => setF((s) => ({ ...s, is_current: e.target.checked }))} /> Güncel dönem yap</label>
          <div className="flex items-end sm:justify-end"><Button variant="primary" icon={<Plus className="size-4" />} loading={add.isPending} disabled={f.name.trim().length < 2 || !f.ends_on} onClick={() => add.mutate()}>Ekle</Button></div>
        </div>
      )}
      {items.length === 0 ? <EmptyState compact icon={<CalendarRange />} title="Henüz dönem yok" /> : (
        <ul>{items.map((t) => (
          <ListRow key={t.id} title={t.name} badge={t.is_current ? 'Güncel' : undefined}
            extra={manage && !t.is_current ? <Button size="xs" variant="ghost" icon={<Star className="size-3.5" />} loading={setCurrent.isPending} onClick={() => setCurrent.mutate(t)}>Güncel yap</Button> : undefined}
            onEdit={manage ? () => setEdit({ ...t, starts_on: t.starts_on ?? todayISO(), ends_on: t.ends_on ?? '' }) : undefined} />
        ))}</ul>
      )}

      <Modal open={!!edit} onClose={() => setEdit(null)} title="Dönemi düzenle"
        footer={<><Button variant="ghost" onClick={() => setEdit(null)}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={!edit || edit.name.trim().length < 2 || !edit.ends_on} onClick={() => edit && save.mutate(edit)}>Kaydet</Button></>}>
        {edit && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Field label="Dönem adı" required className="sm:col-span-2"><Input value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
            <Field label="Başlangıç" required><Input type="date" value={edit.starts_on ?? ''} onChange={(e) => setEdit({ ...edit, starts_on: e.target.value })} /></Field>
            <Field label="Bitiş" required><Input type="date" value={edit.ends_on ?? ''} onChange={(e) => setEdit({ ...edit, ends_on: e.target.value })} /></Field>
          </div>
        )}
      </Modal>
    </Panel>
  )
}
