import { toast } from 'sonner'
import { catalogOf, isDesk, mountOf } from '../catalog'
import { useClassroom } from '../state/classroomStore'
import type { Vec2 } from '../types'
import { footprintQuad, openingZones, rectQuad } from '../utils/collision'
import { edgeAt, edges, offsetPolygon, bbox } from '../utils/polygon'
import { formatMeters } from '../utils/measurement'

/**
 * YAZDIRILABİLİR OTURMA PLANI — üstten 2D plan (duvarlar, kapı, pencere, tahta, öğretmen masası, masa no + öğrenci adı)
 * ve masa → öğrenci listesi. Tarayıcının yazdırma penceresi (PDF olarak kaydet de buradan) açılır; A4 yatay.
 */
const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!)

export function seatingPlanSvg(): string {
  const { doc } = useClassroom.getState()
  const room = doc.room
  const outer = offsetPolygon(room.polygon, room.wallThickness)
  const b = bbox(outer)
  const pad = 0.6
  const W = b.w + pad * 2
  const H = b.d + pad * 2
  const P = (p: Vec2) => `${(p.x - b.minX + pad).toFixed(3)},${(p.z - b.minZ + pad).toFixed(3)}`
  const parts: string[] = []
  parts.push(`<path d="M${outer.map(P).join('L')}Z M${room.polygon.map(P).join('L')}Z" fill="#333" fill-rule="evenodd"/>`)
  parts.push(`<polygon points="${room.polygon.map(P).join(' ')}" fill="#fff"/>`)
  for (const o of doc.openings) {
    if (o.wall >= room.polygon.length) continue
    const e = edgeAt(room.polygon, o.wall)
    const at = (s: number, d: number): Vec2 => ({ x: e.a.x + e.dir.x * s + e.n.x * d, z: e.a.z + e.dir.z * s + e.n.z * d })
    const s0 = o.offset - o.width / 2
    const s1 = o.offset + o.width / 2
    const t = room.wallThickness
    parts.push(`<polygon points="${[at(s0, 0), at(s1, 0), at(s1, t), at(s0, t)].map(P).join(' ')}" fill="#fff" stroke="#333" stroke-width="0.01"/>`)
    if (o.kind === 'window') parts.push(`<line x1="${P(at(s0, t / 2)).split(',')[0]}" y1="${P(at(s0, t / 2)).split(',')[1]}" x2="${P(at(s1, t / 2)).split(',')[0]}" y2="${P(at(s1, t / 2)).split(',')[1]}" stroke="#333" stroke-width="0.02"/>`)
  }
  for (const z of openingZones(room, doc.openings).filter((z) => z.kind === 'door')) {
    parts.push(`<polygon points="${z.quad.map(P).join(' ')}" fill="none" stroke="#999" stroke-width="0.015" stroke-dasharray="0.08 0.06"/>`)
    const c = z.quad.reduce((a, p) => ({ x: a.x + p.x / 4, z: a.z + p.z / 4 }), { x: 0, z: 0 })
    const [cx, cy] = P(c).split(',')
    parts.push(`<text x="${cx}" y="${cy}" font-size="0.2" text-anchor="middle" dominant-baseline="central" fill="#666">KAPI</text>`)
  }
  for (const id of doc.order) {
    const o = doc.objects[id]!
    const c = catalogOf(o.type)
    if (!c || mountOf(o) === 'ceiling') continue
    const [cx, cy] = P({ x: o.x, z: o.z }).split(',')
    if (isDesk(o)) {
      const d = o.d ?? c.d
      parts.push(`<polygon points="${footprintQuad(o, room.ceiling).map(P).join(' ')}" fill="${o.status === 'unavailable' ? '#f4d6da' : o.status === 'reserved' ? '#f7ead0' : '#f3ecdf'}" stroke="#555" stroke-width="0.015"/>`)
      parts.push(`<polygon points="${rectQuad(o, { minX: -(o.w ?? c.w) / 2, maxX: (o.w ?? c.w) / 2, minZ: -d / 2, maxZ: d / 2 }).map(P).join(' ')}" fill="#e4d3b3" stroke="#555" stroke-width="0.015"/>`)
      const names = (o.seats ?? []).map((s) => s?.name ?? '')
      parts.push(`<text x="${cx}" y="${cy}" font-size="0.2" font-weight="700" text-anchor="middle" dominant-baseline="central">${o.no ?? ''}</text>`)
      const label = names.filter(Boolean).join(' / ') || (o.status === 'unavailable' ? 'kullanılamaz' : o.status === 'reserved' ? 'rezerve' : '')
      if (label) {
        const [lx, ly] = P({ x: o.x + Math.sin(o.rot) * 0.47, z: o.z + Math.cos(o.rot) * 0.47 }).split(',')
        parts.push(`<text x="${lx}" y="${ly}" font-size="${label.length > 16 ? 0.11 : 0.13}" text-anchor="middle" dominant-baseline="central" fill="#111">${esc(label)}</text>`)
      }
    } else {
      const fill = o.type === 'smartboard' ? '#222' : o.type === 'teacher-desk' ? '#b59a7a' : o.type === 'column' ? '#bbb' : '#ddd'
      parts.push(`<polygon points="${footprintQuad(o, room.ceiling).map(P).join(' ')}" fill="${fill}" stroke="#555" stroke-width="0.012"/>`)
      if (o.type === 'teacher-desk') parts.push(`<text x="${cx}" y="${cy}" font-size="0.15" text-anchor="middle" dominant-baseline="central">Öğretmen</text>`)
    }
  }
  const board = doc.order.map((id) => doc.objects[id]!).find((o) => o.type === 'smartboard' || o.type === 'whiteboard')
  if (board) {
    const [bx, by] = P({ x: board.x - Math.sin(board.rot) * 0.35, z: board.z - Math.cos(board.rot) * 0.35 }).split(',')
    parts.push(`<text x="${bx}" y="${by}" font-size="0.18" text-anchor="middle" dominant-baseline="central" fill="#333">TAHTA</text>`)
  }
  for (const e of edges(room.polygon)) {
    const off = room.wallThickness + 0.25
    const [mx, my] = P({ x: (e.a.x + e.b.x) / 2 + e.n.x * off, z: (e.a.z + e.b.z) / 2 + e.n.z * off }).split(',')
    parts.push(`<text x="${mx}" y="${my}" font-size="0.16" text-anchor="middle" dominant-baseline="central" fill="#555">${formatMeters(e.len)}</text>`)
  }
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W.toFixed(2)} ${H.toFixed(2)}" font-family="Inter, Arial, sans-serif">${parts.join('')}</svg>`
}

export function printSeatingPlan(groupName: string, roomName: string) {
  const { doc } = useClassroom.getState()
  const desks = doc.order.map((id) => doc.objects[id]!).filter((o) => o && isDesk(o)).sort((a, b) => (a.no ?? 1e9) - (b.no ?? 1e9))
  const rows = desks
    .map((o) => `<tr><td>${o.no ?? '–'}</td><td>${esc((o.seats ?? []).map((s) => s?.name ?? '').filter(Boolean).join(' / ') || (o.status === 'unavailable' ? 'kullanılamaz' : o.status === 'reserved' ? 'rezerve' : '—'))}</td></tr>`)
    .join('')
  const w = window.open('', '_blank', 'width=1100,height=800')
  if (!w) {
    toast.error('Yazdırma penceresi açılamadı (açılır pencere engelleyicisini kapatın).')
    return
  }
  const date = new Date().toLocaleDateString('tr-TR')
  w.document.write(`<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>${esc(groupName)} oturma planı</title>
<style>@page{size:A4 landscape;margin:10mm}body{font-family:Inter,Arial,sans-serif;color:#111;margin:0}h1{font-size:18px;margin:0}
.head{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #1f3b63;padding-bottom:6px;margin-bottom:8px}
.sub{font-size:12px;color:#555}.wrap{display:flex;gap:14px}.plan{flex:1;min-width:0}.plan svg{width:100%;height:auto;max-height:170mm}
table{border-collapse:collapse;font-size:11px;width:62mm}td,th{border:1px solid #bbb;padding:2px 5px;text-align:left}th{background:#eef0f2}td:first-child{width:12mm;text-align:center;font-weight:700}</style></head>
<body><div class="head"><div><h1>${esc(groupName)} — Oturma planı</h1><div class="sub">${esc(roomName)} · ${date}</div></div></div>
<div class="wrap"><div class="plan">${seatingPlanSvg()}</div><table><thead><tr><th>Masa</th><th>Öğrenci</th></tr></thead><tbody>${rows}</tbody></table></div></body></html>`)
  w.document.close()
  w.focus()
  setTimeout(() => w.print(), 300)
}
