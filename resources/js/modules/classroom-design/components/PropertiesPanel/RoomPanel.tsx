import { useState } from 'react'
import { toast } from 'sonner'
import { DoorOpen, PanelTop, PenLine, Trash2 } from 'lucide-react'
import { useClassroom } from '../../state/classroomStore'
import { useSelection } from '../../state/selectionStore'
import type { DoorSwing, Opening } from '../../types'
import { clampOpening, newId } from '../../utils/doc'
import { formatArea, formatMeters } from '../../utils/measurement'
import { area, edgeAt, edges, removeVertex, templatePolygon, TEMPLATE_LABELS, validatePolygon, type TemplateKind, type TemplateParams } from '../../utils/polygon'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/feedback'
import { Select } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import { cn } from '@/lib/cn'
import { NumberField } from './NumberField'

/**
 * ODA paneli: zemin çokgeni (şablon + köşe düzenleme), tavan yüksekliği, duvar kalınlığı, duvar listesi,
 * kapı/pencere ekleme ve düzenleme. Tüm değişiklikler geri alınabilir.
 */

export const SWING_LABELS: Record<DoorSwing, string> = {
  'in-left': 'İçe · menteşe solda',
  'in-right': 'İçe · menteşe sağda',
  'out-left': 'Dışa · menteşe solda',
  'out-right': 'Dışa · menteşe sağda',
}

export function wallLabel(i: number, len: number) {
  return `Duvar ${i + 1} · ${formatMeters(len)}`
}

export function OpeningEditor({ id, readOnly }: { id: string; readOnly?: boolean }) {
  const openings = useClassroom((s) => s.doc.openings)
  const polygon = useClassroom((s) => s.doc.room.polygon)
  const ceiling = useClassroom((s) => s.doc.room.ceiling)
  const o = openings.find((x) => x.id === id)
  if (!o) return null
  const e = edgeAt(polygon, Math.min(o.wall, polygon.length - 1))
  const update = (patch: Partial<Opening>) => {
    const next = clampOpening({ ...o, ...patch }, polygon)
    const overlap = openings.some((x) => x.id !== o.id && x.wall === next.wall && Math.abs(x.offset - next.offset) < (x.width + next.width) / 2 + 0.05)
    if (overlap) {
      toast.error('Bu duvarda başka bir kapı/pencereyle çakışıyor.')
      return
    }
    if (next.kind === 'window' && next.sill + next.height > ceiling - 0.05) {
      toast.error('Pencere tavana taşıyor.')
      return
    }
    useClassroom.getState().setOpenings(openings.map((x) => (x.id === o.id ? next : x)))
  }
  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <h3 className="text-[14px] font-semibold text-ink">{o.kind === 'door' ? 'Kapı' : 'Pencere'}</h3>
        {!readOnly && (
          <Button
            size="xs"
            variant="danger-soft"
            icon={<Trash2 className="size-3.5" />}
            onClick={() => {
              useClassroom.getState().setOpenings(openings.filter((x) => x.id !== o.id))
              useSelection.getState().selectOpening(null)
            }}
          >
            Kaldır
          </Button>
        )}
      </div>
      <label className="flex flex-col gap-1">
        <span className="text-[11.5px] font-medium text-ink-3">Duvar</span>
        <Select
          value={o.wall}
          disabled={readOnly}
          onChange={(ev) => update({ wall: Number(ev.target.value), offset: edgeAt(polygon, Number(ev.target.value)).len / 2 })}
          options={edges(polygon).map((w) => ({ value: w.i, label: wallLabel(w.i, w.len) }))}
        />
      </label>
      <div className="grid grid-cols-2 gap-2">
        <NumberField label="Konum (merkez)" value={o.offset} scale={100} unit="cm" step={5} min={0} max={e.len} disabled={readOnly} onCommit={(v) => update({ offset: v })} testId="opening-offset" />
        <NumberField label="Genişlik" value={o.width} scale={100} unit="cm" step={5} min={0.4} max={Math.min(e.len - 0.1, 4)} disabled={readOnly} onCommit={(v) => update({ width: v })} testId="opening-width" />
        <NumberField label="Yükseklik" value={o.height} scale={100} unit="cm" step={5} min={0.3} max={ceiling - 0.05} disabled={readOnly} onCommit={(v) => update({ height: v })} />
        {o.kind === 'window' && (
          <NumberField label="Yerden yükseklik" value={o.sill} scale={100} unit="cm" step={5} min={0} max={ceiling - 0.4} disabled={readOnly} onCommit={(v) => update({ sill: v })} />
        )}
      </div>
      {o.kind === 'door' && (
        <label className="flex flex-col gap-1">
          <span className="text-[11.5px] font-medium text-ink-3">Açılma yönü</span>
          <Select value={o.swing ?? 'in-left'} disabled={readOnly} onChange={(ev) => update({ swing: ev.target.value as DoorSwing })} options={Object.entries(SWING_LABELS).map(([value, label]) => ({ value, label }))} />
        </label>
      )}
      <p className="text-[11.5px] text-ink-3">Planda kapıyı/pencereyi tutup duvar boyunca kaydırabilirsiniz.</p>
    </div>
  )
}

export function RoomShapeEditor({ onApply, applyLabel = 'Şablonu uygula', initial }: { onApply: (poly: ReturnType<typeof templatePolygon>) => void; applyLabel?: string; initial?: { kind: TemplateKind; params: TemplateParams } }) {
  const [kind, setKind] = useState<TemplateKind>(initial?.kind ?? 'rect')
  const [p, setP] = useState<TemplateParams>(initial?.params ?? { width: 7.2, depth: 5.8, cutW: 2.4, cutD: 1.8 })
  const poly = templatePolygon(kind, p)
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-4 gap-1.5">
        {(Object.keys(TEMPLATE_LABELS) as TemplateKind[]).map((k) => (
          <button
            key={k}
            type="button"
            onClick={() => setKind(k)}
            className={cn('flex flex-col items-center gap-1 rounded-[var(--radius-sm)] border p-1.5 text-[11px] font-medium', kind === k ? 'border-primary bg-primary-soft text-primary-ink' : 'border-line text-ink-2 hover:border-line-strong')}
            data-testid={`tpl-${k}`}
          >
            <TemplateIcon kind={k} />
            {TEMPLATE_LABELS[k]}
          </button>
        ))}
      </div>
      <div className="grid grid-cols-2 gap-2">
        <NumberField label="Genişlik" value={p.width} scale={100} unit="cm" step={10} min={2} max={40} onCommit={(v) => setP({ ...p, width: v })} testId="tpl-width" />
        <NumberField label="Derinlik" value={p.depth} scale={100} unit="cm" step={10} min={2} max={40} onCommit={(v) => setP({ ...p, depth: v })} testId="tpl-depth" />
        {kind !== 'rect' && (
          <>
            <NumberField label={kind === 'u' ? 'Girinti genişliği' : 'Kesik genişliği'} value={p.cutW} scale={100} unit="cm" step={10} min={0.3} max={p.width - 0.5} onCommit={(v) => setP({ ...p, cutW: v })} />
            <NumberField label={kind === 'u' ? 'Girinti derinliği' : 'Kesik derinliği'} value={p.cutD} scale={100} unit="cm" step={10} min={0.3} max={p.depth - 0.5} onCommit={(v) => setP({ ...p, cutD: v })} />
          </>
        )}
      </div>
      <div className="flex items-center justify-between gap-2">
        <span className="text-[12px] text-ink-3">Alan {formatArea(area(poly))}</span>
        <Button size="sm" variant="secondary" onClick={() => onApply(poly)} data-testid="tpl-apply">
          {applyLabel}
        </Button>
      </div>
    </div>
  )
}

function TemplateIcon({ kind }: { kind: TemplateKind }) {
  const pts = templatePolygon(kind, { width: 10, depth: 8, cutW: 4, cutD: 3 })
  return (
    <svg viewBox="-1 -1 12 10" className="h-6 w-8">
      <polygon points={pts.map((p) => `${p.x},${p.z}`).join(' ')} fill="currentColor" fillOpacity={0.12} stroke="currentColor" strokeWidth={0.8} />
    </svg>
  )
}

export function RoomPanel({ readOnly }: { readOnly?: boolean }) {
  const room = useClassroom((s) => s.doc.room)
  const openings = useClassroom((s) => s.doc.openings)
  const objectsCount = useClassroom((s) => s.doc.order.length)
  const tool = useSelection((s) => s.tool)
  const vertex = useSelection((s) => s.vertex)
  const openingId = useSelection((s) => s.openingId)
  const view = useSelection((s) => s.view)
  const [confirmTpl, setConfirmTpl] = useState<ReturnType<typeof templatePolygon> | null>(null)
  const problems = validatePolygon(room.polygon)
  const perimeter = edges(room.polygon).reduce((n, e) => n + e.len, 0)

  const addOpening = (kind: 'door' | 'window', wall: number) => {
    const e = edgeAt(room.polygon, wall)
    const width = kind === 'door' ? 0.9 : 1.2
    // duvarda boş bir yer ara
    const taken = openings.filter((o) => o.wall === wall)
    let offset = e.len / 2
    for (let s = width / 2 + 0.1; s <= e.len - width / 2 - 0.1; s += 0.1) {
      if (taken.every((o) => Math.abs(o.offset - s) >= (o.width + width) / 2 + 0.1)) {
        offset = s
        if (Math.abs(s - e.len / 2) < 0.6 || kind === 'door') break
      }
    }
    if (taken.some((o) => Math.abs(o.offset - offset) < (o.width + width) / 2 + 0.05) || e.len < width + 0.2) {
      toast.error('Bu duvarda yeni açıklık için yer yok.')
      return
    }
    const o: Opening = clampOpening(
      kind === 'door'
        ? { id: newId('d'), kind, wall, offset, width, height: Math.min(2.1, room.ceiling - 0.2), sill: 0, swing: 'in-left' }
        : { id: newId('w'), kind, wall, offset, width, height: Math.min(1.4, room.ceiling - 1.2), sill: 0.9 },
      room.polygon,
    )
    useClassroom.getState().setOpenings([...openings, o])
    useSelection.getState().selectOpening(o.id)
  }

  return (
    <div className="space-y-5 p-3">
      <section className="space-y-2">
        <div className="flex items-center justify-between">
          <h3 className="text-[13px] font-semibold text-ink">Zemin planı</h3>
          <span className="text-[11.5px] text-ink-3 tabular-nums">{room.polygon.length} köşe · {formatArea(area(room.polygon))} · çevre {formatMeters(perimeter)}</span>
        </div>
        {problems.length > 0 && <Alert tone="danger">{problems.map((p) => p.message).join(' ')}</Alert>}
        {!readOnly && (
          <Button
            size="sm"
            variant={tool === 'room' ? 'primary' : 'secondary'}
            icon={<PenLine className="size-4" />}
            onClick={() => {
              const s = useSelection.getState()
              if (tool === 'room') s.setTool('select')
              else {
                s.setTool('room')
                if (view === '3d') s.setView('split')
              }
            }}
            data-testid="room-edit"
          >
            {tool === 'room' ? 'Köşe düzenlemeyi bitir' : 'Köşeleri düzenle (2D planda)'}
          </Button>
        )}
        {tool === 'room' && (
          <p className="text-[11.5px] leading-snug text-ink-3">
            Köşeyi sürükleyin (ızgaraya yapışır), kenar ortasındaki <b>+</b> ile köşe ekleyin, köşeyi seçip <b>Delete</b> ile silin.
          </p>
        )}
        {tool === 'room' && vertex !== null && room.polygon[vertex] && !readOnly && (
          <div className="rounded-[var(--radius-sm)] border border-line p-2">
            <div className="mb-2 flex items-center justify-between">
              <span className="text-[12px] font-medium">Köşe {vertex + 1}</span>
              <Button
                size="xs"
                variant="danger-soft"
                disabled={room.polygon.length <= 3}
                onClick={() => {
                  useClassroom.getState().setPolygon(removeVertex(room.polygon, vertex), true)
                  useSelection.getState().selectVertex(null)
                }}
              >
                Köşeyi sil
              </Button>
            </div>
            <div className="grid grid-cols-2 gap-2">
              {(['x', 'z'] as const).map((axis) => (
                <NumberField
                  key={axis}
                  label={axis.toUpperCase()}
                  value={room.polygon[vertex]![axis]}
                  scale={100}
                  unit="cm"
                  onCommit={(v) => useClassroom.getState().setPolygon(room.polygon.map((p, i) => (i === vertex ? { ...p, [axis]: Math.round(v * 1000) / 1000 } : p)), false)}
                />
              ))}
            </div>
          </div>
        )}
      </section>

      <section className="grid grid-cols-2 gap-2">
        <NumberField label="Tavan yüksekliği" value={room.ceiling} scale={100} unit="cm" step={5} min={2.2} max={6} disabled={readOnly} onCommit={(v) => useClassroom.getState().setRoom({ ceiling: v })} testId="room-ceiling" />
        <NumberField label="Duvar kalınlığı" value={room.wallThickness} scale={100} unit="cm" step={1} min={0.08} max={0.6} disabled={readOnly} onCommit={(v) => useClassroom.getState().setRoom({ wallThickness: v })} testId="room-wall" />
      </section>

      <section className="space-y-2">
        <h3 className="text-[13px] font-semibold text-ink">Duvarlar, kapı ve pencereler</h3>
        <ul className="divide-y divide-line rounded-[var(--radius-sm)] border border-line">
          {edges(room.polygon).map((e) => {
            const list = openings.filter((o) => o.wall === e.i)
            return (
              <li key={e.i} className="px-2.5 py-2">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-[12.5px] font-medium tabular-nums">{wallLabel(e.i, e.len)}</span>
                  {!readOnly && (
                    <span className="flex gap-1">
                      <Button size="xs" variant="ghost" icon={<DoorOpen className="size-3.5" />} onClick={() => addOpening('door', e.i)}>Kapı</Button>
                      <Button size="xs" variant="ghost" icon={<PanelTop className="size-3.5" />} onClick={() => addOpening('window', e.i)}>Pencere</Button>
                    </span>
                  )}
                </div>
                {list.length > 0 && (
                  <div className="mt-1 flex flex-wrap gap-1">
                    {list.map((o) => (
                      <button
                        key={o.id}
                        type="button"
                        onClick={() => useSelection.getState().selectOpening(o.id)}
                        className={cn('rounded-[4px] border px-1.5 py-0.5 text-[11.5px]', openingId === o.id ? 'border-accent bg-accent-soft text-accent' : 'border-line text-ink-2 hover:border-line-strong')}
                      >
                        {o.kind === 'door' ? 'Kapı' : 'Pencere'} {Math.round(o.width * 100)} cm
                      </button>
                    ))}
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      </section>

      {openingId && (
        <section className="rounded-[var(--radius-sm)] border border-accent/40 p-2.5">
          <OpeningEditor id={openingId} readOnly={readOnly} />
        </section>
      )}

      {!readOnly && (
        <section className="space-y-2">
          <h3 className="text-[13px] font-semibold text-ink">Şablondan yeniden oluştur</h3>
          <RoomShapeEditor onApply={(poly) => setConfirmTpl(poly)} />
        </section>
      )}
      <ConfirmDialog
        open={!!confirmTpl}
        onClose={() => setConfirmTpl(null)}
        onConfirm={() => {
          if (confirmTpl) useClassroom.getState().setPolygon(confirmTpl, true)
          setConfirmTpl(null)
        }}
        title="Zemin planı değiştirilsin mi?"
        description={`Kapı ve pencereler en yakın duvara taşınır; ${objectsCount} nesne yerinde kalır (dışarıda kalanlar kırmızı görünür). Geri almak için Ctrl+Z.`}
        confirmLabel="Uygula"
      />
    </div>
  )
}
