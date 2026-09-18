import * as THREE from 'three'
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js'
import { catalogOf, sizeOf, STUDENT_CHAIR_ZONE, TEACHER_CHAIR_ZONE } from '../catalog'
import type { SceneObject } from '../types'
import type { MatKind } from './materials'

/**
 * MODELLER — dış dosya olmadan, temel geometrilerle üretilir. Her model malzeme türüne göre TEK geometride
 * birleştirilir (renk köşe renklerinde). Geometriler anahtara göre önbellekte tutulur; aynı türdeki tüm nesneler
 * aynı geometriyi paylaşır (InstancedMesh). Yerel koordinat: taban y=0, ön −z, arka (duvar/sandalye) +z.
 */

export type ModelParts = { kind: MatKind; geometry: THREE.BufferGeometry }[]
export type SeatSpot = { x: number; z: number; w: number; d: number; y: number }
export type Model = { key: string; parts: ModelParts; seats: SeatSpot[]; height: number }

class Builder {
  buckets = new Map<MatKind, THREE.BufferGeometry[]>()

  private push(kind: MatKind, g: THREE.BufferGeometry, color: string) {
    const c = new THREE.Color(color)
    const n = g.getAttribute('position').count
    const arr = new Float32Array(n * 3)
    for (let i = 0; i < n; i++) {
      arr[i * 3] = c.r
      arr[i * 3 + 1] = c.g
      arr[i * 3 + 2] = c.b
    }
    g.setAttribute('color', new THREE.BufferAttribute(arr, 3))
    if (!g.index) {
      const idx = Array.from({ length: n }, (_, i) => i)
      g.setIndex(idx)
    }
    const list = this.buckets.get(kind) ?? []
    list.push(g)
    this.buckets.set(kind, list)
  }

  /** kutu: merkez (x,y,z), boyut (w,h,d), isteğe bağlı x/y ekseninde eğim */
  box(kind: MatKind, color: string, w: number, h: number, d: number, x: number, y: number, z: number, rot?: { x?: number; y?: number; z?: number }) {
    const g = new THREE.BoxGeometry(w, h, d)
    if (rot?.x) g.rotateX(rot.x)
    if (rot?.y) g.rotateY(rot.y)
    if (rot?.z) g.rotateZ(rot.z)
    g.translate(x, y, z)
    this.push(kind, g, color)
  }

  /** dikey silindir (ya da eksene göre döndürülmüş) */
  cyl(kind: MatKind, color: string, rTop: number, rBottom: number, h: number, x: number, y: number, z: number, seg = 14, rot?: { x?: number; z?: number }) {
    const g = new THREE.CylinderGeometry(rTop, rBottom, h, seg)
    if (rot?.x) g.rotateX(rot.x)
    if (rot?.z) g.rotateZ(rot.z)
    g.translate(x, y, z)
    this.push(kind, g, color)
  }

  build(): ModelParts {
    const parts: ModelParts = []
    for (const [kind, list] of this.buckets) {
      const merged = mergeGeometries(list, false)
      if (!merged) continue
      merged.computeBoundingSphere()
      merged.computeBoundingBox()
      parts.push({ kind, geometry: merged })
      list.forEach((g) => g.dispose())
    }
    return parts
  }
}

// ------------------------------------------------------------------ renkler
const C = {
  beech: '#d8bf98',
  beechEdge: '#b99d74',
  walnut: '#7d5a3f',
  walnutDark: '#5f4330',
  frame: '#3a3f47',
  frameLight: '#6c7580',
  navy: '#27456c',
  navyDark: '#1d3553',
  lightGray: '#d4d7db',
  midGray: '#9aa1aa',
  darkGray: '#2b3038',
  white: '#f2f3f4',
  offWhite: '#e6e8ea',
  black: '#15181c',
  aluminum: '#b9bfc7',
  plaster: '#e3ded6',
  fabric: '#30353d',
  books: ['#8c2f39', '#2f5d8c', '#3e7d5a', '#c79a3b', '#5b4a8a', '#a0522d', '#2d6e7e', '#7a8b99'],
}

// ------------------------------------------------------------------ parçalar

function studentChair(b: Builder, cx: number, cz: number) {
  // oturak + sırtlık (plastik kabuk), metal ayaklar; önü −z (masaya bakar)
  b.box('plastic', C.navy, 0.42, 0.028, 0.4, cx, 0.455, cz)
  b.box('plastic', C.navy, 0.4, 0.28, 0.022, cx, 0.66, cz + 0.2, { x: -0.13 })
  b.box('plastic', C.navyDark, 0.42, 0.012, 0.36, cx, 0.438, cz + 0.01)
  for (const sx of [-0.18, 0.18]) {
    b.cyl('metal', C.frame, 0.011, 0.011, 0.44, cx + sx, 0.22, cz - 0.16, 8)
    b.cyl('metal', C.frame, 0.011, 0.011, 0.44, cx + sx, 0.22, cz + 0.17, 8)
    // arka dikme sırtlığa
    b.cyl('metal', C.frame, 0.01, 0.01, 0.26, cx + sx, 0.58, cz + 0.19, 8, { x: -0.13 })
    // yan bağlantı
    b.box('metal', C.frame, 0.014, 0.014, 0.33, cx + sx, 0.08, cz + 0.005)
  }
}

function studentDesk(b: Builder, w: number, d: number, chairs: number[] | null) {
  const h = 0.75
  b.box('wood', C.beech, w, 0.024, d, 0, h - 0.012, 0)
  b.box('wood', C.beechEdge, w + 0.004, 0.006, d + 0.004, 0, h - 0.027, 0)
  const lx = w / 2 - 0.035
  const lz = d / 2 - 0.035
  for (const sx of [-lx, lx]) {
    for (const sz of [-lz, lz]) b.box('metal', C.frame, 0.03, h - 0.03, 0.03, sx, (h - 0.03) / 2, sz)
    // yan kiriş + taban kızağı
    b.box('metal', C.frame, 0.022, 0.035, d - 0.07, sx, h - 0.065, 0)
    b.box('metal', C.frame, 0.022, 0.022, d - 0.07, sx, 0.12, 0)
  }
  // ön etek (öğrencinin karşısı, −z) + kitap rafı
  b.box('matte', C.frameLight, w - 0.08, 0.22, 0.012, 0, h - 0.17, -d / 2 + 0.04)
  b.box('matte', C.midGray, w - 0.08, 0.01, d - 0.14, 0, h - 0.16, 0.02)
  if (chairs) for (const cx of chairs) studentChair(b, cx, d / 2 + STUDENT_CHAIR_ZONE / 2 - 0.02)
}

function officeChair(b: Builder, cx: number, cz: number) {
  // 5 kollu ayak + tekerlek, amortisör, oturak, sırt, kolçak; önü −z
  for (let i = 0; i < 5; i++) {
    const a = (i / 5) * Math.PI * 2
    const ex = Math.sin(a) * 0.27
    const ez = Math.cos(a) * 0.27
    b.box('metal', C.darkGray, 0.04, 0.03, 0.27, cx + ex / 2, 0.075, cz + ez / 2, { y: a })
    b.cyl('plastic', C.black, 0.025, 0.025, 0.03, cx + ex, 0.03, cz + ez, 10, { z: Math.PI / 2 })
  }
  b.cyl('metal', C.midGray, 0.025, 0.03, 0.32, cx, 0.25, cz, 12)
  b.box('fabric', C.fabric, 0.5, 0.075, 0.48, cx, 0.47, cz)
  b.box('fabric', C.fabric, 0.46, 0.56, 0.06, cx, 0.8, cz + 0.25, { x: -0.1 })
  b.box('plastic', C.darkGray, 0.04, 0.2, 0.04, cx, 0.6, cz + 0.24)
  for (const sx of [-0.26, 0.26]) {
    b.box('plastic', C.darkGray, 0.05, 0.02, 0.26, cx + sx, 0.66, cz - 0.02)
    b.box('plastic', C.darkGray, 0.03, 0.16, 0.03, cx + sx, 0.57, cz + 0.06)
  }
}

function teacherDesk(b: Builder, w: number, d: number, chair: boolean) {
  const h = 0.76
  b.box('wood', C.walnut, w, 0.03, d, 0, h - 0.015, 0)
  // sağ çekmece gövdesi (öğretmen tarafı +z), sol yan panel, ön etek (öğrencilere bakan −z)
  const pw = 0.42
  const px = w / 2 - pw / 2 - 0.01
  b.box('matte', C.lightGray, pw, h - 0.03, d - 0.04, px, (h - 0.03) / 2, 0)
  for (let i = 0; i < 3; i++) {
    const y = 0.12 + i * 0.22
    b.box('matte', C.offWhite, pw - 0.03, 0.19, 0.012, px, y + 0.02, d / 2 - 0.014)
    b.box('metal', C.aluminum, 0.14, 0.012, 0.02, px, y + 0.09, d / 2 - 0.002)
  }
  b.box('wood', C.walnutDark, 0.03, h - 0.03, d - 0.04, -w / 2 + 0.03, (h - 0.03) / 2, 0)
  b.box('wood', C.walnutDark, w - 0.08, 0.42, 0.02, -0.02, h - 0.27, -d / 2 + 0.04)
  if (chair) officeChair(b, 0, d / 2 + TEACHER_CHAIR_ZONE / 2 + 0.02)
}

function smartboard(b: Builder, w: number, h: number) {
  // gövde (duvar tarafı +z), ekran (−z yüzü), alt hoparlör çubuğu, logo şeridi
  b.box('matte', C.black, w, h - 0.08, 0.07, 0, 0.08 + (h - 0.08) / 2, 0.02)
  b.box('screen', '#0f1a26', w - 0.07, h - 0.15, 0.004, 0, 0.08 + (h - 0.08) / 2, -0.017)
  b.box('matte', C.darkGray, w, 0.08, 0.1, 0, 0.04, 0.01)
  b.box('metal', C.aluminum, 0.18, 0.012, 0.004, 0, 0.1, -0.017)
  // duvar askısı
  b.box('metal', C.frame, w * 0.6, 0.06, 0.02, 0, h * 0.6, 0.05)
}

function whiteboard(b: Builder, w: number, h: number) {
  b.box('matte', '#f7f8f9', w - 0.03, h - 0.03, 0.012, 0, h / 2, 0.012)
  const t = 0.022
  b.box('metal', C.aluminum, w, t, 0.03, 0, h - t / 2, 0.01)
  b.box('metal', C.aluminum, w, t, 0.03, 0, t / 2, 0.01)
  b.box('metal', C.aluminum, t, h, 0.03, -w / 2 + t / 2, h / 2, 0.01)
  b.box('metal', C.aluminum, t, h, 0.03, w / 2 - t / 2, h / 2, 0.01)
  // kalem rafı
  b.box('metal', C.aluminum, w * 0.5, 0.02, 0.07, 0, 0.0, -0.02)
  const pens = ['#1d4ed8', '#b91c1c', '#15803d', '#111827']
  pens.forEach((p, i) => b.cyl('plastic', p, 0.009, 0.009, 0.13, -0.2 + i * 0.05, 0.02, -0.03, 8, { z: Math.PI / 2 }))
}

function cabinet(b: Builder, w: number, d: number, h: number) {
  b.box('matte', C.lightGray, w, h - 0.06, d, 0, 0.06 + (h - 0.06) / 2, 0)
  b.box('matte', C.darkGray, w - 0.04, 0.06, d - 0.04, 0, 0.03, 0.01)
  const doors = w > 0.7 ? 2 : 1
  const dw = (w - 0.02) / doors
  for (let i = 0; i < doors; i++) {
    const x = -w / 2 + 0.01 + dw / 2 + i * dw
    b.box('matte', C.offWhite, dw - 0.008, h - 0.1, 0.012, x, 0.06 + (h - 0.08) / 2, -d / 2 - 0.004)
    const hx = doors === 2 ? (i === 0 ? x + dw / 2 - 0.05 : x - dw / 2 + 0.05) : x + dw / 2 - 0.06
    b.box('metal', C.aluminum, 0.016, 0.16, 0.02, hx, h * 0.52, -d / 2 - 0.018)
  }
}

function bookshelf(b: Builder, w: number, d: number, h: number) {
  const t = 0.02
  b.box('wood', C.walnut, t, h, d, -w / 2 + t / 2, h / 2, 0)
  b.box('wood', C.walnut, t, h, d, w / 2 - t / 2, h / 2, 0)
  b.box('wood', C.walnutDark, w, h, 0.008, 0, h / 2, d / 2 - 0.004)
  const shelves = 5
  for (let i = 0; i <= shelves; i++) {
    const y = 0.04 + (i * (h - 0.06)) / shelves
    b.box('wood', C.walnut, w - 2 * t, t, d - 0.01, 0, y, -0.005)
    if (i < shelves && i % 2 === 0) {
      // kitaplar (belirli desen)
      let x = -w / 2 + t + 0.01
      let k = i
      while (x < w / 2 - t - 0.05) {
        const bw = 0.025 + ((k * 7) % 5) * 0.006
        const bh = 0.2 + ((k * 13) % 7) * 0.012
        b.box('matte', C.books[k % C.books.length]!, bw, bh, d * 0.7, x + bw / 2, y + t / 2 + bh / 2, -0.02)
        x += bw + 0.002
        k++
      }
    }
  }
}

function lectern(b: Builder) {
  b.box('wood', C.walnut, 0.52, 0.95, 0.4, 0, 0.475, 0.02)
  b.box('wood', C.walnutDark, 0.6, 0.03, 0.5, 0, 0.02, 0.02)
  b.box('wood', C.walnut, 0.6, 0.03, 0.46, 0, 1.08, 0.02, { x: 0.28 })
  b.box('wood', C.walnut, 0.56, 0.12, 0.4, 0, 1.0, 0.04)
  b.box('matte', C.navy, 0.3, 0.18, 0.006, 0, 0.66, -0.184)
  b.box('metal', C.aluminum, 0.2, 0.012, 0.004, 0, 0.6, -0.188)
}

function trash(b: Builder) {
  b.cyl('plastic', '#5b6776', 0.17, 0.145, 0.55, 0, 0.275, 0, 20)
  b.cyl('plastic', '#2d333b', 0.155, 0.155, 0.01, 0, 0.552, 0, 20)
  b.cyl('plastic', '#6d7a89', 0.175, 0.175, 0.03, 0, 0.54, 0, 20)
}

function ac(b: Builder, w: number) {
  b.box('plastic', C.white, w, 0.28, 0.22, 0, 0.16, 0.01)
  b.box('plastic', C.offWhite, w - 0.02, 0.04, 0.2, 0, 0.3 - 0.02, 0.0)
  b.box('matte', '#aab2bc', w - 0.12, 0.035, 0.012, 0, 0.055, -0.1)
  for (let i = 0; i < 6; i++) b.box('matte', '#c9ced5', w - 0.14, 0.004, 0.01, 0, 0.09 + i * 0.012, -0.099)
  b.box('screen', '#2ecc71', 0.012, 0.006, 0.004, w / 2 - 0.08, 0.2, -0.1)
}

function radiator(b: Builder, w: number) {
  const n = Math.max(6, Math.round(w / 0.05))
  const step = w / n
  for (let i = 0; i < n; i++) b.box('matte', C.white, step * 0.7, 0.56, 0.07, -w / 2 + step / 2 + i * step, 0.3, 0.0)
  b.box('matte', C.offWhite, w, 0.04, 0.05, 0, 0.58, 0.01)
  b.box('matte', C.offWhite, w, 0.04, 0.05, 0, 0.02, 0.01)
  b.cyl('metal', C.aluminum, 0.012, 0.012, 0.08, w / 2 + 0.02, 0.05, 0.0, 10)
}

function outlet(b: Builder) {
  b.box('plastic', C.white, 0.14, 0.08, 0.012, 0, 0.04, 0.014)
  for (const sx of [-0.033, 0.033]) {
    b.cyl('plastic', '#dfe2e5', 0.024, 0.024, 0.006, sx, 0.04, 0.006, 16, { x: Math.PI / 2 })
    b.cyl('matte', C.black, 0.004, 0.004, 0.008, sx - 0.009, 0.04, 0.003, 8, { x: Math.PI / 2 })
    b.cyl('matte', C.black, 0.004, 0.004, 0.008, sx + 0.009, 0.04, 0.003, 8, { x: Math.PI / 2 })
  }
}

function camera(b: Builder) {
  b.box('plastic', C.white, 0.08, 0.1, 0.02, 0, 0.07, 0.09)
  b.box('plastic', C.white, 0.03, 0.03, 0.1, 0, 0.09, 0.04)
  b.box('plastic', C.offWhite, 0.08, 0.075, 0.16, 0, 0.07, -0.03, { x: 0.35 })
  b.cyl('screen', C.black, 0.024, 0.024, 0.02, 0, 0.045, -0.11, 16, { x: Math.PI / 2 + 0.35 })
}

function projector(b: Builder, pole: number) {
  b.box('plastic', '#e4e6e9', 0.36, 0.11, 0.3, 0, 0.055, 0)
  b.box('plastic', '#cfd3d8', 0.34, 0.02, 0.28, 0, 0.115, 0)
  b.cyl('screen', C.black, 0.042, 0.048, 0.04, 0.09, 0.055, -0.155, 18, { x: Math.PI / 2 })
  b.box('matte', '#9aa1aa', 0.1, 0.05, 0.004, -0.08, 0.06, -0.151)
  if (pole > 0.02) {
    b.cyl('metal', C.frame, 0.018, 0.018, pole, 0, 0.125 + pole / 2, 0, 10)
    b.box('metal', C.frame, 0.16, 0.012, 0.16, 0, 0.125 + pole - 0.006, 0)
  }
  b.box('metal', C.frame, 0.2, 0.012, 0.2, 0, 0.125, 0)
}

function column(b: Builder, w: number, d: number, h: number) {
  b.box('matte', C.plaster, w, h, d, 0, h / 2, 0)
  b.box('matte', '#cfc9bf', w + 0.01, 0.08, d + 0.01, 0, 0.04, 0)
}

function looseChair(b: Builder) {
  studentChair(b, 0, 0)
}

// ------------------------------------------------------------------ önbellek

const models = new Map<string, Model>()

/** Nesnenin model anahtarı: aynı anahtardaki nesneler aynı geometriyi (ve InstancedMesh'i) paylaşır */
export function modelKey(o: SceneObject, ceiling: number): string {
  const { w, d, h } = sizeOf(o, ceiling)
  const c = catalogOf(o.type)
  const chair = c?.chair ? (o.chair ? 1 : 0) : 0
  let extra = ''
  if (o.type === 'projector') {
    const elev = o.elev ?? ceiling + (c?.elev ?? -0.6)
    extra = `|${Math.round((ceiling - elev - 0.125) * 20) / 20}`
  }
  return `${o.type}|${w.toFixed(2)}|${d.toFixed(2)}|${h.toFixed(2)}|${chair}${extra}`
}

export function getModel(o: SceneObject, ceiling: number): Model {
  const key = modelKey(o, ceiling)
  const hit = models.get(key)
  if (hit) return hit
  const { w, d, h } = sizeOf(o, ceiling)
  const b = new Builder()
  const seats: SeatSpot[] = []
  const withChair = !!catalogOf(o.type)?.chair && !!o.chair
  switch (o.type) {
    case 'desk-single':
      studentDesk(b, w, d, withChair ? [0] : null)
      seats.push({ x: 0, z: d / 2 - 0.1, w: w - 0.12, d: 0.16, y: 0.752 })
      break
    case 'desk-double':
      studentDesk(b, w, d, withChair ? [-w / 4, w / 4] : null)
      seats.push({ x: -w / 4, z: d / 2 - 0.1, w: w / 2 - 0.1, d: 0.16, y: 0.752 }, { x: w / 4, z: d / 2 - 0.1, w: w / 2 - 0.1, d: 0.16, y: 0.752 })
      break
    case 'chair':
      looseChair(b)
      break
    case 'teacher-desk':
      teacherDesk(b, w, d, withChair)
      break
    case 'teacher-chair':
      officeChair(b, 0, 0)
      break
    case 'smartboard':
      smartboard(b, w, h)
      break
    case 'whiteboard':
      whiteboard(b, w, h)
      break
    case 'cabinet':
      cabinet(b, w, d, h)
      break
    case 'bookshelf':
      bookshelf(b, w, d, h)
      break
    case 'lectern':
      lectern(b)
      break
    case 'trash':
      trash(b)
      break
    case 'ac':
      ac(b, w)
      break
    case 'radiator':
      radiator(b, w)
      break
    case 'outlet':
      outlet(b)
      break
    case 'camera':
      camera(b)
      break
    case 'projector': {
      const pole = Number(key.split('|')[5] ?? 0.4)
      projector(b, Math.max(0, pole))
      break
    }
    case 'column':
      column(b, w, d, h)
      break
    default:
      b.box('matte', C.midGray, w, h, d, 0, h / 2, 0)
  }
  const model: Model = { key, parts: b.build(), seats, height: h }
  models.set(key, model)
  return model
}
