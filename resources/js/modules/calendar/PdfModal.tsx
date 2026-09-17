import { useState } from 'react'
import { FileDown } from 'lucide-react'
import { Modal } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'
import type { FeedChoices } from './FeedModal'
import { weekStartOf } from './types'

type View = keyof FeedChoices
const LABEL: Record<View, string> = { class_group: 'Sınıf', teacher: 'Öğretmen', classroom: 'Derslik' }

/** Haftalık ders programı PDF'i: sınıf, öğretmen ya da derslik için seçilen haftanın çizelgesi (yeni sekmede açılır). */
export function PdfModal({ initial, date, choices, onClose }: { initial: { view: View; id: number } | null; date: string; choices: FeedChoices; onClose: () => void }) {
  const [view, setView] = useState<View>(initial?.view ?? 'class_group')
  const [id, setId] = useState<number>(initial?.id ?? 0)
  const [week, setWeek] = useState(date)
  const list = choices[view]
  const open = () => window.open(`/api/v1/schedule/pdf?view=${view}&id=${id}&date=${weekStartOf(week)}`, '_blank', 'noopener')

  return (
    <Modal open onClose={onClose} title="Haftalık program PDF" description="Seçilen haftanın ders çizelgesi yazdırılabilir PDF olarak yeni sekmede açılır."
      footer={<>
        <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
        <Button variant="primary" icon={<FileDown className="size-4" />} disabled={!id} onClick={open}>PDF aç</Button>
      </>}>
      <div className="flex flex-col gap-3 text-[13px]">
        <Segmented<View> size="sm" className="self-start" value={view} onChange={(v) => { setView(v); setId(0) }}
          options={(Object.keys(LABEL) as View[]).map((v) => ({ value: v, label: LABEL[v] }))} />
        <Field label={LABEL[view]} required>
          <Select value={id || ''} onChange={(e) => setId(Number(e.target.value) || 0)} placeholder={list.length ? 'Seçin' : 'Kayıt yok'} disabled={!list.length}
            options={list.map((x) => ({ value: x.id, label: x.name }))} />
        </Field>
        <Field label="Hafta" hint="Seçilen günün bulunduğu hafta (Pazartesi–Pazar)">
          <Input type="date" value={week} onChange={(e) => e.target.value && setWeek(e.target.value)} />
        </Field>
        {!list.length && <Alert tone="neutral">Önce {LABEL[view].toLocaleLowerCase('tr-TR')} kaydı ve ders programı oluşturun.</Alert>}
      </div>
    </Modal>
  )
}
