import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle, Bot, ListChecks, Play, RotateCcw, Save, SlidersHorizontal } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { num, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Checkbox, Field, Input, Segmented, Select, Switch } from '@/components/ui/form'
import { Alert, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { BotTabs } from './BotTabs'
import { CurriculumDrawer } from './CurriculumDrawer'
import { runTone, type BotClass, type BotOptions, type BotTeacher, type RunSummary, type WeightKey } from './types'

type TeacherDraft = { max: string; target: string; included: boolean }

export default function TimetableBotPage() {
  const can = useCan()
  const manage = can('schedule.manage')
  const viewCurriculum = can('academic.view')
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [termId, setTermId] = useState<number | null>(null)
  const options = useQuery({ queryKey: ['timetable', 'options', termId], queryFn: () => api.get<BotOptions>('/timetable/options', { term_id: termId ?? undefined }) })
  const runs = useQuery({
    queryKey: ['timetable', 'runs'],
    queryFn: () => api.get<{ data: RunSummary[] }>('/timetable/runs'),
    refetchInterval: (q) => (q.state.data?.data.some((r) => r.status === 'queued' || r.status === 'running') ? 2500 : false),
  })

  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [initialized, setInitialized] = useState(false)
  const [weights, setWeights] = useState<Record<WeightKey, number> | null>(null)
  const [maxPerDay, setMaxPerDay] = useState(2)
  const [timeLimit, setTimeLimit] = useState<'10' | '20' | '40'>('20')
  const [advanced, setAdvanced] = useState(false)
  const [levelFilter, setLevelFilter] = useState('all')
  const [trackFilter, setTrackFilter] = useState('all')
  const [sectionFilter, setSectionFilter] = useState('all')
  const [curriculumFor, setCurriculumFor] = useState<number | null>(null)
  const [drafts, setDrafts] = useState<Record<number, TeacherDraft>>({})
  const [onlyRelevant, setOnlyRelevant] = useState(true)

  const data = options.data
  useEffect(() => {
    if (!data) return
    if (!weights) setWeights({ ...data.defaults.weights })
    if (!initialized) {
      // Varsayılan seçim: sınıf yapısındaki sınıflar (yoksa hepsi)
      const structured = data.classes.filter((c) => c.structured)
      setSelected(new Set((structured.length ? structured : data.classes).map((c) => c.id)))
      setInitialized(true)
    }
    const next: Record<number, TeacherDraft> = {}
    data.teachers.forEach((t) => (next[t.id] = { max: t.max_weekly_hours?.toString() ?? '', target: t.target_weekly_hours?.toString() ?? '', included: t.in_timetable }))
    setDrafts(next)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data])

  const classes = useMemo(() => data?.classes ?? [], [data])
  const filtered = classes.filter((c) =>
    (levelFilter === 'all' || (levelFilter === 'other' ? c.level === null : String(c.level) === levelFilter))
    && (trackFilter === 'all' || (trackFilter === 'none' ? !c.track : c.track === trackFilter))
    && (sectionFilter === 'all' || (sectionFilter === 'none' ? !c.section : c.section === sectionFilter)),
  )
  const levelOptions = useMemo(() => {
    const m = new Map<string, string>()
    classes.forEach((c) => m.set(c.level === null ? 'other' : String(c.level), c.level === null ? 'Diğer' : c.level === 13 ? 'Mezun' : String(c.level)))
    return [...m.entries()].sort(([a], [b]) => (a === 'other' ? 1 : b === 'other' ? -1 : Number(a) - Number(b)))
  }, [classes])
  const trackOptions = useMemo(() => [...new Set(classes.map((c) => c.track).filter(Boolean) as string[])].sort(), [classes])
  const sectionOptions = useMemo(() => [...new Set(classes.map((c) => c.section).filter(Boolean) as string[])].sort(), [classes])

  const groups = useMemo(() => {
    const m = new Map<string, BotClass[]>()
    filtered.forEach((c) => {
      const key = c.level ? `${c.level}` : 'other'
      m.set(key, [...(m.get(key) ?? []), c])
    })
    return [...m.entries()].sort(([a], [b]) => (a === 'other' ? 1 : b === 'other' ? -1 : Number(a) - Number(b)))
  }, [filtered])

  const chosen = useMemo(() => classes.filter((c) => selected.has(c.id)), [classes, selected])
  const totalHours = chosen.reduce((s, c) => s + c.weekly_hours, 0)
  const problems = chosen.filter((c) => c.templates.length === 0 || c.template_slots < c.weekly_hours)

  // Seçili sınıfların ders talebi → öğretmen başına branş talebi
  const demand = useMemo(() => {
    const bySubject = new Map<number, number>()
    const byTeacher = new Map<number, number>()
    chosen.forEach((c) => c.demand.forEach(([s, h, t]) => {
      if (t) byTeacher.set(t, (byTeacher.get(t) ?? 0) + h)
      else bySubject.set(s, (bySubject.get(s) ?? 0) + h)
    }))
    return { bySubject, byTeacher }
  }, [chosen])
  const teacherDemand = (t: BotTeacher) => t.subjects.reduce((s, x) => s + (demand.bySubject.get(x.id) ?? 0), 0) + (demand.byTeacher.get(t.id) ?? 0)

  const teachers = data?.teachers ?? []
  const visibleTeachers = teachers.filter((t) => !onlyRelevant || teacherDemand(t) > 0 || (drafts[t.id] && !drafts[t.id]!.included))
  const dirtyTeachers = teachers.filter((t) => {
    const d = drafts[t.id]
    return d && (d.included !== t.in_timetable || d.max !== (t.max_weekly_hours?.toString() ?? '') || d.target !== (t.target_weekly_hours?.toString() ?? ''))
  })

  // Dahil öğretmeni olmayan dersler
  const uncovered = useMemo(() => {
    if (!data) return []
    const names = new Map<number, string>()
    data.teachers.forEach((t) => t.subjects.forEach((s) => names.set(s.id, s.name)))
    return [...demand.bySubject.entries()]
      .filter(([sid]) => !data.teachers.some((t) => drafts[t.id]?.included && t.subjects.some((s) => s.id === sid)))
      .map(([sid, h]) => ({ sid, name: names.get(sid) ?? `Ders #${sid}`, hours: h }))
  }, [data, demand, drafts])

  const toggle = (ids: number[], on: boolean) =>
    setSelected((prev) => {
      const next = new Set(prev)
      ids.forEach((id) => (on ? next.add(id) : next.delete(id)))
      return next
    })
  const setDraft = (id: number, patch: Partial<TeacherDraft>) => setDrafts((prev) => ({ ...prev, [id]: { ...prev[id]!, ...patch } }))

  const saveTeachers = useMutation({
    mutationFn: () => api.put<{ message: string }>('/timetable/teachers', {
      teachers: dirtyTeachers.map((t) => {
        const d = drafts[t.id]!
        return { id: t.id, in_timetable: d.included, max_weekly_hours: d.max === '' ? null : Number(d.max), target_weekly_hours: d.target === '' ? null : Number(d.target) }
      }),
    }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['timetable', 'options'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Öğretmen saatleri kaydedilemedi.'),
  })

  const run = useMutation({
    mutationFn: async () => {
      if (dirtyTeachers.length) await saveTeachers.mutateAsync()
      return api.post<{ id: number; message: string }>('/timetable/runs', {
        academic_term_id: data!.term_id, class_group_ids: [...selected],
        settings: { weights, max_per_day: maxPerDay, time_limit: Number(timeLimit), seed: data!.defaults.seed },
      })
    },
    onSuccess: (r) => {
      toast.success(r.message)
      navigate(`/program-botu/${r.id}`)
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Program botu başlatılamadı.'),
  })

  const running = runs.data?.data.find((r) => r.status === 'queued' || r.status === 'running')
  const includedCount = teachers.filter((t) => drafts[t.id]?.included).length
  const targetCount = teachers.filter((t) => drafts[t.id]?.included && drafts[t.id]?.target).length

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Program botu"
        description="Seçili sınıfların haftalık programını; zaman şablonu, sınıf müfredatı, öğretmen branşı ve haftalık saati, derslik kapasitesine göre çakışmasız hazırlar. Sonuç önce öneri olarak gelir."
      />
      <BotTabs active="bot" />

      {options.isLoading ? (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]"><Skeleton className="h-[420px] rounded-[var(--radius-lg)]" /><Skeleton className="h-[420px] rounded-[var(--radius-lg)]" /></div>
      ) : options.isError || !data ? (
        <Alert tone="danger">Seçenekler yüklenemedi.</Alert>
      ) : (
        <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
          <div className="flex min-w-0 flex-col gap-4">
            <Panel
              title="1. Sınıflar"
              description="Seçilen sınıflar birlikte planlanır; bir öğretmen iki sınıfta aynı anda olamaz. Seçilmeyen sınıfların programı sabit kalır."
              actions={data.terms.length > 1 && (
                <Select className="w-[150px]" aria-label="Dönem" value={data.term_id ?? ''} onChange={(e) => { setTermId(Number(e.target.value)); setInitialized(false) }} options={data.terms.map((t) => ({ value: t.id, label: t.name }))} />
              )}
              flush
            >
              <div className="flex flex-col gap-2.5 border-t border-line px-4 py-3">
                <FilterRow label="Seviye" value={levelFilter} onChange={setLevelFilter} options={[['all', 'Tümü'], ...levelOptions]} />
                {trackOptions.length > 0 && <FilterRow label="Alan" value={trackFilter} onChange={setTrackFilter} options={[['all', 'Tümü'], ...trackOptions.map((t) => [t, data.tracks[t] ?? t] as [string, string]), ['none', 'Alansız']]} />}
                {sectionOptions.length > 0 && <FilterRow label="Şube" value={sectionFilter} onChange={setSectionFilter} options={[['all', 'Tümü'], ...sectionOptions.map((s) => [s, s] as [string, string]), ['none', 'Şubesiz']]} />}
                <div className="flex flex-wrap items-center gap-2 pt-0.5">
                  <Button size="xs" onClick={() => toggle(filtered.map((c) => c.id), true)} disabled={filtered.length === 0}>Görünenleri seç ({filtered.length})</Button>
                  <Button size="xs" variant="ghost" onClick={() => toggle(filtered.map((c) => c.id), false)}>Görünenleri bırak</Button>
                  <Button size="xs" variant="ghost" onClick={() => setSelected(new Set())} disabled={selected.size === 0}>Seçimi temizle</Button>
                  <span className="ml-auto text-[12px] tabular text-ink-3">{selected.size} / {classes.length} seçili</span>
                </div>
              </div>
              {classes.length === 0 ? (
                <EmptyState compact icon={<Bot />} title="Bu dönemde aktif sınıf yok" description="Önce sınıf yapısını tanımlayıp sınıfları açın." action={<ButtonLink size="sm" variant="primary" to="/program-botu/sinif-yapisi">Sınıf yapısına git</ButtonLink>} />
              ) : filtered.length === 0 ? (
                <EmptyState compact icon={<ListChecks />} title="Filtreye uyan sınıf yok" />
              ) : (
                <div className="divide-y divide-line border-t border-line">
                  {groups.map(([level, list]) => {
                    const ids = list.map((c) => c.id)
                    const on = ids.filter((id) => selected.has(id)).length
                    const label = level === 'other' ? 'Diğer sınıflar' : list[0]?.level_label ?? `${level}. sınıf`
                    return (
                      <div key={level}>
                        <div className="flex items-center justify-between gap-2 bg-surface-2/50 px-4 py-2">
                          <Checkbox checked={on === ids.length} indeterminate={on > 0 && on < ids.length} onChange={(v) => toggle(ids, v)} label={<span className="text-[12.5px] font-medium text-ink-2">{label}{ids.length > 1 ? ` · ${ids.length} sınıf` : ''}</span>} />
                          <span className="text-[12px] tabular text-ink-3">{list.reduce((s, c) => s + c.weekly_hours, 0)} saat/hafta</span>
                        </div>
                        {list.map((c) => (
                          <ClassRow key={c.id} c={c} tracks={data.tracks} checked={selected.has(c.id)} onChange={(v) => toggle([c.id], v)} onCurriculum={viewCurriculum ? () => setCurriculumFor(c.id) : undefined} />
                        ))}
                      </div>
                    )
                  })}
                </div>
              )}
            </Panel>

            <Panel
              title="2. Öğretmenler ve haftalık saatleri"
              description="Üst sınır kesin kuraldır; hedef, botun öğretmenin haftalık yükünü yaklaştırmaya çalıştığı saattir. Dahil edilmeyen öğretmene yeni ders verilmez (mevcut dersleri korunur)."
              actions={manage && (
                <Button size="sm" variant={dirtyTeachers.length ? 'primary' : 'secondary'} icon={<Save className="size-3.5" />} disabled={dirtyTeachers.length === 0} loading={saveTeachers.isPending} onClick={() => saveTeachers.mutate()}>
                  Kaydet{dirtyTeachers.length ? ` (${dirtyTeachers.length})` : ''}
                </Button>
              )}
              flush
            >
              <div className="flex flex-wrap items-center gap-3 border-t border-line px-4 py-2.5">
                <Switch checked={onlyRelevant} onChange={setOnlyRelevant} label={<span className="text-[12.5px]">Yalnız seçili sınıflara ders verebilenler</span>} />
                <span className="ml-auto text-[12px] tabular text-ink-3">{includedCount} / {teachers.length} öğretmen dahil</span>
              </div>
              {uncovered.length > 0 && (
                <div className="px-4 pb-3">
                  <Alert tone="warning">Dahil öğretmeni olmayan dersler: {uncovered.map((u) => `${u.name} (${u.hours} saat)`).join(', ')} — bu dersler yerleşemez.</Alert>
                </div>
              )}
              {visibleTeachers.length === 0 ? (
                <EmptyState compact icon={<ListChecks />} title="Seçili sınıflara ders verebilen öğretmen yok" description="Öğretmenlerin branşlarını ders sayfasından bağlayın." />
              ) : (
                <div className="overflow-x-auto scroll-thin border-t border-line">
                  <table className="tbl w-full min-w-[680px] text-left text-[13px]">
                    <thead>
                      <tr className="border-b border-line text-[12px] uppercase tracking-[0.04em] text-ink-3">
                        <th className="w-[56px] px-4 py-2 font-medium text-left">Dahil</th>
                        <th className="fill px-2 py-2 font-medium text-center">Öğretmen</th>
                        <th className="px-2 py-2 font-medium text-center" title="Seçili sınıflarda bu öğretmenin branşına düşen haftalık saat (aynı branştaki öğretmenlerle paylaşılır)">Branş talebi</th>
                        <th className="px-2 py-2 font-medium text-center" title="Şu anki programdaki haftalık ders saati">Mevcut</th>
                        <th className="px-2 py-2 font-medium text-center">Üst sınır</th>
                        <th className="px-4 py-2 font-medium text-center">Hedef</th>
                      </tr>
                    </thead>
                    <tbody>
                      {visibleTeachers.map((t) => {
                        const d = drafts[t.id]
                        if (!d) return null
                        const dem = teacherDemand(t)
                        const invalid = d.target !== '' && d.max !== '' && Number(d.target) > Number(d.max)
                        return (
                          <tr key={t.id} className="border-b border-line last:border-0">
                            <td className="px-4 py-1.5 text-left">
                              <Checkbox checked={d.included} disabled={!manage} onChange={(v) => setDraft(t.id, { included: v })} label={<span className="sr-only">{t.name} bota dahil</span>} />
                            </td>
                            <td className="fill max-w-[280px] px-2 py-1.5 text-center">
                              <p className={cn('font-medium', d.included ? 'text-ink' : 'text-ink-3 line-through')}>{t.name}</p>
                              <p className="truncate text-[12px] text-ink-3">{t.subjects.map((s) => s.name).join(', ') || 'Branş bağlı değil'}{t.fixed_demand ? ` · ${t.fixed_demand} saat sabit atama` : ''}</p>
                            </td>
                            <td className="px-2 py-1.5 tabular text-ink-2 text-center">{dem || '—'}</td>
                            <td className="px-2 py-1.5 tabular text-ink-2 text-center">{t.current_load}</td>
                            <td className="px-2 py-1.5 text-center">
                              <Input type="number" min={0} max={60} placeholder="40" aria-label={`${t.name} haftalık üst sınır`} className="h-8 w-[76px]" disabled={!manage}
                                value={d.max} onChange={(e) => setDraft(t.id, { max: e.target.value })} />
                            </td>
                            <td className="px-4 py-1.5 text-center">
                              <Input type="number" min={0} max={60} placeholder="—" aria-label={`${t.name} haftalık hedef`} className="h-8 w-[76px]" disabled={!manage || !d.included} invalid={invalid}
                                value={d.target} onChange={(e) => setDraft(t.id, { target: e.target.value })} />
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </Panel>
          </div>

          <div className="flex flex-col gap-4 lg:sticky lg:top-4">
            <Panel title="3. Çalıştır">
              <div className="flex flex-col gap-4">
                <div className="grid grid-cols-2 gap-3 text-[13px]">
                  <div><p className="text-[12px] text-ink-3">Seçili sınıf</p><p className="text-[20px] font-semibold tabular">{selected.size}</p></div>
                  <div><p className="text-[12px] text-ink-3">Yerleşecek ders saati</p><p className="text-[20px] font-semibold tabular">{num(totalHours)}</p></div>
                  <div><p className="text-[12px] text-ink-3">Dahil öğretmen</p><p className="text-[20px] font-semibold tabular">{includedCount}</p></div>
                  <div><p className="text-[12px] text-ink-3">Hedef saatli</p><p className="text-[20px] font-semibold tabular">{targetCount}</p></div>
                </div>
                <Field label="Aynı ders aynı gün en fazla" hint="Sınıf müfredatında derse özel sınır verilmişse o geçerlidir.">
                  <Segmented value={String(maxPerDay)} onChange={(v) => setMaxPerDay(Number(v))} options={['1', '2', '3', '4'].map((v) => ({ value: v, label: `${v} saat` }))} />
                </Field>
                <Field label="Arama süresi" hint="Süre uzadıkça tercih puanı iyileşir; çakışma kuralları her durumda korunur.">
                  <Segmented value={timeLimit} onChange={setTimeLimit} options={[{ value: '10', label: '10 sn' }, { value: '20', label: '20 sn' }, { value: '40', label: '40 sn' }]} />
                </Field>

                <button type="button" onClick={() => setAdvanced((v) => !v)} className="inline-flex items-center gap-1.5 self-start text-[12.5px] font-medium text-ink-2 hover:text-ink">
                  <SlidersHorizontal className="size-3.5" /> Tercih ağırlıkları {advanced ? 'gizle' : 'göster'}
                </button>
                {advanced && weights && (
                  <div className="flex flex-col gap-3">
                    {(Object.keys(data.weight_labels) as WeightKey[]).map((k) => (
                      <label key={k} className="flex flex-col gap-1">
                        <span className="flex items-center justify-between text-[12.5px]"><span className="text-ink-2">{data.weight_labels[k]}</span><span className="tabular text-ink-3">{weights[k] ?? 0}</span></span>
                        <input type="range" min={0} max={20} step={1} value={weights[k] ?? 0} onChange={(e) => setWeights({ ...weights, [k]: Number(e.target.value) })} className="w-full accent-[var(--ink-2)]" />
                      </label>
                    ))}
                    <Button size="xs" variant="ghost" className="self-start" icon={<RotateCcw className="size-3.5" />} onClick={() => setWeights({ ...data.defaults.weights })}>Varsayılana dön</Button>
                  </div>
                )}

                {problems.length > 0 && (
                  <Alert tone="warning" title={`${problems.length} sınıfta şablon eksik ya da yetersiz`}>
                    {problems.slice(0, 4).map((c) => c.name).join(', ')}{problems.length > 4 ? '…' : ''} — bu sınıfların bazı dersleri yerleşemeyecek. <Link to="/program-botu/sablonlar" className="underline">Şablonlar</Link> · <Link to="/program-botu/sinif-yapisi" className="underline">Sınıf yapısı</Link>
                  </Alert>
                )}
                {dirtyTeachers.length > 0 && <Alert tone="info">{dirtyTeachers.length} öğretmenin saat ayarı kaydedilmedi; bot çalıştırılınca önce kaydedilir.</Alert>}
                {running && <Alert tone="info">Çalışma #{running.id} sürüyor. <Link to={`/program-botu/${running.id}`} className="underline">İzle</Link></Alert>}

                {manage ? (
                  <Button variant="primary" icon={<Play className="size-4" />} disabled={selected.size === 0 || !!running} loading={run.isPending} onClick={() => run.mutate()}>Botu çalıştır</Button>
                ) : (
                  <Alert tone="neutral">Botu çalıştırmak için ders programı düzenleme yetkisi gerekir.</Alert>
                )}
                <p className="text-[12px] text-ink-3">Çalıştırmak programı değiştirmez. Öneriyi inceledikten sonra uygulayabilir, uyguladıktan sonra da geri alabilirsiniz.</p>
              </div>
            </Panel>
          </div>
        </div>
      )}

      <Panel title="Son çalıştırmalar" className="mt-4" flush>
        {runs.isLoading ? (
          <div className="flex flex-col gap-2 p-4">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-12" />)}</div>
        ) : !runs.data?.data.length ? (
          <EmptyState compact icon={<Bot />} title="Henüz çalıştırma yok" description="Sınıfları seçip botu çalıştırdığınızda öneriler burada listelenir." />
        ) : (
          <ul className="divide-y divide-line border-t border-line">
            {runs.data.data.map((r) => (
              <li key={r.id}>
                <Link to={`/program-botu/${r.id}`} className="flex flex-col gap-2 px-4 py-3 transition-colors hover:bg-surface-2/60 sm:flex-row sm:items-center sm:gap-4">
                  <div className="flex min-w-0 flex-1 items-center gap-3">
                    <span className="w-10 shrink-0 text-[12.5px] tabular text-ink-3">#{r.id}</span>
                    <div className="min-w-0">
                      <p className="truncate text-[13.5px] font-medium">{r.classes.length > 4 ? `${r.classes.slice(0, 4).join(', ')} +${r.classes.length - 4}` : r.classes.join(', ')}</p>
                      <p className="text-[12px] text-ink-3">{relative(r.created_at)}{r.created_by ? ` · ${r.created_by}` : ''}{r.apply_from ? ` · ${r.apply_from} itibarıyla` : ''}</p>
                    </div>
                  </div>
                  {r.status === 'queued' || r.status === 'running' ? (
                    <div className="w-full sm:w-[220px]"><ProgressBar value={r.progress} /><p className="mt-1 text-[12px] text-ink-3">{r.status_label} · %{r.progress}</p></div>
                  ) : r.required > 0 ? (
                    <div className="flex items-center gap-4 text-[12.5px] tabular text-ink-2">
                      <span>{r.placed}/{r.required} ders</span>
                      {r.unplaced > 0 && <span className="inline-flex items-center gap-1 text-warning"><AlertTriangle className="size-3.5" />{r.unplaced}</span>}
                      <span>Kalite %{r.quality ?? '—'}</span>
                    </div>
                  ) : null}
                  <Badge tone={runTone[r.status]} className="self-start sm:self-auto">{r.status_label}</Badge>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      {curriculumFor && <CurriculumDrawer classId={curriculumFor} onClose={() => setCurriculumFor(null)} />}
    </div>
  )
}

function FilterRow({ label, value, onChange, options }: { label: string; value: string; onChange: (v: string) => void; options: [string, string][] }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <span className="w-12 shrink-0 text-[12px] text-ink-3">{label}</span>
      {options.map(([v, l]) => (
        <button
          key={v}
          type="button"
          aria-pressed={value === v}
          onClick={() => onChange(v)}
          className={cn('h-7 rounded-full px-2.5 text-[12px] font-medium ring-1 transition-colors', value === v ? 'bg-ink text-bg ring-ink' : 'bg-surface text-ink-2 ring-line hover:bg-surface-2')}
        >
          {l}
        </button>
      ))}
    </div>
  )
}

function ClassRow({ c, tracks, checked, onChange, onCurriculum }: { c: BotClass; tracks: Record<string, string>; checked: boolean; onChange: (v: boolean) => void; onCurriculum?: () => void }) {
  const short = c.templates.length === 0 ? 'Zaman şablonu atanmamış' : c.template_slots < c.weekly_hours ? `Şablonda ${c.template_slots} dilim var, ${c.weekly_hours} saat gerekiyor` : null
  return (
    <div className={cn('flex flex-col gap-1 px-4 py-2.5 sm:flex-row sm:items-center sm:gap-4', !checked && 'opacity-70')}>
      <Checkbox checked={checked} onChange={onChange} label={<span className="font-medium">{c.name}</span>} className="shrink-0 sm:w-[130px]" />
      <div className="min-w-0 flex-1 text-[12.5px] text-ink-3">
        <p className="flex min-w-0 items-center gap-1.5">
          {c.track && <Badge>{tracks[c.track] ?? c.track}</Badge>}
          <span className="truncate">{c.program ?? '—'} · {c.size}/{c.capacity} öğrenci{c.homeroom ? ` · ${c.homeroom}` : ''}</span>
        </p>
        <p className={cn('truncate', short && 'text-warning')}>{short ?? c.templates.map((t) => t.name).join(' + ')}</p>
      </div>
      <div className="flex shrink-0 items-center gap-3 text-[12px] tabular text-ink-3">
        <span>{c.weekly_hours} saat{c.custom_hours ? ' · özel' : ''}</span>
        <span>{c.lessons} mevcut{c.locked ? ` · ${c.locked} kilitli` : ''}</span>
        {onCurriculum && <Button size="xs" variant="ghost" onClick={onCurriculum}>Ders saatleri</Button>}
      </div>
    </div>
  )
}
