import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { RotateCcw } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date as fmtDate } from '@/lib/format'
import { useCan } from '@/app/auth'
import { Drawer } from '@/components/ui/overlay'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Segmented, Select } from '@/components/ui/form'
import { Alert, Badge } from '@/components/ui/feedback'
import { DescriptionList } from '@/components/ui/layout'
import { toMinutes, type AcademicOptions } from '../academic/types'
import { CancelForm, MoveForm, SubstituteList } from '../academic/timetable/SessionActions'
import type { CalEvent } from './types'

type Mode = 'substitute' | 'move' | 'room' | 'cancel'

/** Takvimde ders oturumu ayrıntısı: yoklama durumu, konu notu; iptal / taşı / yedek ata / derslik değiştir. */
export function SessionDrawer({ event, options, onClose, onChanged }: { event: CalEvent | null; options?: AcademicOptions; onClose: () => void; onChanged: () => void }) {
  const can = useCan()
  const manage = can('schedule.manage')
  const [mode, setMode] = useState<Mode | null>(null)
  const [room, setRoom] = useState('')

  useEffect(() => {
    setMode(null)
    setRoom('')
  }, [event?.key])

  const l = event?.lesson
  const done = () => {
    setMode(null)
    onChanged()
    onClose()
  }
  const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem tamamlanamadı.')
  const restore = useMutation({ mutationFn: () => api.post<{ message: string }>(`/schedule/sessions/${event!.id}/restore`), onSuccess: (r) => { toast.success(r.message); done() }, onError: fail })
  const reassign = useMutation({ mutationFn: () => api.post<{ message: string }>(`/schedule/sessions/${event!.id}/reassign`, { classroom_id: Number(room) }), onSuccess: (r) => { toast.success(`${r.message} Öğrencilere bildirildi.`); done() }, onError: fail })

  if (!event || !l) return null
  const cancelled = event.status === 'cancelled'
  const editable = manage && !cancelled && !l.attendance_taken && event.phase !== 'done'
  const duration = toMinutes(event.ends_at!) - toMinutes(event.starts_at!)

  return (
    <Drawer
      open
      onClose={onClose}
      width={560}
      title={<span className="flex flex-wrap items-center gap-2">{l.subject.name}<span className="font-normal text-ink-3">· {l.class_group.name}</span></span>}
      description={`${fmtDate(event.date, 'day')} · ${event.starts_at}–${event.ends_at}`}
      footer={<><ButtonLink variant="ghost" to={`/ders-programi?tur=class_group&id=${l.class_group.id}&tarih=${event.date}`}>Haftalık programda aç</ButtonLink><Button variant="primary" onClick={onClose}>Kapat</Button></>}
    >
      <div className="flex flex-col gap-5">
        <DescriptionList
          items={[
            { label: 'Öğretmen', value: l.teacher.name },
            { label: 'Derslik', value: l.classroom.name },
            { label: 'Durum', value: cancelled ? <Badge>İptal</Badge> : event.phase === 'in_progress' ? <Badge tone="success" dot>Derste</Badge> : event.phase === 'done' ? <Badge>Bitti</Badge> : <Badge>Yaklaşan</Badge> },
            { label: 'Yoklama', value: l.attendance_taken ? `Alındı · ${l.present_count} var, ${l.absent_count} yok` : 'Alınmadı' },
            { label: 'Tür', value: l.makeup_of_id ? 'Telafi dersi' : l.schedule_id ? 'Haftalık program' : 'Tek seferlik', hidden: !l.makeup_of_id && !!l.schedule_id },
            { label: 'İşlenen konu', value: l.topic_note, hidden: !l.topic_note },
          ]}
        />
        {cancelled && l.cancel_reason && <Alert tone="neutral" title="İptal gerekçesi">{l.cancel_reason}</Alert>}

        {cancelled && manage && !l.holiday_id && event.phase !== 'done' && (
          <Button className="self-start" icon={<RotateCcw className="size-4" />} loading={restore.isPending} onClick={() => restore.mutate()}>İptali geri al</Button>
        )}
        {cancelled && l.holiday_id && <p className="text-[12.5px] text-ink-3">Tatil nedeniyle iptal edildi; tatili “Tatiller” penceresinden düzenleyebilirsiniz.</p>}
        {l.attendance_taken && <p className="text-[12.5px] text-ink-3">Yoklaması alınmış ders taşınamaz ya da iptal edilemez.</p>}

        {editable && (
          <section className="flex flex-col gap-3">
            <Segmented<Mode>
              value={mode ?? 'substitute'}
              onChange={setMode}
              options={[{ value: 'substitute', label: 'Yedek öğretmen' }, { value: 'move', label: 'Taşı' }, { value: 'room', label: 'Derslik' }, { value: 'cancel', label: 'İptal' }]}
            />
            {(mode ?? 'substitute') === 'substitute' && <SubstituteList sessionId={event.id} canManage={manage} onDone={done} limit={6} />}
            {mode === 'move' && <MoveForm sessionId={event.id} duration={duration} options={options} onDone={done} />}
            {mode === 'room' && (
              <div className="flex flex-col gap-3">
                <Field label="Yeni derslik (yalnızca bu ders)" hint="Çakışma denetlenir; öğrencilere derslik değişikliği bildirilir.">
                  <Select value={room} onChange={(e) => setRoom(e.target.value)} placeholder="Seçin" options={(options?.classrooms ?? []).filter((c) => c.is_active && c.id !== l.classroom.id).map((c) => ({ value: c.id, label: `${c.name} · ${c.capacity} kişi` }))} />
                </Field>
                <Button variant="primary" className="self-end" disabled={!room} loading={reassign.isPending} onClick={() => reassign.mutate()}>Dersliği değiştir</Button>
              </div>
            )}
            {mode === 'cancel' && <CancelForm sessionId={event.id} onDone={done} onSuggestSubstitute={() => setMode('substitute')} />}
          </section>
        )}
      </div>
    </Drawer>
  )
}
