import * as THREE from 'three'
import type { DeskState } from '../utils/seating'

/**
 * PAYLAŞILAN MALZEMELER — tüm nesneler aynı birkaç malzemeyi kullanır; renkler köşe renklerinde (vertexColors).
 * Böylece bir modelin tüm parçaları malzeme başına TEK geometride birleşir ve aynı türdeki nesneler tek
 * InstancedMesh ile çizilir (az çizim çağrısı).
 */

export type MatKind = 'wood' | 'matte' | 'metal' | 'plastic' | 'fabric' | 'glass' | 'screen'

let cache: Record<MatKind, THREE.Material> | null = null

export function materials(): Record<MatKind, THREE.Material> {
  if (cache) return cache
  cache = {
    wood: new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.55, metalness: 0.02 }),
    matte: new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.82, metalness: 0 }),
    metal: new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.32, metalness: 0.75 }),
    plastic: new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.42, metalness: 0.04 }),
    fabric: new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.95, metalness: 0 }),
    glass: new THREE.MeshStandardMaterial({ color: '#bcd6e6', roughness: 0.05, metalness: 0.2, transparent: true, opacity: 0.28, depthWrite: false }),
    screen: new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.18, metalness: 0.35, emissive: new THREE.Color('#0d1c2c'), emissiveIntensity: 0.9 }),
  }
  return cache
}

/** Masa üstü oturak göstergesi renkleri (durum) */
export const SEAT_COLORS: Record<DeskState, string> = {
  empty: '#c7d0da',
  partial: '#6fb3a8',
  full: '#16806f',
  reserved: '#d29a3a',
  unavailable: '#c0394b',
}

let seatMat: THREE.MeshStandardMaterial | null = null
export function seatMaterial(): THREE.MeshStandardMaterial {
  seatMat ??= new THREE.MeshStandardMaterial({ color: '#ffffff', roughness: 0.6, metalness: 0 })
  return seatMat
}

/** Duvar: yan yüzler açık, üst kesit koyu (mimari kesit görünümü) */
export const WALL_COLORS = { side: '#ebe7df', top: '#3d4552', outside: '#d9d5cc' }

/** Zemin döşemesi: 60 cm karo, ince derz — kanvasla üretilir (dış dosya yok) */
let floorTex: THREE.CanvasTexture | null = null
export function floorTexture(): THREE.CanvasTexture {
  if (floorTex) return floorTex
  const c = document.createElement('canvas')
  c.width = c.height = 256
  const g = c.getContext('2d')!
  g.fillStyle = '#d8d3c9'
  g.fillRect(0, 0, 256, 256)
  // hafif doku
  for (let i = 0; i < 1400; i++) {
    const v = 200 + Math.floor(Math.random() * 22)
    g.fillStyle = `rgba(${v},${v - 4},${v - 12},0.35)`
    g.fillRect(Math.random() * 256, Math.random() * 256, 2, 2)
  }
  g.strokeStyle = 'rgba(120,112,100,0.55)'
  g.lineWidth = 2
  g.strokeRect(1, 1, 254, 254)
  floorTex = new THREE.CanvasTexture(c)
  floorTex.wrapS = floorTex.wrapT = THREE.RepeatWrapping
  floorTex.repeat.set(1 / 0.6, 1 / 0.6)
  floorTex.colorSpace = THREE.SRGBColorSpace
  floorTex.anisotropy = 4
  return floorTex
}
