import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { BookOpenCheck, ChevronDown, CircleCheck, LayoutGrid, Plus, RotateCcw, Save, Trash2, Wand2, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Switch } from '@/components/ui/form'
import { Alert, Badge, EmptyState, Skeleton, type Tone } from '@/components/ui/feedback'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import { BotTabs } from './BotTabs'
import { CurriculumDrawer } from './CurriculumDrawer'
import { levelLabel, sectionName, type LevelSpec, type PlanRow, type SectionSpec, type Structure, type StructurePayload } from './types'

const LETTERS = 'ABCDEFGHIJKL'.split('')
const GRADES = [5, 6, 7, 8, 9, 10, 11, 12, 13]
const COLOR_LABELS: Record<string, string> = { indigo: 'Çivit', blue: 'Mavi', teal: 'Camgöbeği', green: 'Yeşil', amber: 'Kehribar', orange: 'Turuncu', rose: 'Gül', violet: 'Mor', slate: 'Gri' }
const ACTION: Record<PlanRow['action'], { label: string; tone: Tone }> = {
  create: { label: 'Açılacak', tone: 'primary' },
  update: { label: 'Güncellenecek', tone: 'warning' },
  reactivate: { label: 'Yeniden açılacak', tone: 'warning' },
  ok: { label: 'Uyumlu', tone: 'success' },
}

const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v)) as T

function defaultTrack(grade: number, code: string, defaults: Record<string, string>): string | null {
  if (grade <= 8) return 'LGS'
  if (grade <= 11) return 'TYT'
  return defaults[code] ?? null
}

function newSection(grade: number, code: string, s: Structure): SectionSpec {
  return { code, track: defaultTrack(grade, code, s.track_defaults), capacity: s.default_capacity, program_id: null, time_template_ids: [], homeroom_classroom_id: null, advisor_teacher_id: null, short_name: null, color: null }
}

export default function ClassStructurePage() {
  const can = useCan()
  const manage = can('academic.manage')
  const qc = useQueryClient()
  const [termId, setTermId] = useState<number | null>(null)
  const q = useQuery({ queryKey: ['class-structure', termId], queryFn: () => api.get<StructurePayload>('/class-structure', { term_id: termId ?? undefined }) })
  const [draft, setDraft] = useState<Structure | null>(null)
  const [applyOpen, setApplyOpen] = useState(false)
  const [updateExisting, setUpdateExisting] = useState(true)
  const [curriculumFor, setCurriculumFor] = useState<number | null>(null)
  const [trackApply, setTrackApply] = useState<{ label: string; ids: number[] } | null>(null)
  const [newGrade, setNewGrade] = useState('')
  const [curriculaOpen, setCurriculaOpen] = useState(false)

  const data = q.data
  useEffect(() => {
    if (data) setDraft(clone(data.structure))
  }, [data])

  const dirty = useMemo(() => !!data && !!draft && JSON.stringify(strip(draft)) !== JSON.stringify(strip(data.structure)), [data, draft])
  const plan = useMemo(() => new Map((data?.plan ?? []).map((r) => [r.key, r])), [data])
  const pending = (data?.plan ?? []).filter((r) => r.action !== 'ok')

  const onError = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem tamamlanamadı.')
  const refresh = (r: StructurePayload) => {
    qc.setQueryData(['class-structure', termId], r)
    qc.invalidateQueries({ queryKey: ['placement'] })
    qc.invalidateQueries({ queryKey: ['timetable', 'options'] })
  }
  const save = useMutation({
    mutationFn: () => api.put<StructurePayload & { message: string }>('/class-structure', { term_id: data?.term.id, structure: strip(draft!) }),
    onSuccess: (r) => { toast.success(r.message); refresh(r) },
    onError,
  })
  const apply = useMutation({
    mutationFn: () => api.post<StructurePayload & { message: string }>('/class-structure/apply', { term_id: data!.term.id, update_existing: updateExisting }),
    onSuccess: (r) => { toast.success(r.message); setApplyOpen(false); refresh(r); qc.invalidateQueries({ queryKey: ['class-groups'] }) },
    onError,
  })
  const applyTrack = useMutation({
    mutationFn: (ids: number[]) => api.post<{ message: string }>('/class-structure/apply-track', { class_group_ids: ids }),
    onSuccess: (r) => { toast.success(r.message); setTrackApply(null); qc.invalidateQueries({ queryKey: ['class-structure'] }); qc.invalidateQueries({ queryKey: ['timetable', 'options'] }) },
    onError,
  })

  if (q.isLoading || (!draft && !q.isError)) {
    return (
      <div className="animate-fade-in">
        <PageHeader title="Sınıf yapısı" />
        <BotTabs active="structure" />
        <div className="flex flex-col gap-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-40 rounded-[var(--radius-lg)]" />)}</div>
      </div>
    )
  }
  if (q.isError || !data || !draft) return <Alert tone="danger">Sınıf yapısı yüklenemedi.</Alert>

  const o = data.options
  const trackOptions = [{ value: '', label: 'Alan yok' }, ...Object.entries(o.tracks).map(([v, l]) => ({ value: v, label: l }))]
  const usedGrades = draft.levels.map((l) => l.grade)
  const setLevel = (grade: number, fn: (l: LevelSpec) => LevelSpec) => setDraft({ ...draft, levels: draft.levels.map((l) => (l.grade === grade ? fn(clone(l)) : l)) })
  const setSection = (grade: number, code: string, patch: Partial<SectionSpec>) =>
    setLevel(grade, (l) => ({ ...l, sections: l.sections.map((s) => (s.code === code ? { ...s, ...patch } : s)) }))
  const totalSeats = draft.levels.reduce((a, l) => a + l.sections.reduce((b, s) => b + s.capacity, 0), 0)
  const totalSections = draft.levels.reduce((a, l) => a + l.sections.length, 0)

  return (
    <div className="animate-fade-in pb-20">
      <PageHeader
        title="Sınıf yapısı"
        description="Seviye başına şubeleri, alanlarını (SAY/EA/SÖZ…), kapasiteyi, programı ve zaman şablonunu tanımlayın; sonra döneme uygulayın. Ders saatleri sınıf bazında özelleştirilebilir."
        actions={
          <>
            {data.terms.length > 1 && (
              <Select className="w-40" aria-label="Dönem" value={String(data.term.id)} options={data.terms.map((t) => ({ value: t.id, label: t.name }))} onChange={(e) => setTermId(Number(e.target.value))} />
            )}
            {manage && <Button icon={<CircleCheck className="size-4" />} disabled={dirty || draft.levels.length === 0} title={dirty ? 'Önce değişiklikleri kaydedin' : undefined} onClick={() => setApplyOpen(true)}>Yapıyı uygula{pending.length ? ` (${pending.length})` : ''}</Button>}
          </>
        }
      />
      <BotTabs active="structure" />

      {data.configured === false && draft.levels.length === 0 ? (
        <Panel className="mb-4">
          <EmptyState
            icon={<LayoutGrid />}
            title="Sınıf yapınızı tanımlayın"
            description={manage
              ? 'Kurumunuzdaki seviyeleri (ör. 8. sınıf LGS, 12. sınıf, Mezun) ve şubeleri ekleyin. Aşağıdaki "Seviye ekle" alanından seviye seçip Ekle\'ye basın, ardından "Yapıyı kaydet" ile kaydedin. Kaydedilen yapı yerleştirme ve program botunda kullanılır.'
              : 'Sınıf yapısı henüz tanımlanmadı. Yönetici seviye ve şubeleri tanımladığında burada görünür.'}
            action={manage ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => document.getElementById('seviye-ekle')?.focus()}>Seviye ekle</Button> : undefined}
          />
        </Panel>
      ) : !data.customized && draft.levels.length > 0 ? (
        <Alert tone="info" className="mb-4" title="Yapı henüz kaydedilmedi">
          {usedGrades.map(levelLabel).join(', ')} tanımlı görünüyor ancak kurum yapısı olarak kaydedilmedi. Düzenleyip "Yapıyı kaydet" ile kaydettiğinizde yerleştirme botu ve "Eksik sınıfları oluştur" bu yapıyı kullanır.
        </Alert>
      ) : null}

      <Panel title="Genel" className="mb-4">
        <div className="grid gap-4 md:grid-cols-[180px_minmax(0,1fr)]">
          <Field label="Varsayılan kapasite" hint="Yeni eklenen şubeler için.">
            <Input type="number" min={1} max={60} disabled={!manage} value={draft.default_capacity}
              onChange={(e) => setDraft({ ...draft, default_capacity: Math.max(1, Math.min(60, Number(e.target.value) || 1)) })} />
          </Field>
          <Field label="Şube harfine göre alan önerisi (12. sınıf ve Mezun)" hint="Yeni şube eklerken alan bu tabloya göre önerilir; 9-11 TYT, 8 ve altı LGS önerilir.">
            <div className="flex flex-wrap gap-2">
              {LETTERS.slice(0, 6).map((letter) => (
                <label key={letter} className="flex items-center gap-1.5 rounded-[var(--radius-sm)] ring-1 ring-line px-2 py-1">
                  <span className="w-4 text-[13px] font-semibold">{letter}</span>
                  <Select className="w-[130px]" aria-label={`${letter} şubesi alan önerisi`} disabled={!manage} value={draft.track_defaults[letter] ?? ''}
                    options={trackOptions}
                    onChange={(e) => {
                      const next = { ...draft.track_defaults }
                      if (e.target.value) next[letter] = e.target.value
                      else delete next[letter]
                      setDraft({ ...draft, track_defaults: next })
                    }} />
                </label>
              ))}
            </div>
          </Field>
        </div>
        <p className="mt-3 text-[12.5px] text-ink-3">Toplam {draft.levels.length} seviye, {totalSections} sınıf, {totalSeats} öğrenci kapasitesi.</p>
      </Panel>

      <div className="flex flex-col gap-4">
        {draft.levels.map((level) => {
          const existingIds = level.sections.map((s) => plan.get(`${level.grade}-${s.code}`)?.class_group_id).filter((x): x is number => !!x)
          return (
            <Panel
              key={level.grade}
              title={<span className="flex items-center gap-2">{levelLabel(level.grade)}<span className="text-[12px] font-normal text-ink-3">{level.sectioned ? `${level.sections.length} şube` : 'şubesiz'} · {level.sections.reduce((a, s) => a + s.capacity, 0)} kişi</span></span>}
              actions={
                <div className="flex flex-wrap items-center justify-end gap-2">
                  {manage && existingIds.length > 0 && (
                    <Button size="xs" variant="ghost" icon={<Wand2 className="size-3.5" />} onClick={() => setTrackApply({ label: levelLabel(level.grade), ids: existingIds })}>Alan müfredatını uygula</Button>
                  )}
                  {manage && (
                    <Segmented size="sm" value={level.sectioned ? 'on' : 'off'}
                      onChange={(v) => setLevel(level.grade, (l) => (v === 'on'
                        ? { ...l, sectioned: true, sections: [{ ...l.sections[0]!, code: 'A' }] }
                        : { ...l, sectioned: false, sections: [{ ...l.sections[0]!, code: '-' }] }))}
                      options={[{ value: 'on', label: 'Şubeli' }, { value: 'off', label: 'Şubesiz' }]} />
                  )}
                  {manage && draft.levels.length > 1 && (
                    <Button size="icon-sm" variant="ghost" aria-label={`${levelLabel(level.grade)} seviyesini kaldır`} title="Seviyeyi kaldır" onClick={() => setDraft({ ...draft, levels: draft.levels.filter((l) => l.grade !== level.grade) })}><X className="size-4" /></Button>
                  )}
                </div>
              }
            >
              <div className="flex flex-col gap-3">
                {level.sections.map((s) => (
                  <SectionEditor
                    key={s.code}
                    grade={level.grade}
                    spec={s}
                    plan={plan.get(`${level.grade}-${s.code}`)}
                    options={o}
                    trackOptions={trackOptions}
                    manage={manage}
                    canRemove={level.sectioned && level.sections.length > 1}
                    onChange={(patch) => setSection(level.grade, s.code, patch)}
                    onRemove={() => setLevel(level.grade, (l) => ({ ...l, sections: l.sections.filter((x) => x.code !== s.code) }))}
                    onCurriculum={(id) => setCurriculumFor(id)}
                  />
                ))}
                {manage && level.sectioned && level.sections.length < LETTERS.length && (
                  <Button size="sm" variant="ghost" className="self-start" icon={<Plus className="size-4" />}
                    onClick={() => {
                      const code = LETTERS.find((x) => !level.sections.some((s) => s.code === x))!
                      setLevel(level.grade, (l) => ({ ...l, sections: [...l.sections, newSection(level.grade, code, draft)].sort((a, b) => a.code.localeCompare(b.code)) }))
                    }}>
                    Şube ekle
                  </Button>
                )}
              </div>
            </Panel>
          )
        })}

        {manage && (
          <div className="flex flex-wrap items-end gap-2">
            <Field label="Seviye ekle" className="w-[180px]">
              <Select id="seviye-ekle" value={newGrade} onChange={(e) => setNewGrade(e.target.value)} placeholder="Seçin"
                options={GRADES.filter((g) => !usedGrades.includes(g)).map((g) => ({ value: g, label: levelLabel(g) }))} />
            </Field>
            <Button icon={<Plus className="size-4" />} disabled={!newGrade}
              onClick={() => {
                const g = Number(newGrade)
                const lv: LevelSpec = { grade: g, sectioned: true, sections: [newSection(g, 'A', draft), newSection(g, 'B', draft)] }
                setDraft({ ...draft, levels: [...draft.levels, lv].sort((a, b) => a.grade - b.grade) })
                setNewGrade('')
              }}>
              Ekle
            </Button>
          </div>
        )}
      </div>

      <Panel
        className="mt-4"
        title="Alanlara göre varsayılan müfredat"
        description="Sınıfın alanı seçildiğinde ders saatleri bu tabloya göre önerilir. Öneri sınıfın ders saatleri ekranında düzenlenebilir."
        actions={<Button size="sm" variant="ghost" icon={<ChevronDown className={cn('size-4 transition-transform', curriculaOpen && 'rotate-180')} />} onClick={() => setCurriculaOpen((v) => !v)}>{curriculaOpen ? 'Gizle' : 'Göster'}</Button>}
        flush={curriculaOpen}
      >
        {curriculaOpen && <TrackCurricula data={data} manage={manage} />}
      </Panel>

      {data.unstructured.length > 0 && (
        <Panel className="mt-4" title="Yapı dışındaki sınıflar" description="Bu sınıflar seviye/şube yapısına dahil değil; yerleştirme botu onlara dokunmaz, program botu ise planlayabilir.">
          <div className="flex flex-wrap gap-1.5">
            {data.unstructured.map((g) => (
              <span key={g.id} className="inline-flex items-center gap-1.5 rounded-[6px] px-2 py-1 text-[12.5px] ring-1 ring-line">
                <Link to={`/siniflar/${g.id}`} className="hover:underline">{g.name}</Link>
                {g.track && <span className="text-ink-3">{o.tracks[g.track] ?? g.track}</span>}
                <button type="button" className="text-[12px] text-ink-3 hover:text-ink" onClick={() => setCurriculumFor(g.id)}>ders saatleri</button>
              </span>
            ))}
          </div>
        </Panel>
      )}

      {manage && dirty && (
        <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 backdrop-blur">
          <div className="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-2 px-4 py-2.5">
            <p className="text-[13px] text-ink-2">Kaydedilmemiş değişiklikler var. Kaydetmek mevcut sınıfları değiştirmez; "Yapıyı uygula" ile uygulanır.</p>
            <div className="flex gap-2">
              <Button variant="ghost" icon={<RotateCcw className="size-4" />} onClick={() => setDraft(clone(data.structure))}>Vazgeç</Button>
              <Button variant="primary" icon={<Save className="size-4" />} loading={save.isPending} onClick={() => save.mutate()}>Yapıyı kaydet</Button>
            </div>
          </div>
        </div>
      )}

      <Modal
        open={applyOpen}
        onClose={() => setApplyOpen(false)}
        size="lg"
        title={`Yapıyı ${data.term.name} dönemine uygula`}
        description="Eksik sınıflar açılır; mevcut sınıflar seviye/şubeyle eşleştirilir. Öğrenci üyelikleri ve ders programı bu adımda değişmez."
        footer={<><Button variant="ghost" onClick={() => setApplyOpen(false)}>Vazgeç</Button><Button variant="primary" loading={apply.isPending} disabled={pending.length === 0} onClick={() => apply.mutate()}>Uygula</Button></>}
      >
        <div className="flex flex-col gap-3">
          <Switch checked={updateExisting} onChange={setUpdateExisting} label="Mevcut sınıfların kapasite, alan, program, şablon, derslik ve rehberini de yapıya göre güncelle" />
          {pending.length === 0 ? (
            <EmptyState compact icon={<CircleCheck />} title="Sınıflar yapıyla uyumlu" description="Uygulanacak değişiklik yok." />
          ) : (
            <ul className="flex flex-col divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
              {pending.map((r) => {
                const skip = r.action === 'update' && !updateExisting
                return (
                  <li key={r.key} className={cn('flex flex-col gap-1 px-3 py-2 text-[13px] sm:flex-row sm:items-center sm:gap-3', skip && 'opacity-50')}>
                    <span className="w-24 shrink-0 font-semibold">{r.name}</span>
                    <Badge tone={ACTION[r.action].tone}>{skip ? 'Atlanacak' : ACTION[r.action].label}</Badge>
                    <span className="min-w-0 flex-1 text-[12.5px] text-ink-3">
                      {r.action === 'create' ? `${r.capacity} kişilik${r.track ? `, ${o.tracks[r.track]}` : ''}` : r.changes.map((c) => changeText(c, o)).join(' · ')}
                    </span>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      </Modal>

      <ConfirmDialog
        open={!!trackApply}
        onClose={() => setTrackApply(null)}
        onConfirm={() => trackApply && applyTrack.mutate(trackApply.ids)}
        loading={applyTrack.isPending}
        title={`${trackApply?.label ?? ''} sınıflarına alan müfredatını uygula`}
        description="Her sınıfın ders saatleri, alanının varsayılan müfredatına göre yeniden yazılır (sabit öğretmen ve günlük sınır ayarları korunur). Alanı seçilmemiş sınıflar atlanır. Ders programı değişmez; bot sonraki çalıştırmada yeni saatleri kullanır."
        confirmLabel="Uygula"
      />

      {curriculumFor && <CurriculumDrawer classId={curriculumFor} onClose={() => setCurriculumFor(null)} />}
    </div>
  )
}

function strip(s: Structure): Structure {
  return {
    default_capacity: s.default_capacity,
    track_defaults: Object.fromEntries(Object.entries(s.track_defaults).sort()),
    levels: s.levels.map((l) => ({ grade: l.grade, sectioned: l.sectioned, sections: l.sections.map((x) => ({ ...x, time_template_ids: [...x.time_template_ids].sort((a, b) => a - b) })) })),
  }
}

function changeText(c: { field: string; label: string; from: unknown; to: unknown }, o: StructurePayload['options']): string {
  const fmt = (v: unknown): string => {
    if (v === null || v === undefined || v === '') return '—'
    if (c.field === 'track') return o.tracks[String(v)] ?? String(v)
    if (c.field === 'program_id') return o.programs.find((p) => p.id === v)?.name ?? `#${v}`
    if (c.field === 'homeroom_classroom_id') return o.classrooms.find((p) => p.id === v)?.name ?? `#${v}`
    if (c.field === 'advisor_teacher_id') return o.teachers.find((p) => p.id === v)?.name ?? `#${v}`
    if (c.field === 'time_template_ids') return (v as number[]).map((id) => o.templates.find((t) => t.id === id)?.name ?? `#${id}`).join(' + ') || '—'
    if (c.field === 'color') return COLOR_LABELS[String(v)] ?? String(v)
    return String(v)
  }
  return `${c.label}: ${fmt(c.from)} → ${fmt(c.to)}`
}

function SectionEditor({
  grade, spec, plan, options: o, trackOptions, manage, canRemove, onChange, onRemove, onCurriculum,
}: {
  grade: number
  spec: SectionSpec
  plan: PlanRow | undefined
  options: StructurePayload['options']
  trackOptions: { value: string; label: string }[]
  manage: boolean
  canRemove: boolean
  onChange: (patch: Partial<SectionSpec>) => void
  onRemove: () => void
  onCurriculum: (classId: number) => void
}) {
  const [more, setMore] = useState(false)
  const cur = plan?.current
  const slots = cur?.template_slots ?? 0
  const hoursState = cur ? (cur.template_slots === 0 ? 'Şablon yok' : cur.weekly_hours > cur.template_slots ? `${cur.weekly_hours - cur.template_slots} saat fazla` : cur.weekly_hours < cur.template_slots ? `${cur.template_slots - cur.weekly_hours} dilim boş` : 'Tam dolu') : null
  const templateOptions = o.templates.filter((t) => !spec.time_template_ids.includes(t.id))
  const specSlots = spec.time_template_ids.reduce((a, id) => a + (o.templates.find((t) => t.id === id)?.slots ?? 0), 0)

  return (
    <div className="rounded-[var(--radius-md)] ring-1 ring-line">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-line px-3 py-2">
        <span className="text-[16px] font-semibold tracking-[-0.01em]">{sectionName(grade, spec.code)}</span>
        {plan && <Badge tone={ACTION[plan.action].tone}>{ACTION[plan.action].label}</Badge>}
        {cur && (
          <span className="text-[12.5px] tabular text-ink-3">
            {cur.name !== sectionName(grade, spec.code) ? `${cur.name} · ` : ''}{cur.size}/{cur.capacity} öğrenci · {cur.weekly_hours} saat/hafta{cur.custom_hours ? ' (özel)' : ''} · {slots} dilim
            {hoursState && <span className={cn('ml-1', (cur.template_slots === 0 || cur.weekly_hours > cur.template_slots) && 'text-warning')}>· {hoursState}</span>}
          </span>
        )}
        <div className="ml-auto flex items-center gap-1">
          {cur && <Button size="xs" icon={<BookOpenCheck className="size-3.5" />} onClick={() => onCurriculum(cur.id)}>Ders saatleri</Button>}
          {manage && canRemove && <Button size="icon-sm" variant="ghost" aria-label={`${sectionName(grade, spec.code)} şubesini kaldır`} title="Şubeyi kaldır" onClick={onRemove}><Trash2 className="size-3.5" /></Button>}
        </div>
      </div>
      <div className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-4">
        <Field label="Alan">
          <Select value={spec.track ?? ''} disabled={!manage} onChange={(e) => onChange({ track: e.target.value || null })} options={trackOptions} />
        </Field>
        <Field label="Kapasite">
          <Input type="number" min={1} max={60} disabled={!manage} value={spec.capacity} onChange={(e) => onChange({ capacity: Math.max(1, Math.min(60, Number(e.target.value) || 1)) })} />
        </Field>
        <Field label="Program" hint={spec.program_id ? undefined : 'Boşsa alan/seviye programı'}>
          <Select value={spec.program_id ?? ''} disabled={!manage} onChange={(e) => onChange({ program_id: e.target.value ? Number(e.target.value) : null })}
            options={[{ value: '', label: 'Otomatik' }, ...o.programs.map((p) => ({ value: p.id, label: p.name }))]} />
        </Field>
        <Field label="Zaman şablonu" hint={spec.time_template_ids.length ? `${specSlots} dilim` : 'Boşsa mevcut şablon korunur'}>
          <div className="flex flex-col gap-1.5">
            {spec.time_template_ids.length > 0 && (
              <div className="flex flex-wrap gap-1">
                {spec.time_template_ids.map((id) => (
                  <span key={id} className="inline-flex items-center gap-1 rounded-[6px] bg-surface-2 px-1.5 py-0.5 text-[12px]">
                    {o.templates.find((t) => t.id === id)?.name ?? `#${id}`}
                    {manage && <button type="button" aria-label="Şablonu çıkar" onClick={() => onChange({ time_template_ids: spec.time_template_ids.filter((x) => x !== id) })}><X className="size-3 text-ink-3" /></button>}
                  </span>
                ))}
              </div>
            )}
            {manage && templateOptions.length > 0 && (
              <Select value="" aria-label="Şablon ekle" onChange={(e) => e.target.value && onChange({ time_template_ids: [...spec.time_template_ids, Number(e.target.value)] })}
                placeholder="Şablon ekle…" options={templateOptions.map((t) => ({ value: t.id, label: `${t.name} (${t.slots})` }))} />
            )}
          </div>
        </Field>
      </div>
      <button type="button" onClick={() => setMore((v) => !v)} className="flex w-full items-center gap-1 border-t border-line px-3 py-1.5 text-left text-[12px] font-medium text-ink-3 hover:text-ink">
        <ChevronDown className={cn('size-3.5 transition-transform', more && 'rotate-180')} />
        Derslik, rehber öğretmen, kısa ad, renk
        {(spec.homeroom_classroom_id || spec.advisor_teacher_id || spec.short_name || spec.color) && <span className="text-ink-3">· ayarlı</span>}
      </button>
      {more && (
        <div className="grid gap-3 border-t border-line p-3 sm:grid-cols-2 xl:grid-cols-4">
          <Field label="Ana derslik">
            <Select value={spec.homeroom_classroom_id ?? ''} disabled={!manage} onChange={(e) => onChange({ homeroom_classroom_id: e.target.value ? Number(e.target.value) : null })}
              options={[{ value: '', label: 'Değiştirme' }, ...o.classrooms.map((r) => ({ value: r.id, label: `${r.name} (${r.capacity})`, disabled: r.capacity < spec.capacity }))]} />
          </Field>
          <Field label="Sınıf rehber öğretmeni">
            <Select value={spec.advisor_teacher_id ?? ''} disabled={!manage} onChange={(e) => onChange({ advisor_teacher_id: e.target.value ? Number(e.target.value) : null })}
              options={[{ value: '', label: 'Değiştirme' }, ...o.teachers.map((t) => ({ value: t.id, label: t.name }))]} />
          </Field>
          <Field label="Kısa ad">
            <Input maxLength={20} placeholder={sectionName(grade, spec.code).replace('-', '')} disabled={!manage} value={spec.short_name ?? ''} onChange={(e) => onChange({ short_name: e.target.value || null })} />
          </Field>
          <Field label="Renk">
            <Select value={spec.color ?? ''} disabled={!manage} onChange={(e) => onChange({ color: e.target.value || null })}
              options={[{ value: '', label: 'Değiştirme' }, ...o.colors.map((c) => ({ value: c, label: COLOR_LABELS[c] ?? c }))]} />
          </Field>
        </div>
      )}
    </div>
  )
}

function TrackCurricula({ data, manage }: { data: StructurePayload; manage: boolean }) {
  const qc = useQueryClient()
  const tracks = Object.keys(data.options.tracks)
  const [draft, setDraft] = useState(() => clone(data.track_curricula))
  useEffect(() => setDraft(clone(data.track_curricula)), [data.track_curricula])
  const subjects = data.options.subjects.filter((s) => s.code)
  const dirty = JSON.stringify(draft) !== JSON.stringify(data.track_curricula)
  const save = useMutation({
    mutationFn: () => api.put<{ message: string }>('/class-structure/track-curricula', { curricula: draft }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['class-structure'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })
  if (subjects.length === 0) return <EmptyState compact icon={<LayoutGrid />} title="Ders kodu tanımlı ders yok" />

  return (
    <div className="border-t border-line">
      <div className="overflow-x-auto scroll-thin">
        <table className="tbl w-full min-w-[640px] text-left text-[13px]">
          <thead>
            <tr className="border-b border-line text-[12px] uppercase tracking-[0.04em] text-ink-3">
              <th className="px-4 py-2 font-medium text-left">Ders</th>
              {tracks.map((t) => <th key={t} className="px-2 py-2 font-medium text-center">{data.options.tracks[t]}</th>)}
            </tr>
          </thead>
          <tbody>
            {subjects.map((s) => (
              <tr key={s.id} className="border-b border-line">
                <td className="px-4 py-1.5 font-medium text-left">{s.name}</td>
                {tracks.map((t) => (
                  <td key={t} className="px-2 py-1 text-center">
                    <Input type="number" min={0} max={20} aria-label={`${data.options.tracks[t]} ${s.name}`} className="mx-auto h-8 w-[60px] text-center" disabled={!manage}
                      value={draft[t]?.[s.code] ?? 0}
                      onChange={(e) => {
                        const v = Math.max(0, Math.min(20, Number(e.target.value) || 0))
                        const next = clone(draft)
                        next[t] = { ...(next[t] ?? {}) }
                        if (v > 0) next[t]![s.code] = v
                        else delete next[t]![s.code]
                        setDraft(next)
                      }} />
                  </td>
                ))}
              </tr>
            ))}
            <tr>
              <td className="px-4 py-2 font-semibold text-left">Toplam</td>
              {tracks.map((t) => <td key={t} className="px-2 py-2 font-semibold tabular text-center">{Object.values(draft[t] ?? {}).reduce((a, b) => a + b, 0)}</td>)}
            </tr>
          </tbody>
        </table>
      </div>
      {manage && (
        <div className="flex justify-end gap-2 border-t border-line px-4 py-2.5">
          <Button variant="ghost" disabled={!dirty} onClick={() => setDraft(clone(data.track_curricula))}>Vazgeç</Button>
          <Button variant="primary" disabled={!dirty} loading={save.isPending} onClick={() => save.mutate()}>Müfredatları kaydet</Button>
        </div>
      )}
    </div>
  )
}
