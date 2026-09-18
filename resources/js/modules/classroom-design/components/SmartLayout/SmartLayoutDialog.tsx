import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { isDesk } from '../../catalog'
import { applyGenerated } from '../../state/actions'
import { useClassroom } from '../../state/classroomStore'
import { useGhosts } from '../../state/ghostStore'
import { DEFAULT_GENERATOR, detectBoardWall, generateLayout, type GeneratorOptions } from '../../utils/layoutGenerator'
import { edges } from '../../utils/polygon'
import { NumberField } from '../PropertiesPanel/NumberField'
import { wallLabel } from '../PropertiesPanel/RoomPanel'
import { Button } from '@/components/ui/Button'
import { Checkbox, Segmented, Select } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import { Alert } from '@/components/ui/feedback'

/**
 * AKILLI YERLEŞİM — masa tipi, satır/sütun, aralıklar, tahtaya/duvara mesafe, koridor ve öğrenci sayısıyla
 * GERÇEK oda çokgenine (kapı önü, kolon ve diğer engeller dahil) yerleşim üretir. Sonuç planda/3D'de önizlenir,
 * "Uygula" ile (geri alınabilir) eski öğrenci masalarının yerini alır.
 */
export function SmartLayoutDialog({ open, onClose, studentCount }: { open: boolean; onClose: () => void; studentCount: number }) {
  const doc = useClassroom((s) => s.doc)
  const [opt, setOpt] = useState<GeneratorOptions>(() => ({ ...DEFAULT_GENERATOR, students: studentCount, boardWall: 0 }))
  const [preview, setPreview] = useState(true)

  useEffect(() => {
    if (!open) return
    const d = useClassroom.getState().doc
    setOpt((o) => ({ ...o, boardWall: detectBoardWall(d.room, d.order.map((id) => d.objects[id]!)), students: o.students || studentCount }))
  }, [open]) // eslint-disable-line react-hooks/exhaustive-deps

  const result = useMemo(() => {
    if (!open) return null
    return generateLayout(doc.room, doc.openings, doc.order.map((id) => doc.objects[id]!), opt)
  }, [open, doc, opt])

  const existing = doc.order.filter((id) => doc.objects[id] && isDesk(doc.objects[id]!)).length

  // Önizleme: üretilen masalar planda/sahnede yarı saydam (sürükle-bırak önizleme katmanı yerine ayrı katman)
  useEffect(() => {
    useGhosts.getState().set(open && preview && result ? result.objects : [])
    return () => useGhosts.getState().set([])
  }, [open, preview, result])

  const set = (patch: Partial<GeneratorOptions>) => setOpt((o) => ({ ...o, ...patch }))
  const cm = (label: string, key: keyof GeneratorOptions, min: number, max: number) => (
    <NumberField label={label} value={opt[key] as number} scale={100} unit="cm" step={5} min={min} max={max} onCommit={(v) => set({ [key]: v } as Partial<GeneratorOptions>)} />
  )

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={400}
      title="Akıllı yerleşim"
      description="Oda biçimi, kapı önü ve engeller hesaba katılarak masa düzeni üretilir."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button
            variant="primary"
            disabled={!result?.placed}
            onClick={() => {
              if (!result) return
              const r = applyGenerated(result.objects, true)
              onClose()
              void r
              toast.success(`${result.placed} masa yerleştirildi. Geri almak için Ctrl+Z.`)
            }}
            data-testid="smart-apply"
          >
            Uygula ({result?.placed ?? 0} masa)
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div>
          <div className="mb-1 text-[11.5px] font-medium text-ink-3">Masa tipi</div>
          <Segmented value={opt.deskType} onChange={(v) => set({ deskType: v })} options={[{ value: 'desk-single', label: 'Tek kişilik' }, { value: 'desk-double', label: 'Çift kişilik' }]} />
        </div>
        <label className="flex flex-col gap-1">
          <span className="text-[11.5px] font-medium text-ink-3">Tahta duvarı (öğrenciler bu duvara bakar)</span>
          <Select value={opt.boardWall} onChange={(e) => set({ boardWall: Number(e.target.value) })} options={edges(doc.room.polygon).map((e) => ({ value: e.i, label: wallLabel(e.i, e.len) }))} />
        </label>
        <div className="grid grid-cols-2 gap-2">
          <NumberField label="Öğrenci sayısı" value={opt.students} min={0} max={400} onCommit={(v) => set({ students: Math.round(v) })} testId="smart-students" />
          <div />
          <NumberField label="Satır (0 = otomatik)" value={opt.rows} min={0} max={40} onCommit={(v) => set({ rows: Math.round(v) })} />
          <NumberField label="Sütun (0 = otomatik)" value={opt.cols} min={0} max={30} onCommit={(v) => set({ cols: Math.round(v) })} />
          {cm('Yatay aralık', 'gapX', 0, 3)}
          {cm('Sıra arası', 'gapZ', 0, 3)}
          {cm('Tahtaya en az', 'boardClearance', 0.5, 6)}
          {cm('Duvara en az', 'wallClearance', 0, 3)}
          {cm('Orta koridor', 'aisle', 0, 4)}
        </div>
        <Checkbox checked={preview} onChange={setPreview} label="Önizlemeyi sahnede göster" />
        {result && (
          <div className="space-y-2 rounded-[var(--radius-sm)] bg-surface-2 p-3 text-[13px] text-ink-2">
            <div className="flex justify-between"><span>Üretilen masa</span><b className="tabular-nums text-ink">{result.placed}</b></div>
            <div className="flex justify-between"><span>Oturak kapasitesi</span><b className="tabular-nums text-ink">{result.capacity}</b></div>
            <div className="flex justify-between"><span>Izgara</span><span className="tabular-nums">{result.rows} satır × {result.cols} sütun</span></div>
            {result.skipped > 0 && <div className="flex justify-between"><span>Engel/oda sınırı nedeniyle atlanan</span><span className="tabular-nums">{result.skipped} hücre</span></div>}
            {existing > 0 && <p className="text-[12px] text-ink-3">Mevcut {existing} öğrenci masası kaldırılıp yenileri konacak; diğer nesneler yerinde kalır. Sınıf oturma planlarında kaldırılan masalardaki öğrenciler "yerleşmemiş" olarak kalır.</p>}
          </div>
        )}
        {result && result.shortage > 0 && <Alert tone="warning">{result.shortage} öğrenci için yer yok. Aralıkları küçültün, çift kişilik masa seçin ya da mesafeleri azaltın.</Alert>}
        {result && result.placed === 0 && <Alert tone="danger">Bu ayarlarla odaya hiç masa sığmıyor.</Alert>}
      </div>
    </Drawer>
  )
}

