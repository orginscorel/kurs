import { useEffect } from 'react'
import { toast } from 'sonner'
import { copySelected, deleteSelected, duplicateSelected, gestureActive, nudgeSelected, pasteObjects, rotateSelected, selectAll } from '../../state/actions'
import { useClassroom } from '../../state/classroomStore'
import { useDrag } from '../../state/dragStore'
import { useSelection } from '../../state/selectionStore'
import { removeVertex } from '../../utils/polygon'

/**
 * KLAVYE — Ctrl+Z / Ctrl+Shift+Z (Ctrl+Y) geri al/yinele, Delete sil, Ctrl+C/V/D kopyala/yapıştır/çoğalt,
 * R 90° döndür (Shift+R ters), oklar 1 cm (Shift 10 cm), Esc seçimi kaldır, Ctrl+A tümünü seç,
 * V seç aracı, M ölçü aracı, Ctrl+S kaydet. Yazı alanındayken devre dışı.
 */
export function useEditorKeyboard({ readOnly, onSave }: { readOnly: boolean; onSave: () => void }) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement | null
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return
      if (document.querySelector('[role="dialog"]')) return
      if (useDrag.getState().payload) return
      const mod = e.ctrlKey || e.metaKey
      const key = e.key.toLowerCase()
      const sel = useSelection.getState()
      const cs = useClassroom.getState()

      if (mod && key === 's') {
        e.preventDefault()
        if (!readOnly) onSave()
        return
      }
      if (mod && (key === 'z' || key === 'y')) {
        e.preventDefault()
        if (readOnly || gestureActive()) return
        const redo = key === 'y' || e.shiftKey
        const ok = redo ? cs.redo() : cs.undo()
        if (!ok) toast.info(redo ? 'Yinelenecek işlem yok.' : 'Geri alınacak işlem yok.', { id: 'history' })
        else {
          // silinmiş nesneleri seçimden düş
          const doc = useClassroom.getState().doc
          sel.select(sel.selected.filter((id) => doc.objects[id]))
        }
        return
      }
      if (mod && key === 'a') {
        e.preventDefault()
        selectAll()
        return
      }
      if (e.key === 'Escape') {
        if (sel.tool === 'measure' && sel.pendingMeasure) sel.setPendingMeasure(null)
        else if (sel.tool !== 'select') sel.setTool('select')
        else sel.clear()
        return
      }
      if (readOnly) return
      if (mod && key === 'c') {
        copySelected()
        return
      }
      if (mod && key === 'v') {
        e.preventDefault()
        pasteObjects()
        return
      }
      if (mod && key === 'd') {
        e.preventDefault()
        duplicateSelected()
        return
      }
      if (e.key === 'Delete' || e.key === 'Backspace') {
        e.preventDefault()
        if (sel.tool === 'room' && sel.vertex !== null) {
          const poly = cs.doc.room.polygon
          if (poly.length <= 3) toast.error('Oda en az 3 köşeden oluşmalı.')
          else {
            cs.setPolygon(removeVertex(poly, sel.vertex), true)
            sel.selectVertex(null)
          }
          return
        }
        deleteSelected()
        return
      }
      if (!mod && key === 'r') {
        rotateSelected(e.shiftKey ? Math.PI / 2 : -Math.PI / 2)
        return
      }
      if (!mod && key === 'm') {
        sel.setTool(sel.tool === 'measure' ? 'select' : 'measure')
        return
      }
      if (!mod && key === 'v') {
        sel.setTool('select')
        return
      }
      const step = e.shiftKey ? 0.1 : 0.01
      const d: Record<string, [number, number]> = { arrowleft: [-step, 0], arrowright: [step, 0], arrowup: [0, -step], arrowdown: [0, step] }
      if (d[key] && sel.selected.length) {
        e.preventDefault()
        nudgeSelected(...d[key]!)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [readOnly, onSave])
}
