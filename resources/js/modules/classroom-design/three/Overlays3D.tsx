import { memo, useEffect, useMemo } from 'react'
import { Html } from '@react-three/drei'
import type { ThreeEvent } from '@react-three/fiber'
import * as THREE from 'three'
import { elevationOf, isDesk, localFootprint, mountOf, verticalRange } from '../catalog'
import { useClassroom } from '../state/classroomStore'
import { useDrag } from '../state/dragStore'
import { useGhosts } from '../state/ghostStore'
import { useSeatingContext } from '../state/seatingContext'
import { useSelection } from '../state/selectionStore'
import type { Room, SceneObject, Vec2 } from '../types'
import { footprintQuad, nearestNeighbor } from '../utils/collision'
import { formatCm, formatLength } from '../utils/measurement'
import type { SceneTheme } from '../hooks/useSceneTheme'
import { materials } from './materials'
import { getModel } from './models'
import { objectMatrix } from './Furniture3D'

/** Sahne yardımcıları: seçim konturu, çakışma, taşıma/döndürme tutamakları, mesafe etiketi, önizleme, ölçüm, etiketler. */

const HELPER = { helper: true }

function lineLoop(points: Vec2[], y: number): THREE.BufferGeometry {
  return new THREE.BufferGeometry().setFromPoints([...points, points[0]!].map((p) => new THREE.Vector3(p.x, y, p.z)))
}

/** Nesnenin kutu kenarları (seçim konturu) */
const Outline = memo(function Outline({ o, room, color, dashed }: { o: SceneObject; room: Room; color: string; dashed?: boolean }) {
  const { line, box } = useMemo(() => {
    const q = footprintQuad(o, room.ceiling)
    const [y0, y1] = verticalRange(o, room.ceiling)
    const loop = new THREE.Line(lineLoop(q, Math.max(0.012, y0 + 0.003)), new THREE.LineBasicMaterial({ color, depthTest: false, transparent: true }))
    loop.renderOrder = 20
    const f = localFootprint(o, room.ceiling)
    const g = new THREE.BoxGeometry(f.maxX - f.minX, y1 - y0, f.maxZ - f.minZ)
    g.translate((f.maxX + f.minX) / 2, (y1 - y0) / 2, (f.maxZ + f.minZ) / 2)
    const edges = new THREE.LineSegments(new THREE.EdgesGeometry(g), new THREE.LineBasicMaterial({ color, transparent: true, opacity: dashed ? 0.45 : 0.9 }))
    g.dispose()
    edges.position.set(o.x, y0, o.z)
    edges.rotation.set(0, o.rot, 0)
    return { line: loop, box: edges }
  }, [o, room.ceiling, color, dashed])
  useEffect(() => () => {
    line.geometry.dispose()
    ;(line.material as THREE.Material).dispose()
    box.geometry.dispose()
    ;(box.material as THREE.Material).dispose()
  }, [line, box])
  return (
    <group userData={HELPER}>
      <primitive object={line} />
      <primitive object={box} />
    </group>
  )
})

export function SelectionOutlines({ theme }: { theme: SceneTheme }) {
  const selected = useSelection((s) => s.selected)
  const invalid = useSelection((s) => s.invalid)
  const hover = useSelection((s) => s.hover)
  const objects = useClassroom((s) => s.doc.objects)
  const room = useClassroom((s) => s.doc.room)
  const ids = new Set([...selected, ...invalid])
  return (
    <>
      {[...ids].map((id) => {
        const o = objects[id]
        if (!o) return null
        return <Outline key={id} o={o} room={room} color={invalid.has(id) ? theme.danger : theme.accent} />
      })}
      {hover && !ids.has(hover) && objects[hover] && <Outline o={objects[hover]!} room={room} color={theme.hover} dashed />}
    </>
  )
}

// ------------------------------------------------------------------ tutamaklar

export type GizmoHandlers = {
  onMoveAxis: (axis: 'x' | 'z', e: ThreeEvent<PointerEvent>) => void
  onRotate: (e: ThreeEvent<PointerEvent>) => void
}

export function Gizmo({ theme, handlers }: { theme: SceneTheme; handlers: GizmoHandlers }) {
  const selected = useSelection((s) => s.selected)
  const tool = useSelection((s) => s.tool)
  const o = useClassroom((s) => (selected.length === 1 ? s.doc.objects[selected[0]!] : undefined))
  const ceiling = useClassroom((s) => s.doc.room.ceiling)
  const mats = useMemo(
    () => ({
      ring: new THREE.MeshBasicMaterial({ color: theme.accent, transparent: true, opacity: 0.55, depthTest: false, side: THREE.DoubleSide }),
      x: new THREE.MeshBasicMaterial({ color: '#d64545', depthTest: false, transparent: true }),
      z: new THREE.MeshBasicMaterial({ color: '#3b74d4', depthTest: false, transparent: true }),
      knob: new THREE.MeshBasicMaterial({ color: theme.accent, depthTest: false }),
    }),
    [theme.accent],
  )
  if (!o || o.locked || tool !== 'select' || mountOf(o) === 'wall') return null
  const f = localFootprint(o, ceiling)
  const r = Math.hypot(f.maxX - f.minX, f.maxZ - f.minZ) / 2 + 0.22
  const y = (mountOf(o) === 'ceiling' ? elevationOf(o, ceiling) : 0) + 0.03
  const arrow = (axis: 'x' | 'z') => {
    const len = r + 0.35
    const mat = axis === 'x' ? mats.x : mats.z
    const rot: [number, number, number] = axis === 'x' ? [0, 0, -Math.PI / 2] : [Math.PI / 2, 0, 0]
    const pos = (d: number): [number, number, number] => (axis === 'x' ? [d, 0, 0] : [0, 0, d])
    return (
      <group
        onPointerDown={(e) => {
          e.stopPropagation()
          handlers.onMoveAxis(axis, e)
        }}
      >
        <mesh material={mat} position={pos(len / 2 + 0.05)} rotation={rot} renderOrder={30}>
          <cylinderGeometry args={[0.018, 0.018, len - 0.1, 8]} />
        </mesh>
        <mesh material={mat} position={pos(len + 0.02)} rotation={rot} renderOrder={30}>
          <coneGeometry args={[0.06, 0.16, 16]} />
        </mesh>
        {/* geniş, görünmez tutma alanı */}
        <mesh position={pos(len / 2 + 0.1)} rotation={rot} visible={false}>
          <cylinderGeometry args={[0.08, 0.08, len, 6]} />
        </mesh>
      </group>
    )
  }
  return (
    <group position={[o.x, y, o.z]} userData={HELPER}>
      <mesh
        rotation={[-Math.PI / 2, 0, 0]}
        material={mats.ring}
        renderOrder={30}
        onPointerDown={(e) => {
          e.stopPropagation()
          handlers.onRotate(e)
        }}
      >
        <ringGeometry args={[r - 0.035, r + 0.035, 72]} />
      </mesh>
      <mesh position={[Math.sin(o.rot + Math.PI) * r, 0, Math.cos(o.rot + Math.PI) * r]} material={mats.knob} renderOrder={31}
        onPointerDown={(e) => {
          e.stopPropagation()
          handlers.onRotate(e)
        }}>
        <sphereGeometry args={[0.07, 16, 12]} />
      </mesh>
      {arrow('x')}
      {arrow('z')}
    </group>
  )
}

// ------------------------------------------------------------------ mesafe etiketi

function Tag({ position, children, tone = 'neutral' }: { position: [number, number, number]; children: React.ReactNode; tone?: 'neutral' | 'accent' | 'danger' }) {
  const cls =
    tone === 'danger' ? 'bg-danger text-white' : tone === 'accent' ? 'bg-accent text-white' : 'bg-surface text-ink ring-1 ring-line-strong'
  return (
    <Html position={position} center zIndexRange={[30, 0]} style={{ pointerEvents: 'none' }}>
      <div className={`whitespace-nowrap rounded-[4px] px-1.5 py-0.5 text-[11px] font-semibold tabular-nums shadow-sm ${cls}`}>{children}</div>
    </Html>
  )
}

export function DistanceLabel({ theme }: { theme: SceneTheme }) {
  const selected = useSelection((s) => s.selected)
  const invalid = useSelection((s) => s.invalid)
  const doc = useClassroom((s) => s.doc)
  const o = selected.length === 1 ? doc.objects[selected[0]!] : undefined
  const near = useMemo(() => {
    if (!o || mountOf(o) !== 'floor') return null
    return nearestNeighbor(o, doc.room, doc.order.map((id) => doc.objects[id]!), new Set(selected))
  }, [o, doc, selected])
  const line = useMemo(() => {
    if (!near) return null
    const l = new THREE.Line(
      new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(near.a.x, 0.06, near.a.z), new THREE.Vector3(near.b.x, 0.06, near.b.z)]),
      new THREE.LineBasicMaterial({ color: invalid.size ? theme.danger : theme.accent, depthTest: false }),
    )
    l.renderOrder = 25
    return l
  }, [near, invalid.size, theme])
  useEffect(() => () => {
    line?.geometry.dispose()
    ;(line?.material as THREE.Material | undefined)?.dispose()
  }, [line])
  if (!near || !line) return null
  return (
    <group userData={HELPER}>
      <primitive object={line} />
      <Tag position={[(near.a.x + near.b.x) / 2, 0.1, (near.a.z + near.b.z) / 2]} tone={invalid.size ? 'danger' : 'accent'}>
        ↔ {formatCm(near.dist)}
        {near.target === 'wall' ? ' · duvar' : ''}
      </Tag>
    </group>
  )
}

// ------------------------------------------------------------------ kütüphane önizlemesi

const ghostMats = new Map<string, THREE.Material>()
function ghostMaterial(kind: string, valid: boolean): THREE.Material {
  const key = `${kind}-${valid}`
  let m = ghostMats.get(key)
  if (!m) {
    const base = materials()[kind as keyof ReturnType<typeof materials>] as THREE.MeshStandardMaterial
    const g = base.clone()
    g.transparent = true
    g.opacity = 0.6
    g.depthWrite = false
    if (!valid) {
      g.vertexColors = false
      g.color = new THREE.Color('#d23347')
    }
    ghostMats.set(key, g)
    m = g
  }
  return m
}

export function DragPreview({ theme }: { theme: SceneTheme }) {
  const preview = useDrag((s) => s.preview)
  const valid = useDrag((s) => s.previewValid)
  const room = useClassroom((s) => s.doc.room)
  if (!preview) return null
  const model = getModel(preview, room.ceiling)
  const m = objectMatrix(preview, room.ceiling, new THREE.Matrix4())
  return (
    <group userData={HELPER}>
      <group matrixAutoUpdate={false} matrix={m}>
        {model.parts.map((p) => (
          <mesh key={p.kind} geometry={p.geometry} material={ghostMaterial(p.kind, valid)} />
        ))}
      </group>
      <Outline o={preview} room={room} color={valid ? theme.accent : theme.danger} />
    </group>
  )
}

// ------------------------------------------------------------------ ölçümler

export function Measurements3D({ theme }: { theme: SceneTheme }) {
  const list = useSelection((s) => s.measurements)
  const pending = useSelection((s) => s.pendingMeasure)
  const lines = useMemo(() => {
    return list.map((m) => {
      const l = new THREE.Line(
        new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(m.a.x, 0.04, m.a.z), new THREE.Vector3(m.b.x, 0.04, m.b.z)]),
        new THREE.LineBasicMaterial({ color: theme.hover, depthTest: false }),
      )
      l.renderOrder = 26
      return { m, l }
    })
  }, [list, theme.hover])
  useEffect(() => () => lines.forEach(({ l }) => {
    l.geometry.dispose()
    ;(l.material as THREE.Material).dispose()
  }), [lines])
  return (
    <group userData={HELPER}>
      {lines.map(({ m, l }) => (
        <group key={m.id}>
          <primitive object={l} />
          <mesh position={[m.a.x, 0.04, m.a.z]} renderOrder={27}><sphereGeometry args={[0.035, 12, 8]} /><meshBasicMaterial color={theme.hover} depthTest={false} /></mesh>
          <mesh position={[m.b.x, 0.04, m.b.z]} renderOrder={27}><sphereGeometry args={[0.035, 12, 8]} /><meshBasicMaterial color={theme.hover} depthTest={false} /></mesh>
          <Tag position={[(m.a.x + m.b.x) / 2, 0.12, (m.a.z + m.b.z) / 2]}>
            {formatCm(Math.hypot(m.b.x - m.a.x, m.b.z - m.a.z))} · {formatLength(Math.hypot(m.b.x - m.a.x, m.b.z - m.a.z))}
          </Tag>
        </group>
      ))}
      {pending && (
        <mesh position={[pending.x, 0.04, pending.z]} renderOrder={27}><sphereGeometry args={[0.05, 12, 8]} /><meshBasicMaterial color={theme.hover} depthTest={false} /></mesh>
      )}
    </group>
  )
}

// ------------------------------------------------------------------ masa etiketleri

export function DeskLabels() {
  const labels = useClassroom((s) => s.settings.labels)
  const doc = useClassroom((s) => s.doc)
  const target = useDrag((s) => s.target)
  const previewing = useGhosts((s) => s.list.length > 0)
  const seating = useSeatingContext((s) => s.active)
  if ((!labels && !target) || previewing) return null
  const desks = doc.order.map((id) => doc.objects[id]!).filter((o) => o && isDesk(o))
  return (
    <>
      {desks.map((o) => {
        const names = seating ? (o.seats ?? []).filter(Boolean).map((s) => shortName(s!.name)) : []
        const hot = target?.id === o.id
        if (!labels && !hot) return null
        return (
          <Html key={o.id} position={[o.x, 1.05, o.z]} center zIndexRange={[20, 0]} style={{ pointerEvents: 'none' }}>
            <div
              className={`flex max-w-[140px] items-center gap-1 whitespace-nowrap rounded-[4px] px-1.5 py-[1px] text-[10.5px] leading-4 shadow-sm ring-1 ${
                hot ? 'bg-accent text-white ring-accent' : seating && o.status === 'unavailable' ? 'bg-danger-soft text-danger ring-danger/30' : 'bg-surface/95 text-ink ring-line-strong'
              }`}
            >
              <span className="font-semibold tabular-nums">{o.no ?? '–'}</span>
              {names.length > 0 && <span className={`truncate ${hot ? 'text-white' : 'text-ink-2'}`}>{names.join(' · ')}</span>}
              {seating && o.status === 'reserved' && !names.length && <span className="text-warning">rezerve</span>}
            </div>
          </Html>
        )
      })}
    </>
  )
}

export function shortName(name: string): string {
  const parts = name.trim().split(/\s+/)
  if (parts.length < 2) return name
  return `${parts[0]} ${parts[parts.length - 1]!.charAt(0)}.`
}


// ------------------------------------------------------------------ akıllı yerleşim önizlemesi

export function GhostLayer() {
  const list = useGhosts((s) => s.list)
  const ceiling = useClassroom((s) => s.doc.room.ceiling)
  if (!list.length) return null
  return (
    <group userData={HELPER}>
      {list.map((o) => {
        const model = getModel(o, ceiling)
        const m = objectMatrix(o, ceiling, new THREE.Matrix4())
        return (
          <group key={o.id} matrixAutoUpdate={false} matrix={m}>
            {model.parts.map((p) => (
              <mesh key={p.kind} geometry={p.geometry} material={ghostMaterial(p.kind, true)} />
            ))}
          </group>
        )
      })}
    </group>
  )
}
