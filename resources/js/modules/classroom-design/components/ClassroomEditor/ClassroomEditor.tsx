import { useCallback, useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { Link, useBlocker, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { ArrowLeft, Armchair, History, Loader2, Save, Settings2, SlidersHorizontal, Users } from 'lucide-react'
import { useClassroomOptions, useRoster, useSaveLayout, saveThumbnail } from '../../api'
import { isDirty, useClassroom } from '../../state/classroomStore'
import { useSelection, type PanelTab } from '../../state/selectionStore'
import { computeStats } from '../../utils/seating'
import { validatePolygon } from '../../utils/polygon'
import { ThreeScene } from '../../three/ThreeScene'
import { sceneBridge } from '../../three/sceneBridge'
import { PlanView } from '../PlanView/PlanView'
import { FurnitureLibrary } from '../FurnitureLibrary/FurnitureLibrary'
import { PropertiesPanel } from '../PropertiesPanel/PropertiesPanel'
import { RoomPanel } from '../PropertiesPanel/RoomPanel'
import { RoomClassesPanel } from '../SeatingManager/RoomClassesPanel'
import { SmartLayoutDialog } from '../SmartLayout/SmartLayoutDialog'
import { StatsStrip } from '../Measurements/StatsStrip'
import { Toolbar } from '../Toolbar/Toolbar'
import { VersionsDrawer } from '../Versions/VersionsDrawer'
import { DragLayer } from './DragLayer'
import { useEditorKeyboard } from './useEditorKeyboard'
import { useMedia } from '../../hooks/useMedia'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/feedback'
import { ConfirmDialog } from '@/components/ui/overlay'
import { cn } from '@/lib/cn'

/**
 * DÜZENLEYİCİ — tam ekran mimari araç görünümü: üst başlık (ad, derslik, kaydet, sürümler), araç çubuğu,
 * görünüm (3D / 2D plan / bölünmüş), SAĞDA kütüphane + özellikler + öğrenciler + oda sekmeleri, altta canlı istatistik.
 * Tablet/telefonda sağ panel alttan açılan sayfa olur.
 */

const TABS: { key: PanelTab; label: string; icon: typeof Armchair }[] = [
  { key: 'library', label: 'Kütüphane', icon: Armchair },
  { key: 'props', label: 'Özellikler', icon: SlidersHorizontal },
  { key: 'students', label: 'Sınıflar', icon: Users },
  { key: 'room', label: 'Oda', icon: Settings2 },
]

export function ClassroomEditor({ canManage, onReload }: { canManage: boolean; onReload: () => void }) {
  const readOnly = !canManage
  const navigate = useNavigate()
  const layoutId = useClassroom((s) => s.layoutId)
  const name = useClassroom((s) => s.name)
  const classroomId = useClassroom((s) => s.classroomId)
  const version = useClassroom((s) => s.version)
  const isDemo = useClassroom((s) => s.isDemo)
  const hasThumbnail = useClassroom((s) => s.hasThumbnail)
  const dirty = useClassroom(isDirty)
  const view = useSelection((s) => s.view)
  const tab = useSelection((s) => s.tab)
  const selectedCount = useSelection((s) => s.selected.length)
  const openingId = useSelection((s) => s.openingId)
  const classrooms = useClassroomOptions()
  const roster = useRoster(classroomId, null)
  const save = useSaveLayout()
  const [smart, setSmart] = useState(false)
  const [versions, setVersions] = useState(false)
  const [sheet, setSheet] = useState(false)
  const [conflict, setConflict] = useState(false)
  const desktop = useMedia('(min-width: 1024px)')

  // Seçim olunca Özellikler sekmesine geç (öğrenci sekmesindeyse kal); dar ekranda alt sayfayı aç
  useEffect(() => {
    if ((selectedCount || openingId) && tab === 'library') useSelection.getState().setTab(openingId ? 'room' : 'props')
    if ((selectedCount === 1 || openingId) && !desktop) setSheet(true)
  }, [selectedCount, openingId]) // eslint-disable-line react-hooks/exhaustive-deps

  const doSave = useCallback(
    (asCopy = false) => {
      if (readOnly || save.isPending) return
      const s = useClassroom.getState()
      const problems = validatePolygon(s.doc.room.polygon)
      if (problems.length) {
        toast.error(`Kaydedilemedi: ${problems[0]!.message}`)
        return
      }
      if (!s.name.trim()) {
        toast.error('Tasarım adı boş olamaz.')
        return
      }
      const thumbnail = sceneBridge()?.capture() ?? null
      const stats = computeStats(s.doc)
      save.mutate(
        {
          id: asCopy ? null : s.layoutId,
          name: asCopy ? `${s.name} (kopya)` : s.name.trim(),
          classroom_id: s.classroomId,
          data: s.toData(),
          stats,
          thumbnail,
          base_version: asCopy ? undefined : s.version,
        },
        {
          onSuccess: (r) => {
            const newId = 'id' in r ? r.id : s.layoutId
            if (asCopy || !s.layoutId) {
              useClassroom.getState().setMeta({ name: asCopy ? `${s.name} (kopya)` : s.name })
              useClassroom.setState({ layoutId: newId })
              useClassroom.getState().markSaved(r.version)
              navigate(`/derslik-tasarimi/${newId}`, { replace: true })
            } else useClassroom.getState().markSaved(r.version)
            toast.success(asCopy ? 'Kopya olarak kaydedildi.' : r.message, { id: 'save' })
          },
          onError: (e) => {
            if (e instanceof ApiError && e.status === 409) setConflict(true)
            else toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.')
          },
        },
      )
    },
    [readOnly, save, navigate],
  )

  useEditorKeyboard({ readOnly, onSave: doSave })

  // Küçük görseli olmayan (ör. örnek) kayıt: ilk açılışta 3D görünümden bir kez üret
  useEffect(() => {
    if (readOnly || hasThumbnail || !layoutId) return
    const t = setTimeout(() => {
      const img = sceneBridge()?.capture()
      if (img) saveThumbnail(layoutId, img).then(() => useClassroom.setState({ hasThumbnail: true })).catch(() => undefined)
    }, 1500)
    return () => clearTimeout(t)
  }, [layoutId, hasThumbnail, readOnly])

  // Kaydedilmemiş değişiklik koruması
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && currentLocation.pathname !== nextLocation.pathname)
  useEffect(() => {
    const h = (e: BeforeUnloadEvent) => {
      if (!isDirty(useClassroom.getState())) return
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', h)
    return () => window.removeEventListener('beforeunload', h)
  }, [])

  const classroomOptions = useMemo(() => (classrooms.data ?? []).map((c) => ({ value: c.id, label: `${c.name}${c.floor ? ` · ${c.floor}` : ''}` })), [classrooms.data])
  const studentCount = roster.data?.students.length ?? 0

  const panel = (
    <>
      <div className="flex h-10 shrink-0 items-stretch border-b border-line" role="tablist">
        {TABS.filter((t) => !(readOnly && t.key === 'library')).map((t) => (
          <button
            key={t.key}
            type="button"
            role="tab"
            aria-selected={tab === t.key}
            onClick={() => useSelection.getState().setTab(t.key)}
            data-testid={`tab-${t.key}`}
            className={cn(
              'relative inline-flex flex-1 items-center justify-center gap-1.5 text-[12.5px] font-medium transition-colors [&_svg]:size-3.5',
              tab === t.key ? 'text-ink after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:rounded-full after:bg-primary' : 'text-ink-3 hover:text-ink',
            )}
          >
            <t.icon />
            {t.label}
          </button>
        ))}
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto scroll-thin">
        {tab === 'library' && !readOnly && <FurnitureLibrary />}
        {tab === 'props' && <PropertiesPanel readOnly={readOnly} />}
        {tab === 'students' && <RoomClassesPanel />}
        {tab === 'room' && <RoomPanel readOnly={readOnly} />}
      </div>
    </>
  )

  // Tam ekran: kabuğun sayfa geçiş animasyonu (transform) fixed konumu bozmasın diye body'ye taşınır
  return createPortal(
    <div className="fixed inset-0 z-40 flex select-none flex-col bg-bg [&_input]:select-text [&_textarea]:select-text" data-testid="classroom-editor">
      {/* başlık */}
      <header className="flex h-12 shrink-0 items-center gap-2 border-b border-line bg-surface px-2 sm:px-3" style={{ paddingTop: 'env(safe-area-inset-top, 0px)' }}>
        <Link to="/derslik-tasarimi" className="inline-flex h-8 items-center gap-1 rounded-[6px] px-2 text-[13px] text-ink-2 hover:bg-surface-2 hover:text-ink" title="Derslik listesine dön">
          <ArrowLeft className="size-4" />
          <span className="max-sm:hidden">Derslikler</span>
        </Link>
        <span className="h-5 w-px bg-line max-sm:hidden" />
        <input
          value={name}
          readOnly={readOnly}
          onChange={(e) => useClassroom.getState().setMeta({ name: e.target.value })}
          className="h-8 min-w-0 max-w-[340px] flex-1 truncate rounded-[6px] border border-transparent bg-transparent px-2 text-[14.5px] font-semibold text-ink hover:border-line focus:border-primary focus:outline-none"
          aria-label="Tasarım adı"
          data-testid="layout-name"
        />
        {isDemo && <Badge tone="info">Örnek</Badge>}
        <select
          value={classroomId ?? ''}
          disabled={readOnly}
          onChange={(e) => {
            const id = e.target.value ? Number(e.target.value) : null
            const c = classrooms.data?.find((x) => x.id === id)
            useClassroom.getState().setMeta({ classroomId: id, classroomName: c?.name ?? null, classroomCapacity: c?.capacity ?? null })
          }}
          className="h-8 max-w-[200px] cursor-pointer truncate rounded-[6px] border border-line bg-surface px-2 text-[12.5px] text-ink-2 focus:outline-none max-md:hidden"
          aria-label="Bağlı derslik"
          title="Bu tasarımın ait olduğu derslik (öğrenci listesi bu dersliği kullanan sınıflardan gelir)"
        >
          <option value="">Derslik bağlı değil</option>
          {classroomOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
        <div className="ml-auto flex items-center gap-1.5">
          <span className="text-[12px] text-ink-3 max-sm:hidden" data-testid="save-status">
            {save.isPending ? 'Kaydediliyor…' : dirty ? 'Kaydedilmemiş değişiklik' : version ? `V${version} kaydedildi` : 'Kaydedilmedi'}
          </span>
          {layoutId && (
            <Button size="sm" variant="ghost" icon={<History className="size-4" />} onClick={() => setVersions(true)} data-testid="btn-versions">
              <span className="max-sm:hidden">Sürümler</span>
            </Button>
          )}
          {!readOnly && (
            <Button size="sm" variant="primary" icon={save.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />} onClick={() => doSave()} disabled={save.isPending} data-testid="btn-save" title="Ctrl+S">
              Kaydet
            </Button>
          )}
        </div>
      </header>

      <Toolbar readOnly={readOnly} onSmartLayout={() => setSmart(true)} />

      <div className="flex min-h-0 flex-1">
        <main className="relative min-w-0 flex-1">
          <div className={cn('absolute inset-0 flex', view === 'split' ? 'flex-row max-md:flex-col' : '')}>
            <div className={cn('relative min-h-0 min-w-0', view === '2d' ? 'pointer-events-none invisible absolute inset-0' : 'flex-1', view === 'split' && 'border-r border-line max-md:border-r-0 max-md:border-b')}>
              <ThreeScene readOnly={readOnly} className="absolute inset-0" />
              {view !== '2d' && (
                <div className="pointer-events-none absolute left-2 top-2 rounded-[4px] bg-surface/85 px-1.5 py-0.5 text-[11px] text-ink-3 ring-1 ring-line max-md:hidden">
                  Sol: seç/taşı · Sağ: döndür · Orta/Shift+sağ: kaydır · Tekerlek: yakınlaştır · R: 90°
                </div>
              )}
            </div>
            {view !== '3d' && <PlanView className="relative min-h-0 min-w-0 flex-1" readOnly={readOnly} />}
          </div>
        </main>
        {desktop && (
          <aside className="flex w-[340px] shrink-0 flex-col border-l border-line bg-surface" aria-label="Kütüphane ve özellikler">
            {panel}
          </aside>
        )}
      </div>

      {/* tablet/telefon: alt sayfa */}
      {!desktop && (
      <div>
        {sheet && <div className="fixed inset-0 z-40 bg-black/20" onClick={() => setSheet(false)} />}
        {sheet && (
          <div className="fixed inset-x-0 bottom-0 z-40 flex h-[58dvh] flex-col rounded-t-[var(--radius-xl)] bg-surface ring-1 ring-line shadow-[var(--shadow-pop)] animate-slide-up" data-testid="mobile-sheet">
            <div className="flex items-center justify-between px-3 py-1.5">
              <span className="h-1 w-10 rounded-full bg-line-strong" />
              <button type="button" onClick={() => setSheet(false)} className="text-[12.5px] font-medium text-ink-2">Kapat</button>
            </div>
            {panel}
          </div>
        )}
        {!sheet && (
          <div className="fixed bottom-12 right-3 z-40 flex gap-1.5" style={{ marginBottom: 'env(safe-area-inset-bottom, 0px)' }}>
            {TABS.filter((t) => !(readOnly && t.key === 'library')).map((t) => (
              <Button key={t.key} size="sm" variant="secondary" icon={<t.icon className="size-4" />} onClick={() => { useSelection.getState().setTab(t.key); setSheet(true) }}>
                <span className="max-sm:hidden">{t.label}</span>
              </Button>
            ))}
          </div>
        )}
      </div>
      )}

      <footer className="flex h-9 shrink-0 items-center border-t border-line bg-surface" style={{ paddingBottom: 'env(safe-area-inset-bottom, 0px)' }}>
        <StatsStrip className="w-full" />
      </footer>

      <DragLayer />
      <SmartLayoutDialog open={smart} onClose={() => setSmart(false)} studentCount={studentCount} />
      <VersionsDrawer open={versions} onClose={() => setVersions(false)} layoutId={layoutId} dirty={dirty} canManage={canManage} onRestored={onReload} />
      <ConfirmDialog
        open={blocker.state === 'blocked'}
        onClose={() => blocker.reset?.()}
        onConfirm={() => blocker.proceed?.()}
        title="Kaydedilmemiş değişiklikler var"
        description="Sayfadan çıkarsanız son kayıttan sonraki değişiklikler kaybolur."
        confirmLabel="Kaydetmeden çık"
        danger
      />
      <ConfirmDialog
        open={conflict}
        onClose={() => setConflict(false)}
        onConfirm={() => {
          setConflict(false)
          doSave(true)
        }}
        title="Tasarım başka bir yerde değişti"
        description="Siz açtıktan sonra bu tasarım başka bir oturumda kaydedilmiş. Değişikliklerinizi kaybetmemek için kopya olarak kaydedebilirsiniz."
        confirmLabel="Kopya olarak kaydet"
      />
    </div>,
    document.body,
  )
}
