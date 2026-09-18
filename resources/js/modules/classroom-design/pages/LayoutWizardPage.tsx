import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { ArrowLeft, ArrowRight, Check } from 'lucide-react'
import { useCan } from '@/app/auth'
import { ApiError } from '@/lib/api'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Alert, EmptyState } from '@/components/ui/feedback'
import { Checkbox, Field, Input, Segmented, Select } from '@/components/ui/form'
import { PageHeader } from '@/components/ui/layout'
import { useClassroomOptions, useRoster, useSaveLayout } from '../api'
import { useClassroom } from '../state/classroomStore'
import { useSelection } from '../state/selectionStore'
import type { LayoutData, SceneObject } from '../types'
import { PlanView } from '../components/PlanView/PlanView'
import { RoomPanel } from '../components/PropertiesPanel/RoomPanel'
import { NumberField } from '../components/PropertiesPanel/NumberField'
import { ThreeScene } from '../three/ThreeScene'
import { makeObject } from '../state/actions'
import { DEFAULT_GENERATOR, generateLayout } from '../utils/layoutGenerator'
import { edgeAt, templatePolygon, validatePolygon } from '../utils/polygon'
import { snapToWall, placeObject } from '../utils/snap'
import { checkObject, openingZones } from '../utils/collision'
import { computeStats } from '../utils/seating'
import { dataFromDoc, newId } from '../utils/doc'
import { formatArea } from '../utils/measurement'
import { cn } from '@/lib/cn'

/**
 * DERSLİK OLUŞTURMA SİHİRBAZI — 1) ad, derslik, kat · 2) çokgen zemin (şablon + köşe sürükle/ekle/sil),
 * tavan, duvar kalınlığı, kapı ve pencereler (duvarlarda gerçek boşluk) · 3) başlangıç donatımı (tahta,
 * öğretmen masası, akıllı yerleşimle masalar). Oluşturunca düzenleyici açılır.
 */

const STEPS = ['Bilgiler', 'Oda ve duvarlar', 'Başlangıç donatımı']

function initialData(): LayoutData {
  const polygon = templatePolygon('rect', { width: 7.2, depth: 5.8, cutW: 2.4, cutD: 1.8 })
  return {
    schema: 1,
    room: { polygon, ceiling: 3, wallThickness: 0.2 },
    openings: [
      { id: newId('d'), kind: 'door', wall: 1, offset: 4.9, width: 0.9, height: 2.1, sill: 0, swing: 'in-left' },
      { id: newId('w'), kind: 'window', wall: 3, offset: 1.6, width: 1.2, height: 1.4, sill: 0.9 },
      { id: newId('w'), kind: 'window', wall: 3, offset: 4.0, width: 1.2, height: 1.4, sill: 0.9 },
    ],
    objects: [],
    settings: { grid: 0.1, snap: true, labels: true },
  }
}

export default function LayoutWizardPage() {
  const can = useCan()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const presetClassroom = params.get('derslik') ? Number(params.get('derslik')) : null
  const classrooms = useClassroomOptions()
  const save = useSaveLayout()
  const [step, setStep] = useState(0)
  const [name, setName] = useState('')
  const [classroomId, setClassroomId] = useState<number | null>(presetClassroom)
  const [floor, setFloor] = useState('')
  const [preview, setPreview] = useState<'2d' | '3d'>('2d')
  const [board, setBoard] = useState(true)
  const [teacher, setTeacher] = useState(true)
  const [desks, setDesks] = useState(true)
  const [deskType, setDeskType] = useState<'desk-single' | 'desk-double'>('desk-single')
  const roster = useRoster(classroomId, null)
  const [students, setStudents] = useState<number | null>(null)
  const polygon = useClassroom((s) => s.doc.room.polygon)
  const problems = validatePolygon(polygon)
  const cls = classrooms.data?.find((c) => c.id === classroomId)

  useEffect(() => {
    useClassroom.getState().load(
      { layoutId: null, name: '', classroomId: null, classroomName: null, classroomCapacity: null, version: 0, isDemo: false, hasThumbnail: false },
      initialData(),
    )
    const s = useSelection.getState()
    s.clear()
    s.clearMeasurements()
    s.setTool('select')
  }, [])

  useEffect(() => {
    if (!cls) return
    setName((n) => n || `${cls.name} düzeni`)
    setFloor((f) => f || cls.floor || '')
  }, [cls])

  useEffect(() => {
    useSelection.getState().setTool(step === 1 ? 'room' : 'select')
  }, [step])

  const studentCount = students ?? roster.data?.students.length ?? cls?.capacity ?? 20

  const summary = useMemo(() => {
    const d = useClassroom.getState().doc
    return { area: computeStats(d).area }
  }, [polygon]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!can('classroom_layouts.manage')) {
    return <EmptyState title="Yetki gerekli" description="Derslik tasarımı oluşturmak için 'Derslik tasarımı ve oturma düzeni düzenleme' yetkisi gerekir." action={<ButtonLink to="/derslik-tasarimi">Listeye dön</ButtonLink>} />
  }

  const create = () => {
    const cs = useClassroom.getState()
    const room = { ...cs.doc.room, floor: floor || undefined }
    const objects: SceneObject[] = []
    const world = () => ({ room, zones: openingZones(room, cs.doc.openings), objects })
    const e0 = edgeAt(room.polygon, 0)
    if (board) {
      const b = snapToWall(makeObject('smartboard'), room, { x: e0.a.x + e0.dir.x * (e0.len / 2), z: e0.a.z + e0.dir.z * (e0.len / 2) })
      if (checkObject(b, world()).ok) objects.push(b)
    }
    if (teacher) {
      // tahtanın yanında, öğrencilere dönük
      const inward = { x: -e0.n.x, z: -e0.n.z }
      const along = Math.min(e0.len - 1.1, e0.len / 2 + 1.9)
      const t0 = makeObject('teacher-desk')
      t0.rot = Math.atan2(e0.n.x, e0.n.z)
      const target = { x: e0.a.x + e0.dir.x * along + inward.x * 1.0, z: e0.a.z + e0.dir.z * along + inward.z * 1.0 }
      const t = placeObject(t0, room, target, { grid: 0.05, snap: true })
      if (checkObject(t, world()).ok) objects.push(t)
    }
    if (desks) {
      const g = generateLayout(room, cs.doc.openings, objects, { ...DEFAULT_GENERATOR, deskType, students: studentCount, boardWall: 0 })
      g.objects.forEach((o) => objects.push({ ...o, id: newId('o') }))
    }
    const doc = { ...cs.doc, room, objects: Object.fromEntries(objects.map((o) => [o.id, o])), order: objects.map((o) => o.id) }
    useClassroom.getState().commit(doc)
    // küçük görsel: düzenleyici ilk açılışta 3D görünümden üretir (nesneler henüz sahnede çizilmedi)
    save.mutate(
      { id: null, name: name.trim(), classroom_id: classroomId, data: dataFromDoc(doc, null, cs.settings), stats: computeStats(doc), thumbnail: null, label: 'İlk sürüm' },
      {
        onSuccess: (r) => {
          useClassroom.setState({ savedDoc: useClassroom.getState().doc })
          toast.success('Derslik tasarımı oluşturuldu.')
          navigate(`/derslik-tasarimi/${r.id}`, { replace: true })
        },
        onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Oluşturulamadı.'),
      },
    )
  }

  const canNext = step === 0 ? name.trim().length >= 2 : step === 1 ? problems.length === 0 : true

  return (
    <div>
      <PageHeader
        title="Yeni derslik tasarımı"
        breadcrumbs={[{ label: 'Derslik tasarımı', to: '/derslik-tasarimi' }, { label: 'Yeni' }]}
        description="Oda planını çizin; kapı ve pencereleri duvarlara yerleştirin. Sonra 3D düzenleyicide masaları kurarsınız."
      />
      <ol className="mb-5 flex flex-wrap items-center gap-2 text-[12.5px]">
        {STEPS.map((s, i) => (
          <li key={s} className="flex items-center gap-2">
            <span className={cn('grid size-6 place-items-center rounded-full text-[11.5px] font-semibold', i < step ? 'bg-success text-white' : i === step ? 'bg-primary text-white' : 'bg-surface-3 text-ink-3')}>
              {i < step ? <Check className="size-3.5" /> : i + 1}
            </span>
            <span className={cn(i === step ? 'font-semibold text-ink' : 'text-ink-3')}>{s}</span>
            {i < STEPS.length - 1 && <span className="mx-1 h-px w-8 bg-line" />}
          </li>
        ))}
      </ol>

      {step === 0 && (
        <div className="max-w-xl space-y-4 rounded-[var(--radius-lg)] bg-surface p-5 ring-1 ring-line">
          <Field label="Derslik (fiziksel oda)" optional hint="Öğrenci ataması bu dersliği kullanan sınıflardan yapılır. Bağlamadan da tasarlayabilirsiniz.">
            <Select value={classroomId ?? ''} placeholder="Dersliğe bağlama" onChange={(e) => setClassroomId(e.target.value ? Number(e.target.value) : null)}
              options={(classrooms.data ?? []).map((c) => ({ value: c.id, label: `${c.name}${c.floor ? ` · ${c.floor}` : ''} · ${c.capacity} kişi` }))} />
          </Field>
          <Field label="Tasarım adı" required htmlFor="wz-name">
            <Input id="wz-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="ör. TYT-A Dersliği" data-testid="wz-name" />
          </Field>
          <Field label="Kat" optional htmlFor="wz-floor">
            <Input id="wz-floor" value={floor} onChange={(e) => setFloor(e.target.value)} placeholder="ör. 1. Kat" />
          </Field>
        </div>
      )}

      {step === 1 && (
        <div className="grid gap-4 lg:grid-cols-[360px_1fr]">
          <div className="max-h-[calc(100dvh-260px)] overflow-y-auto scroll-thin rounded-[var(--radius-lg)] bg-surface ring-1 ring-line max-lg:max-h-none">
            <RoomPanel />
          </div>
          <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-2">
              <Segmented value={preview} onChange={setPreview} options={[{ value: '2d', label: '2D plan (köşe düzenle)' }, { value: '3d', label: '3D önizleme' }]} />
              <span className="text-[12.5px] text-ink-3 tabular-nums">Alan {formatArea(summary.area)}</span>
            </div>
            <div className="relative h-[calc(100dvh-300px)] min-h-[420px] overflow-hidden rounded-[var(--radius-lg)] ring-1 ring-line">
              <div className={cn('absolute inset-0', preview !== '3d' && 'invisible')}>
                <ThreeScene readOnly className="absolute inset-0" />
              </div>
              {preview === '2d' && <PlanView className="absolute inset-0" roomOnly />}
            </div>
            {problems.length > 0 && <Alert tone="danger">{problems.map((p) => p.message).join(' ')}</Alert>}
          </div>
        </div>
      )}

      {step === 2 && (
        <div className="grid gap-4 lg:grid-cols-[360px_1fr]">
          <div className="space-y-4 rounded-[var(--radius-lg)] bg-surface p-4 ring-1 ring-line">
            <Checkbox checked={board} onChange={setBoard} label="Akıllı tahta (duvar 1 ortasına)" />
            <Checkbox checked={teacher} onChange={setTeacher} label="Öğretmen masası (tahtanın yanına)" />
            <Checkbox checked={desks} onChange={setDesks} label="Öğrenci masalarını akıllı yerleşimle diz" />
            {desks && (
              <div className="space-y-3 border-t border-line pt-3">
                <Segmented value={deskType} onChange={setDeskType} options={[{ value: 'desk-single', label: 'Tek kişilik' }, { value: 'desk-double', label: 'Çift kişilik' }]} />
                <NumberField label={roster.data?.students.length ? `Öğrenci sayısı (sınıfta ${roster.data.students.length})` : 'Öğrenci sayısı'} value={studentCount} min={1} max={300} onCommit={(v) => setStudents(Math.round(v))} />
              </div>
            )}
            <p className="text-[12px] text-ink-3">Hepsi oluşturduktan sonra düzenleyicide taşınabilir, silinebilir.</p>
          </div>
          <div className="relative h-[calc(100dvh-300px)] min-h-[420px] overflow-hidden rounded-[var(--radius-lg)] ring-1 ring-line">
            <ThreeScene readOnly className="absolute inset-0" />
          </div>
        </div>
      )}

      <div className="mt-5 flex items-center justify-between gap-2">
        <Button variant="ghost" icon={<ArrowLeft className="size-4" />} onClick={() => (step === 0 ? navigate('/derslik-tasarimi') : setStep(step - 1))}>
          {step === 0 ? 'Vazgeç' : 'Geri'}
        </Button>
        {step < STEPS.length - 1 ? (
          <Button variant="primary" iconRight={<ArrowRight className="size-4" />} disabled={!canNext} onClick={() => setStep(step + 1)} data-testid="wz-next">
            Devam
          </Button>
        ) : (
          <Button variant="primary" icon={<Check className="size-4" />} loading={save.isPending} disabled={problems.length > 0} onClick={create} data-testid="wz-create">
            Oluştur ve düzenleyiciyi aç
          </Button>
        )}
      </div>
    </div>
  )
}
