import { create } from 'zustand'
import type { CameraState, Doc, LayoutData, LayoutSettings, Opening, Room, RoomPolygon, SceneObject } from '../types'
import { DEFAULT_SETTINGS, dataFromDoc, docFromData, remapOpenings } from '../utils/doc'
import { openingZones, type CollisionWorld, type Zone } from '../utils/collision'
import { useHistory } from './historyStore'

/**
 * TEK DURUM DEPOSU — 3D sahne, 2D plan, paneller ve istatistik aynı belgeden okur (anlık eşitleme).
 * Belge değişmez (immutable) güncellenir; her kalıcı işlem `commit` ile geri alma geçmişine girer.
 * Sürükleme: begin → update (geçmişe yazmadan) → end (tek adım).
 */

export type LayoutMeta = {
  layoutId: number | null
  name: string
  classroomId: number | null
  classroomName: string | null
  classroomCapacity: number | null
  version: number
  isDemo: boolean
  hasThumbnail: boolean
}

type ClassroomState = LayoutMeta & {
  doc: Doc
  /** son kaydedilen belge (kaydedilmemiş değişiklik denetimi) */
  savedDoc: Doc
  savedMeta: { name: string; classroomId: number | null }
  settings: LayoutSettings
  camera: CameraState | null
  /** sürükleme başındaki belge */
  gestureBase: Doc | null
  /** açıklık bölgeleri önbelleği (room/openings değişince yenilenir) */
  zones: Zone[]
  zonesKey: unknown

  load: (meta: LayoutMeta, data: LayoutData) => void
  setMeta: (patch: Partial<Pick<LayoutMeta, 'name' | 'classroomId' | 'classroomName' | 'classroomCapacity'>>) => void
  markSaved: (version: number) => void
  setSettings: (patch: Partial<LayoutSettings>) => void
  setCamera: (c: CameraState | null) => void

  commit: (next: Doc) => void
  undo: () => boolean
  redo: () => boolean

  beginGesture: () => void
  updateGesture: (objects: SceneObject[]) => void
  endGesture: (revertTo?: SceneObject[]) => void
  cancelGesture: () => void
  /** oda/açıklık sürüklemesi (köşe, kapı konumu): belge geçici değişir, bitişte tek adım */
  updateGestureDoc: (doc: Doc) => void
  endDocGesture: () => void

  addObjects: (objs: SceneObject[]) => void
  patchObjects: (patch: Record<string, Partial<SceneObject>>) => void
  replaceObjects: (objects: Record<string, SceneObject>, order?: string[]) => void
  removeObjects: (ids: string[]) => void

  setRoom: (patch: Partial<Omit<Room, 'polygon'>>) => void
  setPolygon: (polygon: RoomPolygon, topologyChanged: boolean) => void
  setOpenings: (openings: Opening[]) => void

  toData: () => LayoutData
}

const EMPTY_DOC: Doc = {
  room: { polygon: [{ x: 0, z: 0 }, { x: 6, z: 0 }, { x: 6, z: 5 }, { x: 0, z: 5 }], ceiling: 3, wallThickness: 0.2 },
  openings: [],
  objects: {},
  order: [],
}

export const useClassroom = create<ClassroomState>((set, get) => ({
  layoutId: null,
  name: '',
  classroomId: null,
  classroomName: null,
  classroomCapacity: null,
  version: 0,
  isDemo: false,
  hasThumbnail: false,
  doc: EMPTY_DOC,
  savedDoc: EMPTY_DOC,
  savedMeta: { name: '', classroomId: null },
  settings: DEFAULT_SETTINGS,
  camera: null,
  gestureBase: null,
  zones: [],
  zonesKey: null,

  load: (meta, data) => {
    const doc = docFromData(data)
    useHistory.getState().clear()
    set({
      ...meta,
      doc,
      savedDoc: doc,
      savedMeta: { name: meta.name, classroomId: meta.classroomId },
      settings: { ...DEFAULT_SETTINGS, ...(data.settings ?? {}) },
      camera: data.camera ?? null,
      gestureBase: null,
      zones: openingZones(doc.room, doc.openings),
      zonesKey: [doc.room, doc.openings],
    })
  },
  setMeta: (patch) => set(patch),
  markSaved: (version) => set((s) => ({ version, savedDoc: s.doc, savedMeta: { name: s.name, classroomId: s.classroomId }, hasThumbnail: true })),
  setSettings: (patch) => set((s) => ({ settings: { ...s.settings, ...patch } })),
  setCamera: (camera) => set({ camera }),

  commit: (next) => {
    const cur = get().doc
    if (next === cur) return
    useHistory.getState().push(cur)
    set(withZones(next))
  },
  undo: () => {
    const s = get()
    if (s.gestureBase) return false
    const prev = useHistory.getState().undo(s.doc)
    if (!prev) return false
    set(withZones(prev))
    return true
  },
  redo: () => {
    const s = get()
    if (s.gestureBase) return false
    const next = useHistory.getState().redo(s.doc)
    if (!next) return false
    set(withZones(next))
    return true
  },

  beginGesture: () => set((s) => ({ gestureBase: s.doc })),
  updateGesture: (objs) =>
    set((s) => {
      const objects = { ...s.doc.objects }
      for (const o of objs) objects[o.id] = o
      return { doc: { ...s.doc, objects } }
    }),
  endGesture: (revertTo) => {
    const s = get()
    const base = s.gestureBase
    let doc = s.doc
    if (revertTo?.length) {
      const objects = { ...doc.objects }
      for (const o of revertTo) objects[o.id] = o
      doc = { ...doc, objects }
    }
    const changed = base && base.order.some((id) => base.objects[id] !== doc.objects[id] && !sameTransform(base.objects[id], doc.objects[id]))
    if (base && changed) useHistory.getState().push(base)
    set({ doc: changed || !base ? doc : base, gestureBase: null })
  },
  cancelGesture: () => {
    const base = get().gestureBase
    if (base) set({ ...withZones(base), gestureBase: null })
  },
  updateGestureDoc: (doc) => set(withZones(doc)),
  endDocGesture: () => {
    const { gestureBase: base, doc } = get()
    if (base && base !== doc) useHistory.getState().push(base)
    set({ gestureBase: null })
  },

  addObjects: (objs) => {
    const { doc, commit } = get()
    const objects = { ...doc.objects }
    const order = doc.order.slice()
    for (const o of objs) {
      objects[o.id] = o
      if (!order.includes(o.id)) order.push(o.id)
    }
    commit({ ...doc, objects, order })
  },
  patchObjects: (patch) => {
    const { doc, commit } = get()
    const objects = { ...doc.objects }
    let changed = false
    for (const [id, p] of Object.entries(patch)) {
      const o = objects[id]
      if (!o) continue
      objects[id] = { ...o, ...p }
      changed = true
    }
    if (changed) commit({ ...doc, objects })
  },
  replaceObjects: (objects, order) => {
    const { doc, commit } = get()
    commit({ ...doc, objects, order: order ?? doc.order.filter((id) => objects[id]) })
  },
  removeObjects: (ids) => {
    const { doc, commit } = get()
    const drop = new Set(ids)
    const objects = { ...doc.objects }
    for (const id of ids) delete objects[id]
    commit({ ...doc, objects, order: doc.order.filter((id) => !drop.has(id)) })
  },

  setRoom: (patch) => {
    const { doc, commit } = get()
    commit({ ...doc, room: { ...doc.room, ...patch } })
  },
  setPolygon: (polygon, topologyChanged) => {
    const { doc, commit } = get()
    commit({ ...doc, room: { ...doc.room, polygon }, openings: remapOpenings(doc.openings, doc.room.polygon, polygon, !topologyChanged) })
  },
  setOpenings: (openings) => {
    const { doc, commit } = get()
    commit({ ...doc, openings })
  },

  toData: () => {
    const s = get()
    return dataFromDoc(s.doc, s.camera, s.settings)
  },
}))

function sameTransform(a?: SceneObject, b?: SceneObject) {
  return !!a && !!b && a.x === b.x && a.z === b.z && a.rot === b.rot
}

function withZones(doc: Doc): Partial<ClassroomState> {
  return { doc, zones: openingZones(doc.room, doc.openings), zonesKey: [doc.room, doc.openings] }
}

/** Çarpışma dünyası (tüm nesneler) */
export function collisionWorld(): CollisionWorld {
  const s = useClassroom.getState()
  return { room: s.doc.room, zones: s.zones, objects: s.doc.order.map((id) => s.doc.objects[id]!).filter(Boolean) }
}

export function isDirty(s: Pick<ClassroomState, 'doc' | 'savedDoc' | 'name' | 'classroomId' | 'savedMeta'>): boolean {
  return s.doc !== s.savedDoc || s.name !== s.savedMeta.name || s.classroomId !== s.savedMeta.classroomId
}
