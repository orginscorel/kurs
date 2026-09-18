import { memo, useEffect, useMemo, useRef } from 'react'
import { useFrame, type ThreeEvent } from '@react-three/fiber'
import * as THREE from 'three'
import type { Opening, Room } from '../types'
import { edgeAt, edges, offsetPolygon } from '../utils/polygon'
import { floorTexture, WALL_COLORS } from './materials'

/**
 * ODA — zemin (çokgen), gerçek kalınlıkta duvarlar (dışa doğru, gönyeli köşeler), duvarlarda kapı/pencere için
 * GERÇEK boşluk (duvar parçalara bölünür: boşluğun yanları, altı ve üstü), 3D kapı kanadı + zemin açılma yayı,
 * pencere doğraması + cam + denizlik. Kameranın önündeki duvarlar saydamlaşır (odanın içi görünsün).
 */

type V = THREE.Vector3

function prismGeometry(inner0: { x: number; z: number }, inner1: { x: number; z: number }, outer1: { x: number; z: number }, outer0: { x: number; z: number }, y0: number, y1: number, topColored: boolean): THREE.BufferGeometry {
  const pos: number[] = []
  const nor: number[] = []
  const col: number[] = []
  const side = new THREE.Color(WALL_COLORS.side)
  const top = new THREE.Color(WALL_COLORS.top)
  const outside = new THREE.Color(WALL_COLORS.outside)
  const P = (p: { x: number; z: number }, y: number) => new THREE.Vector3(p.x, y, p.z)
  const center = new THREE.Vector3((inner0.x + inner1.x + outer0.x + outer1.x) / 4, (y0 + y1) / 2, (inner0.z + inner1.z + outer0.z + outer1.z) / 4)
  const quad = (a: V, b: V, c: V, d: V, color: THREE.Color) => {
    const n = new THREE.Vector3().subVectors(b, a).cross(new THREE.Vector3().subVectors(c, a)).normalize()
    const mid = new THREE.Vector3().add(a).add(b).add(c).add(d).multiplyScalar(0.25)
    let pts = [a, b, c, d]
    if (n.dot(new THREE.Vector3().subVectors(mid, center)) < 0) {
      pts = [a, d, c, b]
      n.negate()
    }
    for (const i of [0, 1, 2, 0, 2, 3]) {
      const p = pts[i]!
      pos.push(p.x, p.y, p.z)
      nor.push(n.x, n.y, n.z)
      col.push(color.r, color.g, color.b)
    }
  }
  const a0 = P(inner0, y0), a1 = P(inner1, y0), b1 = P(outer1, y0), b0 = P(outer0, y0)
  const c0 = P(inner0, y1), c1 = P(inner1, y1), d1 = P(outer1, y1), d0 = P(outer0, y1)
  quad(a0, a1, c1, c0, side)                 // iç yüz
  quad(b0, b1, d1, d0, outside)              // dış yüz
  quad(c0, c1, d1, d0, topColored ? top : side) // üst (kesit)
  quad(a0, a1, b1, b0, side)                 // alt
  quad(a0, b0, d0, c0, side)                 // uçlar (boşluk kenarı)
  quad(a1, b1, d1, c1, side)
  const g = new THREE.BufferGeometry()
  g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3))
  g.setAttribute('normal', new THREE.Float32BufferAttribute(nor, 3))
  g.setAttribute('color', new THREE.Float32BufferAttribute(col, 3))
  return g
}

type WallGeo = { geometry: THREE.BufferGeometry; normal: THREE.Vector3; center: THREE.Vector3; index: number }

/** Duvar i için parça geometrileri (boşluklar çıkarılmış) — tek geometriye birleştirilir */
function buildWalls(room: Room, openings: Opening[]): WallGeo[] {
  const poly = room.polygon
  const t = room.wallThickness
  const H = room.ceiling
  const outer = offsetPolygon(poly, t)
  const out: WallGeo[] = []
  for (const e of edges(poly)) {
    const holes = openings
      .filter((o) => o.wall === e.i)
      .map((o) => ({ s0: Math.max(0, o.offset - o.width / 2), s1: Math.min(e.len, o.offset + o.width / 2), y0: o.kind === 'door' ? 0 : o.sill, y1: Math.min(H, (o.kind === 'door' ? 0 : o.sill) + o.height) }))
    const cuts = Array.from(new Set([0, e.len, ...holes.flatMap((h) => [h.s0, h.s1])])).filter((s) => s >= 0 && s <= e.len).sort((a, b) => a - b)
    const pieces: THREE.BufferGeometry[] = []
    for (let k = 0; k < cuts.length - 1; k++) {
      const s0 = cuts[k]!
      const s1 = cuts[k + 1]!
      if (s1 - s0 < 1e-4) continue
      const mid = (s0 + s1) / 2
      const covering = holes.filter((h) => h.s0 <= mid && mid <= h.s1).sort((a, b) => a.y0 - b.y0)
      // dikeyde dolu aralıklar = [0,H] − boşluklar
      const solid: [number, number][] = []
      let y = 0
      for (const h of covering) {
        if (h.y0 > y + 1e-4) solid.push([y, h.y0])
        y = Math.max(y, h.y1)
      }
      if (y < H - 1e-4) solid.push([y, H])
      const i0 = { x: e.a.x + e.dir.x * s0, z: e.a.z + e.dir.z * s0 }
      const i1 = { x: e.a.x + e.dir.x * s1, z: e.a.z + e.dir.z * s1 }
      const o0 = s0 < 1e-6 ? outer[e.i]! : { x: i0.x + e.n.x * t, z: i0.z + e.n.z * t }
      const o1 = s1 > e.len - 1e-6 ? outer[(e.i + 1) % poly.length]! : { x: i1.x + e.n.x * t, z: i1.z + e.n.z * t }
      for (const [ya, yb] of solid) pieces.push(prismGeometry(i0, i1, o1, o0, ya, yb, yb >= H - 1e-4))
    }
    if (!pieces.length) continue
    const merged = mergeNonIndexed(pieces)
    out.push({
      geometry: merged,
      normal: new THREE.Vector3(e.n.x, 0, e.n.z),
      center: new THREE.Vector3((e.a.x + e.b.x) / 2 + e.n.x * t / 2, H / 2, (e.a.z + e.b.z) / 2 + e.n.z * t / 2),
      index: e.i,
    })
  }
  return out
}

function mergeNonIndexed(list: THREE.BufferGeometry[]): THREE.BufferGeometry {
  const attrs = ['position', 'normal', 'color'] as const
  const g = new THREE.BufferGeometry()
  for (const a of attrs) {
    const total = list.reduce((n, x) => n + x.getAttribute(a).array.length, 0)
    const arr = new Float32Array(total)
    let off = 0
    for (const x of list) {
      arr.set(x.getAttribute(a).array as Float32Array, off)
      off += x.getAttribute(a).array.length
    }
    g.setAttribute(a, new THREE.BufferAttribute(arr, 3))
  }
  list.forEach((x) => x.dispose())
  g.computeBoundingSphere()
  return g
}

const Wall = memo(function Wall({ wall, fade }: { wall: WallGeo; fade: boolean }) {
  const mat = useMemo(() => new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.92, metalness: 0, transparent: true, opacity: 1 }), [])
  const ref = useRef<THREE.Mesh>(null)
  useEffect(() => () => {
    mat.dispose()
    wall.geometry.dispose()
  }, [mat, wall])
  const tmp = useMemo(() => new THREE.Vector3(), [])
  useFrame(({ camera }) => {
    // Kamera duvarın dış tarafındaysa (duvar kamerayla oda arasında) saydamlaştır
    const behind = fade && tmp.subVectors(camera.position, wall.center).dot(wall.normal) > 0.2 && camera.position.y < 40
    const target = behind ? 0.14 : 1
    if (Math.abs(mat.opacity - target) > 0.01) {
      mat.opacity = target
      mat.depthWrite = !behind
      if (ref.current) ref.current.castShadow = !behind
    }
  })
  return <mesh ref={ref} geometry={wall.geometry} material={mat} castShadow receiveShadow userData={{ helper: false, wall: wall.index }} />
})

function Floor({ room, onPointerDown }: { room: Room; onPointerDown?: (e: ThreeEvent<PointerEvent>) => void }) {
  const geo = useMemo(() => {
    const shape = new THREE.Shape(room.polygon.map((p) => new THREE.Vector2(p.x, -p.z)))
    const g = new THREE.ShapeGeometry(shape)
    g.rotateX(-Math.PI / 2)
    return g
  }, [room.polygon])
  useEffect(() => () => geo.dispose(), [geo])
  const mat = useMemo(() => new THREE.MeshStandardMaterial({ map: floorTexture(), roughness: 0.78, metalness: 0 }), [])
  return <mesh geometry={geo} material={mat} receiveShadow onPointerDown={onPointerDown} userData={{ floor: true }} />
}

// ------------------------------------------------------------------ açıklıklar

const frameMat = new THREE.MeshStandardMaterial({ color: '#f1f2f3', roughness: 0.5 })
const leafMat = new THREE.MeshStandardMaterial({ color: '#b98f63', roughness: 0.6 })
const handleMat = new THREE.MeshStandardMaterial({ color: '#b9bfc7', roughness: 0.3, metalness: 0.8 })
const glassMat = new THREE.MeshStandardMaterial({ color: '#a9cde0', roughness: 0.05, metalness: 0.15, transparent: true, opacity: 0.3, depthWrite: false })
const sillMat = new THREE.MeshStandardMaterial({ color: '#e8e6e1', roughness: 0.35 })
const arcMat = new THREE.LineBasicMaterial({ color: '#7a8594' })
const selMat = new THREE.MeshBasicMaterial({ color: '#1f8a7f', transparent: true, opacity: 0.35, depthWrite: false })

function openingFrame(room: Room, o: Opening) {
  const e = edgeAt(room.polygon, Math.min(o.wall, room.polygon.length - 1))
  const t = room.wallThickness
  const cx = e.a.x + e.dir.x * o.offset + e.n.x * (t / 2)
  const cz = e.a.z + e.dir.z * o.offset + e.n.z * (t / 2)
  const rot = Math.atan2(-e.dir.z, e.dir.x)
  return { cx, cz, rot, t }
}

function Door({ room, o, selected, onSelect }: { room: Room; o: Opening; selected: boolean; onSelect: (e: ThreeEvent<PointerEvent>) => void }) {
  const { cx, cz, rot, t } = openingFrame(room, o)
  const w = o.width
  const h = o.height
  const hingeRight = o.swing?.endsWith('right')
  const inward = !o.swing?.startsWith('out')
  const a = (78 * Math.PI) / 180
  const hx = hingeRight ? w / 2 - 0.03 : -w / 2 + 0.03
  const hz = inward ? t / 2 : -t / 2
  const pivot = hingeRight ? (inward ? a : -a) : inward ? -a : a
  const leafW = w - 0.06
  const arc = useMemo(() => {
    const pts: THREE.Vector3[] = []
    const dirClosed = hingeRight ? -1 : 1
    for (let i = 0; i <= 24; i++) {
      const th = (i / 24) * (Math.PI / 2)
      pts.push(new THREE.Vector3(hx + dirClosed * Math.cos(th) * leafW, 0.012, hz + (inward ? 1 : -1) * Math.sin(th) * leafW))
    }
    pts.push(new THREE.Vector3(hx, 0.012, hz))
    return new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts), arcMat)
  }, [hx, hz, hingeRight, inward, leafW])
  useEffect(() => () => arc.geometry.dispose(), [arc])
  return (
    <group position={[cx, 0, cz]} rotation={[0, rot, 0]} onPointerDown={onSelect} userData={{ opening: o.id }}>
      {/* kasa */}
      <mesh material={frameMat} position={[-w / 2 + 0.025, h / 2, 0]} castShadow><boxGeometry args={[0.05, h, t + 0.02]} /></mesh>
      <mesh material={frameMat} position={[w / 2 - 0.025, h / 2, 0]} castShadow><boxGeometry args={[0.05, h, t + 0.02]} /></mesh>
      <mesh material={frameMat} position={[0, h - 0.025, 0]} castShadow><boxGeometry args={[w, 0.05, t + 0.02]} /></mesh>
      {/* kanat */}
      <group position={[hx, 0, hz]} rotation={[0, pivot, 0]}>
        <mesh material={leafMat} position={[(hingeRight ? -1 : 1) * (leafW / 2), (h - 0.06) / 2 + 0.01, 0]} castShadow>
          <boxGeometry args={[leafW, h - 0.06, 0.042]} />
        </mesh>
        <mesh material={handleMat} position={[(hingeRight ? -1 : 1) * (leafW - 0.08), 1.02, 0.04]}><boxGeometry args={[0.12, 0.02, 0.02]} /></mesh>
        <mesh material={handleMat} position={[(hingeRight ? -1 : 1) * (leafW - 0.08), 1.02, -0.04]}><boxGeometry args={[0.12, 0.02, 0.02]} /></mesh>
      </group>
      <primitive object={arc} />
      {selected && (
        <mesh material={selMat} position={[0, h / 2, 0]} renderOrder={5}><boxGeometry args={[w + 0.04, h + 0.04, t + 0.08]} /></mesh>
      )}
    </group>
  )
}

function Window({ room, o, selected, onSelect }: { room: Room; o: Opening; selected: boolean; onSelect: (e: ThreeEvent<PointerEvent>) => void }) {
  const { cx, cz, rot, t } = openingFrame(room, o)
  const w = o.width
  const h = o.height
  const f = 0.05
  const mullions = w > 1.1 ? Math.max(1, Math.round(w / 0.9) - 1) : 0
  return (
    <group position={[cx, o.sill, cz]} rotation={[0, rot, 0]} onPointerDown={onSelect} userData={{ opening: o.id }}>
      <mesh material={frameMat} position={[0, f / 2, 0]}><boxGeometry args={[w, f, 0.07]} /></mesh>
      <mesh material={frameMat} position={[0, h - f / 2, 0]}><boxGeometry args={[w, f, 0.07]} /></mesh>
      <mesh material={frameMat} position={[-w / 2 + f / 2, h / 2, 0]}><boxGeometry args={[f, h, 0.07]} /></mesh>
      <mesh material={frameMat} position={[w / 2 - f / 2, h / 2, 0]}><boxGeometry args={[f, h, 0.07]} /></mesh>
      {Array.from({ length: mullions }, (_, i) => (
        <mesh key={i} material={frameMat} position={[-w / 2 + ((i + 1) * w) / (mullions + 1), h / 2, 0]}><boxGeometry args={[0.04, h, 0.06]} /></mesh>
      ))}
      <mesh material={glassMat} position={[0, h / 2, 0]} renderOrder={2}><boxGeometry args={[w - 2 * f, h - 2 * f, 0.01]} /></mesh>
      {/* iç denizlik */}
      <mesh material={sillMat} position={[0, -0.015, t / 2 - 0.02]} castShadow receiveShadow><boxGeometry args={[w + 0.1, 0.03, t / 2 + 0.1]} /></mesh>
      {selected && (
        <mesh material={selMat} position={[0, h / 2, 0]} renderOrder={5}><boxGeometry args={[w + 0.04, h + 0.04, t + 0.08]} /></mesh>
      )}
    </group>
  )
}

export function Room3D({
  room, openings, selectedOpening, onFloorDown, onOpeningDown, fadeWalls = true,
}: {
  room: Room
  openings: Opening[]
  selectedOpening: string | null
  onFloorDown?: (e: ThreeEvent<PointerEvent>) => void
  onOpeningDown?: (id: string, e: ThreeEvent<PointerEvent>) => void
  fadeWalls?: boolean
}) {
  const walls = useMemo(() => buildWalls(room, openings), [room, openings])
  return (
    <group>
      <Floor room={room} onPointerDown={onFloorDown} />
      {walls.map((w) => <Wall key={`${w.index}-${w.geometry.id}`} wall={w} fade={fadeWalls} />)}
      {openings.map((o) =>
        o.wall < room.polygon.length ? (
          o.kind === 'door' ? (
            <Door key={o.id} room={room} o={o} selected={selectedOpening === o.id} onSelect={(e) => onOpeningDown?.(o.id, e)} />
          ) : (
            <Window key={o.id} room={room} o={o} selected={selectedOpening === o.id} onSelect={(e) => onOpeningDown?.(o.id, e)} />
          )
        ) : null,
      )}
    </group>
  )
}
