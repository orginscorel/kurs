import { useMemo } from 'react'
import { toast } from 'sonner'
import { Copy, Lock, LockOpen, MousePointer2, RotateCw, Trash2 } from 'lucide-react'
import { catalogOf, isDesk, mountOf, seatCount } from '../../catalog'
import { useSeatingContext } from '../../state/seatingContext'
import { assignToSeat, deleteSelected, describeCollision, duplicateSelected, removeFromSeat, rotateSelected, toggleLockSelected } from '../../state/actions'
import { collisionWorld, useClassroom } from '../../state/classroomStore'
import { useSelection } from '../../state/selectionStore'
import type { SceneObject, SeatStatus } from '../../types'
import { checkObject } from '../../utils/collision'
import { degrees, radians } from '../../utils/measurement'
import { normalizeAngle, placeObject, round3 } from '../../utils/snap'
import { Button } from '@/components/ui/Button'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Segmented, Select, Switch } from '@/components/ui/form'
import { NumberField } from './NumberField'
import { OpeningEditor } from './RoomPanel'

/**
 * ÖZELLİKLER — seçili nesne(ler): konum, açı, boyut, yükseklik, sandalye, masa no, durum, oturaklara öğrenci,
 * kilit; toplu işlemler. Konum/açı/boyut değişikliği çakışıyorsa uygulanmaz (neden gösterilir).
 */

export const STATUS_OPTIONS: { value: SeatStatus; label: string }[] = [
  { value: 'normal', label: 'Kullanımda' },
  { value: 'reserved', label: 'Rezerve' },
  { value: 'unavailable', label: 'Kullanılamaz' },
]

function tryPatch(o: SceneObject, patch: Partial<SceneObject>) {
  const cs = useClassroom.getState()
  let next = { ...o, ...patch }
  // duvar nesnesi: konum değişince duvara yeniden dayanır
  if (mountOf(o) === 'wall' && ('x' in patch || 'z' in patch || 'w' in patch)) next = placeObject(next, cs.doc.room, { x: next.x, z: next.z }, { grid: 0, snap: false })
  const r = checkObject(next, collisionWorld())
  if (!r.ok) {
    toast.error(`Uygulanamadı: ${describeCollision(r)}`)
    return
  }
  cs.patchObjects({ [o.id]: { ...patch, x: next.x, z: next.z, rot: next.rot } })
}

export function PropertiesPanel({ readOnly }: { readOnly?: boolean }) {
  const selected = useSelection((s) => s.selected)
  const openingId = useSelection((s) => s.openingId)
  const objects = useClassroom((s) => s.doc.objects)
  const room = useClassroom((s) => s.doc.room)
  const objs = selected.map((id) => objects[id]!).filter(Boolean)

  if (openingId) return <div className="p-3"><OpeningEditor id={openingId} readOnly={readOnly} /></div>
  if (!objs.length) {
    return <EmptyState compact icon={<MousePointer2 />} title="Seçim yok" description="Sahnede ya da planda bir nesneye tıklayın. Shift ile çoklu seçim, boş alanda sürükleyerek seçim kutusu." />
  }
  if (objs.length > 1) return <MultiProps objs={objs} readOnly={readOnly} />
  const o = objs[0]!
  const c = catalogOf(o.type)
  const mount = mountOf(o)
  const invalid = checkObject(o, collisionWorld())

  return (
    <div className="space-y-4 p-3">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 className="truncate text-[14px] font-semibold text-ink">
            {c?.label ?? 'Nesne'}
            {isDesk(o) && o.no ? ` ${o.no}` : ''}
          </h3>
          <p className="text-[11.5px] text-ink-3">{c?.hint}</p>
        </div>
        {o.locked && <Badge tone="warning">Kilitli</Badge>}
      </div>
      {!invalid.ok && (
        <p className="rounded-[4px] border-l-[3px] border-danger bg-danger-soft px-2 py-1.5 text-[12px] text-danger">Uyarı: {describeCollision(invalid)}</p>
      )}

      <div className="grid grid-cols-2 gap-2">
        <NumberField label="X (sol → sağ)" value={o.x} scale={100} unit="cm" step={1} disabled={readOnly || o.locked} onCommit={(v) => tryPatch(o, { x: round3(v) })} testId="prop-x" />
        <NumberField label="Z (tahta → arka)" value={o.z} scale={100} unit="cm" step={1} disabled={readOnly || o.locked} onCommit={(v) => tryPatch(o, { z: round3(v) })} testId="prop-z" />
        {mount !== 'wall' && (
          <NumberField label="Açı" value={degrees(o.rot)} unit="°" step={15} disabled={readOnly || o.locked} onCommit={(v) => tryPatch(o, { rot: round3(normalizeAngle(radians(v))) })} testId="prop-rot" />
        )}
        {mount !== 'floor' && (
          <NumberField label={mount === 'ceiling' ? 'Yükseklik (alt)' : 'Yerden yükseklik'} value={o.elev ?? c?.elev ?? 1} scale={100} unit="cm" step={5} min={0} max={room.ceiling - 0.05}
            disabled={readOnly || o.locked} onCommit={(v) => tryPatch(o, { elev: round3(v) })} />
        )}
        {c?.resizable?.w && (
          <NumberField label="Genişlik" value={o.w ?? c.w} scale={100} unit="cm" step={5} min={c.resizable.w[0]} max={c.resizable.w[1]} disabled={readOnly || o.locked} onCommit={(v) => tryPatch(o, { w: round3(v) })} />
        )}
        {c?.resizable?.d && (
          <NumberField label="Derinlik" value={o.d ?? c.d} scale={100} unit="cm" step={5} min={c.resizable.d[0]} max={c.resizable.d[1]} disabled={readOnly || o.locked} onCommit={(v) => tryPatch(o, { d: round3(v) })} />
        )}
      </div>

      {c?.chair && (
        <Switch
          checked={!!o.chair}
          disabled={readOnly || o.locked}
          onChange={(v) => tryPatch(o, { chair: v })}
          label={c.chair.label + ' bağlı'}
        />
      )}

      {isDesk(o) && <DeskSection o={o} readOnly={readOnly} />}

      {!readOnly && (
        <div className="flex flex-wrap gap-1.5 border-t border-line pt-3">
          {mount !== 'wall' && (
            <Button size="sm" variant="secondary" icon={<RotateCw className="size-4" />} onClick={() => rotateSelected(-Math.PI / 2)} disabled={o.locked} title="R">
              90° döndür
            </Button>
          )}
          <Button size="sm" variant="secondary" icon={<Copy className="size-4" />} onClick={duplicateSelected} title="Ctrl+D">
            Çoğalt
          </Button>
          <Button size="sm" variant="secondary" icon={o.locked ? <LockOpen className="size-4" /> : <Lock className="size-4" />} onClick={toggleLockSelected}>
            {o.locked ? 'Kilidi aç' : 'Kilitle'}
          </Button>
          <Button size="sm" variant="danger-soft" icon={<Trash2 className="size-4" />} onClick={deleteSelected} disabled={o.locked} title="Delete">
            Sil
          </Button>
        </div>
      )}
    </div>
  )
}


/**
 * Masa bölümü. Oda düzenleyicisinde (oturma kipi kapalı) yalnız masa numarası; sınıf oturma ekranında
 * (oturma kipi) masa durumu + oturaklara bu sınıfın öğrencileri.
 */
export function DeskSection({ o, readOnly }: { o: SceneObject; readOnly?: boolean }) {
  const seating = useSeatingContext((s) => s.active)
  const students = useSeatingContext((s) => s.students)
  const objects = useClassroom((s) => s.doc.objects)
  const seatedIn = useMemo(() => {
    const m = new Map<string, number | undefined>()
    for (const d of Object.values(objects)) d.seats?.forEach((s) => s && m.set(s.uuid, d.no))
    return m
  }, [objects])
  const n = seatCount(o)
  if (!seating) {
    return (
      <div className="space-y-2 border-t border-line pt-3">
        <div className="grid grid-cols-2 gap-2">
          <NumberField label="Masa no" value={o.no ?? 0} min={0} max={999} disabled={readOnly} onCommit={(v) => useClassroom.getState().patchObjects({ [o.id]: { no: Math.round(v) || undefined } })} />
        </div>
        <p className="text-[11.5px] leading-snug text-ink-3">Öğrenci yerleşimi sınıf bazındadır: Sınıflar › sınıf › Oturma düzeni.</p>
      </div>
    )
  }
  return (
    <div className="space-y-3">
      <div>
        <div className="mb-1 text-[11.5px] font-medium text-ink-3">Masa durumu (bu sınıf için)</div>
        <Segmented
          size="sm"
          value={o.status ?? 'normal'}
          onChange={(v) => {
            if (readOnly) return
            const patch: Partial<SceneObject> = { status: v }
            if (v === 'unavailable' && o.seats?.some(Boolean)) {
              patch.seats = o.seats.map(() => null)
              toast.info('Kullanılamaz masadaki öğrenciler listeye döndü.')
            }
            useClassroom.getState().patchObjects({ [o.id]: patch })
          }}
          options={STATUS_OPTIONS}
        />
      </div>
      <div className="space-y-2">
        <div className="text-[11.5px] font-medium text-ink-3">Oturan öğrenci{n > 1 ? 'ler' : ''}</div>
        {Array.from({ length: n }, (_, i) => {
          const cur = o.seats?.[i] ?? null
          const options = [
            ...(cur && !students.some((s) => s.uuid === cur.uuid) ? [{ value: cur.uuid, label: cur.name }] : []),
            ...students.map((s) => ({
              value: s.uuid,
              label: `${s.name}${seatedIn.has(s.uuid) && s.uuid !== cur?.uuid ? ` · Masa ${seatedIn.get(s.uuid) ?? '–'}` : ''}`,
            })),
          ]
          return (
            <div key={i} className="flex items-center gap-1.5">
              {n > 1 && <span className="w-12 shrink-0 text-[11.5px] text-ink-3">{i === 0 ? 'Sol' : 'Sağ'}</span>}
              <Select
                aria-label={`Öğrenci ata (oturak ${i + 1})`}
                className="min-w-0 flex-1"
                value={cur?.uuid ?? ''}
                disabled={readOnly || o.status === 'unavailable'}
                placeholder={readOnly ? '— Boş —' : '— Öğrenci seç —'}
                options={options}
                onChange={(e) => {
                  const uuid = e.target.value
                  if (!uuid) {
                    if (cur) removeFromSeat(o.id, i)
                    return
                  }
                  const s = students.find((x) => x.uuid === uuid) ?? (cur?.uuid === uuid ? cur : null)
                  if (s) assignToSeat({ uuid: s.uuid, name: s.name }, o.id, i)
                }}
              />
              {cur && !readOnly && (
                <Button size="icon-sm" variant="ghost" aria-label="Masadan kaldır" title="Masadan kaldır" onClick={() => removeFromSeat(o.id, i)}>
                  <Trash2 className="size-3.5" />
                </Button>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

function MultiProps({ objs, readOnly }: { objs: SceneObject[]; readOnly?: boolean }) {
  const counts = new Map<string, number>()
  for (const o of objs) counts.set(catalogOf(o.type)?.label ?? 'Nesne', (counts.get(catalogOf(o.type)?.label ?? 'Nesne') ?? 0) + 1)
  return (
    <div className="space-y-4 p-3">
      <div>
        <h3 className="text-[14px] font-semibold text-ink">{objs.length} nesne seçili</h3>
        <p className="mt-0.5 text-[11.5px] text-ink-3">{[...counts.entries()].map(([k, v]) => `${v} ${k.toLocaleLowerCase('tr')}`).join(', ')}</p>
      </div>
      {!readOnly && (
        <div className="flex flex-wrap gap-1.5 border-t border-line pt-3">
          <Button size="sm" variant="secondary" icon={<RotateCw className="size-4" />} onClick={() => rotateSelected(-Math.PI / 2)}>
            90° döndür
          </Button>
          <Button size="sm" variant="secondary" icon={<Copy className="size-4" />} onClick={duplicateSelected}>
            Çoğalt
          </Button>
          <Button size="sm" variant="secondary" icon={<Lock className="size-4" />} onClick={toggleLockSelected}>
            Kilitle / aç
          </Button>
          <Button size="sm" variant="danger-soft" icon={<Trash2 className="size-4" />} onClick={deleteSelected}>
            Sil
          </Button>
        </div>
      )}
      <p className="text-[11.5px] text-ink-3">Birlikte taşımak için seçili nesnelerden birini sürükleyin; ok tuşları 1 cm, Shift + ok 10 cm.</p>
    </div>
  )
}
