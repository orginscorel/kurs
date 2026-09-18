/**
 * 3D DERSLİK TASARIMI — veri modeli.
 *
 * Birim: METRE (1 birim = 1 m). Plan koordinatı: x sağa, z "aşağı" (tahtadan arkaya). 3D'de y yukarı.
 * Oda çokgeni iç duvar yüzüdür; saat yönünün tersine (pozitif işaretli alan) tutulur → duvar i: polygon[i] → polygon[i+1],
 * dış normal (dz, −dx). Duvarlar çokgenin DIŞINA doğru `wallThickness` kalınlığında örülür.
 * Nesne dönüşü (rot, radyan, Y ekseni): 0 → nesnenin "önü" −z'ye bakar (öğrenci masası tahtaya bakar).
 * Sunucuda (classroom_layouts.data) aynı JSON saklanır — app/Services/ClassroomDesign/SampleLayout.php.
 */

export type Vec2 = { x: number; z: number }
export type RoomPolygon = Vec2[]

export type DoorSwing = 'in-left' | 'in-right' | 'out-left' | 'out-right'

export type Opening = {
  id: string
  kind: 'door' | 'window'
  /** Duvar (kenar) sırası: polygon[wall] → polygon[wall+1] */
  wall: number
  /** Boşluk MERKEZİNİN duvar başlangıcına uzaklığı (m) */
  offset: number
  width: number
  height: number
  /** Pencere: yerden yükseklik (denizlik). Kapı: 0 */
  sill: number
  swing?: DoorSwing
}

export type Room = {
  polygon: RoomPolygon
  ceiling: number
  wallThickness: number
  floor?: string
}

export type SeatStatus = 'normal' | 'reserved' | 'unavailable'
export type SeatStudent = { uuid: string; name: string }

export type FurnitureType =
  | 'desk-single'
  | 'desk-double'
  | 'chair'
  | 'teacher-desk'
  | 'teacher-chair'
  | 'smartboard'
  | 'whiteboard'
  | 'cabinet'
  | 'bookshelf'
  | 'lectern'
  | 'trash'
  | 'ac'
  | 'radiator'
  | 'outlet'
  | 'camera'
  | 'projector'
  | 'column'

export type SceneObject = {
  id: string
  /** Katalog türü ya da kayıtlı GLTF modelinin türü (gltf:…) */
  type: FurnitureType | `gltf:${string}`
  x: number
  z: number
  rot: number
  /** Duvar/tavan nesneleri: alt kenarın yerden yüksekliği (m). Zemin nesnelerinde yok. */
  elev?: number
  /** Boyut değiştirilebilen türlerde (kolon, beyaz tahta, dolap…) genişlik/derinlik (m) */
  w?: number
  d?: number
  locked?: boolean
  /** Masalar: sandalye(ler) masaya bağlı mı */
  chair?: boolean
  /** Masa numarası */
  no?: number
  status?: SeatStatus
  /** Masa oturakları (tek: 1, çift: 2) — öğrenci UUID + ad (ad görüntü için anlık kopya) */
  seats?: (SeatStudent | null)[]
}

export type CameraState = { position: [number, number, number]; target: [number, number, number] }

export type LayoutSettings = { grid: number; snap: boolean; labels: boolean }

/** Sunucuda saklanan belge (classroom_layouts.data) */
export type LayoutData = {
  schema: 1
  room: Room
  openings: Opening[]
  objects: SceneObject[]
  camera?: CameraState | null
  settings?: LayoutSettings
}

/** Düzenleyicideki belge: nesneler kimliğe göre (O(1) erişim, seçici bazlı yeniden çizim) */
export type Doc = {
  room: Room
  openings: Opening[]
  objects: Record<string, SceneObject>
  order: string[]
}

export type LayoutStats = {
  area: number
  desks: number
  chairs: number
  capacity: number
  assigned: number
  empty: number
  objects: number
  occupancy: number
}

// ------------------------------------------------------------------ API

export type LayoutListItem = {
  id: number
  uuid: string | null
  name: string
  classroom: { id: number; name: string; floor: string | null; capacity: number | null } | null
  version: number
  is_active: boolean
  is_demo: boolean
  stats: Partial<LayoutStats> | null
  thumbnail_url: string | null
  updated_at: string | null
  updated_by: string | null
}

export type LayoutDetail = {
  id: number
  uuid: string | null
  name: string
  classroom: { id: number; name: string; floor: string | null; capacity: number | null; kind: string } | null
  version: number
  is_active: boolean
  is_demo: boolean
  has_thumbnail: boolean
  data: LayoutData
  stats: Partial<LayoutStats> | null
  updated_at: string | null
}

export type LayoutVersion = {
  id: number
  version: number
  label: string | null
  stats: Partial<LayoutStats> | null
  created_at: string | null
  created_by: string | null
  is_current: boolean
}

export type RosterGroup = { id: number; name: string; student_count: number; homeroom: boolean; weekly_lessons: number }
export type RosterStudent = {
  uuid: string
  name: string
  first_name: string
  last_name: string
  student_no: string
  group_id: number
  group_name: string
  joined_on: string | null
}
export type Roster = {
  groups: RosterGroup[]
  using_group_ids: number[]
  selected_group_ids: number[]
  students: RosterStudent[]
  can_view_students: boolean
}
