import { Suspense, useMemo } from 'react'
import { useGLTF } from '@react-three/drei'
import * as THREE from 'three'
import { elevationOf, gltfModelOf, sizeOf } from '../catalog'
import type { SceneObject } from '../types'
import type { ObjectPointerHandler } from './Furniture3D'

/**
 * GLB/GLTF MODEL ALTYAPISI — `registerGltfModel()` ile kaydedilmiş gerçek model dosyaları için.
 * Model, katalogdaki ayak izine (w × d) sığacak şekilde ölçeklenir, tabanı zemine oturur, merkezlenir.
 * Kayıtlı model yoksa hiç kullanılmaz (kütüphanede de görünmez).
 */

function Inner({ object, url, ceiling, onDown, onHover }: { object: SceneObject; url: string; ceiling: number; onDown: ObjectPointerHandler; onHover: (id: string | null) => void }) {
  const gltf = useGLTF(url)
  const { w, d } = sizeOf(object, ceiling)
  const scene = useMemo(() => {
    const s = gltf.scene.clone(true)
    const box = new THREE.Box3().setFromObject(s)
    const size = box.getSize(new THREE.Vector3())
    const k = Math.min(w / (size.x || 1), d / (size.z || 1))
    s.scale.setScalar(k)
    const b2 = new THREE.Box3().setFromObject(s)
    const c = b2.getCenter(new THREE.Vector3())
    s.position.set(-c.x, -b2.min.y, -c.z)
    s.traverse((n) => {
      if ((n as THREE.Mesh).isMesh) {
        n.castShadow = true
        n.receiveShadow = true
      }
    })
    return s
  }, [gltf, w, d])
  return (
    <group
      position={[object.x, elevationOf(object, ceiling), object.z]}
      rotation={[0, object.rot, 0]}
      userData={{ pickable: true, ids: [object.id] }}
      onPointerDown={(e) => onDown(object.id, e)}
      onPointerMove={() => onHover(object.id)}
      onPointerOut={() => onHover(null)}
    >
      <primitive object={scene} />
    </group>
  )
}

export function GltfModel(props: { object: SceneObject; ceiling: number; onDown: ObjectPointerHandler; onHover: (id: string | null) => void }) {
  const def = gltfModelOf(props.object.type)
  if (!def) return null
  return (
    <Suspense fallback={null}>
      <Inner {...props} url={def.url} />
    </Suspense>
  )
}
