import { useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, UserRoundX } from 'lucide-react'
import { api } from '@/lib/api'
import { date as fmtDate, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select } from '@/components/ui/form'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Modal } from '@/components/ui/overlay'
import { useAcademicOptions } from '../hooks'
import { addDays, toMinutes } from '../types'
import { BotTabs } from './BotTabs'
import { CancelForm, MoveForm, SubstituteList } from './SessionActions'
import type { LeaveImpact } from './types'

type Row = LeaveImpact['sessions'][number]
type Mode = 'substitute' | 'move' | 'cancel'

/** Yedek öğretmen botu: izinli öğretmenlerin etkilenen dersleri → puanlı yedek önerisi, taşıma ya da iptal. */
export default function SubstitutePage() {
  const can = useCan()
  const manage = can('schedule.manage')
  const qc = useQueryClient()
  const options = useAcademicOptions()
  const [from, setFrom] = useState(todayISO())
  const [to, setTo] = useState(addDays(todayISO(), 14))
  const [teacherId, setTeacherId] = useState('')
  const [active, setActive] = useState<{ row: Row; mode: Mode } | null>(null)

  const q = useQuery({
    queryKey: ['timetable', 'leave-impact', from, to, teacherId],
    queryFn: () => api.get<LeaveImpact>('/timetable/leave-impact', { from, to, teacher_id: teacherId || undefined }),
  })

  const byDate = useMemo(() => {
    const m = new Map<string, Row[]>()
    q.data?.sessions.forEach((s) => m.set(s.date, [...(m.get(s.date) ?? []), s]))
    return [...m.entries()]
  }, [q.data])

  const done = () => {
    setActive(null)
    qc.invalidateQueries({ queryKey: ['timetable'] })
    qc.invalidateQueries({ queryKey: ['schedule'] })
    qc.invalidateQueries({ queryKey: ['calendar'] })
  }

  return (
    <div className="animate-fade-in">
      <PageHeader title="Yedek öğretmen" description="İzinli öğretmenlerin önümüzdeki dersleri. Her ders için o saatte boş, branşı uyan ve günlük yükü düşük öğretmenler puanlanır; tek tıkla atayın, taşıyın ya da iptal edin." />
      <BotTabs active="substitute" />

      <div className="mb-4 flex flex-wrap items-end gap-3 rounded-[var(--radius-lg)] bg-surface p-3 ring-1 ring-line">
        <Field label="Başlangıç" className="w-[160px]"><Input type="date" value={from} onChange={(e) => e.target.value && setFrom(e.target.value)} /></Field>
        <Field label="Bitiş" className="w-[160px]"><Input type="date" value={to} min={from} onChange={(e) => e.target.value && setTo(e.target.value)} /></Field>
        <Field label="Öğretmen" hint="Seçerseniz izin kaydı olmasa da dersleri listelenir" className="w-full sm:w-[260px]">
          <Select value={teacherId} onChange={(e) => setTeacherId(e.target.value)} placeholder="İzinli tüm öğretmenler" options={(options.data?.teachers ?? []).filter((t) => t.is_active).map((t) => ({ value: t.id, label: t.name }))} />
        </Field>
      </div>

      {q.data && q.data.leaves.length > 0 && (
        <div className="mb-4 flex flex-wrap gap-2">
          {q.data.leaves.map((l) => (
            <span key={l.id} className="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-[12.5px]">
              <UserRoundX className="size-3.5 text-ink-3" /><span className="font-medium">{l.teacher}</span><span className="tabular text-ink-3">{fmtDate(l.starts_on)}{l.ends_on !== l.starts_on ? ` – ${fmtDate(l.ends_on)}` : ''}</span>
            </span>
          ))}
        </div>
      )}

      {q.isLoading ? (
        <div className="flex flex-col gap-2">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-14" />)}</div>
      ) : byDate.length === 0 ? (
        <Panel><EmptyState icon={<CalendarClock />} title={teacherId ? 'Bu aralıkta dersi yok' : 'Etkilenen ders yok'} description={teacherId ? 'Seçili öğretmenin bu tarihlerde iptal edilmemiş dersi bulunmuyor.' : 'Seçili tarihlerde onaylı izni olan öğretmenin dersi yok. İzinler öğretmen sayfasından girilir.'} /></Panel>
      ) : (
        <div className="flex flex-col gap-4">
          {byDate.map(([day, rows]) => (
            <Panel key={day} title={fmtDate(day, 'day')} flush>
              <ul className="divide-y divide-line border-t border-line">
                {rows.map((r) => (
                  <li key={r.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
                    <div className="w-[96px] shrink-0 tabular"><p className="text-[13.5px] font-semibold">{r.starts_at}</p><p className="text-[12px] text-ink-3">{r.ends_at}</p></div>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-[13.5px] font-medium">{r.subject} <span className="font-normal text-ink-3">· {r.class_group}</span></p>
                      <p className="flex flex-wrap items-center gap-2 text-[12.5px] text-ink-3">{r.teacher} · {r.classroom}{r.on_leave && <Badge tone="warning">İzinli</Badge>}</p>
                    </div>
                    {manage && !r.attendance_taken && (
                      <div className="flex flex-wrap gap-1.5">
                        <Button size="sm" variant="primary" onClick={() => setActive({ row: r, mode: 'substitute' })}>Yedek öner</Button>
                        <Button size="sm" onClick={() => setActive({ row: r, mode: 'move' })}>Taşı</Button>
                        <Button size="sm" variant="danger-soft" onClick={() => setActive({ row: r, mode: 'cancel' })}>İptal</Button>
                      </div>
                    )}
                    {!manage && <Button size="sm" onClick={() => setActive({ row: r, mode: 'substitute' })}>Önerileri gör</Button>}
                  </li>
                ))}
              </ul>
            </Panel>
          ))}
        </div>
      )}

      {active && (
        <Modal
          open
          size="lg"
          onClose={() => setActive(null)}
          title={`${active.row.subject} · ${active.row.class_group}`}
          description={`${fmtDate(active.row.date, 'day')} ${active.row.starts_at}–${active.row.ends_at} · ${active.row.teacher}`}
        >
          {manage && (
            <Segmented<Mode>
              className={cn('mb-4')}
              value={active.mode}
              onChange={(mode) => setActive({ ...active, mode })}
              options={[{ value: 'substitute', label: 'Yedek öğretmen' }, { value: 'move', label: 'Taşı / telafi' }, { value: 'cancel', label: 'İptal' }]}
            />
          )}
          {active.mode === 'substitute' && <SubstituteList sessionId={active.row.id} canManage={manage} onDone={done} />}
          {active.mode === 'move' && <MoveForm sessionId={active.row.id} duration={toMinutes(active.row.ends_at) - toMinutes(active.row.starts_at)} options={options.data} onDone={done} />}
          {active.mode === 'cancel' && <CancelForm sessionId={active.row.id} onDone={done} onSuggestSubstitute={() => setActive({ ...active, mode: 'substitute' })} />}
        </Modal>
      )}
    </div>
  )
}
