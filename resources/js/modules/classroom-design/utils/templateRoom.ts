import type { LayoutData, SceneObject } from '../types'
import { checkObject, openingZones } from './collision'
import { newId } from './doc'
import { DEFAULT_GENERATOR, generateLayout } from './layoutGenerator'
import { edgeAt, templatePolygon } from './polygon'
import { newObjectDefaults, placeObject, snapToWall } from './snap'

/**
 * HAZIR ŞABLON — öğrenci sayısına göre klasik (tahtaya dönük sıralı) dikdörtgen derslik:
 * tek kişilik masalar 5-6 sütun, akıllı tahta, öğretmen masası, arka sağda kapı, sol duvarda pencereler.
 * Oda ölçüsü masa sayısına göre hesaplanır (en az 6,0 × 5,0 m).
 */
export function classicTemplate(students: number): LayoutData {
  const n = Math.max(4, Math.min(60, Math.round(students) || 20))
  const cols = n <= 12 ? 4 : n <= 25 ? 5 : 6
  const rows = Math.ceil(n / cols)
  const width = Math.max(6, Math.round((2 * 0.35 + cols * 0.7 + (cols - 1) * 0.5 + 0.9) * 10) / 10)
  const depth = Math.max(5, Math.round((1.45 + rows * 0.92 + (rows - 1) * 0.12 + 0.45) * 10) / 10)
  const polygon = templatePolygon('rect', { width, depth, cutW: 0, cutD: 0 })
  const room = { polygon, ceiling: 3, wallThickness: 0.2 }
  const openings: LayoutData['openings'] = [
    { id: newId('d'), kind: 'door', wall: 1, offset: Math.round((depth - 0.8) * 100) / 100, width: 0.9, height: 2.1, sill: 0, swing: 'in-left' },
  ]
  const windows = Math.max(2, Math.floor(depth / 2))
  for (let i = 0; i < windows; i++) openings.push({ id: newId('w'), kind: 'window', wall: 3, offset: Math.round(((depth * (i + 0.5)) / windows) * 100) / 100, width: 1.2, height: 1.4, sill: 0.9 })

  const objects: SceneObject[] = []
  const world = () => ({ room, zones: openingZones(room, openings), objects })
  const e0 = edgeAt(polygon, 0)
  const board = snapToWall({ id: newId('o'), type: 'smartboard', x: 0, z: 0, rot: 0, elev: 0.9 }, room, { x: width / 2, z: 0 })
  if (checkObject(board, world()).ok) objects.push(board)
  const teacher = placeObject({ id: newId('o'), type: 'teacher-desk', x: 0, z: 0, rot: Math.atan2(e0.n.x, e0.n.z), ...newObjectDefaults('teacher-desk') }, room, { x: width - 1.05, z: 0.95 }, { grid: 0.05, snap: true })
  if (checkObject(teacher, world()).ok) objects.push(teacher)
  const g = generateLayout(room, openings, objects, { ...DEFAULT_GENERATOR, students: n, boardWall: 0, deskType: 'desk-single' })
  for (const o of g.objects) objects.push({ ...o, id: newId('o') })
  return { schema: 1, room, openings, objects, camera: null, settings: { grid: 0.1, snap: true, labels: true } }
}
