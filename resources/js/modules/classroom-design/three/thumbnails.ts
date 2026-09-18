import { useEffect, useState } from 'react'
import * as THREE from 'three'
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js'
import { CATALOG } from '../catalog'
import type { SceneObject } from '../types'
import { newObjectDefaults } from '../utils/snap'
import { materials } from './materials'
import { getModel } from './models'

/**
 * KÜTÜPHANE ÖNİZLEMELERİ — her katalog nesnesi BİR KEZ ekran dışı bir WebGL tuvaline çizilir, görüntü (webp)
 * olarak bellekte ve tarayıcıda (localStorage) önbelleğe alınır. Kütüphane açıkken canlı 3D çizim yapılmaz.
 */

const STORE_KEY = 'cd-thumbs-v3'
const W = 192
const H = 144

let memory: Record<string, string> | null = null
let pending: Promise<Record<string, string>> | null = null

function readStore(): Record<string, string> {
  try {
    return JSON.parse(localStorage.getItem(STORE_KEY) || '{}') as Record<string, string>
  } catch {
    return {}
  }
}

function renderAll(): Record<string, string> {
  const out: Record<string, string> = {}
  const canvas = document.createElement('canvas')
  canvas.width = W * 2
  canvas.height = H * 2
  let renderer: THREE.WebGLRenderer
  try {
    renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true, preserveDrawingBuffer: true })
  } catch {
    return out
  }
  renderer.setSize(W * 2, H * 2, false)
  renderer.toneMapping = THREE.ACESFilmicToneMapping
  renderer.toneMappingExposure = 1.1
  const scene = new THREE.Scene()
  const pmrem = new THREE.PMREMGenerator(renderer)
  const env = pmrem.fromScene(new RoomEnvironment(), 0.04).texture
  scene.environment = env
  scene.environmentIntensity = 0.6
  scene.add(new THREE.HemisphereLight('#ffffff', '#b8b0a4', 1.1))
  const dir = new THREE.DirectionalLight('#ffffff', 1.6)
  dir.position.set(3, 5, 4)
  scene.add(dir)
  const cam = new THREE.PerspectiveCamera(30, W / H, 0.01, 100)
  const mats = materials()

  for (const item of CATALOG) {
    const o: SceneObject = { id: 'thumb', type: item.type, x: 0, z: 0, rot: 0, ...newObjectDefaults(item.type) }
    if (item.type === 'column') o.w = 0.4
    const ceiling = item.type === 'column' ? 1.6 : 3
    if (item.mount === 'ceiling') o.elev = ceiling - 0.45
    const model = getModel(o, ceiling)
    const group = new THREE.Group()
    for (const p of model.parts) group.add(new THREE.Mesh(p.geometry, mats[p.kind]))
    // ön yüz kameraya dönük: nesnenin önü −z → kamerayı −z tarafına al
    group.rotation.y = Math.PI
    scene.add(group)
    const box = new THREE.Box3().setFromObject(group)
    const size = box.getSize(new THREE.Vector3())
    const center = box.getCenter(new THREE.Vector3())
    const r = Math.max(size.x, size.y * 1.1, size.z) * 0.62 + 0.05
    const dist = r / Math.tan((cam.fov * Math.PI) / 360) * 1.05
    const dirv = new THREE.Vector3(0.75, 0.55, 1).normalize()
    cam.position.copy(center).addScaledVector(dirv, dist)
    cam.lookAt(center)
    renderer.setClearColor(0x000000, 0)
    renderer.render(scene, cam)
    try {
      out[item.type] = canvas.toDataURL('image/webp', 0.9)
    } catch {
      /* yok say */
    }
    scene.remove(group)
  }
  env.dispose()
  pmrem.dispose()
  renderer.dispose()
  renderer.forceContextLoss()
  return out
}

export function loadThumbnails(): Promise<Record<string, string>> {
  if (memory) return Promise.resolve(memory)
  if (pending) return pending
  const stored = readStore()
  if (CATALOG.every((c) => stored[c.type])) {
    memory = stored
    return Promise.resolve(stored)
  }
  pending = new Promise((resolve) => {
    const run = () => {
      const out = renderAll()
      memory = out
      try {
        localStorage.setItem(STORE_KEY, JSON.stringify(out))
      } catch {
        /* dolu olabilir */
      }
      resolve(out)
    }
    const w = window as Window & { requestIdleCallback?: (cb: () => void, o?: { timeout: number }) => number }
    if (w.requestIdleCallback) w.requestIdleCallback(run, { timeout: 800 })
    else setTimeout(run, 50)
  })
  return pending
}

export function useCatalogThumbnails(): Record<string, string> {
  const [thumbs, setThumbs] = useState<Record<string, string>>(memory ?? {})
  useEffect(() => {
    let live = true
    loadThumbnails().then((t) => live && setThumbs(t))
    return () => {
      live = false
    }
  }, [])
  return thumbs
}

