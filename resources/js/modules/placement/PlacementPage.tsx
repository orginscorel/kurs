import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { DndContext, DragOverlay, MouseSensor, TouchSensor, useDraggable, useDroppable, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core'
import { ArrowLeftRight, GraduationCap, LayoutGrid, Pin, PinOff, Plus, Search, Settings2, Sparkles, Undo2, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Tabs } from '@/components/ui/layout'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Select, Switch } from '@/components/ui/form'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import { AutoPlaceDrawer } from './AutoPlaceDrawer'
import { ChangeClassDialog } from './ChangeClassDialog'
import { PromotionDrawer } from './PromotionDrawer'
import { FillMeter, MetricLine, StudentTile } from './ui'
import { levelLabel, runSummary, sectionName, TRACK_LABELS, type ClassOption, type ClassSettings, type LevelData, type Overview, type RunRow, type SectionData, type StudentCard, type WaitCard } from './types'

type ChangeState = { student: StudentCard; targetId: number | null; level: LevelData }

function levelOptions(level: LevelData, currentId: number | null): ClassOption[] {
  return level.sections
    .filter((s) => s.class_group)
    .map((s) => ({
      class_group_id: s.class_group!.id, name: s.name, section: s.section, capacity: s.class_group!.capacity,
      count: s.count, full: s.count >= s.class_group!.capacity, is_current: s.class_group!.id === currentId,
    }))
}

export default function PlacementPage() {
  const can = useCan()
  const manage = can('academic.manage')
  const canChange = can(['academic.manage', 'students.update'])
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const [termId, setTermId] = useState<number | null>(null)
  const [tab, setTab] = useState('')
  const [query, setQuery] = useState('')
  const searchRef = useRef<HTMLInputElement>(null)
  const [autoOpen, setAutoOpen] = useState(false)
  const [promoOpen, setPromoOpen] = useState(false)
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [change, setChange] = useState<ChangeState | null>(null)
  const [cancelEntry, setCancelEntry] = useState<WaitCard | null>(null)
  const [revertRun, setRevertRun] = useState<RunRow | null>(null)
  const [dragging, setDragging] = useState<StudentCard | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['placement', 'overview', termId],
    queryFn: () => api.get<Overview>('/placement/overview', { term_id: termId ?? undefined }),
  })

  // Komut paleti: ?islem=otomatik | degistir
  useEffect(() => {
    const action = params.get('islem')
    if (!action || !data) return
    if (action === 'otomatik' && manage) setAutoOpen(true)
    if (action === 'degistir') searchRef.current?.focus()
    const next = new URLSearchParams(params)
    next.delete('islem')
    setParams(next, { replace: true })
  }, [params, data, manage, setParams])

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['placement'] })
    qc.invalidateQueries({ queryKey: ['class-groups'] })
  }
  const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')
  const structure = useMutation({ mutationFn: () => api.post<{ message: string }>('/placement/structure', { term_id: data?.term.id }), onSuccess: (r) => { toast.success(r.message); invalidate() }, onError: fail })
  const pin = useMutation({ mutationFn: (v: { id: number; pinned: boolean }) => api.post<{ message: string }>(`/placement/students/${v.id}/pin`, { term_id: data?.term.id, pinned: v.pinned }), onSuccess: (r) => { toast.success(r.message); invalidate() }, onError: fail })
  const cancel = useMutation({ mutationFn: (id: number) => api.delete<{ message: string }>(`/placement/waitlist/${id}`), onSuccess: (r) => { toast.success(r.message); setCancelEntry(null); invalidate() }, onError: (e) => { fail(e); setCancelEntry(null) } })
  const revert = useMutation({ mutationFn: (id: number) => api.post<{ message: string }>(`/placement/runs/${id}/revert`), onSuccess: (r) => { toast.success(r.message); setRevertRun(null); invalidate() }, onError: (e) => { fail(e); setRevertRun(null) } })

  const sensors = useSensors(
    useSensor(MouseSensor, { activationConstraint: { distance: 6 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 220, tolerance: 8 } }),
  )

  const level = data?.levels.find((l) => String(l.level) === tab) ?? data?.levels[0]

  const results = useMemo(() => {
    if (!data || query.trim().length < 2) return null
    const q = query.trim().toLocaleLowerCase('tr')
    const seen = new Set<number>()
    const out: { s: StudentCard; level: LevelData; where: string }[] = []
    const push = (s: StudentCard, l: LevelData, where: string) => {
      if (seen.has(s.id) || !(`${s.full_name} ${s.student_no}`.toLocaleLowerCase('tr').includes(q))) return
      seen.add(s.id)
      out.push({ s, level: l, where })
    }
    for (const l of data.levels) {
      l.sections.forEach((sec) => sec.students.forEach((s) => push(s, l, sec.name)))
      l.unplaced.forEach((s) => push(s, l, `${l.level}. sınıf · şubesiz`))
      l.waitlist.forEach((s) => push(s, l, `${l.level}. sınıf · bekleme listesi`))
    }
    return out.slice(0, 30)
  }, [data, query])

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton className="h-8 w-72" />
        <Skeleton className="h-10" />
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2"><Skeleton className="h-[420px]" /><Skeleton className="h-[420px]" /></div>
      </div>
    )
  }
  if (data && data.levels.length === 0 && data.configured === false) {
    return (
      <div className="animate-fade-in">
        <PageHeader title="Sınıflar ve yerleştirme" description={data.term.name} />
        <Panel>
          <EmptyState
            icon={<LayoutGrid />}
            title="Sınıf yapınızı tanımlayın"
            description={manage
              ? 'Kurumunuzda hangi seviyeler (LGS, 9-12, Mezun…) ve şubeler olduğunu henüz tanımlamadınız. Önce sınıf yapısında seviye ekleyin; ardından sınıfları açıp öğrencileri buradan yerleştirebilirsiniz.'
              : 'Sınıf yapısı henüz tanımlanmadı. Yönetici seviye ve şubeleri tanımladığında sınıflar burada görünür.'}
            action={<ButtonLink to="/program-botu/sinif-yapisi" variant={manage ? 'primary' : 'secondary'} icon={manage ? <Plus className="size-4" /> : undefined}>{manage ? 'Seviye ekle' : 'Sınıf yapısını gör'}</ButtonLink>}
          />
        </Panel>
      </div>
    )
  }
  if (error || !data || !level) {
    return <EmptyState icon={<LayoutGrid />} title="Sınıf bilgisi alınamadı" description={error instanceof ApiError ? error.firstError() : 'Sayfayı yenileyip tekrar deneyin.'} />
  }

  const onDragEnd = (e: DragEndEvent) => {
    setDragging(null)
    const s = e.active.data.current?.student as StudentCard | undefined
    const target = e.over?.data.current?.classGroupId as number | undefined
    if (!s || !target || s.class_group_id === target) return
    setChange({ student: s, targetId: target, level })
  }

  const cardActions = (s: StudentCard, l: LevelData) => (
    // Düğmeler sürüklemeyi başlatmasın
    <span className="flex shrink-0 items-center" onMouseDown={(e) => e.stopPropagation()} onTouchStart={(e) => e.stopPropagation()}>
      {manage && s.class_group_id !== null && (
        <Button size="icon-sm" variant="ghost" aria-label={s.pinned ? 'Sabitlemeyi kaldır' : 'Şubesine sabitle'} title={s.pinned ? 'Sabitlemeyi kaldır' : 'Şubesine sabitle (otomatik yerleştirme taşımaz)'} onClick={() => pin.mutate({ id: s.id, pinned: !s.pinned })}>
          {s.pinned ? <PinOff className="size-3.5" /> : <Pin className="size-3.5" />}
        </Button>
      )}
      {canChange && (
        <Button size="icon-sm" variant="ghost" aria-label="Sınıf değiştir" title="Sınıf değiştir" onClick={() => setChange({ student: s, targetId: null, level: l })}>
          <ArrowLeftRight className="size-3.5" />
        </Button>
      )}
    </span>
  )

  const totalActive = data.levels.reduce((a, l) => a + l.sections.reduce((b, s) => b + s.count, 0), 0)
  const seats = data.levels.reduce((a, l) => a + l.sections.reduce((b, s) => b + (s.class_group?.capacity ?? 0), 0), 0)

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Sınıflar ve yerleştirme"
        description={`${data.term.name} · ${data.levels.map((l) => (l.sectioned ? `${l.level === 13 ? 'Mezun' : l.level} (${l.sections.map((x) => x.section).join('/')})` : l.label)).join(', ')} · ${totalActive}/${seats} kontenjan dolu`}
        actions={
          <>
            {data.terms.length > 1 && (
              <Select className="w-40" aria-label="Dönem" value={String(data.term.id)} options={data.terms.map((t) => ({ value: t.id, label: t.name }))} onChange={(e) => setTermId(Number(e.target.value))} />
            )}
            {manage && <Button variant="ghost" size="icon" aria-label="Sınıf ayarları" title="Sınıf ayarları" onClick={() => setSettingsOpen(true)}><Settings2 className="size-4" /></Button>}
            {manage && <Button icon={<GraduationCap className="size-4" />} onClick={() => setPromoOpen(true)}>Seviye atlat</Button>}
            {manage && <Button variant="primary" icon={<Sparkles className="size-4" />} disabled={data.missing.length === data.levels.reduce((a, l) => a + l.sections.length, 0)} onClick={() => setAutoOpen(true)}>Otomatik yerleştir</Button>}
          </>
        }
      />

      {data.missing.length > 0 && (
        <Alert
          tone="warning"
          className="mb-4"
          title="Sınıf yapısı eksik"
          action={manage ? <Button size="sm" loading={structure.isPending} onClick={() => structure.mutate()}>Eksik sınıfları oluştur</Button> : undefined}
        >
          {data.missing.join(', ')} bu dönemde tanımlı değil.{manage ? ' Sınıflar, sınıf yapısındaki kapasite ve alanla açılır; ders programı ayrıca hazırlanır. ' : ' Yönetici oluşturabilir.'}
          {manage && <Link to="/program-botu/sinif-yapisi" className="underline">Sınıf yapısını düzenle</Link>}
        </Alert>
      )}

      <div className="mb-4 flex flex-col-reverse gap-3 sm:flex-row sm:items-end">
        <Tabs
          value={String(level.level)}
          onChange={(v) => { setTab(v); setQuery('') }}
          className="min-w-0 flex-1"
          tabs={data.levels.map((l) => ({ value: String(l.level), label: l.label, count: l.sections.reduce((a, s) => a + s.count, 0) }))}
        />
        <div className="w-full sm:w-64">
          <Input ref={searchRef} leading={<Search className="size-4" />} placeholder="Öğrenci ara (ad, numara)" value={query} onChange={(e) => setQuery(e.target.value)}
            trailing={query ? <button type="button" aria-label="Aramayı temizle" onClick={() => setQuery('')}><X className="size-3.5 text-ink-3" /></button> : undefined} />
        </div>
      </div>

      {results ? (
        <Panel title="Arama sonuçları" description="Sınıfını değiştirmek istediğiniz öğrencinin yanındaki düğmeyi kullanın." flush>
          {results.length === 0 ? (
            <EmptyState compact icon={<Search />} title="Öğrenci bulunamadı" description="Yalnız sınıf yapısındaki seviyelerin aktif öğrencileri ve bekleme listesi aranır." />
          ) : (
            <ul className="flex flex-col gap-1.5 p-2">
              {results.map(({ s, level: l, where }) => (
                <li key={s.id} className="flex items-center gap-2">
                  <StudentTile s={s} className="flex-1" actions={cardActions(s, l)} />
                  <Badge className="hidden sm:inline-flex">{where}</Badge>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      ) : !level.ready && level.sections.every((s) => !s.class_group) ? (
        <Panel>
          <EmptyState icon={<LayoutGrid />} title={`${level.label} ${level.sectioned ? 'şubeleri' : 'sınıfı'} tanımlı değil`} description={manage ? 'Üstteki "Eksik sınıfları oluştur" ile sınıf yapısındaki şubeleri açın.' : 'Yönetici sınıf yapısını oluşturduğunda burada görünür.'} />
        </Panel>
      ) : (
        <DndContext
          sensors={sensors}
          onDragStart={(e) => setDragging((e.active.data.current?.student as StudentCard | undefined) ?? null)}
          onDragEnd={onDragEnd}
          onDragCancel={() => setDragging(null)}
        >
          {canChange && <p className="mb-2 text-[12px] text-ink-3">Şube değiştirmek için öğrenciyi diğer şubeye sürükleyin (dokunmatikte basılı tutun) ya da kartındaki değiştir düğmesini kullanın.</p>}
          <div className={cn('grid grid-cols-1 gap-4 md:grid-cols-2', level.sections.length >= 3 && 'xl:grid-cols-3')}>
            {level.sections.map((sec) => (
              <SectionColumn key={sec.section} sec={sec} canDrag={canChange}>
                {sec.students.map((s) => <DraggableTile key={s.id} s={s} disabled={!canChange} actions={cardActions(s, level)} />)}
              </SectionColumn>
            ))}
          </div>

          <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <Panel title={`Şubesiz öğrenciler · ${level.unplaced.length}`} description={`${level.level}. sınıf olup bu dönem şubesi olmayan aktif öğrenciler`}>
              {level.unplaced.length === 0 ? (
                <p className="py-4 text-center text-[12.5px] text-ink-3">Şubesiz öğrenci yok.</p>
              ) : (
                <div className="flex flex-col gap-1.5">
                  {level.unplaced.map((s) => <DraggableTile key={s.id} s={s} disabled={!canChange} actions={cardActions(s, level)} />)}
                </div>
              )}
            </Panel>
            <Panel title={`Bekleme listesi · ${level.waitlist.length}`} description="Yer açılınca şubeye sürükleyin ya da yerleştirin">
              {level.waitlist.length === 0 ? (
                <p className="py-4 text-center text-[12.5px] text-ink-3">Bekleyen öğrenci yok.</p>
              ) : (
                <div className="flex flex-col gap-2">
                  {level.waitlist.map((w) => (
                    <div key={w.entry_id} className="flex flex-col gap-1">
                      <DraggableTile
                        s={w}
                        disabled={!canChange}
                        actions={
                          <span className="flex shrink-0 items-center" onMouseDown={(e) => e.stopPropagation()} onTouchStart={(e) => e.stopPropagation()}>
                            {w.preferred_section && <Badge className="mr-1">{sectionName(level.level, w.preferred_section)}</Badge>}
                            {canChange && (
                              <Button size="icon-sm" variant="ghost" aria-label="Şubeye yerleştir" title="Şubeye yerleştir"
                                onClick={() => setChange({ student: w, targetId: level.sections.find((x) => x.section === w.preferred_section)?.class_group?.id ?? null, level })}>
                                <ArrowLeftRight className="size-3.5" />
                              </Button>
                            )}
                            {canChange && (
                              <Button size="icon-sm" variant="ghost" aria-label="Listeden çıkar" title="Listeden çıkar" onClick={() => setCancelEntry(w)}>
                                <X className="size-3.5" />
                              </Button>
                            )}
                          </span>
                        }
                      />
                      <p className="px-1 text-[12px] text-ink-3">Kaynak: {w.source_label}{w.reason ? ` · Gerekçe: ${w.reason}` : ''} · Bekliyor: {relative(w.waiting_since)}</p>
                    </div>
                  ))}
                </div>
              )}
            </Panel>
          </div>

          <DragOverlay>{dragging ? <StudentTile s={dragging} className="shadow-[var(--shadow-pop)] ring-line-strong" /> : null}</DragOverlay>
        </DndContext>
      )}

      {data.runs.length > 0 && (
        <Panel title="Son toplu işlemler" className="mt-4" flush>
          <ul className="divide-y divide-line border-t border-line">
            {data.runs.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-[13px]">
                <span className="font-medium">{r.kind_label}</span>
                <span className="text-ink-2 tabular">{runSummary(r)}</span>
                <span className="text-[12.5px] text-ink-3">Uygulayan: {r.applied_by ?? 'Sistem'} · {relative(r.created_at)}</span>
                <span className="ml-auto">
                  {r.reverted_at ? <Badge>Geri alındı</Badge> : manage && r.can_revert ? (
                    <Button size="sm" variant="ghost" icon={<Undo2 className="size-3.5" />} onClick={() => setRevertRun(r)}>Geri al</Button>
                  ) : null}
                </span>
              </li>
            ))}
          </ul>
        </Panel>
      )}

      {autoOpen && (
        <AutoPlaceDrawer termId={data.term.id} levels={data.settings.levels} defaultLevel={level.level} siblingsDefault={data.settings.siblings_apart} onClose={() => setAutoOpen(false)} />
      )}
      {promoOpen && <PromotionDrawer terms={data.terms} fromTermId={data.term.id} onClose={() => setPromoOpen(false)} />}
      {settingsOpen && <SettingsModal settings={data.settings} onClose={() => setSettingsOpen(false)} />}
      {change && (
        <ChangeClassDialog
          studentId={change.student.id}
          studentName={change.student.full_name}
          currentName={change.level.sections.find((x) => x.class_group && x.class_group.id === change.student.class_group_id)?.name ?? null}
          options={levelOptions(change.level, change.student.class_group_id)}
          initialTargetId={change.targetId}
          onClose={() => setChange(null)}
        />
      )}
      <ConfirmDialog
        open={!!cancelEntry}
        onClose={() => setCancelEntry(null)}
        onConfirm={() => cancelEntry && cancel.mutate(cancelEntry.entry_id)}
        loading={cancel.isPending}
        title="Bekleme listesinden çıkarılsın mı?"
        confirmLabel="Listeden çıkar"
        description={cancelEntry ? `${cancelEntry.full_name} bekleme listesinden çıkarılacak. Öğrenci kaydı ve varsa mevcut şubesi değişmez.` : undefined}
      />
      <ConfirmDialog
        open={!!revertRun}
        onClose={() => setRevertRun(null)}
        onConfirm={() => revertRun && revert.mutate(revertRun.id)}
        loading={revert.isPending}
        danger
        title="İşlem geri alınsın mı?"
        confirmLabel="Geri al"
        description={revertRun ? `"${revertRun.kind_label}" (${runSummary(revertRun)}) öncesindeki sınıf üyelikleri, bekleme listesi ve öğrenci bilgileri geri yüklenecek.` : undefined}
      />
    </div>
  )
}

function SectionColumn({ sec, canDrag, children }: { sec: SectionData; canDrag: boolean; children: ReactNode }) {
  const drop = useDroppable({ id: `section-${sec.class_group?.id ?? sec.name}`, data: { classGroupId: sec.class_group?.id }, disabled: !sec.class_group || !canDrag })
  const full = sec.class_group ? sec.count >= sec.class_group.capacity : false
  return (
    <section ref={drop.setNodeRef} className={cn('flex flex-col rounded-[var(--radius-lg)] bg-surface ring-1 ring-line transition-shadow', drop.isOver && (full ? 'ring-2 ring-warning' : 'ring-2 ring-ink'))}>
      <header className="flex flex-col gap-2 border-b border-line px-4 py-3">
        <div className="flex items-baseline justify-between gap-2">
          <h3 className="text-[19px] font-semibold tracking-[-0.01em]">{sec.name}</h3>
          <span className="flex min-w-0 items-center gap-1.5 truncate text-[12px] text-ink-3">
            {sec.track && <Badge>{TRACK_LABELS[sec.track] ?? sec.track}</Badge>}
            <span className="truncate">{sec.class_group ? (sec.class_group.program ?? '') : 'Bu dönem tanımlı değil'}</span>
          </span>
        </div>
        {sec.class_group && <FillMeter count={sec.count} capacity={sec.class_group.capacity} />}
        <MetricLine female={sec.female} male={sec.male} avg={sec.avg_net} highRisk={sec.high_risk} />
      </header>
      <div className="flex min-h-28 flex-col gap-1.5 p-2">
        {sec.students.length === 0 ? (
          <p className="py-8 text-center text-[12.5px] text-ink-3">{sec.class_group ? 'Şube boş. Öğrenci sürükleyin ya da otomatik yerleştirin.' : 'Önce sınıf yapısını oluşturun.'}</p>
        ) : children}
      </div>
    </section>
  )
}

function DraggableTile({ s, disabled, actions }: { s: StudentCard; disabled: boolean; actions?: ReactNode }) {
  const d = useDraggable({ id: `student-${s.id}`, data: { student: s }, disabled })
  return (
    <div ref={d.setNodeRef} {...d.listeners} {...d.attributes} className={cn('outline-none', !disabled && 'cursor-grab touch-manipulation', d.isDragging && 'opacity-40')}>
      <StudentTile s={s} actions={actions} />
    </div>
  )
}

function SettingsModal({ settings, onClose }: { settings: ClassSettings; onClose: () => void }) {
  const qc = useQueryClient()
  const [capacity, setCapacity] = useState(String(settings.capacity))
  const [siblings, setSiblings] = useState(settings.siblings_apart)
  const save = useMutation({
    mutationFn: () => api.put<{ message: string }>('/placement/settings', { capacity: Number(capacity), siblings_apart: siblings }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['placement'] }); onClose() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Ayarlar kaydedilemedi.'),
  })
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title="Sınıf ayarları"
      description={`Seviyeler: ${settings.levels.map(levelLabel).join(', ')} · şube ve alanlar "Sınıf yapısı" ekranından düzenlenir`}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} disabled={!(Number(capacity) >= 1)} onClick={() => save.mutate()}>Kaydet</Button></>}
    >
      <div className="flex flex-col gap-4">
        <Field label="Yeni şube kapasitesi" hint="Yeni oluşturulan sınıflara uygulanır; mevcut sınıfın kontenjanı sınıf kartından değişir.">
          <Input type="number" min={1} max={60} value={capacity} onChange={(e) => setCapacity(e.target.value)} />
        </Field>
        <Switch checked={siblings} onChange={setSiblings} label="Otomatik yerleştirmede kardeşleri farklı şubelere ayır" />
      </div>
    </Modal>
  )
}
