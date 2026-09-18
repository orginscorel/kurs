import type { ReactNode } from 'react'
import { Box, Columns2, Grid3x3, Hash, Magnet, Maximize, MousePointer2, PenLine, Redo2, Ruler, Sparkles, Square, Tags, Trash, Undo2 } from 'lucide-react'
import { useClassroom } from '../../state/classroomStore'
import { useHistory } from '../../state/historyStore'
import { useSelection, type Tool, type ViewMode } from '../../state/selectionStore'
import { renumberDesks } from '../../state/actions'
import { detectBoardWall } from '../../utils/layoutGenerator'
import { GRID_SIZES } from '../../utils/snap'
import { sceneBridge } from '../../three/sceneBridge'
import { cn } from '@/lib/cn'

/** ARAÇ ÇUBUĞU — geri al/yinele, araçlar (seç, ölçü, oda), ızgara + yapışma, etiketler, görünüm, akıllı yerleşim, numaralandır */

function TB({ active, onClick, title, children, disabled, testId }: { active?: boolean; onClick: () => void; title: string; children: ReactNode; disabled?: boolean; testId?: string }) {
  return (
    <button
      type="button"
      title={title}
      aria-label={title}
      aria-pressed={active}
      disabled={disabled}
      onClick={onClick}
      data-testid={testId}
      className={cn(
        'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-[6px] px-2 text-[12.5px] font-medium transition-colors disabled:opacity-40 [&_svg]:size-4',
        active ? 'bg-primary text-white' : 'text-ink-2 hover:bg-surface-2 hover:text-ink',
      )}
    >
      {children}
    </button>
  )
}

const Sep = () => <span className="mx-1 h-5 w-px shrink-0 bg-line" />

export function Toolbar({ readOnly, onSmartLayout }: { readOnly: boolean; onSmartLayout: () => void }) {
  const canUndo = useHistory((s) => s.past.length > 0)
  const canRedo = useHistory((s) => s.future.length > 0)
  const tool = useSelection((s) => s.tool)
  const view = useSelection((s) => s.view)
  const measures = useSelection((s) => s.measurements.length)
  const settings = useClassroom((s) => s.settings)
  const setSettings = useClassroom((s) => s.setSettings)
  const setTool = (t: Tool) => useSelection.getState().setTool(tool === t && t !== 'select' ? 'select' : t)
  const setView = (v: ViewMode) => useSelection.getState().setView(v)

  return (
    <div className="flex h-11 items-center gap-0.5 overflow-x-auto scroll-thin border-b border-line bg-surface px-2" role="toolbar" aria-label="Araçlar">
      {!readOnly && (
        <>
          <TB title="Geri al (Ctrl+Z)" disabled={!canUndo} onClick={() => useClassroom.getState().undo()} testId="tb-undo"><Undo2 /></TB>
          <TB title="Yinele (Ctrl+Shift+Z)" disabled={!canRedo} onClick={() => useClassroom.getState().redo()} testId="tb-redo"><Redo2 /></TB>
          <Sep />
        </>
      )}
      <TB title="Seç ve taşı (V)" active={tool === 'select'} onClick={() => setTool('select')}><MousePointer2 /><span className="max-md:hidden">Seç</span></TB>
      <TB title="Ölçü aracı (M): iki noktaya tıklayın" active={tool === 'measure'} onClick={() => setTool('measure')} testId="tb-measure"><Ruler /><span className="max-md:hidden">Ölç</span></TB>
      {measures > 0 && <TB title="Ölçümleri temizle" onClick={() => useSelection.getState().clearMeasurements()}><Trash /></TB>}
      {!readOnly && (
        <TB
          title="Oda köşelerini düzenle (2D planda)"
          active={tool === 'room'}
          onClick={() => {
            setTool('room')
            if (tool !== 'room' && view === '3d') setView('split')
          }}
        >
          <PenLine />
          <span className="max-md:hidden">Oda</span>
        </TB>
      )}
      <Sep />
      <label className="inline-flex h-8 shrink-0 items-center gap-1 rounded-[6px] px-1.5 text-[12.5px] text-ink-2" title="Izgara aralığı">
        <Grid3x3 className="size-4" />
        <select
          value={settings.grid}
          onChange={(e) => setSettings({ grid: Number(e.target.value) })}
          className="h-7 cursor-pointer rounded-[4px] border border-line bg-surface px-1 text-[12.5px] text-ink focus:outline-none"
          aria-label="Izgara aralığı"
          data-testid="tb-grid"
        >
          {GRID_SIZES.map((g) => <option key={g} value={g}>{Math.round(g * 100)} cm</option>)}
        </select>
      </label>
      <TB title={settings.snap ? 'Izgaraya yapıştır: açık (Alt ile geçici serbest)' : 'Izgaraya yapıştır: kapalı'} active={settings.snap} onClick={() => setSettings({ snap: !settings.snap })} testId="tb-snap"><Magnet /><span className="max-lg:hidden">Yapıştır</span></TB>
      <TB title="Masa no ve öğrenci adı etiketleri" active={settings.labels} onClick={() => setSettings({ labels: !settings.labels })} testId="tb-labels"><Tags /><span className="max-lg:hidden">Etiketler</span></TB>
      <Sep />
      <div className="inline-flex shrink-0 rounded-[6px] bg-surface-2 p-0.5 ring-1 ring-line" role="group" aria-label="Görünüm">
        {([['3d', '3D', Box], ['2d', '2D plan', Square], ['split', 'Bölünmüş', Columns2]] as const).map(([v, label, Icon]) => (
          <button
            key={v}
            type="button"
            onClick={() => setView(v)}
            data-testid={`tb-view-${v}`}
            className={cn('inline-flex h-7 items-center gap-1 rounded-[5px] px-2 text-[12px] font-medium [&_svg]:size-3.5', view === v ? 'bg-surface text-ink shadow-[var(--shadow-soft)]' : 'text-ink-2 hover:text-ink', v === 'split' && 'max-md:hidden')}
          >
            <Icon />
            {label}
          </button>
        ))}
      </div>
      <TB title="Görünümü odaya sığdır" onClick={() => sceneBridge()?.frame()}><Maximize /></TB>
      {!readOnly && (
        <>
          <Sep />
          <TB title="Akıllı yerleşim: odaya göre masa düzeni üret" onClick={onSmartLayout} testId="tb-smart"><Sparkles /><span className="max-lg:hidden">Akıllı yerleşim</span></TB>
          <TB
            title="Masaları tahtaya göre önden arkaya, soldan sağa numaralandır"
            onClick={() => {
              const d = useClassroom.getState().doc
              renumberDesks(detectBoardWall(d.room, d.order.map((id) => d.objects[id]!)))
            }}
          >
            <Hash />
            <span className="max-xl:hidden">Numaralandır</span>
          </TB>
        </>
      )}
    </div>
  )
}
