import type { FurnitureType, SceneObject } from './types'

/**
 * NESNE KATALOĞU — boyutlar metre. Modeller `three/models.ts` içinde geometrilerle üretilir (dış dosya yok).
 * Ön (öğrencinin/kullanıcının baktığı yön) yerel −z; masaya bağlı sandalye alanı yerel +z tarafındadır.
 * Sunucudaki örnek derslik (SampleLayout.php) bu ölçüleri kullanır — değiştirirseniz orayı da güncelleyin.
 */

export type Mount = 'floor' | 'wall' | 'ceiling'

export type CatalogCategory = 'student' | 'teacher' | 'board' | 'storage' | 'building' | 'electric'

export const CATEGORY_LABELS: Record<CatalogCategory, string> = {
  student: 'Öğrenci',
  teacher: 'Öğretmen',
  board: 'Tahta ve sunum',
  storage: 'Dolap ve depolama',
  electric: 'Elektrik ve iklim',
  building: 'Yapı',
}

export type CatalogItem = {
  type: FurnitureType
  label: string
  hint: string
  category: CatalogCategory
  w: number
  d: number
  h: number
  mount: Mount
  /** duvar/tavan nesnesinin varsayılan alt kenar yüksekliği (tavan nesnesinde tavandan aşağı mesafe = -elev) */
  elev?: number
  seats?: number
  /** masaya bağlı sandalye varsayılanı ve arkada kapladığı derinlik */
  chair?: { default: boolean; zone: number; h: number; label: string }
  resizable?: { w?: [number, number]; d?: [number, number] }
  /** klavye/hızlı arama anahtar sözcükleri */
  keywords?: string[]
}

/** Masa arkasındaki öğrenci sandalyesi alanı (m) */
export const STUDENT_CHAIR_ZONE = 0.42
export const TEACHER_CHAIR_ZONE = 0.55

export const CATALOG: CatalogItem[] = [
  { type: 'desk-single', label: 'Tek kişilik masa', hint: '70 × 50 cm · sandalyeli', category: 'student', w: 0.7, d: 0.5, h: 0.75, mount: 'floor', seats: 1,
    chair: { default: true, zone: STUDENT_CHAIR_ZONE, h: 0.84, label: 'Öğrenci sandalyesi' }, keywords: ['sıra', 'masa', 'tekli'] },
  { type: 'desk-double', label: 'Çift kişilik masa', hint: '120 × 50 cm · 2 sandalye', category: 'student', w: 1.2, d: 0.5, h: 0.75, mount: 'floor', seats: 2,
    chair: { default: true, zone: STUDENT_CHAIR_ZONE, h: 0.84, label: 'Öğrenci sandalyeleri' }, keywords: ['sıra', 'masa', 'ikili'] },
  { type: 'chair', label: 'Sandalye', hint: '44 × 44 cm', category: 'student', w: 0.44, d: 0.44, h: 0.84, mount: 'floor', keywords: ['oturak'] },
  { type: 'teacher-desk', label: 'Öğretmen masası', hint: '140 × 70 cm · çekmeceli', category: 'teacher', w: 1.4, d: 0.7, h: 0.76, mount: 'floor',
    chair: { default: true, zone: TEACHER_CHAIR_ZONE, h: 1.08, label: 'Öğretmen sandalyesi' }, keywords: ['kürsü', 'öğretmen'] },
  { type: 'teacher-chair', label: 'Öğretmen sandalyesi', hint: 'Döner, tekerlekli', category: 'teacher', w: 0.62, d: 0.62, h: 1.08, mount: 'floor' },
  { type: 'lectern', label: 'Kürsü', hint: '60 × 50 cm', category: 'teacher', w: 0.6, d: 0.5, h: 1.15, mount: 'floor', keywords: ['podyum'] },
  { type: 'smartboard', label: 'Akıllı tahta', hint: '86″ · duvara', category: 'board', w: 2.0, d: 0.12, h: 1.18, mount: 'wall', elev: 0.9, keywords: ['ekran', 'etkileşimli'] },
  { type: 'whiteboard', label: 'Beyaz tahta', hint: '300 × 120 cm · duvara', category: 'board', w: 3.0, d: 0.05, h: 1.2, mount: 'wall', elev: 0.9,
    resizable: { w: [0.9, 6] }, keywords: ['yazı tahtası'] },
  { type: 'projector', label: 'Projeksiyon', hint: 'Tavana askılı', category: 'board', w: 0.36, d: 0.3, h: 0.13, mount: 'ceiling', elev: -0.6 },
  { type: 'cabinet', label: 'Dolap', hint: '100 × 50 × 190 cm', category: 'storage', w: 1.0, d: 0.5, h: 1.9, mount: 'floor', resizable: { w: [0.5, 3] } },
  { type: 'bookshelf', label: 'Kitaplık', hint: '90 × 35 × 180 cm', category: 'storage', w: 0.9, d: 0.35, h: 1.8, mount: 'floor', resizable: { w: [0.5, 3] } },
  { type: 'trash', label: 'Çöp kutusu', hint: 'Ø 35 cm', category: 'storage', w: 0.35, d: 0.35, h: 0.55, mount: 'floor' },
  { type: 'ac', label: 'Klima', hint: 'Duvar tipi · 90 cm', category: 'electric', w: 0.9, d: 0.25, h: 0.3, mount: 'wall', elev: 2.25 },
  { type: 'radiator', label: 'Radyatör', hint: '100 × 60 cm · duvara', category: 'electric', w: 1.0, d: 0.1, h: 0.6, mount: 'wall', elev: 0.12, resizable: { w: [0.4, 2.4] } },
  { type: 'outlet', label: 'Priz', hint: 'Çift priz · duvara', category: 'electric', w: 0.14, d: 0.04, h: 0.08, mount: 'wall', elev: 0.3 },
  { type: 'camera', label: 'Güvenlik kamerası', hint: 'Duvara, köşeye', category: 'electric', w: 0.14, d: 0.2, h: 0.14, mount: 'wall', elev: 2.5 },
  { type: 'column', label: 'Kolon', hint: '40 × 40 cm · tavana kadar', category: 'building', w: 0.4, d: 0.4, h: 3, mount: 'floor',
    resizable: { w: [0.2, 1.5], d: [0.2, 1.5] }, keywords: ['sütun', 'kiriş', 'taşıyıcı'] },
]

export const CATALOG_BY_TYPE = Object.fromEntries(CATALOG.map((c) => [c.type, c])) as Record<FurnitureType, CatalogItem>

/**
 * GLB/GLTF MODEL KAYIT DEFTERİ — gerçek bir model dosyası eklendiğinde buraya kaydedilir; kütüphanede
 * YALNIZ kayıtlı model varsa görünür (varsayılan: boş). Tür adı `gltf:<id>`, çizim `three/GltfModel.tsx`.
 *   registerGltfModel({ id: 'masa-x', url: '/build/models/masa-x.glb', label: 'Masa X', hint: '…', category: 'student',
 *                       w: 0.7, d: 0.5, h: 0.75, mount: 'floor', seats: 1 })
 * Model, ölçülen ayak izine (w × d) ölçeklenir ve tabanı zemine oturtulur.
 */
export type GltfModelDef = Omit<CatalogItem, 'type'> & { id: string; url: string }
export type GltfCatalogItem = Omit<CatalogItem, 'type'> & { type: `gltf:${string}`; url: string }

const GLTF_MODELS = new Map<string, GltfCatalogItem>()

export function registerGltfModel(def: GltfModelDef): void {
  const { id, ...rest } = def
  GLTF_MODELS.set(`gltf:${id}`, { ...rest, type: `gltf:${id}` })
}

export function gltfModels(): GltfCatalogItem[] {
  return [...GLTF_MODELS.values()]
}

export function gltfModelOf(type: string): GltfCatalogItem | undefined {
  return GLTF_MODELS.get(type)
}

export function catalogOf(type: string): CatalogItem | undefined {
  return CATALOG_BY_TYPE[type as FurnitureType] ?? (GLTF_MODELS.get(type) as unknown as CatalogItem | undefined)
}

export const DESK_TYPES: FurnitureType[] = ['desk-single', 'desk-double']
export const isDesk = (o: Pick<SceneObject, 'type'>) => o.type === 'desk-single' || o.type === 'desk-double'

/** Nesnenin etkin genişlik/derinlik/yüksekliği (boyut değiştirilmişse) */
export function sizeOf(o: SceneObject, ceiling: number): { w: number; d: number; h: number } {
  const c = catalogOf(o.type)
  const w = o.w ?? c?.w ?? 0.5
  const d = o.d ?? c?.d ?? 0.5
  const h = o.type === 'column' ? ceiling : c?.h ?? 0.8
  return { w, d, h }
}

/** Yerel ayak izi (merkeze göre): sandalye alanı +z tarafına eklenir */
export function localFootprint(o: SceneObject, ceiling: number): { minX: number; maxX: number; minZ: number; maxZ: number } {
  const { w, d } = sizeOf(o, ceiling)
  const c = catalogOf(o.type)
  const chairZone = c?.chair && o.chair ? c.chair.zone : 0
  return { minX: -w / 2, maxX: w / 2, minZ: -d / 2, maxZ: d / 2 + chairZone }
}

/** Dikey aralık [alt, üst] (m) — çarpışmada yalnız dikeyde de kesişen nesneler çakışır */
export function verticalRange(o: SceneObject, ceiling: number): [number, number] {
  const c = catalogOf(o.type)
  const { h } = sizeOf(o, ceiling)
  if (!c || c.mount === 'floor') {
    const top = c?.chair && o.chair ? Math.max(h, c.chair.h) : h
    return [0, top]
  }
  if (c.mount === 'ceiling') return [elevationOf(o, ceiling), ceiling]
  const e = elevationOf(o, ceiling)
  return [e, e + h]
}

export function elevationOf(o: SceneObject, ceiling: number): number {
  const c = catalogOf(o.type)
  if (!c || c.mount === 'floor') return 0
  if (c.mount === 'ceiling') {
    const e = o.elev ?? ceiling + (c.elev ?? -0.6)
    return Math.min(Math.max(e, 1.9), ceiling - c.h)
  }
  return o.elev ?? c.elev ?? 1
}

export function seatCount(o: SceneObject): number {
  return catalogOf(o.type)?.seats ?? 0
}

export function mountOf(o: Pick<SceneObject, 'type'>): Mount {
  return catalogOf(o.type)?.mount ?? 'floor'
}
