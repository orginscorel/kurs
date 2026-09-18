import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useBlocker } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Box, Columns2, ExternalLink, Loader2, PenLine, Printer, Redo2, Save, Sparkles, Square, Tags, Undo2, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Alert, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Select } from '@/components/ui/form'
import { cn } from '@/lib/cn'
import { catalogOf, isDesk } from '../catalog'
import { saveThumbnail } from '../api'
import { isDirty, useClassroom } from '../state/classroomStore'
import { useHistory } from '../state/historyStore'
import { useSelection, type ViewMode } from '../state/selectionStore'
import { useSeatingContext, type SeatingStudent } from '../state/seatingContext'
import { useDrag } from '../state/dragStore'
import type { LayoutData, SceneObject } from '../types'
import { ThreeScene } from '../three/ThreeScene'
import { sceneBridge } from '../three/sceneBridge'
import { PlanView } from '../components/PlanView/PlanView'
import { SeatingManager } from '../components/SeatingManager/SeatingManager'
import { DeskSection } from '../components/PropertiesPanel/PropertiesPanel'
import { StatsStrip } from '../components/Measurements/StatsStrip'
import { DragLayer } from '../components/ClassroomEditor/DragLayer'
import { classicTemplate } from '../utils/templateRoom'
import { computeStats } from '../utils/seating'
import { docFromData } from '../utils/doc'
import { printSeatingPlan } from './printSeating'

/**
 * SINIF OTURMA PLANI — sınıfın dersliğindeki etkin oda düzeni 3D + 2D açılır; oda burada DEĞİŞMEZ (yalnız "Odayı düzenle"
 * bağlantısı). Öğrenciler yalnız bu sınıfın öğrencileri: sürükle-bırak, masa seç → öğrenci seç, yer değiştirme, masadan
 * kaldırma, masa durumu (rezerve/kullanılamaz), önizlemeli otomatik yerleştirme. Plan sınıf başına saklanır.
 * `source='teacher'`: öğretmen portalı, SALT OKUNUR.
 */

type Room = { id: number; name: string; floor: string | null; capacity: number; homeroom: boolean; weekly_lessons: number; layout: { id: number; name: string; version: number; thumbnail_url: string | null } | null }
type Payload = {
  group: { id: number; name: string }
  classrooms: Room[]
  classroom_id: number | null
  layout: { id: number; name: string; version: number; is_demo: boolean; has_thumbnail: boolean; data: LayoutData } | null
  plan: { id: number | null; seats: Record<string, (string | null)[]>; statuses: Record<string, 'reserved' | 'unavailable'>; updated_at: string | null; updated_by: string | null; legacy: boolean } | null
  students: SeatingStudent[]
  former_students: { uuid: string; name: string }[]
  has_gender: boolean
  can_edit: boolean
  can_edit_room: boolean
}

export default function ClassSeatingPanel({ groupId, source = 'admin' }: { groupId: number; source?: 'admin' | 'teacher' }) {
  const qc = useQueryClient()
  const [classroomId, setClassroomId] = useState<number | null>(null)
  const path = source === 'teacher' ? `/teacher-portal/classes/${groupId}/seating` : `/class-groups/${groupId}/seating`
  const q = useQuery({
    queryKey: ['class-seating', source, groupId, classroomId],
    queryFn: () => api.get<Payload>(path, { classroom_id: classroomId ?? undefined }),
    staleTime: Infinity,
    refetchOnWindowFocus: false,
  })
  const d = q.data
  const readOnly = !d?.can_edit
  const [notice, setNotice] = useState<{ removedDesks: number; former: string[] } | null>(null)
  const loadedKey = useRef<string | null>(null)

  // Oda düzeni + plan → tek belge (masa oturakları plandan)
  useEffect(() => {
    if (!d?.layout) {
      useSeatingContext.getState().set({ active: true, students: d?.students ?? [], hasGender: !!d?.has_gender })
      return
    }
    const key = `${d.layout.id}-${d.layout.version}-${d.plan?.updated_at ?? 'yok'}-${d.classroom_id}`
    if (loadedKey.current === key) return
    loadedKey.current = key
    const names = new Map<string, string>()
    for (const s of d.students) names.set(s.uuid, s.name)
    const known = new Set(d.students.map((s) => s.uuid))
    const planSeats = d.plan?.seats ?? {}
    const statuses = d.plan?.statuses ?? {}
    const deskIds = new Set(d.layout.data.objects.filter(isDesk).map((o) => o.id))
    const removedDesks = Object.keys(planSeats).filter((id) => !deskIds.has(id) && (planSeats[id] ?? []).some(Boolean)).length
    const former = new Set<string>()
    const objects: SceneObject[] = d.layout.data.objects.map((o) => {
      if (!isDesk(o)) return o
      const n = catalogOf(o.type)?.seats ?? 1
      const row = planSeats[o.id] ?? []
      const seats = Array.from({ length: n }, (_, i) => {
        const u = row[i]
        if (!u) return null
        if (!known.has(u)) {
          former.add(u)
          return null
        }
        return { uuid: u, name: names.get(u) ?? 'Öğrenci' }
      })
      return { ...o, seats, status: statuses[o.id] ?? 'normal' }
    })
    useSeatingContext.getState().set({ active: true, students: d.students, hasGender: d.has_gender })
    useClassroom.getState().load(
      {
        layoutId: d.layout.id, name: d.layout.name, classroomId: d.classroom_id, classroomName: d.classrooms.find((c) => c.id === d.classroom_id)?.name ?? null,
        classroomCapacity: d.classrooms.find((c) => c.id === d.classroom_id)?.capacity ?? null, version: d.layout.version, isDemo: d.layout.is_demo, hasThumbnail: d.layout.has_thumbnail,
      },
      { ...d.layout.data, objects },
    )
    const s = useSelection.getState()
    s.clear()
    s.setTool('select')
    const formerNames = d.former_students.filter((f) => former.has(f.uuid)).map((f) => f.name)
    setNotice(removedDesks || former.size ? { removedDesks, former: formerNames.length ? formerNames : former.size ? [`${former.size} öğrenci`] : [] } : null)
  }, [d])

  useEffect(() => () => {
    useSeatingContext.getState().set({ active: false, students: [], hasGender: false })
  }, [])

  // Küçük görseli olmayan oda düzeni: oda düzenleme yetkisi varsa 3D görünümden bir kez üret (sınıf listesinde görünsün)
  useEffect(() => {
    if (!d?.layout || d.layout.has_thumbnail || !d.can_edit_room) return
    const id = d.layout.id
    const t = setTimeout(() => {
      const img = sceneBridge()?.capture()
      if (img) saveThumbnail(id, img).then(() => qc.invalidateQueries({ queryKey: ['class-seating-overview'] })).catch(() => undefined)
    }, 1800)
    return () => clearTimeout(t)
  }, [d, qc])

  const save = useMutation({
    mutationFn: () => {
      const doc = useClassroom.getState().doc
      const seats: Record<string, (string | null)[]> = {}
      const statuses: Record<string, string> = {}
      for (const id of doc.order) {
        const o = doc.objects[id]!
        if (!isDesk(o)) continue
        if (o.seats?.some(Boolean)) seats[id] = o.seats.map((s) => s?.uuid ?? null)
        if (o.status === 'reserved' || o.status === 'unavailable') statuses[id] = o.status
      }
      return api.put<{ message: string }>(`/class-groups/${groupId}/seating`, { classroom_layout_id: d!.layout!.id, seats, statuses })
    },
    onSuccess: (r) => {
      useClassroom.setState((s) => ({ savedDoc: s.doc }))
      toast.success(r.message)
      setNotice(null)
      qc.invalidateQueries({ queryKey: ['class-seating-overview'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const template = useMutation({
    mutationFn: async (room: Room) => {
      const n = templateSize(d?.students.length ?? 0, room.capacity)
      const data = classicTemplate(n)
      return api.post<{ id: number }>('/classroom-layouts', { name: `${room.name} düzeni`, classroom_id: room.id, data, stats: computeStats(docFromData(data)), label: 'Hazır şablon' })
    },
    onSuccess: () => {
      toast.success('Hazır şablondan oda düzeni oluşturuldu.')
      loadedKey.current = null
      qc.invalidateQueries({ queryKey: ['class-seating'] })
      qc.invalidateQueries({ queryKey: ['classroom-layouts'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Oluşturulamadı.'),
  })

  if (q.isLoading) return <Skeleton className="h-[560px] rounded-[var(--radius-lg)]" />
  if (q.isError || !d) return <EmptyState title="Oturma düzeni yüklenemedi" description="Lütfen sayfayı yenileyin." />

  const room = d.classrooms.find((c) => c.id === d.classroom_id) ?? null
  const roomPicker = d.classrooms.length > 1 && (
    <Select
      aria-label="Derslik"
      className="w-full sm:w-[240px]"
      value={d.classroom_id ?? ''}
      onChange={(e) => {
        loadedKey.current = null
        setClassroomId(Number(e.target.value))
      }}
      options={d.classrooms.map((c) => ({ value: c.id, label: `${c.name}${c.homeroom ? ' · sınıf dersliği' : c.weekly_lessons ? ` · haftada ${c.weekly_lessons} ders` : ''}${c.layout ? '' : ' · düzen yok'}` }))}
    />
  )

  if (!room) {
    return (
      <EmptyState
        icon={<Box />}
        title="Bu sınıfın dersliği yok"
        description="Oturma düzeni için sınıfa ana derslik atayın (Düzenle) ya da ders programında bu sınıfa derslik seçin."
      />
    )
  }
  if (!d.layout) {
    return (
      <div className="space-y-3">
        {roomPicker}
        <EmptyState
          icon={<Box />}
          title={`${room.name} dersliğinin oda düzeni yok`}
          description={d.can_edit_room ? 'Oturma planı için önce dersliğin masa düzeni gerekir. Planı çizebilir ya da sınıf mevcuduna göre hazır klasik düzen oluşturabilirsiniz.' : 'Oturma planı için önce dersliğin masa düzeni oluşturulmalı. Bu işlem için "Derslik tasarımı" yetkisi gerekir.'}
          action={
            d.can_edit_room && (
              <div className="flex flex-wrap justify-center gap-2">
                <Button variant="primary" icon={<Sparkles className="size-4" />} loading={template.isPending} onClick={() => template.mutate(room)} data-testid="seating-template">
                  Hazır şablondan oluştur ({templateSize(d.students.length, room.capacity)} kişilik)
                </Button>
                <ButtonLink to={`/derslik-tasarimi/yeni?derslik=${room.id}`} icon={<PenLine className="size-4" />}>Oluştur (planı çiz)</ButtonLink>
              </div>
            )
          }
        />
      </div>
    )
  }
  return (
    <SeatingWorkspace
      payload={d}
      room={room}
      roomPicker={roomPicker}
      readOnly={readOnly}
      source={source}
      notice={notice}
      saving={save.isPending}
      onSave={() => save.mutate()}
    />
  )
}

function SeatingWorkspace({
  payload, room, roomPicker, readOnly, source, notice, saving, onSave,
}: {
  payload: Payload
  room: Room
  roomPicker: React.ReactNode
  readOnly: boolean
  source: 'admin' | 'teacher'
  notice: { removedDesks: number; former: string[] } | null
  saving: boolean
  onSave: () => void
}) {
  const view = useSelection((s) => s.view)
  const selected = useSelection((s) => s.selected)
  const doc = useClassroom((s) => s.doc)
  const labels = useClassroom((s) => s.settings.labels)
  const dirty = useClassroom(isDirty)
  const canUndo = useHistory((s) => s.past.length > 0)
  const canRedo = useHistory((s) => s.future.length > 0)
  const desk = selected.length === 1 && doc.objects[selected[0]!] && isDesk(doc.objects[selected[0]!]!) ? doc.objects[selected[0]!]! : null
  const wsRef = useRef<HTMLDivElement>(null)
  const saveRef = useRef(onSave)
  saveRef.current = onSave

  useEffect(() => {
    if (useSelection.getState().view === '3d') useSelection.getState().setView('split')
  }, [])

  // Klavye: geri al / yinele / kaydet / Esc
  const onKey = useCallback(
    (e: KeyboardEvent) => {
      const t = e.target as HTMLElement | null
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT')) return
      if (document.querySelector('[role="dialog"]') || useDrag.getState().payload) return
      if (!wsRef.current || wsRef.current.offsetParent === null) return   // sekme gizliyken klavye işlemez
      const mod = e.ctrlKey || e.metaKey
      const key = e.key.toLowerCase()
      if (e.key === 'Escape') useSelection.getState().clear()
      if (readOnly) return
      if (mod && key === 's') {
        e.preventDefault()
        saveRef.current()
      } else if (mod && (key === 'z' || key === 'y')) {
        e.preventDefault()
        if (key === 'y' || e.shiftKey) useClassroom.getState().redo()
        else useClassroom.getState().undo()
      }
    },
    [readOnly],
  )
  useEffect(() => {
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onKey])

  const blocker = useBlocker(({ currentLocation, nextLocation }) => !readOnly && dirty && currentLocation.pathname !== nextLocation.pathname)
  useEffect(() => {
    if (blocker.state !== 'blocked') return
    if (window.confirm('Oturma planında kaydedilmemiş değişiklikler var. Kaydetmeden çıkılsın mı?')) blocker.proceed?.()
    else blocker.reset?.()
  }, [blocker])

  const stats = useMemo(() => computeStats(doc), [doc])
  // Eski atamalardan gelen ya da masası silinmiş plan: değişiklik olmasa da kaydedilebilir
  const pending = !!notice || !!payload.plan?.legacy
  const setView = (v: ViewMode) => useSelection.getState().setView(v)

  return (
    <div ref={wsRef} className="space-y-3" data-testid="seating-workspace">
      {notice && (
        <Alert tone="warning" title="Bazı öğrenciler yerleşmemiş listesine düştü">
          {notice.removedDesks > 0 && `${notice.removedDesks} masa oda düzeninden kaldırıldığı için o masalardaki öğrenciler yerleşmemiş listesinde. `}
          {notice.former.length > 0 && `Sınıftan ayrılan öğrenciler (${notice.former.join(', ')}) plandan çıkarıldı. `}
          {!readOnly && 'Kaydedince plan güncellenir.'}
        </Alert>
      )}
      {payload.plan?.legacy && !readOnly && (
        <Alert tone="info">Bu plan, oda düzenindeki eski atamalardan alındı. Kaydedince sınıfın kendi oturma planı olarak saklanır.</Alert>
      )}
      <div className="flex flex-wrap items-center gap-2">
        {roomPicker}
        <span className="text-[12.5px] text-ink-3">
          {room.name}{room.floor ? ` · ${room.floor}` : ''} · düzen: {payload.layout!.name} (V{payload.layout!.version})
        </span>
        <div className="ml-auto flex flex-wrap items-center gap-1.5">
          <div className="inline-flex rounded-[6px] bg-surface-2 p-0.5 ring-1 ring-line" role="group" aria-label="Görünüm">
            {([['3d', '3D', Box], ['2d', '2D plan', Square], ['split', 'Bölünmüş', Columns2]] as const).map(([v, label, Icon]) => (
              <button key={v} type="button" onClick={() => setView(v)} data-testid={`seat-view-${v}`}
                className={cn('inline-flex h-7 items-center gap-1 rounded-[5px] px-2 text-[12px] font-medium [&_svg]:size-3.5', view === v ? 'bg-surface text-ink shadow-[var(--shadow-soft)]' : 'text-ink-2 hover:text-ink', v === 'split' && 'max-md:hidden')}>
                <Icon />{label}
              </button>
            ))}
          </div>
          <Button size="sm" variant={labels ? 'soft' : 'ghost'} icon={<Tags className="size-4" />} onClick={() => useClassroom.getState().setSettings({ labels: !labels })} title="Masa no ve öğrenci adı etiketleri">
            <span className="max-sm:hidden">Etiketler</span>
          </Button>
          <Button size="sm" variant="ghost" icon={<Printer className="size-4" />} onClick={() => printSeatingPlan(payload.group.name, room.name)} title="Yazdırılabilir oturma planı" data-testid="seat-print">
            <span className="max-sm:hidden">Yazdır</span>
          </Button>
          {!readOnly && (
            <>
              <Button size="icon-sm" variant="ghost" disabled={!canUndo} onClick={() => useClassroom.getState().undo()} aria-label="Geri al" title="Geri al (Ctrl+Z)"><Undo2 className="size-4" /></Button>
              <Button size="icon-sm" variant="ghost" disabled={!canRedo} onClick={() => useClassroom.getState().redo()} aria-label="Yinele" title="Yinele (Ctrl+Shift+Z)"><Redo2 className="size-4" /></Button>
            </>
          )}
          {payload.can_edit_room && source === 'admin' && (
            <Link to={`/derslik-tasarimi/${payload.layout!.id}`} className="inline-flex h-8 items-center gap-1 rounded-[var(--radius-sm)] px-2.5 text-[13px] font-medium text-ink-2 hover:bg-surface-2 hover:text-ink">
              <ExternalLink className="size-4" /> Odayı düzenle
            </Link>
          )}
          {!readOnly && (
            <Button size="sm" variant="primary" icon={saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />} disabled={saving || (!dirty && !pending)} onClick={onSave} data-testid="seat-save" title="Ctrl+S">
              {dirty || pending ? 'Kaydet' : 'Kaydedildi'}
            </Button>
          )}
        </div>
      </div>

      <div className="flex min-h-[560px] flex-col overflow-hidden rounded-[var(--radius-lg)] bg-surface ring-1 ring-line lg:h-[calc(100dvh-260px)] lg:flex-row">
        <div className="relative min-h-[380px] min-w-0 flex-1 max-lg:h-[62dvh]">
          <div className={cn('absolute inset-0 flex', view === 'split' ? 'flex-row max-md:flex-col' : '')}>
            <div className={cn('relative min-h-0 min-w-0', view === '2d' ? 'pointer-events-none invisible absolute inset-0' : 'flex-1', view === 'split' && 'border-r border-line max-md:border-b max-md:border-r-0')}>
              <ThreeScene readOnly className="absolute inset-0" />
            </div>
            {view !== '3d' && <PlanView className="relative min-h-0 min-w-0 flex-1" readOnly />}
          </div>
        </div>
        <aside className="flex w-full shrink-0 flex-col border-line max-lg:max-h-[70dvh] max-lg:border-t lg:w-[320px] lg:border-l" aria-label="Öğrenciler">
          {desk && (
            <div className="border-b border-line p-3" data-testid="seat-desk-panel">
              <div className="mb-2 flex items-center justify-between">
                <h3 className="text-[13.5px] font-semibold text-ink">{catalogOf(desk.type)?.label} {desk.no ?? ''}</h3>
                <button type="button" onClick={() => useSelection.getState().clear()} className="grid size-6 place-items-center rounded text-ink-3 hover:bg-surface-2 hover:text-ink" aria-label="Seçimi kaldır"><X className="size-4" /></button>
              </div>
              <DeskSection o={desk} readOnly={readOnly} />
            </div>
          )}
          <div className="min-h-0 flex-1">
            <SeatingManager readOnly={readOnly} />
          </div>
        </aside>
      </div>
      <div className="flex h-9 items-center overflow-hidden rounded-[var(--radius-sm)] bg-surface ring-1 ring-line">
        <StatsStrip className="w-full" />
      </div>
      <p className="text-[11.5px] text-ink-3">
        {readOnly ? 'Salt okunur görünüm.' : 'Öğrenciyi listeden masaya sürükleyin; dolu masaya bırakılan oturmuş öğrenci yer değiştirir. Masaya tıklayınca öğrenci ve masa durumu seçilir.'} Oda düzeni burada değiştirilemez. {stats.capacity} oturak · {stats.assigned} atama.
      </p>
      <DragLayer />
    </div>
  )
}

/** Şablon masa sayısı: sınıf mevcudu esas; salon kapasitesi yalnız üst sınır (15 kişilik sınıfa 80 masa kurulmasın). */
function templateSize(students: number, capacity: number | null | undefined): number {
  if (students > 0) return capacity ? Math.min(students, capacity) : students
  return capacity || 20
}
