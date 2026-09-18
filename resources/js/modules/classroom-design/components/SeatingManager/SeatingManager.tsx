import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import { GripVertical, Search, Shuffle, UserMinus, Users, X } from 'lucide-react'
import { isDesk, seatCount } from '../../catalog'
import { assignToSeat, removeFromSeat } from '../../state/actions'
import { useClassroom } from '../../state/classroomStore'
import { useDrag } from '../../state/dragStore'
import { useSelection } from '../../state/selectionStore'
import { useSeatingContext, type SeatingStudent } from '../../state/seatingContext'
import type { SceneObject } from '../../types'
import { AUTO_MODE_LABELS, autoAssign, type AutoMode } from '../../utils/seating'
import { Button } from '@/components/ui/Button'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Checkbox, Input, Segmented } from '@/components/ui/form'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import { cn } from '@/lib/cn'

/**
 * ÖĞRENCİ LİSTESİ (sınıf oturma planı) — yalnız bu sınıfın öğrencileri. Öğrenciyi masaya sürükleyin (dolu masada
 * oturan öğrenci yer değiştirir), masa seçiliyken öğrenciye tıklayınca o masaya oturur. Otomatik yerleştirme
 * önizlemeli ve onaylıdır; rezerve / kullanılamaz masalar atlanır.
 */
export function SeatingManager({ readOnly }: { readOnly?: boolean }) {
  const objects = useClassroom((s) => s.doc.objects)
  const order = useClassroom((s) => s.doc.order)
  const selected = useSelection((s) => s.selected)
  const students = useSeatingContext((s) => s.students)
  const hasGender = useSeatingContext((s) => s.hasGender)
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState<'all' | 'free' | 'seated'>('all')
  const [auto, setAuto] = useState(false)
  const [clearAll, setClearAll] = useState(false)

  const seatOf = useMemo(() => {
    const m = new Map<string, { id: string; no?: number; seat: number }>()
    for (const id of order) {
      const o = objects[id]
      o?.seats?.forEach((s, i) => s && m.set(s.uuid, { id, no: o.no, seat: i }))
    }
    return m
  }, [objects, order])

  const list = students.filter((s) => {
    if (filter === 'free' && seatOf.has(s.uuid)) return false
    if (filter === 'seated' && !seatOf.has(s.uuid)) return false
    const t = q.trim().toLocaleLowerCase('tr')
    return !t || `${s.name} ${s.student_no}`.toLocaleLowerCase('tr').includes(t)
  })
  const selectedDesk = selected.length === 1 && objects[selected[0]!] && isDesk(objects[selected[0]!]!) ? objects[selected[0]!]! : null
  const capacity = order.reduce((n, id) => {
    const o = objects[id]
    return o && isDesk(o) && (o.status ?? 'normal') === 'normal' ? n + seatCount(o) : n
  }, 0)
  const seatedCount = students.filter((s) => seatOf.has(s.uuid)).length

  const onTap = (s: SeatingStudent) => {
    const at = seatOf.get(s.uuid)
    if (selectedDesk && !readOnly) {
      assignToSeat({ uuid: s.uuid, name: s.name }, selectedDesk.id)
      return
    }
    if (at) {
      useSelection.getState().select([at.id])
      return
    }
    if (!readOnly) toast.info('Önce bir masa seçin ya da öğrenciyi sürükleyip masaya bırakın.', { id: 'seat-hint' })
  }

  const startDrag = (s: SeatingStudent, e: React.PointerEvent) => {
    if (e.button !== 0) return
    if (readOnly) return onTap(s)
    const sx = e.clientX
    const sy = e.clientY
    let started = false
    const move = (m: PointerEvent) => {
      if (!started && Math.hypot(m.clientX - sx, m.clientY - sy) > 6) {
        started = true
        const from = seatOf.get(s.uuid)
        useDrag.getState().start({ kind: 'student', student: { uuid: s.uuid, name: s.name }, fromSeat: from ? { id: from.id, seat: from.seat } : null }, m.clientX, m.clientY)
      }
    }
    const up = () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
      window.removeEventListener('pointercancel', up)
      if (!started) onTap(s)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
    window.addEventListener('pointercancel', up)
  }

  if (!students.length) {
    return <EmptyState compact icon={<Users />} title="Sınıfta öğrenci yok" description="Sınıfa öğrenci eklendiğinde burada listelenir." />
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="space-y-2.5 border-b border-line p-3">
        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Öğrenci ara (ad, no)" leading={<Search />} aria-label="Öğrenci ara" />
        <Segmented size="sm" value={filter} onChange={setFilter} options={[{ value: 'all', label: `Tümü ${students.length}` }, { value: 'free', label: `Yerleşmemiş ${students.length - seatedCount}` }, { value: 'seated', label: `Oturan ${seatedCount}` }]} />
        {!readOnly && (
          <div className="flex flex-wrap gap-1.5">
            <Button size="sm" variant="secondary" icon={<Shuffle className="size-4" />} onClick={() => setAuto(true)} disabled={!capacity} data-testid="btn-auto-seat">
              Otomatik yerleştir
            </Button>
            <Button size="sm" variant="ghost" icon={<UserMinus className="size-4" />} onClick={() => setClearAll(true)} disabled={seatOf.size === 0}>
              Tümünü temizle
            </Button>
          </div>
        )}
        {selectedDesk && !readOnly && (
          <p className="rounded-[4px] bg-accent-soft px-2 py-1.5 text-[11.5px] text-accent">Masa {selectedDesk.no ?? ''} seçili — listeden bir öğrenciye tıklayınca bu masaya oturur.</p>
        )}
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto scroll-thin">
        {list.length === 0 && <p className="p-6 text-center text-[13px] text-ink-3">Eşleşen öğrenci yok.</p>}
        <ul className="divide-y divide-line">
          {list.map((s) => {
            const at = seatOf.get(s.uuid)
            return (
              <li key={s.uuid}>
                <div
                  role="button"
                  tabIndex={0}
                  onPointerDown={(e) => startDrag(s, e)}
                  onKeyDown={(e) => e.key === 'Enter' && onTap(s)}
                  className={cn('flex touch-none select-none items-center gap-2 px-3 py-2 hover:bg-surface-2', !readOnly && 'cursor-grab active:cursor-grabbing')}
                  data-testid={`student-${s.uuid}`}
                >
                  {!readOnly && <GripVertical className="size-3.5 shrink-0 text-ink-3" />}
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-[13px] font-medium text-ink">{s.name}</div>
                    <div className="truncate text-[11px] text-ink-3">{s.student_no}</div>
                  </div>
                  {at ? (
                    <span className="inline-flex items-center gap-1">
                      <Badge tone="accent">Masa {at.no ?? '–'}</Badge>
                      {!readOnly && (
                        <button type="button" aria-label={`${s.name} masadan kaldır`} className="grid size-6 place-items-center rounded text-ink-3 hover:bg-surface-3 hover:text-danger" onPointerDown={(e) => e.stopPropagation()} onClick={() => removeFromSeat(at.id, at.seat)}>
                          <X className="size-3.5" />
                        </button>
                      )}
                    </span>
                  ) : (
                    <span className="text-[11px] text-ink-3">yerleşmedi</span>
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      </div>
      {!readOnly && <AutoSeatDialog open={auto} onClose={() => setAuto(false)} students={students} capacity={capacity} seated={seatedCount} hasGender={hasGender} />}
      <ConfirmDialog
        open={clearAll}
        onClose={() => setClearAll(false)}
        onConfirm={() => {
          const d = useClassroom.getState().doc
          const next = { ...d.objects }
          for (const id of d.order) {
            const o = next[id]!
            if (o.seats?.some(Boolean)) next[id] = { ...o, seats: o.seats.map(() => null) }
          }
          useClassroom.getState().replaceObjects(next, d.order)
          setClearAll(false)
          toast.success('Tüm atamalar kaldırıldı. Kaydedince kalıcı olur; geri almak için Ctrl+Z.')
        }}
        title="Tüm atamalar kaldırılsın mı?"
        description={`${seatOf.size} öğrenci masasından kaldırılacak. Masalar yerinde kalır.`}
        confirmLabel="Tümünü temizle"
        danger
      />
    </div>
  )
}

function AutoSeatDialog({ open, onClose, students, capacity, seated, hasGender }: { open: boolean; onClose: () => void; students: SeatingStudent[]; capacity: number; seated: number; hasGender: boolean }) {
  const [mode, setMode] = useState<AutoMode>('alpha')
  const [keep, setKeep] = useState(false)
  const [seed, setSeed] = useState(() => Date.now())
  const objects = useClassroom((s) => s.doc.objects)
  const order = useClassroom((s) => s.doc.order)
  const modes = (Object.keys(AUTO_MODE_LABELS) as AutoMode[]).filter((m) => m !== 'gender' || hasGender)

  const preview = useMemo(() => {
    if (!open) return null
    const r = autoAssign(objects, order, students.map((s) => ({ uuid: s.uuid, name: s.name, first_name: s.first_name, last_name: s.last_name, student_no: s.student_no, gender: s.gender ?? null })), { mode, keepExisting: keep, seed })
    const rows = order
      .map((id) => r.objects[id]!)
      .filter((o): o is SceneObject => !!o && isDesk(o))
      .sort((a, b) => (a.no ?? 1e9) - (b.no ?? 1e9))
      .map((o) => ({ no: o.no, names: (o.seats ?? []).filter(Boolean).map((s) => s!.name).join(' · '), status: o.status }))
    return { ...r, rows }
  }, [open, objects, order, students, mode, keep, seed])

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title="Otomatik yerleştir"
      description="Masalar numara sırasıyla doldurulur; rezerve ve kullanılamaz masalar atlanır. Önizlemeyi kontrol edip uygulayın."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button
            variant="primary"
            data-testid="auto-apply"
            onClick={() => {
              if (!preview) return
              const d = useClassroom.getState().doc
              useClassroom.getState().replaceObjects(preview.objects, d.order)
              onClose()
              toast.success(`${preview.placed} öğrenci yerleştirildi${preview.left ? `; ${preview.left} öğrenci için boş masa kalmadı` : ''}. Kaydetmeyi unutmayın.`)
            }}
          >
            Uygula
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-[230px_1fr]">
        <div className="space-y-4">
          <fieldset className="space-y-2">
            <legend className="mb-1 text-[12.5px] font-medium text-ink-2">Sıralama</legend>
            {modes.map((m) => (
              <label key={m} className="flex cursor-pointer items-start gap-2 text-[13px]">
                <input type="radio" name="auto-mode" checked={mode === m} onChange={() => setMode(m)} className="mt-0.5 accent-[var(--primary)]" />
                {AUTO_MODE_LABELS[m]}
              </label>
            ))}
          </fieldset>
          {mode === 'random' && <Button size="xs" variant="outline" icon={<Shuffle className="size-3.5" />} onClick={() => setSeed(Date.now())}>Yeniden karıştır</Button>}
          <Checkbox checked={keep} onChange={setKeep} label="Mevcut yerleşimi koru" />
          <div className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 text-[12.5px] text-ink-2">
            Yerleşecek: <b className="tabular-nums">{keep ? students.length - seated : students.length}</b> · kullanılabilir oturak: <b className="tabular-nums">{capacity}</b>
            {preview && preview.left > 0 && <div className="mt-1 text-warning">{preview.left} öğrenci için yer yetmeyecek.</div>}
          </div>
        </div>
        <div className="max-h-[46dvh] overflow-y-auto scroll-thin rounded-[var(--radius-sm)] border border-line" data-testid="auto-preview">
          <table className="w-full text-[12.5px]">
            <thead className="sticky top-0 bg-surface-2 text-ink-3"><tr><th className="px-2 py-1.5 text-left font-medium">Masa</th><th className="px-2 py-1.5 text-left font-medium">Öğrenci (önizleme)</th></tr></thead>
            <tbody>
              {preview?.rows.map((r, i) => (
                <tr key={i} className="border-t border-line">
                  <td className="px-2 py-1 tabular-nums">{r.no ?? '–'}</td>
                  <td className="px-2 py-1">{r.status === 'unavailable' ? <span className="text-danger">kullanılamaz</span> : r.names || (r.status === 'reserved' ? <span className="text-warning">rezerve</span> : <span className="text-ink-3">boş</span>)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </Modal>
  )
}
