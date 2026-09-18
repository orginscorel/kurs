import { memo, useLayoutEffect, useMemo, useRef } from 'react'
import type { ThreeEvent } from '@react-three/fiber'
import * as THREE from 'three'
import { elevationOf, gltfModelOf } from '../catalog'
import type { SceneObject } from '../types'
import { deskState } from '../utils/seating'
import { materials, SEAT_COLORS, seatMaterial } from './materials'
import { getModel, modelKey, type Model } from './models'
import { GltfModel } from './GltfModel'
import { useSeatingContext } from '../state/seatingContext'

/**
 * MOBİLYA KATMANI — aynı model anahtarındaki nesneler TEK InstancedMesh (malzeme başına) ile çizilir.
 * Sürüklenen nesne değişince yalnız o grubun matrisleri güncellenir. Seçim/işaretçi olayları instanceId → nesne.
 */

export type ObjectPointerHandler = (id: string, e: ThreeEvent<PointerEvent>) => void

const tmpObj = new THREE.Object3D()

export function objectMatrix(o: SceneObject, ceiling: number, target: THREE.Matrix4): THREE.Matrix4 {
  tmpObj.position.set(o.x, elevationOf(o, ceiling), o.z)
  tmpObj.rotation.set(0, o.rot, 0)
  tmpObj.scale.set(1, 1, 1)
  tmpObj.updateMatrix()
  return target.copy(tmpObj.matrix)
}

function capacityFor(n: number) {
  let c = 4
  while (c < n) c *= 2
  return c
}

const InstancedGroup = memo(function InstancedGroup({
  model, objects, ceiling, onDown, onHover,
}: {
  model: Model
  objects: SceneObject[]
  ceiling: number
  onDown: ObjectPointerHandler
  onHover: (id: string | null) => void
}) {
  const mats = materials()
  const capacity = capacityFor(objects.length)
  const refs = useRef<(THREE.InstancedMesh | null)[]>([])
  const ids = useMemo(() => objects.map((o) => o.id), [objects])

  useLayoutEffect(() => {
    const m = new THREE.Matrix4()
    for (const mesh of refs.current) {
      if (!mesh) continue
      objects.forEach((o, i) => mesh.setMatrixAt(i, objectMatrix(o, ceiling, m)))
      mesh.count = objects.length
      mesh.instanceMatrix.needsUpdate = true
      mesh.computeBoundingSphere()
      mesh.userData.ids = ids
    }
  }, [objects, ceiling, ids])

  return (
    <>
      {model.parts.map((p, k) => (
        <instancedMesh
          key={`${p.kind}-${capacity}`}
          ref={(el) => {
            refs.current[k] = el
          }}
          args={[p.geometry, mats[p.kind], capacity]}
          castShadow={p.kind !== 'glass'}
          receiveShadow
          frustumCulled={false}
          userData={{ pickable: true, ids }}
          onPointerDown={(e) => {
            if (e.instanceId === undefined) return
            const id = ids[e.instanceId]
            if (id) onDown(id, e)
          }}
          onPointerMove={(e) => {
            if (e.instanceId !== undefined) onHover(ids[e.instanceId] ?? null)
          }}
          onPointerOut={() => onHover(null)}
        />
      ))}
    </>
  )
})

/** Masa üstü oturak göstergeleri: tek InstancedMesh, durum rengi örnek rengi olarak */
const SeatPlates = memo(function SeatPlates({ objects, ceiling, seating }: { objects: SceneObject[]; ceiling: number; seating: boolean }) {
  const ref = useRef<THREE.InstancedMesh>(null)
  const geo = useMemo(() => new THREE.BoxGeometry(1, 1, 1), [])
  const plates = useMemo(() => {
    const out: { o: SceneObject; x: number; z: number; w: number; d: number; y: number; color: string }[] = []
    for (const o of objects) {
      const m = getModel(o, ceiling)
      if (!m.seats.length) continue
      const state = seating ? deskState(o) : 'empty'
      m.seats.forEach((s, i) => {
        const filled = seating && !!o.seats?.[i]
        const color = state === 'unavailable' ? SEAT_COLORS.unavailable : filled ? SEAT_COLORS.full : state === 'reserved' ? SEAT_COLORS.reserved : SEAT_COLORS.empty
        out.push({ o, x: s.x, z: s.z, w: s.w, d: s.d, y: s.y, color })
      })
    }
    return out
  }, [objects, ceiling, seating])
  const capacity = capacityFor(plates.length)

  useLayoutEffect(() => {
    const mesh = ref.current
    if (!mesh) return
    const base = new THREE.Matrix4()
    const local = new THREE.Matrix4()
    const col = new THREE.Color()
    plates.forEach((p, i) => {
      objectMatrix(p.o, ceiling, base)
      local.compose(new THREE.Vector3(p.x, p.y, p.z), new THREE.Quaternion(), new THREE.Vector3(p.w, 0.004, p.d))
      mesh.setMatrixAt(i, base.multiply(local))
      mesh.setColorAt(i, col.set(p.color))
    })
    mesh.count = plates.length
    mesh.instanceMatrix.needsUpdate = true
    if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true
    mesh.computeBoundingSphere()
  }, [plates, ceiling])

  if (!plates.length) return null
  return <instancedMesh key={capacity} ref={ref} args={[geo, seatMaterial(), capacity]} frustumCulled={false} receiveShadow />
})

export function FurnitureLayer({
  objects, ceiling, onDown, onHover,
}: {
  objects: SceneObject[]
  ceiling: number
  onDown: ObjectPointerHandler
  onHover: (id: string | null) => void
}) {
  const seating = useSeatingContext((s) => s.active)
  // Model anahtarına göre grupla; nesne dizisi referansı yalnız o grupta değişiklik varsa değişir
  const prev = useRef(new Map<string, SceneObject[]>())
  const groups = useMemo(() => {
    const next = new Map<string, SceneObject[]>()
    const gltf: SceneObject[] = []
    for (const o of objects) {
      if (gltfModelOf(o.type)) {
        gltf.push(o)
        continue
      }
      const k = modelKey(o, ceiling)
      const list = next.get(k) ?? []
      list.push(o)
      next.set(k, list)
    }
    const stable = new Map<string, SceneObject[]>()
    for (const [k, list] of next) {
      const old = prev.current.get(k)
      stable.set(k, old && old.length === list.length && old.every((o, i) => o === list[i]) ? old : list)
    }
    prev.current = stable
    return { stable, gltf }
  }, [objects, ceiling])

  return (
    <group>
      {[...groups.stable.entries()].map(([k, list]) => (
        <InstancedGroup key={k} model={getModel(list[0]!, ceiling)} objects={list} ceiling={ceiling} onDown={onDown} onHover={onHover} />
      ))}
      {groups.gltf.map((o) => (
        <GltfModel key={o.id} object={o} ceiling={ceiling} onDown={onDown} onHover={onHover} />
      ))}
      <SeatPlates objects={objects} ceiling={ceiling} seating={seating} />
    </group>
  )
}
