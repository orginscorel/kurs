import { useEffect } from 'react'
import { createPortal } from 'react-dom'
import { toast } from 'sonner'
import { catalogOf, isDesk } from '../../catalog'
import { assignToSeat, describeCollision, makeObject, preparePlacement } from '../../state/actions'
import { useClassroom } from '../../state/classroomStore'
import { resolveAt, useDrag } from '../../state/dragStore'
import { useSelection } from '../../state/selectionStore'
import type { SceneObject } from '../../types'
import { cn } from '@/lib/cn'

/**
 * Kütüphaneden nesne / listeden öğrenci sürükleme katmanı (fare + dokunmatik).
 * İmleç bir görünümün (3D ya da 2D) üstündeyse o görünümün çözücüsüyle zemin noktası / masa bulunur;
 * nesnede yeşil/kırmızı önizleme, öğrencide hedef masa vurgulanır. Bırakınca eklenir / atanır.
 */
export function DragLayer() {
  const payload = useDrag((s) => s.payload)
  const pointer = useDrag((s) => s.pointer)
  const preview = useDrag((s) => s.preview)
  const valid = useDrag((s) => s.previewValid)
  const reason = useDrag((s) => s.previewReason)
  const target = useDrag((s) => s.target)

  useEffect(() => {
    if (!payload) return
    // sürüklenen nesnenin önizleme şablonu (kimlik ve masa no bir kez)
    const template: SceneObject | null = payload.kind === 'furniture' ? makeObject(payload.type) : null
    const onMove = (e: PointerEvent) => {
      const d = useDrag.getState()
      d.move(e.clientX, e.clientY)
      const hit = resolveAt(e.clientX, e.clientY)
      if (payload.kind === 'furniture' && template) {
        if (!hit?.point) {
          d.setPreview(null, false)
          return
        }
        const { obj, result } = preparePlacement(template, hit.point)
        d.setPreview(obj, result.ok, result.ok ? null : describeCollision(result))
      } else if (payload.kind === 'student') {
        const o = hit?.objectId ? useClassroom.getState().doc.objects[hit.objectId] : undefined
        d.setTarget(o && isDesk(o) ? { id: o.id, seat: hit!.seat ?? 0 } : null)
      }
    }
    const onUp = (e: PointerEvent) => {
      const d = useDrag.getState()
      const p = d.payload
      const pv = d.preview
      const ok = d.previewValid
      const tg = d.target
      const why = d.previewReason
      d.end()
      if (!p) return
      if (p.kind === 'furniture') {
        if (pv && ok) {
          useClassroom.getState().addObjects([pv])
          useSelection.getState().select([pv.id])
        } else if (pv && !ok) {
          toast.error(`${catalogOf(p.type)?.label ?? 'Nesne'} buraya konamaz: ${why ?? ''}`)
        } else if (!resolveAt(e.clientX, e.clientY)) {
          // görünüm dışına bırakıldı: sessizce vazgeç
        }
      } else if (tg) {
        assignToSeat(p.student, tg.id, tg.seat)
      }
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') useDrag.getState().end()
    }
    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    window.addEventListener('pointercancel', onUp)
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
      window.removeEventListener('pointercancel', onUp)
      window.removeEventListener('keydown', onKey)
    }
  }, [payload])

  if (!payload || !pointer) return null
  const overView = preview !== null || target !== null
  const label = payload.kind === 'furniture' ? payload.label : payload.student.name
  const tone = payload.kind === 'furniture' ? (preview ? (valid ? 'ok' : 'bad') : 'idle') : target ? 'ok' : 'idle'
  const desk = target ? useClassroom.getState().doc.objects[target.id] : undefined
  return createPortal(
    <div
      className={cn(
        'pointer-events-none fixed z-[60] -translate-x-1/2 translate-y-3 whitespace-nowrap rounded-[6px] px-2 py-1 text-[12px] font-medium shadow-[var(--shadow-pop)] ring-1',
        tone === 'ok' ? 'bg-accent text-white ring-accent' : tone === 'bad' ? 'bg-danger text-white ring-danger' : 'bg-surface text-ink ring-line-strong',
        overView && payload.kind === 'furniture' && 'opacity-90',
      )}
      style={{ left: pointer.x, top: pointer.y }}
    >
      {label}
      {tone === 'bad' && reason ? ` — ${reason}` : ''}
      {payload.kind === 'student' && desk ? ` → Masa ${desk.no ?? ''}${desk.type === 'desk-double' ? ` (${(target!.seat ?? 0) + 1}. oturak)` : ''}` : ''}
    </div>,
    document.body,
  )
}

