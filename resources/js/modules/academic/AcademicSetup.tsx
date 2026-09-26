import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Building2, CalendarRange, GraduationCap, LayoutGrid, Plus } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { todayISO } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Tabs } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/form'

/**
 * Akademik kurulum: sınıf açmak için gereken her şey TEK yerde, sekmeli ve basit.
 * Sıra: 1) Program (kurs türü) → 2) Derslik → 3) Sınıf (şube). Dönem çoğunlukla hazırdır.
 */
type Opt = {
  programs: { id: number; name: string; kind: string; is_active: boolean }[]
  classrooms: { id: number; name: string; capacity: number; is_active: boolean }[]
  terms: { id: number; name: string; is_current: boolean }[]
  class_groups: { id: number; name: string; program_id: number | null; capacity: number; is_active: boolean }[]
}
type Tab = 'programs' | 'classrooms' | 'classes' | 'terms'
const KIND_LABEL: Record<string, string> = { group: 'Grup dersi', private: 'Birebir', study: 'Etüt' }

/** Türkçe adı A-Z0-9 program koduna çevirir (backend: büyük harf + [A-Z0-9_-]). */
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
  const classrooms = data?.classrooms.filter((c) => c.is_active) ?? []

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Akademik kurulum"
        breadcrumbs={[{ label: 'Akademik', to: '/akademik' }, { label: 'Kurulum' }]}
        description="Sınıf açmak için gereken her şey tek yerde. Sırasıyla: 1) Program 2) Derslik 3) Sınıf (şube)."
      />

      <div className="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-2">
        <StepCard n={1} label="Program" done={programs.length} icon={<GraduationCap className="size-4" />} active={tab === 'programs'} onClick={() => setTab('programs')} />
        <StepCard n={2} label="Derslik" done={classrooms.length} icon={<Building2 className="size-4" />} active={tab === 'classrooms'} onClick={() => setTab('classrooms')} />
        <StepCard n={3} label="Sınıf (şube)" done={data?.class_groups.filter((c) => c.is_active).length ?? 0} icon={<LayoutGrid className="size-4" />} active={tab === 'classes'} onClick={() => setTab('classes')} />
      </div>

      <Tabs
        value={tab}
        onChange={setTab}
        className="mb-4"
        tabs={[
          { value: 'programs', label: 'Programlar', count: programs.length },
          { value: 'classrooms', label: 'Derslikler', count: classrooms.length },
          { value: 'classes', label: 'Sınıflar (şubeler)', count: data?.class_groups.filter((c) => c.is_active).length ?? 0 },
          { value: 'terms', label: 'Dönemler', count: data?.terms.length ?? 0 },
        ]}
      />

      {isLoading ? <Skeleton className="h-72" /> : (
        <>
          {tab === 'programs' && <ProgramsTab items={programs} manage={manage} onDone={refresh} fail={fail} />}
          {tab === 'classrooms' && <ClassroomsTab items={classrooms} manage={manage} onDone={refresh} fail={fail} />}
          {tab === 'classes' && <ClassesTab data={data} programs={programs} classrooms={classrooms} manage={manage} onDone={refresh} fail={fail} goProgram={() => setTab('programs')} />}
          {tab === 'terms' && <TermsTab items={data?.terms ?? []} onDone={refresh} fail={fail} />}
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

function ListRow({ title, meta, badge }: { title: string; meta?: string; badge?: string }) {
  return (
    <li className="flex items-center gap-3 border-t border-line px-4 py-2.5 first:border-0">
      <span className="min-w-0 flex-1"><span className="block truncate font-medium text-[13.5px]">{title}</span>{meta && <span className="block text-[12px] text-ink-3">{meta}</span>}</span>
      {badge && <Badge tone="neutral">{badge}</Badge>}
    </li>
  )
}

function ProgramsTab({ items, manage, onDone, fail }: { items: Opt['programs']; manage: boolean; onDone: () => void; fail: (e: unknown) => void }) {
  const [f, setF] = useState({ name: '', kind: 'group' })
  const add = useMutation({
    mutationFn: () => api.post('/programs', { name: f.name.trim(), code: toCode(f.name), kind: f.kind }),
    onSuccess: () => { toast.success('Program eklendi.'); setF({ name: '', kind: 'group' }); onDone() },
    onError: fail,
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
      {items.length === 0 ? <EmptyState compact icon={<GraduationCap />} title="Henüz program yok" description="Yukarıdan ilk programı ekleyin." /> : <ul>{items.map((p) => <ListRow key={p.id} title={p.name} badge={KIND_LABEL[p.kind] ?? p.kind} />)}</ul>}
    </Panel>
  )
}

function ClassroomsTab({ items, manage, onDone, fail }: { items: Opt['classrooms']; manage: boolean; onDone: () => void; fail: (e: unknown) => void }) {
  const [f, setF] = useState({ name: '', capacity: '24' })
  const add = useMutation({
    mutationFn: () => api.post('/classrooms', { name: f.name.trim(), kind: 'classroom', capacity: Number(f.capacity) || 24 }),
    onSuccess: () => { toast.success('Derslik eklendi.'); setF({ name: '', capacity: '24' }); onDone() },
    onError: fail,
  })
  return (
    <Panel title="Derslikler" description="Sınıfların fiziksel odaları. Sınıf açarken 'ana derslik' olarak seçilir." flush>
      {manage && (
        <div className="flex flex-wrap items-end gap-2 border-b border-line p-3">
          <Field label="Derslik adı" className="min-w-[180px] flex-1"><Input value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} placeholder="Örn. A-101" /></Field>
          <Field label="Kapasite" className="w-28"><Input type="number" min={1} max={1000} value={f.capacity} onChange={(e) => setF((s) => ({ ...s, capacity: e.target.value }))} /></Field>
          <Button variant="primary" icon={<Plus className="size-4" />} loading={add.isPending} disabled={f.name.trim().length < 1} onClick={() => add.mutate()}>Ekle</Button>
        </div>
      )}
      {items.length === 0 ? <EmptyState compact icon={<Building2 />} title="Henüz derslik yok" description="Yukarıdan ilk dersliği ekleyin." /> : <ul>{items.map((c) => <ListRow key={c.id} title={c.name} badge={`${c.capacity} kişi`} />)}</ul>}
    </Panel>
  )
}

function ClassesTab({ data, programs, classrooms, manage, onDone, fail, goProgram }: { data?: Opt; programs: Opt['programs']; classrooms: Opt['classrooms']; manage: boolean; onDone: () => void; fail: (e: unknown) => void; goProgram: () => void }) {
  const current = data?.terms.find((t) => t.is_current) ?? data?.terms[0]
  const [f, setF] = useState({ name: '', program_id: '', academic_term_id: '', homeroom_classroom_id: '', capacity: '24' })
  const termId = f.academic_term_id || (current ? String(current.id) : '')
  const add = useMutation({
    mutationFn: () => api.post('/class-groups', {
      name: f.name.trim(), program_id: Number(f.program_id), academic_term_id: Number(termId),
      homeroom_classroom_id: f.homeroom_classroom_id ? Number(f.homeroom_classroom_id) : null, capacity: Number(f.capacity) || 24, is_active: true,
    }),
    onSuccess: () => { toast.success('Sınıf (şube) oluşturuldu.'); setF({ name: '', program_id: '', academic_term_id: '', homeroom_classroom_id: '', capacity: '24' }); onDone() },
    onError: fail,
  })
  const groups = data?.class_groups.filter((c) => c.is_active) ?? []
  const progName = (id: number | null) => programs.find((p) => p.id === id)?.name ?? '—'

  return (
    <Panel title="Sınıflar (şubeler)" description="Öğrencilerin yerleştiği şube. Bir programa ve döneme bağlıdır. Örn. 12-SAY-A." flush>
      {manage && programs.length === 0 ? (
        <div className="p-3"><Alert tone="info" title="Önce program gerekir">Sınıf bir programa bağlıdır. <button className="font-medium text-primary underline" onClick={goProgram}>Programlar sekmesinden</button> en az bir program ekleyin.</Alert></div>
      ) : manage && (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 border-b border-line p-3">
          <Field label="Sınıf / şube adı" required hint="Örn. 12-SAY-A, 8-LGS-B"><Input value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Program" required><Select value={f.program_id} onChange={(e) => setF((s) => ({ ...s, program_id: e.target.value }))} placeholder="Program seçin" options={programs.map((p) => ({ value: p.id, label: p.name }))} /></Field>
          <Field label="Dönem" required><Select value={termId} onChange={(e) => setF((s) => ({ ...s, academic_term_id: e.target.value }))} options={(data?.terms ?? []).map((t) => ({ value: t.id, label: `${t.name}${t.is_current ? ' (güncel)' : ''}` }))} /></Field>
          <Field label="Ana derslik" optional hint={classrooms.length === 0 ? 'Derslik yok — Derslikler sekmesinden ekleyin' : undefined}><Select value={f.homeroom_classroom_id} onChange={(e) => setF((s) => ({ ...s, homeroom_classroom_id: e.target.value }))} placeholder="Atanmadı" options={classrooms.map((c) => ({ value: c.id, label: `${c.name} · ${c.capacity} kişi` }))} /></Field>
          <Field label="Kontenjan" required><Input type="number" min={1} max={500} value={f.capacity} onChange={(e) => setF((s) => ({ ...s, capacity: e.target.value }))} /></Field>
          <div className="flex items-end"><Button variant="primary" className="w-full sm:w-auto" icon={<Plus className="size-4" />} loading={add.isPending} disabled={f.name.trim().length < 1 || !f.program_id || !termId} onClick={() => add.mutate()}>Sınıfı oluştur</Button></div>
        </div>
      )}
      {groups.length === 0 ? <EmptyState compact icon={<LayoutGrid />} title="Henüz sınıf yok" description="Program ve derslik hazırsa yukarıdan sınıf (şube) oluşturun." /> : <ul>{groups.map((g) => <ListRow key={g.id} title={g.name} meta={progName(g.program_id)} badge={`${g.capacity} kişi`} />)}</ul>}
    </Panel>
  )
}

function TermsTab({ items, onDone, fail }: { items: Opt['terms']; onDone: () => void; fail: (e: unknown) => void }) {
  const [f, setF] = useState({ name: '', starts_on: todayISO(), ends_on: '', is_current: false })
  const add = useMutation({
    mutationFn: () => api.post('/academic-terms', { name: f.name.trim(), starts_on: f.starts_on, ends_on: f.ends_on, is_current: f.is_current }),
    onSuccess: () => { toast.success('Dönem eklendi.'); setF({ name: '', starts_on: todayISO(), ends_on: '', is_current: false }); onDone() },
    onError: fail,
  })
  return (
    <Panel title="Dönemler" description="Eğitim-öğretim dönemi. Sınıflar ve kayıtlar döneme bağlanır. Genelde bir kez tanımlanır." flush>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 border-b border-line p-3">
        <Field label="Dönem adı" required hint="Örn. 2026-2027"><Input value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} /></Field>
        <div />
        <Field label="Başlangıç" required><Input type="date" value={f.starts_on} onChange={(e) => setF((s) => ({ ...s, starts_on: e.target.value }))} /></Field>
        <Field label="Bitiş" required><Input type="date" value={f.ends_on} onChange={(e) => setF((s) => ({ ...s, ends_on: e.target.value }))} /></Field>
        <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={f.is_current} onChange={(e) => setF((s) => ({ ...s, is_current: e.target.checked }))} /> Güncel dönem yap</label>
        <div className="flex items-end sm:justify-end"><Button variant="primary" icon={<Plus className="size-4" />} loading={add.isPending} disabled={f.name.trim().length < 2 || !f.ends_on} onClick={() => add.mutate()}>Ekle</Button></div>
      </div>
      {items.length === 0 ? <EmptyState compact icon={<CalendarRange />} title="Henüz dönem yok" /> : <ul>{items.map((t) => <ListRow key={t.id} title={t.name} badge={t.is_current ? 'Güncel' : undefined} />)}</ul>}
    </Panel>
  )
}
