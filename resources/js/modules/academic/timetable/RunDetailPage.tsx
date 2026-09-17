import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle, CheckCircle2, ListChecks, RotateCcw, Undo2, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, num, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat, Tabs } from '@/components/ui/layout'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Segmented, Select } from '@/components/ui/form'
import { Alert, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import { addDays, WEEKDAY_SHORT } from '../types'
import { DiffLegend, ProposalGrid } from './ProposalGrid'
import { runTone, type ProposalItem, type RunDetail } from './types'

type Tab = 'classes' | 'issues' | 'loads' | 'score' | 'log'

export default function RunDetailPage() {
  const { id } = useParams()
  const can = useCan()
  const manage = can('schedule.manage')
  const qc = useQueryClient()
  const q = useQuery({
    queryKey: ['timetable', 'run', id],
    queryFn: () => api.get<RunDetail>(`/timetable/runs/${id}`),
    refetchInterval: (s) => (s.state.data && ['queued', 'running'].includes(s.state.data.run.status) ? 1500 : false),
  })
  const [tab, setTab] = useState<Tab>('classes')
  const [classId, setClassId] = useState<number | null>(null)
  const [view, setView] = useState<'class' | 'teacher'>('class')
  const [teacherId, setTeacherId] = useState<number | null>(null)
  const [applyOpen, setApplyOpen] = useState(false)
  const [applyFrom, setApplyFrom] = useState(addDays(todayISO(), 1))
  const [confirm, setConfirm] = useState<'discard' | 'rollback' | null>(null)

  const data = q.data
  const preview = data?.preview
  useEffect(() => {
    if (preview && !classId && preview.classes[0]) setClassId(preview.classes[0].id)
  }, [preview, classId])

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['timetable'] })
    qc.invalidateQueries({ queryKey: ['schedule'] })
    qc.invalidateQueries({ queryKey: ['calendar'] })
  }
  const onError = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem tamamlanamadı.')
  const apply = useMutation({ mutationFn: () => api.post<{ message: string }>(`/timetable/runs/${id}/apply`, { apply_from: applyFrom }), onSuccess: (r) => { toast.success(r.message); setApplyOpen(false); refresh() }, onError })
  const discard = useMutation({ mutationFn: () => api.post<{ message: string }>(`/timetable/runs/${id}/discard`), onSuccess: (r) => { toast.success(r.message); setConfirm(null); refresh() }, onError })
  const rollback = useMutation({ mutationFn: () => api.post<{ message: string }>(`/timetable/runs/${id}/rollback`), onSuccess: (r) => { toast.success(r.message); setConfirm(null); refresh() }, onError })

  // Öğretmen görünümü: tüm sınıflardaki önerilen dersler + seçilmeyen sınıflardaki mevcut dersleri
  const teacherView = useMemo(() => {
    const byTeacher = new Map<number, { items: ProposalItem[]; locked: ProposalItem[] }>()
    const get = (id: number) => {
      if (!byTeacher.has(id)) byTeacher.set(id, { items: [], locked: [] })
      return byTeacher.get(id)!
    }
    preview?.classes.forEach((c) => {
      c.items.forEach((i) => get(i.teacher.id).items.push({ ...i, class_name: c.name }))
      c.locked.forEach((i) => get(i.teacher.id).locked.push({ ...i, class_name: c.name }))
    })
    preview?.external?.forEach((e) => get(e.teacher).locked.push({
      weekday: e.weekday, start: e.start, end: e.end, class_name: e.class_name, external: true,
      subject: { id: 0, name: e.subject_name }, teacher: { id: e.teacher, name: null }, room: { id: 0, name: e.room_name },
    }))
    return byTeacher
  }, [preview])
  const teacherOptions = useMemo(() => (preview?.teacher_loads ?? []).filter((t) => t.total > 0).map((t) => ({ value: t.teacher, label: `${t.name ?? '#' + t.teacher} · ${t.total} saat` })), [preview])
  useEffect(() => {
    if (!teacherId && teacherOptions[0]) setTeacherId(Number(teacherOptions[0].value))
  }, [teacherOptions, teacherId])

  const totals = useMemo(() => {
    const t = { added: 0, changed: 0, same: 0, removed: 0 }
    preview?.classes.forEach((c) => (Object.keys(t) as (keyof typeof t)[]).forEach((k) => (t[k] += c.counts[k])))
    return t
  }, [preview])

  if (q.isLoading) return <div className="flex flex-col gap-4"><Skeleton className="h-10 w-72" /><Skeleton className="h-24" /><Skeleton className="h-[420px]" /></div>
  if (q.isError || !data) return <EmptyState title="Program önerisi bulunamadı" description={q.error instanceof ApiError && q.error.status !== 404 ? q.error.message : 'Öneri silinmiş ya da adres hatalı olabilir.'} action={<ButtonLink to="/program-botu">Program botuna dön</ButtonLink>} />

  const { run } = data
  const busy = run.status === 'queued' || run.status === 'running'
  const current = preview?.classes.find((c) => c.id === classId)

  return (
    <div className="animate-fade-in">
      <PageHeader
        breadcrumbs={[{ label: 'Program botu', to: '/program-botu' }, { label: `Öneri #${run.id}` }]}
        title={<span className="flex flex-wrap items-center gap-2.5">Öneri #{run.id}<Badge tone={runTone[run.status]}>{run.status_label}</Badge></span>}
        description={`${run.classes.join(', ')} · ${dateTime(run.created_at)}${run.created_by ? ` · ${run.created_by}` : ''}`}
        actions={manage && (
          <>
            {run.status === 'completed' && <Button variant="ghost" icon={<X className="size-4" />} onClick={() => setConfirm('discard')}>Vazgeç</Button>}
            {run.status === 'completed' && <Button variant="primary" icon={<CheckCircle2 className="size-4" />} disabled={run.placed === 0 || run.hard_violations > 0} onClick={() => setApplyOpen(true)}>Uygula</Button>}
            {run.status === 'applied' && <Button icon={<Undo2 className="size-4" />} onClick={() => setConfirm('rollback')}>Geri al</Button>}
            {['failed', 'discarded', 'rolled_back'].includes(run.status) && <ButtonLink to="/program-botu" icon={<RotateCcw className="size-4" />}>Yeniden çalıştır</ButtonLink>}
          </>
        )}
      />

      {busy && (
        <Panel title={run.status === 'queued' ? 'Sırada' : 'Çalışıyor'} description={run.status === 'queued' ? 'Kuyruk işçisi en geç bir dakika içinde başlatır.' : 'Kısıt yayılımlı yerleştirme ve ardından yerel arama ile iyileştirme yapılıyor.'} className="mb-4">
          <ProgressBar value={run.progress} />
          <p className="mt-1.5 text-[12px] tabular text-ink-3">%{run.progress}</p>
          <LogList log={run.log} className="mt-3" />
        </Panel>
      )}

      {run.status === 'failed' && (
        <>
          <Alert tone="danger" title="Çalışma başarısız" className="mb-4">{run.error ?? 'Beklenmeyen bir sorun oluştu.'}</Alert>
          <Panel title="Günlük"><LogList log={run.log} /></Panel>
        </>
      )}

      {preview && (
        <>
          {run.status === 'applied' && run.snapshot && (
            <Alert tone="success" className="mb-4" title={`${run.apply_from} tarihinden itibaren uygulandı`}>
              {run.snapshot.created} ders saati yazıldı, {run.snapshot.ended} eski şablon sonlandırıldı. Geçmiş ve yoklaması alınmış dersler korundu. Geri alırsanız önceki program geri yüklenir.
            </Alert>
          )}
          {run.status === 'rolled_back' && <Alert tone="neutral" className="mb-4">Bu öneri uygulanmış ve geri alınmıştır; önceki program geri yüklendi.</Alert>}

          <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
            <Stat label="Yerleşen ders saati" value={`${num(run.placed)} / ${num(run.required)}`} sub={run.required ? `%${Math.round((run.placed / run.required) * 100)}` : undefined} />
            <Stat label="Yerleşemeyen" value={num(run.unplaced)} tone={run.unplaced > 0 ? 'warning' : undefined} sub={run.unplaced > 0 ? 'Sorunlar sekmesine bakın' : 'Tamamı yerleşti'} />
            <Stat label="Çakışma" value={num(run.hard_violations)} tone={run.hard_violations > 0 ? 'danger' : undefined} sub="Öğretmen / derslik / sınıf" />
            <Stat label="Kalite" value={`%${run.quality ?? '—'}`} sub={`Ceza ${num(preview.stats.initial_penalty, 1)} → ${num(run.penalty ?? 0, 1)}`} />
            <Stat label="Süre" value={`${num((run.duration_ms ?? 0) / 1000, 1)} sn`} sub={`${num(preview.stats.iterations)} hamle`} className="col-span-2 lg:col-span-1" />
          </div>

          {preview.violations.length > 0 && (
            <Alert tone="danger" title="Sert kısıt ihlali — uygulanamaz" className="mb-4"><ul className="list-disc pl-4">{preview.violations.map((v, i) => <li key={i}>{v}</li>)}</ul></Alert>
          )}
          {preview.warnings.length > 0 && (
            <Alert tone="neutral" title="Bilgi" className="mb-4"><ul className="list-disc pl-4">{preview.warnings.map((w, i) => <li key={i}>{w}</li>)}</ul></Alert>
          )}

          <Tabs<Tab>
            className="mb-4"
            value={tab}
            onChange={setTab}
            tabs={[
              { value: 'classes', label: 'Sınıf programları' },
              { value: 'issues', label: 'Sorunlar', count: preview.unplaced.length },
              { value: 'loads', label: 'Öğretmen yükü' },
              { value: 'score', label: 'Puan kırılımı' },
              { value: 'log', label: 'Günlük' },
            ]}
          />

          {tab === 'classes' && (
            <div className="flex flex-col gap-3">
              <div className="flex flex-wrap items-center gap-3">
                <Segmented value={view} onChange={setView} options={[{ value: 'class', label: 'Sınıf' }, { value: 'teacher', label: 'Öğretmen' }]} />
                {view === 'class' ? (
                  <Select className="w-full sm:w-[260px]" aria-label="Sınıf" value={classId ?? ''} onChange={(e) => setClassId(Number(e.target.value))} options={preview.classes.map((c) => ({ value: c.id, label: `${c.name}${c.unplaced.length ? ' · eksik var' : ''}` }))} />
                ) : (
                  <Select className="w-full sm:w-[260px]" aria-label="Öğretmen" value={teacherId ?? ''} onChange={(e) => setTeacherId(Number(e.target.value))} options={teacherOptions} placeholder={teacherOptions.length ? undefined : 'Öğretmen yok'} />
                )}
                {view === 'class' && current && run.status === 'completed' && (
                  <p className="text-[12.5px] tabular text-ink-3">{current.counts.added} yeni · {current.counts.changed} değişen · {current.counts.same} aynı · {current.counts.removed} kaldırılacak</p>
                )}
                {run.status === 'completed' && <div className="sm:ml-auto"><DiffLegend /></div>}
              </div>
              {view === 'teacher' && teacherId && (
                <>
                  <ProposalGrid mode="teacher" items={teacherView.get(teacherId)?.items ?? []} locked={teacherView.get(teacherId)?.locked ?? []} showDiff={run.status === 'completed'} />
                  <p className="text-[12px] text-ink-3">Soluk kartlar öğretmenin seçilmeyen sınıflardaki mevcut dersleridir; bot bunlara dokunmaz ve çakışma üretmez.</p>
                </>
              )}
              {view === 'class' && current && (
                <>
                  <ProposalGrid items={current.items} locked={current.locked} showDiff={run.status === 'completed'} />
                  {current.unplaced.map((u, i) => <Alert key={i} tone="warning">{u.message}</Alert>)}
                  {current.removed.length > 0 && (
                    <Panel title="Mevcut programdan kalkacak dersler" description="Uygulanınca bu saatler yeni programla değişir (geçmiş dersler korunur).">
                      <ul className="flex flex-wrap gap-1.5">
                        {current.removed.map((r, i) => <li key={i} className="rounded-[6px] border border-line bg-surface-2/60 px-2 py-1 text-[12px] text-ink-2"><span className="tabular">{WEEKDAY_SHORT[r.weekday]} {r.start}</span> · {r.subject.name} · {r.teacher.name}</li>)}
                      </ul>
                    </Panel>
                  )}
                </>
              )}
            </div>
          )}

          {tab === 'issues' && (
            preview.unplaced.length === 0 ? (
              <Panel><EmptyState compact icon={<ListChecks />} title="Sorun yok" description="Tüm ders saatleri sert kısıtlara uyarak yerleşti." /></Panel>
            ) : (
              <div className="flex flex-col gap-3">
                {preview.unplaced.map((u, i) => (
                  <Panel key={i}>
                    <div className="flex items-start gap-3">
                      <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
                      <div className="min-w-0 flex-1">
                        <p className="text-[13.5px] text-ink">{u.message}</p>
                        <ul className="mt-2 flex flex-col gap-1 text-[12.5px] text-ink-2">{u.suggestions.map((s, k) => <li key={k}>→ {s}</li>)}</ul>
                        <div className="mt-3 flex flex-wrap gap-2">
                          {['template_short', 'no_template', 'class_full', 'room_conflict', 'teacher_conflict'].includes(u.code) && <ButtonLink size="xs" to="/program-botu/sablonlar">Zaman şablonları</ButtonLink>}
                          {['teacher_unavailable', 'teacher_conflict'].includes(u.code) && <ButtonLink size="xs" to="/etut/uygunluk">Öğretmen uygunluğu</ButtonLink>}
                          {['no_teacher', 'teacher_max', 'teacher_conflict'].includes(u.code) && <ButtonLink size="xs" to={`/akademik/dersler/${u.subject}`}>{u.subject_name ?? 'Ders'} sayfası</ButtonLink>}
                        </div>
                      </div>
                    </div>
                  </Panel>
                ))}
              </div>
            )
          )}

          {tab === 'loads' && (
            <Panel flush>
              <div className="overflow-x-auto scroll-thin">
                <table className="tbl w-full min-w-[720px] text-left text-[13px]">
                  <thead><tr className="border-b border-line text-[12px] uppercase tracking-[0.04em] text-ink-3">
                    <th className="px-4 py-2 font-medium text-left">Öğretmen</th><th className="px-2 py-2 font-medium text-center">Haftalık yük / üst sınır</th><th className="px-2 py-2 font-medium text-center">Hedef</th>
                    {[1, 2, 3, 4, 5, 6, 7].map((d) => <th key={d} className="px-2 py-2 font-medium text-center">{WEEKDAY_SHORT[d]}</th>)}
                    <th className="px-4 py-2 font-medium text-center">Boş saat</th>
                  </tr></thead>
                  <tbody>
                    {preview.teacher_loads.map((t) => (
                      <tr key={t.teacher} className="border-b border-line last:border-0">
                        <td className="px-4 py-2 font-medium text-left">{t.name}</td>
                        <td className="px-2 py-2 w-[180px] text-center">
                          <div className="flex items-center gap-2"><ProgressBar value={(t.total / Math.max(1, t.max)) * 100} tone={t.total >= t.max ? 'warning' : 'neutral'} className="w-20" /><span className="tabular text-ink-2">{t.total}/{t.max}</span></div>
                          <p className="text-[12px] text-ink-3">{t.units} saat bu öneride{t.fixed > 0 ? ` · ${t.fixed} saat diğer sınıflarda` : ''}</p>
                        </td>
                        <td className="px-2 py-2 tabular text-center">
                          {t.target ? (
                            <span className={cn(t.total === t.target ? 'text-success' : 'text-ink-2')} title={t.total === t.target ? 'Hedefte' : `Hedeften ${t.total > t.target ? 'fazla' : 'eksik'}`}>
                              {t.target}{t.total !== t.target && <span className="ml-1 text-[12px] text-ink-3">({t.total > t.target ? '+' : ''}{t.total - t.target})</span>}
                            </span>
                          ) : <span className="text-ink-3">—</span>}
                        </td>
                        {[1, 2, 3, 4, 5, 6, 7].map((d) => <td key={d} className={cn('px-2 py-2 tabular text-center', t.per_day[d] ? 'text-ink' : 'text-ink-3')}>{t.per_day[d] ?? '·'}</td>)}
                        <td className={cn('px-4 py-2 tabular text-center', t.gap_hours > 0 ? 'text-ink' : 'text-ink-3')}>{t.gap_hours > 0 ? `${num(t.gap_hours, 1)} sa` : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          )}

          {tab === 'score' && (
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
              <Panel title="Yumuşak kısıt cezaları" description="Düşük daha iyi. Ağırlıkları bot sayfasında değiştirebilirsiniz." flush>
                <div className="overflow-x-auto scroll-thin">
                <table className="tbl w-full text-left text-[13px]">
                  <thead><tr className="border-y border-line text-[12px] uppercase tracking-[0.04em] text-ink-3"><th className="px-4 py-2 font-medium text-left">Kısıt</th><th className="px-2 py-2 font-medium text-center">Ham</th><th className="px-2 py-2 font-medium text-center">Ağırlık</th><th className="px-4 py-2 font-medium text-center">Ceza</th></tr></thead>
                  <tbody>
                    {Object.entries(preview.components).map(([k, c]) => (
                      <tr key={k} className="border-b border-line last:border-0"><td className="px-4 py-2 text-left">{c.label}</td><td className="px-2 py-2 tabular text-ink-2 text-center">{num(c.raw, 1)}</td><td className="px-2 py-2 tabular text-ink-3 text-center">×{c.weight}</td><td className="px-4 py-2 tabular font-medium text-center">{num(c.weighted, 1)}</td></tr>
                    ))}
                    <tr className="border-t border-line"><td className="px-4 py-2 font-semibold text-left" colSpan={3}>Toplam</td><td className="px-4 py-2 tabular font-semibold text-center">{num(run.penalty ?? 0, 1)}</td></tr>
                  </tbody>
                </table>
                </div>
              </Panel>
              <Panel title="Çözücü">
                <dl className="grid grid-cols-2 gap-y-2 text-[12.5px]">
                  {[
                    ['Kurulum', `${num(preview.stats.construction_ms)} ms`], ['İyileştirme', `${num(preview.stats.search_ms)} ms`],
                    ['Denenen hamle', num(preview.stats.iterations)], ['Kabul edilen', num(preview.stats.accepted)],
                    ['Geri izleme (çıkarma)', num(preview.stats.ejections)], ['Onarım', num(preview.stats.repairs)],
                    ['Başlangıç cezası', num(preview.stats.initial_penalty, 1)], ['Son ceza', num(run.penalty ?? 0, 1)],
                    ['Günlük üst sınır', `${run.settings.max_per_day} saat`], ['Tohum', String(run.settings.seed)],
                  ].map(([l, v]) => <div key={l} className="contents"><dt className="text-ink-3">{l}</dt><dd className="text-right tabular">{v}</dd></div>)}
                </dl>
              </Panel>
            </div>
          )}

          {tab === 'log' && <Panel><LogList log={run.log} /></Panel>}
        </>
      )}

      <Modal
        open={applyOpen}
        onClose={() => setApplyOpen(false)}
        title="Öneriyi uygula"
        description="Seçili sınıfların kilitsiz şablonları bu tarihten itibaren yeni programla değiştirilir."
        footer={<><Button variant="ghost" onClick={() => setApplyOpen(false)}>Vazgeç</Button><Button variant="primary" loading={apply.isPending} onClick={() => apply.mutate()}>Uygula</Button></>}
      >
        <div className="flex flex-col gap-4">
          <Field label="Geçerlilik başlangıcı" hint="En erken yarın. Bugünkü ve geçmiş dersler, yoklamalar ve iptaller korunur.">
            <Input type="date" min={addDays(todayISO(), 1)} value={applyFrom} onChange={(e) => setApplyFrom(e.target.value)} />
          </Field>
          <ul className="grid grid-cols-2 gap-2 text-[13px]">
            <li className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2"><span className="block text-[12px] text-ink-3">Sınıf</span><span className="font-semibold tabular">{run.classes.length}</span></li>
            <li className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2"><span className="block text-[12px] text-ink-3">Yazılacak ders saati</span><span className="font-semibold tabular">{run.placed}</span></li>
            <li className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2"><span className="block text-[12px] text-ink-3">Yeni / değişen</span><span className="font-semibold tabular">{totals.added} / {totals.changed}</span></li>
            <li className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2"><span className="block text-[12px] text-ink-3">Kalkacak</span><span className="font-semibold tabular">{totals.removed}</span></li>
          </ul>
          {run.unplaced > 0 && <Alert tone="warning">{run.unplaced} ders saati yerleşemedi; uygulanırsa bu saatler programda eksik kalır.</Alert>}
          <p className="text-[12.5px] text-ink-3">Gelecek oturumlar yeniden üretilir. Uyguladıktan sonra bu sayfadan geri alabilirsiniz.</p>
        </div>
      </Modal>

      <ConfirmDialog
        open={confirm === 'discard'}
        onClose={() => setConfirm(null)}
        onConfirm={() => discard.mutate()}
        loading={discard.isPending}
        title="Öneriden vazgeç"
        description="Program değişmez; öneri geçmişte kalır."
        confirmLabel="Vazgeç"
      />
      <ConfirmDialog
        open={confirm === 'rollback'}
        onClose={() => setConfirm(null)}
        onConfirm={() => rollback.mutate()}
        loading={rollback.isPending}
        danger
        title="Uygulamayı geri al"
        description="Bu öneriyle yazılan şablonlar kaldırılır, önceki program yarından itibaren geri yüklenir. Yoklaması alınmış dersler korunur."
        confirmLabel="Geri al"
      />
    </div>
  )
}

function LogList({ log, className }: { log: { t: string; m: string }[]; className?: string }) {
  const ref = useRef<HTMLOListElement>(null)
  useEffect(() => {
    if (ref.current) ref.current.scrollTop = ref.current.scrollHeight
  }, [log.length])
  if (!log.length) return <p className={cn('text-[12.5px] text-ink-3', className)}>Günlük boş.</p>
  return (
    <ol ref={ref} className={cn('flex max-h-[320px] flex-col gap-1 overflow-y-auto scroll-thin text-[12.5px]', className)}>
      {log.map((l, i) => <li key={i} className="flex gap-3"><span className="w-[60px] shrink-0 tabular text-ink-3">{l.t}</span><span className="text-ink-2">{l.m}</span></li>)}
    </ol>
  )
}
