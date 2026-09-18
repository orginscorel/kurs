import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { catalogOf, isDesk, localFootprint, mountOf, TEACHER_CHAIR_ZONE } from '../../catalog'
import { useClassroom } from '../../state/classroomStore'
import { registerResolver, useDrag } from '../../state/dragStore'
import { useSelection } from '../../state/selectionStore'
import { useGhosts } from '../../state/ghostStore'
import { useSeatingContext } from '../../state/seatingContext'
import { endGesture, startMove, startRotate, updateMove, updateRotate } from '../../state/actions'
import type { Opening, Room, SceneObject, Vec2 } from '../../types'
import { footprintQuad, rectQuad, toLocal, toWorld } from '../../utils/collision'
import { clampOpening } from '../../utils/doc'
import { bbox, distPointSegment, edgeAt, edges, insertVertex, moveVertex, offsetPolygon, pointInPolygon } from '../../utils/polygon'
import { formatCm, formatMeters, formatLength } from '../../utils/measurement'
import { snapValue } from '../../utils/snap'
import { newId } from '../../utils/doc'
import { deskState } from '../../utils/seating'
import { SEAT_COLORS } from '../../three/materials'
import { shortName } from '../../three/Overlays3D'

/**
 * 2D PLAN — üstten ortografik mimari çizim (SVG). 3D ile AYNI depodan okur/yazar (anlık eşitleme).
 * Duvar ölçüleri "── 7,20 m ──", kapı açılma yayları, pencereler, nesne ayak izleri, masa no + öğrenci.
 * Planda da sürükle-taşı, döndürme tutamağı, seçim kutusu, ölçüm; "Oda" aracında köşe sürükle/ekle/sil ve
 * kapı/pencereyi duvar boyunca kaydırma.
 */

type View = { x0: number; z0: number; scale: number }

const PAD = 60

function useSize(ref: React.RefObject<HTMLElement | null>) {
  const [size, setSize] = useState({ w: 800, h: 600 })
  useEffect(() => {
    const el = ref.current
    if (!el) return
    const ro = new ResizeObserver(() => setSize({ w: el.clientWidth || 800, h: el.clientHeight || 600 }))
    ro.observe(el)
    setSize({ w: el.clientWidth || 800, h: el.clientHeight || 600 })
    return () => ro.disconnect()
  }, [ref])
  return size
}

export function PlanView({ className, roomOnly = false, readOnly = false }: { className?: string; roomOnly?: boolean; readOnly?: boolean }) {
  const wrap = useRef<HTMLDivElement>(null)
  const svg = useRef<SVGSVGElement>(null)
  const size = useSize(wrap)
  const doc = useClassroom((s) => s.doc)
  const settings = useClassroom((s) => s.settings)
  const layoutId = useClassroom((s) => s.layoutId)
  const selected = useSelection((s) => s.selected)
  const invalid = useSelection((s) => s.invalid)
  const hover = useSelection((s) => s.hover)
  const tool = useSelection((s) => s.tool)
  const openingId = useSelection((s) => s.openingId)
  const vertex = useSelection((s) => s.vertex)
  const measurements = useSelection((s) => s.measurements)
  const pending = useSelection((s) => s.pendingMeasure)
  const preview = useDrag((s) => s.preview)
  const previewValid = useDrag((s) => s.previewValid)
  const dropTarget = useDrag((s) => s.target)
  const ghosts = useGhosts((s) => s.list)
  const seating = useSeatingContext((s) => s.active)
  const [view, setView] = useState<View | null>(null)
  const [marquee, setMarquee] = useState<{ x0: number; y0: number; x1: number; y1: number } | null>(null)
  const [hoverPoint, setHoverPoint] = useState<Vec2 | null>(null)

  const room = doc.room
  const t = room.wallThickness

  // Odaya sığdır (ilk açılış, tasarım değişimi, boyut değişimi)
  const fit = useCallback(() => {
    const b = bbox(offsetPolygon(room.polygon, t))
    const scale = Math.max(8, Math.min((size.w - PAD * 2) / Math.max(b.w, 1), (size.h - PAD * 2) / Math.max(b.d, 1)))
    setView({ scale, x0: (b.minX + b.maxX) / 2 - size.w / 2 / scale, z0: (b.minZ + b.maxZ) / 2 - size.h / 2 / scale })
  }, [room.polygon, t, size.w, size.h])
  const fitted = useRef<unknown>(null)
  useEffect(() => {
    const key = `${layoutId}-${size.w}-${size.h}-${roomOnly ? room.polygon.length : ''}`
    if (fitted.current === key) return
    fitted.current = key
    fit()
  }, [layoutId, size.w, size.h, fit, roomOnly, room.polygon.length])

  const v = view ?? { x0: -1, z0: -1, scale: 60 }
  const sx = (x: number) => (x - v.x0) * v.scale
  const sy = (z: number) => (z - v.z0) * v.scale
  const P = (p: Vec2) => `${sx(p.x).toFixed(1)},${sy(p.z).toFixed(1)}`
  const toPlan = useCallback(
    (clientX: number, clientY: number): Vec2 => {
      const r = svg.current!.getBoundingClientRect()
      const vv = view ?? v
      return { x: (clientX - r.left) / vv.scale + vv.x0, z: (clientY - r.top) / vv.scale + vv.z0 }
    },
    [view], // eslint-disable-line react-hooks/exhaustive-deps
  )
  const viewRef = useRef(v)
  viewRef.current = v

  // ------------------------------------------------------------ bırakma çözücüsü (kütüphane / öğrenci)
  useEffect(
    () =>
      registerResolver('2d', {
        el: () => wrap.current,
        resolve: (cx, cy) => {
          const p = toPlan(cx, cy)
          const d = useClassroom.getState().doc
          let objectId: string | null = null
          let seat: number | null = null
          for (let i = d.order.length - 1; i >= 0; i--) {
            const o = d.objects[d.order[i]!]!
            if (!pointInQuad(p, footprintQuad(o, d.room.ceiling))) continue
            objectId = o.id
            if (isDesk(o)) seat = o.type === 'desk-double' ? (toLocal(o, p.x, p.z).x < 0 ? 0 : 1) : 0
            break
          }
          return { point: p, objectId, seat }
        },
      }),
    [toPlan],
  )

  // ------------------------------------------------------------ yakınlaştır / kaydır
  const onWheel = (e: React.WheelEvent) => {
    const r = svg.current!.getBoundingClientRect()
    const mx = e.clientX - r.left
    const my = e.clientY - r.top
    const k = Math.exp(-e.deltaY * 0.0015)
    setView((cur) => {
      const c = cur ?? v
      const scale = Math.min(600, Math.max(6, c.scale * k))
      const wx = mx / c.scale + c.x0
      const wz = my / c.scale + c.z0
      return { scale, x0: wx - mx / scale, z0: wz - my / scale }
    })
  }
  useEffect(() => {
    const el = svg.current
    if (!el) return
    const stop = (e: WheelEvent) => e.preventDefault()
    el.addEventListener('wheel', stop, { passive: false })
    return () => el.removeEventListener('wheel', stop)
  }, [])

  const pointers = useRef(new Map<number, { x: number; y: number }>())
  const pinch = useRef<{ d: number; scale: number; mid: { x: number; y: number }; x0: number; z0: number } | null>(null)

  const startPan = (e: React.PointerEvent) => {
    const start = { x: e.clientX, y: e.clientY, x0: v.x0, z0: v.z0 }
    const scale = v.scale
    const move = (m: PointerEvent) => setView({ scale, x0: start.x0 - (m.clientX - start.x) / scale, z0: start.z0 - (m.clientY - start.y) / scale })
    const up = () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  const track = (onMove: (e: PointerEvent) => void, onUp: (e: PointerEvent) => void) => {
    const move = (e: PointerEvent) => onMove(e)
    const up = (e: PointerEvent) => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
      window.removeEventListener('pointercancel', up)
      onUp(e)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
    window.addEventListener('pointercancel', up)
  }

  const measureAt = (p: Vec2) => {
    const s = useSelection.getState()
    let q = { x: Math.round(p.x * 100) / 100, z: Math.round(p.z * 100) / 100 }
    for (const c of room.polygon) if (Math.hypot(c.x - p.x, c.z - p.z) < 12 / v.scale) q = { x: c.x, z: c.z }
    if (!s.pendingMeasure) s.setPendingMeasure(q)
    else s.addMeasurement({ id: newId('m'), a: s.pendingMeasure, b: q })
  }

  // Boş alana basış: seçim kutusu (fare) / kaydırma (orta-sağ tuş, dokunmatik tek parmak) / ölçüm
  const onBackgroundDown = (e: React.PointerEvent) => {
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    if (pointers.current.size === 2) {
      const [a, b] = [...pointers.current.values()]
      pinch.current = { d: Math.hypot(a!.x - b!.x, a!.y - b!.y), scale: v.scale, mid: { x: (a!.x + b!.x) / 2, y: (a!.y + b!.y) / 2 }, x0: v.x0, z0: v.z0 }
      return
    }
    if (e.button === 1 || e.button === 2 || (e.pointerType === 'touch' && tool !== 'measure')) {
      if (e.pointerType === 'touch') {
        const sx0 = e.clientX
        const sy0 = e.clientY
        const clearIfTap = (u: PointerEvent) => {
          window.removeEventListener('pointerup', clearIfTap)
          if (Math.hypot(u.clientX - sx0, u.clientY - sy0) < 6) useSelection.getState().clear()
        }
        window.addEventListener('pointerup', clearIfTap)
      }
      startPan(e)
      return
    }
    if (e.button !== 0) return
    const p = toPlan(e.clientX, e.clientY)
    if (tool === 'measure') {
      measureAt(p)
      return
    }
    const r = svg.current!.getBoundingClientRect()
    const x0 = e.clientX - r.left
    const y0 = e.clientY - r.top
    const additive = e.shiftKey || e.ctrlKey || e.metaKey
    let moved = false
    track(
      (m) => {
        const x1 = m.clientX - r.left
        const y1 = m.clientY - r.top
        if (!moved && Math.hypot(x1 - x0, y1 - y0) < 5) return
        moved = true
        setMarquee({ x0, y0, x1, y1 })
      },
      (u) => {
        setMarquee(null)
        const s = useSelection.getState()
        if (!moved) {
          if (!additive) s.clear()
          return
        }
        const a = toPlan(Math.min(e.clientX, u.clientX), Math.min(e.clientY, u.clientY))
        const b = toPlan(Math.max(e.clientX, u.clientX), Math.max(e.clientY, u.clientY))
        const d = useClassroom.getState().doc
        s.select(d.order.filter((id) => {
          const o = d.objects[id]!
          return o.x >= a.x && o.x <= b.x && o.z >= a.z && o.z <= b.z
        }), additive)
      },
    )
  }

  const onPointerMoveSvg = (e: React.PointerEvent) => {
    if (pointers.current.has(e.pointerId)) pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    if (pinch.current && pointers.current.size === 2) {
      const [a, b] = [...pointers.current.values()]
      const d = Math.hypot(a!.x - b!.x, a!.y - b!.y)
      const pc = pinch.current
      const scale = Math.min(600, Math.max(6, (pc.scale * d) / Math.max(pc.d, 1)))
      const r = svg.current!.getBoundingClientRect()
      const mx = pc.mid.x - r.left
      const my = pc.mid.y - r.top
      const wx = mx / pc.scale + pc.x0
      const wz = my / pc.scale + pc.z0
      setView({ scale, x0: wx - mx / scale, z0: wz - my / scale })
    }
    if (tool === 'measure' && pending) setHoverPoint(toPlan(e.clientX, e.clientY))
  }
  const onPointerUpSvg = (e: React.PointerEvent) => {
    pointers.current.delete(e.pointerId)
    if (pointers.current.size < 2) pinch.current = null
  }

  // ------------------------------------------------------------ nesne etkileşimi
  const onObjectDown = (o: SceneObject, e: React.PointerEvent) => {
    if (e.button !== 0) return
    e.stopPropagation()
    const s = useSelection.getState()
    const p = toPlan(e.clientX, e.clientY)
    if (tool === 'measure') {
      measureAt(p)
      return
    }
    if (e.shiftKey || e.ctrlKey || e.metaKey) {
      s.toggle(o.id)
      return
    }
    if (!s.selected.includes(o.id)) s.select([o.id])
    if (readOnly || roomOnly) return
    const ids = useSelection.getState().selected
    const sx0 = e.clientX
    const sy0 = e.clientY
    let started = false
    track(
      (m) => {
        if (!started) {
          if (Math.hypot(m.clientX - sx0, m.clientY - sy0) < 4) return
          started = startMove(ids, p)
          if (!started) return
        }
        updateMove(toPlan(m.clientX, m.clientY), { free: m.altKey })
      },
      () => {
        if (started) endGesture()
      },
    )
  }

  const onRotateDown = (o: SceneObject, e: React.PointerEvent) => {
    e.stopPropagation()
    if (readOnly || !startRotate(o.id, toPlan(e.clientX, e.clientY))) return
    track(
      (m) => updateRotate(toPlan(m.clientX, m.clientY), { fine: m.shiftKey }),
      () => endGesture(),
    )
  }

  // ------------------------------------------------------------ oda düzenleme (köşe + açıklık)
  const onVertexDown = (i: number, e: React.PointerEvent) => {
    e.stopPropagation()
    if (readOnly) return
    useSelection.getState().selectVertex(i)
    const cs = useClassroom.getState()
    cs.beginGesture()
    const base = cs.doc
    track(
      (m) => {
        const p = toPlan(m.clientX, m.clientY)
        const g = settings.snap ? settings.grid : 0
        const q = { x: g ? snapValue(p.x, g) : Math.round(p.x * 100) / 100, z: g ? snapValue(p.z, g) : Math.round(p.z * 100) / 100 }
        // Shift: önceki köşeyle yatay/dikey hizala
        const polygon = moveVertex(base.room.polygon, i, q)
        const openings = base.openings.map((o) => clampOpening(o, polygon))
        useClassroom.getState().updateGestureDoc({ ...base, room: { ...base.room, polygon }, openings })
      },
      () => useClassroom.getState().endDocGesture(),
    )
  }

  const onMidDown = (i: number, e: React.PointerEvent) => {
    e.stopPropagation()
    if (readOnly) return
    const cs = useClassroom.getState()
    const poly = insertVertex(cs.doc.room.polygon, i)
    cs.setPolygon(poly, true)
    useSelection.getState().selectVertex(i + 1)
  }

  const onOpeningDown = (o: Opening, e: React.PointerEvent) => {
    if (e.button !== 0 || tool === 'measure') return
    e.stopPropagation()
    useSelection.getState().selectOpening(o.id)
    if (readOnly) return
    const cs = useClassroom.getState()
    const base = cs.doc
    const e0 = edgeAt(base.room.polygon, o.wall)
    const sx0 = e.clientX
    const sy0 = e.clientY
    let started = false
    track(
      (m) => {
        if (!started) {
          if (Math.hypot(m.clientX - sx0, m.clientY - sy0) < 4) return
          started = true
          useClassroom.getState().beginGesture()
        }
        const p = toPlan(m.clientX, m.clientY)
        const r = distPointSegment(p, e0.a, e0.b)
        const g = settings.snap ? settings.grid : 0.01
        const offset = snapValue(r.t * e0.len, g)
        const openings = base.openings.map((x) => (x.id === o.id ? clampOpening({ ...x, offset }, base.room.polygon) : x))
        useClassroom.getState().updateGestureDoc({ ...base, openings })
      },
      () => {
        if (started) useClassroom.getState().endDocGesture()
      },
    )
  }

  // ------------------------------------------------------------ çizim
  const outer = useMemo(() => offsetPolygon(room.polygon, t), [room.polygon, t])
  const wallPath = `M${outer.map(P).join('L')}Z M${room.polygon.map(P).join('L')}Z`
  const objects = doc.order.map((id) => doc.objects[id]!).filter((o) => o && !(ghosts.length > 0 && isDesk(o)))
  const selSet = new Set(selected)
  const fs = Math.max(9, Math.min(13, v.scale * 0.16))

  return (
    <div ref={wrap} className={className} style={{ touchAction: 'none' }} data-testid="plan-2d" onContextMenu={(e) => e.preventDefault()}>
      <svg
        ref={svg}
        width={size.w}
        height={size.h}
        className="block select-none bg-[var(--plan-bg)]"
        style={{ ['--plan-bg' as string]: 'var(--surface-2)', cursor: tool === 'measure' ? 'crosshair' : undefined }}
        onWheel={onWheel}
        onPointerDown={onBackgroundDown}
        onPointerMove={onPointerMoveSvg}
        onPointerUp={onPointerUpSvg}
        onPointerCancel={onPointerUpSvg}
      >
        <defs>
          <pattern id="pv-grid" width={settings.grid * v.scale} height={settings.grid * v.scale} patternUnits="userSpaceOnUse" x={sx(0)} y={sy(0)}>
            <path d={`M ${settings.grid * v.scale} 0 L 0 0 0 ${settings.grid * v.scale}`} fill="none" stroke="var(--line)" strokeWidth={settings.grid * v.scale > 6 ? 0.6 : 0} />
          </pattern>
          <pattern id="pv-grid-major" width={v.scale} height={v.scale} patternUnits="userSpaceOnUse" x={sx(0)} y={sy(0)}>
            <path d={`M ${v.scale} 0 L 0 0 0 ${v.scale}`} fill="none" stroke="var(--line-strong)" strokeWidth={0.8} />
          </pattern>
          <pattern id="pv-hatch" width={6} height={6} patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
            <line x1={0} y1={0} x2={0} y2={6} stroke="var(--ink-3)" strokeWidth={1} />
          </pattern>
        </defs>
        <rect width={size.w} height={size.h} fill="url(#pv-grid)" />
        <rect width={size.w} height={size.h} fill="url(#pv-grid-major)" />

        {/* zemin + duvarlar */}
        <polygon points={room.polygon.map(P).join(' ')} fill="var(--surface)" />
        <path d={wallPath} fillRule="evenodd" fill="var(--ink-2)" />
        {doc.openings.map((o) => (
          <OpeningGlyph key={o.id} o={o} room={room} P={P} scale={v.scale} selected={openingId === o.id} onDown={(e) => onOpeningDown(o, e)} />
        ))}
        <Dimensions room={room} P={P} fs={fs} />

        {/* nesneler */}
        {!roomOnly &&
          objects.map((o) => (
            <ObjectGlyph
              key={o.id}
              o={o}
              room={room}
              P={P}
              scale={v.scale}
              labels={settings.labels}
              selected={selSet.has(o.id)}
              invalid={invalid.has(o.id)}
              hovered={hover === o.id}
              target={dropTarget?.id === o.id}
              seating={seating}
              onDown={onObjectDown}
              onHover={(id) => useSelection.getState().setHover(id)}
            />
          ))}
        {roomOnly && objects.map((o) => <polygon key={o.id} points={footprintQuad(o, room.ceiling).map(P).join(' ')} fill="var(--surface-3)" stroke="var(--line-strong)" strokeWidth={1} />)}

        {/* döndürme tutamağı */}
        {!roomOnly && !readOnly && selected.length === 1 && tool === 'select' && (() => {
          const o = doc.objects[selected[0]!]
          if (!o || o.locked || mountOf(o) === 'wall') return null
          const f = localFootprint(o, room.ceiling)
          const r = Math.hypot(f.maxX - f.minX, f.maxZ - f.minZ) / 2 + 0.2
          const hx = o.x + Math.sin(o.rot + Math.PI) * r
          const hz = o.z + Math.cos(o.rot + Math.PI) * r
          return (
            <g>
              <circle cx={sx(o.x)} cy={sy(o.z)} r={r * v.scale} fill="none" stroke="var(--accent)" strokeDasharray="4 4" strokeWidth={1} pointerEvents="none" />
              <line x1={sx(o.x)} y1={sy(o.z)} x2={sx(hx)} y2={sy(hz)} stroke="var(--accent)" strokeWidth={1} pointerEvents="none" />
              <circle cx={sx(hx)} cy={sy(hz)} r={7} fill="var(--accent)" stroke="var(--surface)" strokeWidth={2} style={{ cursor: 'grab' }} onPointerDown={(e) => onRotateDown(o, e)} data-testid="plan-rotate" />
            </g>
          )
        })()}

        {/* akıllı yerleşim önizlemesi */}
        {ghosts.map((g) => (
          <polygon key={g.id} points={footprintQuad(g, room.ceiling).map(P).join(' ')} fill="color-mix(in srgb, var(--accent) 18%, transparent)" stroke="var(--accent)" strokeDasharray="4 3" strokeWidth={1} pointerEvents="none" />
        ))}
        {/* sürükleme önizlemesi */}
        {preview && (
          <polygon points={footprintQuad(preview, room.ceiling).map(P).join(' ')} fill={previewValid ? 'color-mix(in srgb, var(--accent) 25%, transparent)' : 'color-mix(in srgb, var(--danger) 25%, transparent)'} stroke={previewValid ? 'var(--accent)' : 'var(--danger)'} strokeWidth={1.5} pointerEvents="none" />
        )}

        {/* oda düzenleme tutamakları */}
        {tool === 'room' && !readOnly && (
          <g>
            {edges(room.polygon).map((e) => {
              const m = { x: (e.a.x + e.b.x) / 2, z: (e.a.z + e.b.z) / 2 }
              return (
                <g key={`m${e.i}`} style={{ cursor: 'copy' }} onPointerDown={(ev) => onMidDown(e.i, ev)} data-testid={`plan-mid-${e.i}`}>
                  <circle cx={sx(m.x)} cy={sy(m.z)} r={8} fill="var(--surface)" stroke="var(--accent)" strokeWidth={1.5} />
                  <path d={`M${sx(m.x) - 4},${sy(m.z)}h8M${sx(m.x)},${sy(m.z) - 4}v8`} stroke="var(--accent)" strokeWidth={1.5} />
                </g>
              )
            })}
            {room.polygon.map((p, i) => (
              <circle key={`v${i}`} cx={sx(p.x)} cy={sy(p.z)} r={vertex === i ? 8 : 6.5} fill={vertex === i ? 'var(--accent)' : 'var(--surface)'} stroke="var(--accent)" strokeWidth={2} style={{ cursor: 'move' }} onPointerDown={(ev) => onVertexDown(i, ev)} data-testid={`plan-vertex-${i}`} />
            ))}
          </g>
        )}

        {/* ölçümler */}
        {measurements.map((m) => (
          <MeasureLine key={m.id} a={m.a} b={m.b} P={P} />
        ))}
        {pending && (
          <>
            <circle cx={sx(pending.x)} cy={sy(pending.z)} r={4} fill="var(--info)" />
            {hoverPoint && <MeasureLine a={pending} b={hoverPoint} P={P} live />}
          </>
        )}

        {marquee && (
          <rect x={Math.min(marquee.x0, marquee.x1)} y={Math.min(marquee.y0, marquee.y1)} width={Math.abs(marquee.x1 - marquee.x0)} height={Math.abs(marquee.y1 - marquee.y0)}
            fill="color-mix(in srgb, var(--accent) 10%, transparent)" stroke="var(--accent)" strokeWidth={1} pointerEvents="none" />
        )}
      </svg>
      <div className="pointer-events-none absolute bottom-2 left-2 rounded-[4px] bg-surface/90 px-1.5 py-0.5 text-[11px] text-ink-3 ring-1 ring-line tabular-nums">
        Izgara {formatCm(settings.grid)} · 1:{Math.round(100 / (v.scale / 37.8))}
      </div>
      <button type="button" onClick={fit} className="absolute bottom-2 right-2 rounded-[6px] bg-surface px-2 py-1 text-[12px] font-medium text-ink-2 ring-1 ring-line hover:text-ink">
        Plana sığdır
      </button>
    </div>
  )
}

function pointInQuad(p: Vec2, q: Vec2[]) {
  return pointInPolygon(p, q)
}

// ------------------------------------------------------------------ parçalar

const Dimensions = memo(function Dimensions({ room, P, fs }: { room: Room; P: (p: Vec2) => string; fs: number }) {
  const off = room.wallThickness + 0.32
  return (
    <g pointerEvents="none">
      {edges(room.polygon).map((e) => {
        const a = { x: e.a.x + e.n.x * off, z: e.a.z + e.n.z * off }
        const b = { x: e.b.x + e.n.x * off, z: e.b.z + e.n.z * off }
        const [ax, ay] = P(a).split(',').map(Number) as [number, number]
        const [bx, by] = P(b).split(',').map(Number) as [number, number]
        const [a0x, a0y] = P({ x: e.a.x + e.n.x * (room.wallThickness + 0.08), z: e.a.z + e.n.z * (room.wallThickness + 0.08) }).split(',').map(Number) as [number, number]
        const [b0x, b0y] = P({ x: e.b.x + e.n.x * (room.wallThickness + 0.08), z: e.b.z + e.n.z * (room.wallThickness + 0.08) }).split(',').map(Number) as [number, number]
        const mx = (ax + bx) / 2
        const my = (ay + by) / 2
        let ang = (Math.atan2(by - ay, bx - ax) * 180) / Math.PI
        if (ang > 90 || ang < -90) ang += 180
        const label = formatMeters(e.len)
        const px = Math.hypot(bx - ax, by - ay)
        const tw = label.length * fs * 0.62 + 10
        if (px < 24) return null
        return (
          <g key={e.i}>
            <line x1={a0x} y1={a0y} x2={ax} y2={ay} stroke="var(--ink-3)" strokeWidth={0.8} />
            <line x1={b0x} y1={b0y} x2={bx} y2={by} stroke="var(--ink-3)" strokeWidth={0.8} />
            <line x1={ax} y1={ay} x2={bx} y2={by} stroke="var(--ink-3)" strokeWidth={0.9} />
            <g transform={`translate(${mx},${my}) rotate(${ang})`}>
              {px > tw + 8 && <rect x={-tw / 2} y={-fs * 0.72} width={tw} height={fs * 1.44} fill="var(--surface-2)" />}
              <text textAnchor="middle" dominantBaseline="central" fontSize={fs} fontWeight={600} fill="var(--ink-2)" className="tabular-nums">
                {px > tw + 8 ? label : ''}
              </text>
            </g>
          </g>
        )
      })}
    </g>
  )
})

function OpeningGlyph({ o, room, P, scale, selected, onDown }: { o: Opening; room: Room; P: (p: Vec2) => string; scale: number; selected: boolean; onDown: (e: React.PointerEvent) => void }) {
  if (o.wall >= room.polygon.length) return null
  const e = edgeAt(room.polygon, o.wall)
  const t = room.wallThickness
  const at = (s: number, d: number): Vec2 => ({ x: e.a.x + e.dir.x * s - e.n.x * d, z: e.a.z + e.dir.z * s - e.n.z * d })
  const s0 = o.offset - o.width / 2
  const s1 = o.offset + o.width / 2
  const cut = [at(s0, 0), at(s1, 0), at(s1, -t), at(s0, -t)]
  const stroke = selected ? 'var(--accent)' : 'var(--ink-2)'
  if (o.kind === 'window') {
    return (
      <g onPointerDown={onDown} style={{ cursor: 'pointer' }} data-testid={`plan-opening-${o.id}`}>
        <polygon points={cut.map(P).join(' ')} fill="var(--surface)" stroke={stroke} strokeWidth={selected ? 2 : 1} />
        <line {...lineAttrs(at(s0, -t * 0.4), at(s1, -t * 0.4), P)} stroke={stroke} strokeWidth={1} />
        <line {...lineAttrs(at(s0, -t * 0.6), at(s1, -t * 0.6), P)} stroke={stroke} strokeWidth={1} />
      </g>
    )
  }
  const hingeRight = o.swing?.endsWith('right')
  const inward = !o.swing?.startsWith('out')
  const hs = hingeRight ? s1 : s0
  const other = hingeRight ? s0 : s1
  const depth = inward ? o.width : -o.width
  const hinge = at(hs, inward ? 0 : -t)
  const tip = at(hs, (inward ? 0 : -t) + depth)
  const closed = at(other, inward ? 0 : -t)
  const r = o.width * scale
  const [hx, hy] = P(hinge).split(',').map(Number) as [number, number]
  const [tx, ty] = P(tip).split(',').map(Number) as [number, number]
  const [cx, cy] = P(closed).split(',').map(Number) as [number, number]
  // yayın yönü (SVG süpürme bayrağı): çapraz çarpım işareti
  const cross = (tx - hx) * (cy - hy) - (ty - hy) * (cx - hx)
  const sweep = cross > 0 ? 1 : 0
  return (
    <g onPointerDown={onDown} style={{ cursor: 'pointer' }} data-testid={`plan-opening-${o.id}`}>
      <polygon points={cut.map(P).join(' ')} fill="var(--surface)" stroke={selected ? 'var(--accent)' : 'none'} strokeWidth={2} />
      <line x1={hx} y1={hy} x2={tx} y2={ty} stroke={stroke} strokeWidth={selected ? 2.5 : 2} />
      <path d={`M${tx},${ty} A${r},${r} 0 0 ${sweep} ${cx},${cy}`} fill="none" stroke={stroke} strokeWidth={1} strokeDasharray="4 3" />
    </g>
  )
}

function lineAttrs(a: Vec2, b: Vec2, P: (p: Vec2) => string) {
  const [x1, y1] = P(a).split(',').map(Number) as [number, number]
  const [x2, y2] = P(b).split(',').map(Number) as [number, number]
  return { x1, y1, x2, y2 }
}

function MeasureLine({ a, b, P, live }: { a: Vec2; b: Vec2; P: (p: Vec2) => string; live?: boolean }) {
  const { x1, y1, x2, y2 } = lineAttrs(a, b, P)
  const d = Math.hypot(b.x - a.x, b.z - a.z)
  return (
    <g pointerEvents="none">
      <line x1={x1} y1={y1} x2={x2} y2={y2} stroke="var(--info)" strokeWidth={1.5} strokeDasharray={live ? '5 4' : undefined} />
      <circle cx={x1} cy={y1} r={3.5} fill="var(--info)" />
      <circle cx={x2} cy={y2} r={3.5} fill="var(--info)" />
      <g transform={`translate(${(x1 + x2) / 2},${(y1 + y2) / 2 - 10})`}>
        <rect x={-38} y={-9} width={76} height={18} rx={3} fill="var(--info)" />
        <text textAnchor="middle" dominantBaseline="central" fontSize={11} fontWeight={600} fill="#fff" className="tabular-nums">{d < 1 ? formatCm(d) : formatLength(d)}</text>
      </g>
    </g>
  )
}

const ObjectGlyph = memo(function ObjectGlyph({
  o, room, P, scale, labels, selected, invalid, hovered, target, onDown, onHover, seating,
}: {
  seating: boolean
  o: SceneObject
  room: Room
  P: (p: Vec2) => string
  scale: number
  labels: boolean
  selected: boolean
  invalid: boolean
  hovered: boolean
  target: boolean
  onDown: (o: SceneObject, e: React.PointerEvent) => void
  onHover: (id: string | null) => void
}) {
  const c = catalogOf(o.type)
  const f = localFootprint(o, room.ceiling)
  const full = footprintQuad(o, room.ceiling)
  const bodyD = o.d ?? c?.d ?? 0.5
  const body = rectQuad(o, { minX: f.minX, maxX: f.maxX, minZ: -bodyD / 2, maxZ: bodyD / 2 })
  const stroke = invalid ? 'var(--danger)' : selected ? 'var(--accent)' : target ? 'var(--accent)' : hovered ? 'var(--info)' : 'var(--ink-3)'
  const sw = invalid || selected || target ? 2 : 1
  const mount = mountOf(o)
  let fill = 'var(--surface-3)'
  if (isDesk(o)) fill = 'color-mix(in srgb, #d8bf98 70%, var(--surface))'
  if (o.type === 'teacher-desk' || o.type === 'lectern' || o.type === 'bookshelf') fill = 'color-mix(in srgb, #7d5a3f 55%, var(--surface))'
  if (o.type === 'smartboard') fill = 'var(--ink)'
  if (o.type === 'whiteboard') fill = 'var(--surface)'
  if (o.type === 'column') fill = 'url(#pv-hatch)'
  const seatSpots = isDesk(o) ? (o.type === 'desk-double' ? [-0.3, 0.3] : [0]) : []
  const state = isDesk(o) ? (seating ? deskState(o) : 'empty') : null
  const names = seating ? (o.seats ?? []).filter(Boolean).map((s) => shortName(s!.name)) : []
  const [cx, cy] = P({ x: o.x, z: o.z }).split(',').map(Number) as [number, number]
  const fs = Math.max(8.5, Math.min(12, scale * 0.15))
  return (
    <g
      onPointerDown={(e) => onDown(o, e)}
      onPointerEnter={() => onHover(o.id)}
      onPointerLeave={() => onHover(null)}
      style={{ cursor: o.locked ? 'not-allowed' : 'move' }}
      data-testid={`plan-obj-${o.id}`}
      opacity={mount === 'ceiling' ? 0.75 : 1}
    >
      {c?.chair && o.chair && <polygon points={full.map(P).join(' ')} fill="transparent" stroke="var(--line-strong)" strokeDasharray="3 3" strokeWidth={1} />}
      <polygon points={body.map(P).join(' ')} fill={fill} stroke={stroke} strokeWidth={sw} strokeDasharray={mount === 'ceiling' ? '5 3' : undefined} />
      {/* bağlı sandalyeler */}
      {c?.chair && o.chair && seatSpots.map((sx0, i) => {
        const zc = (o.d ?? c.d) / 2 + c.chair!.zone / 2
        const q = rectQuad(o, { minX: sx0 - 0.19, maxX: sx0 + 0.19, minZ: zc - 0.17, maxZ: zc + 0.17 })
        const seat = seating ? o.seats?.[i] : null
        const color = state === 'unavailable' ? SEAT_COLORS.unavailable : seat ? SEAT_COLORS.full : state === 'reserved' ? SEAT_COLORS.reserved : SEAT_COLORS.empty
        return <polygon key={i} points={q.map(P).join(' ')} fill={color} stroke="var(--ink-3)" strokeWidth={0.8} />
      })}
      {o.type === 'teacher-desk' && o.chair && (() => {
        const [tx, ty] = P(toWorld(o, 0, bodyD / 2 + TEACHER_CHAIR_ZONE / 2 + 0.02)).split(',').map(Number) as [number, number]
        return <circle cx={tx} cy={ty} r={0.24 * scale} fill="var(--surface-3)" stroke="var(--ink-3)" strokeWidth={0.8} />
      })()}
      {o.locked && (
        <g transform={`translate(${cx - 5},${cy - fs - 12})`} pointerEvents="none">
          <rect x={0} y={4} width={10} height={8} rx={1.5} fill="var(--ink-2)" />
          <path d="M2.5 4.5V3a2.5 2.5 0 0 1 5 0v1.5" fill="none" stroke="var(--ink-2)" strokeWidth={1.4} />
        </g>
      )}
      {isDesk(o) && labels && scale > 28 && (
        <text x={cx} y={cy} textAnchor="middle" dominantBaseline="central" fontSize={fs} fontWeight={700} fill="var(--ink)" pointerEvents="none" className="tabular-nums">
          {o.no ?? ''}
        </text>
      )}
      {isDesk(o) && labels && names.length > 0 && scale > 40 && (
        <text x={cx} y={cy + fs * 1.15} textAnchor="middle" dominantBaseline="central" fontSize={fs * 0.82} fill="var(--ink-2)" pointerEvents="none">
          {names.join(' · ')}
        </text>
      )}
      {!isDesk(o) && labels && scale > 45 && c && mount !== 'wall' && (
        <text x={cx} y={cy} textAnchor="middle" dominantBaseline="central" fontSize={fs * 0.82} fill={o.type === 'smartboard' ? 'var(--surface)' : 'var(--ink-2)'} pointerEvents="none">
          {c.label}
        </text>
      )}
    </g>
  )
})

