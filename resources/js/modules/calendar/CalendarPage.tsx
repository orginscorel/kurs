import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarOff, CalendarPlus, ChevronLeft, ChevronRight, FileDown, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date as fmtDate, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Input, Segmented, Select } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'
import { useAcademicOptions } from '../academic/hooks'
import { monthCells } from '../academic/schedule/MonthView'
import { addDays } from '../academic/types'
import { AgendaList, MonthGrid, TimeGrid } from './CalendarViews'
import { SessionDrawer } from './SessionDrawer'
import { HolidayDrawer } from './HolidayDrawer'
import { FeedModal, type FeedChoices, type FeedOwner } from './FeedModal'
import { PdfModal } from './PdfModal'
import { ALL_TYPES, TYPE_LABELS, weekStartOf, type CalData, type CalEvent, type CalEventType, type CalView } from './types'

const VIEW_KEYS: Record<string, CalView> = { ay: 'month', hafta: 'week', gun: 'day', ajanda: 'agenda' }
const VIEW_PARAM: Record<CalView, string> = { month: 'ay', week: 'hafta', day: 'gun', agenda: 'ajanda' }

export default function CalendarPage() {
  const can = useCan()
  const qc = useQueryClient()
  const options = useAcademicOptions()
  const [params, setParams] = useSearchParams()
  const [isMobile] = useState(() => typeof window !== 'undefined' && window.matchMedia('(max-width: 640px)').matches)

  const view: CalView = VIEW_KEYS[params.get('gorunum') ?? ''] ?? (isMobile ? 'agenda' : 'week')
  const date = params.get('tarih') || todayISO()
  const classId = Number(params.get('sinif')) || 0
  const teacherId = Number(params.get('ogretmen')) || 0
  const roomId = Number(params.get('derslik')) || 0
  const types = (params.get('tur')?.split(',').filter((t): t is CalEventType => (ALL_TYPES as string[]).includes(t))) ?? ALL_TYPES

  const setP = (patch: Record<string, string | null>) =>
    setParams((p) => {
      Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? p.delete(k) : p.set(k, v)))
      return p
    }, { replace: true })

  const [selected, setSelected] = useState<CalEvent | null>(null)
  const [holidays, setHolidays] = useState(false)
  const [feed, setFeed] = useState(false)
  const [pdf, setPdf] = useState(false)

  const month = date.slice(0, 7)
  const range = useMemo(() => {
    if (view === 'month') {
      const cells = monthCells(month)
      return { from: cells[0]!.date, to: cells[41]!.date }
    }
    if (view === 'week') {
      const s = weekStartOf(date)
      return { from: s, to: addDays(s, 6) }
    }
    if (view === 'day') return { from: date, to: date }
    return { from: date, to: addDays(date, 13) }
  }, [view, date, month])

  const q = useQuery({
    queryKey: ['calendar', 'events', range.from, range.to, types.join(','), classId, teacherId, roomId],
    queryFn: () => api.get<CalData>('/calendar/events', { from: range.from, to: range.to, types, class_group_id: classId || undefined, teacher_id: teacherId || undefined, classroom_id: roomId || undefined }),
    placeholderData: keepPreviousData,
  })

  const shift = (n: number) => setP({ tarih: view === 'month' ? `${addDays(`${month}-15`, n * 30).slice(0, 7)}-01` : addDays(date, view === 'week' ? n * 7 : view === 'agenda' ? n * 14 : n) })
  const label = view === 'month'
    ? new Date(`${month}-01T12:00:00`).toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' })
    : view === 'day' ? fmtDate(date, 'day') : `${fmtDate(range.from)} – ${fmtDate(range.to)}`

  const o = options.data
  const owner: FeedOwner | null = teacherId && o ? { type: 'teacher', id: teacherId, name: o.teachers.find((t) => t.id === teacherId)?.name ?? 'Öğretmen' }
    : classId && o ? { type: 'class_group', id: classId, name: o.class_groups.find((g) => g.id === classId)?.name ?? 'Sınıf' }
      : roomId && o ? { type: 'classroom', id: roomId, name: o.classrooms.find((c) => c.id === roomId)?.name ?? 'Derslik' }
        : o?.my_teacher_id ? { type: 'teacher', id: o.my_teacher_id, name: o.teachers.find((t) => t.id === o.my_teacher_id)?.name ?? 'Programım' } : null
  const pdfTarget = teacherId ? { view: 'teacher' as const, id: teacherId } : classId ? { view: 'class_group' as const, id: classId } : roomId ? { view: 'classroom' as const, id: roomId } : null
  const choices: FeedChoices | undefined = o ? {
    teacher: o.teachers.filter((t) => t.is_active).map((t) => ({ id: t.id, name: t.name })),
    class_group: o.class_groups.filter((g) => g.is_active).map((g) => ({ id: g.id, name: g.name })),
    classroom: o.classrooms.filter((c) => c.is_active).map((c) => ({ id: c.id, name: c.name })),
  } : undefined
  // Kurulum yeni: takvimde gösterilecek sınıf ya da öğretmen yok
  const bare = !!o && !o.class_groups.some((g) => g.is_active) && !o.teachers.some((t) => t.is_active)

  const toggleType = (t: CalEventType) => {
    const next = types.includes(t) ? types.filter((x) => x !== t) : [...types, t]
    setP({ tur: next.length === ALL_TYPES.length || next.length === 0 ? null : next.join(',') })
  }
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['calendar'] })
    qc.invalidateQueries({ queryKey: ['schedule'] })
    qc.invalidateQueries({ queryKey: ['timetable'] })
  }
  const onEvent = (e: CalEvent) => e.type === 'lesson' && setSelected(e)
  const s = q.data?.summary

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Takvim"
        description="Dersler, etüt ve birebirler, sınavlar, öğretmen izinleri ve tatiller tek takvimde."
        actions={
          <>
            <Button icon={<CalendarOff className="size-4" />} onClick={() => setHolidays(true)}>Tatiller</Button>
            <Button icon={<FileDown className="size-4" />} disabled={!choices} onClick={() => setPdf(true)}>Haftalık PDF</Button>
            <Button icon={<CalendarPlus className="size-4" />} disabled={!choices} onClick={() => setFeed(true)}>Takvime ekle</Button>
          </>
        }
      />

      <div className="mb-4 flex flex-col gap-3 rounded-[var(--radius-lg)] bg-surface p-3 ring-1 ring-line">
        <div className="flex flex-wrap items-center gap-2">
          <Button size="icon-sm" variant="ghost" onClick={() => shift(-1)} aria-label="Önceki"><ChevronLeft className="size-4" /></Button>
          <Button size="icon-sm" variant="ghost" onClick={() => shift(1)} aria-label="Sonraki"><ChevronRight className="size-4" /></Button>
          <Button size="sm" variant="ghost" onClick={() => setP({ tarih: null })}>Bugün</Button>
          <p className="text-[13.5px] font-medium tabular">{label}</p>
          <div className="w-[150px] shrink-0"><Input type="date" value={date} onChange={(e) => e.target.value && setP({ tarih: e.target.value })} /></div>
          <Segmented<CalView> size="sm" className="sm:ml-auto" value={view} onChange={(v) => setP({ gorunum: VIEW_PARAM[v] })}
            options={[{ value: 'month', label: 'Ay' }, { value: 'week', label: 'Hafta' }, { value: 'day', label: 'Gün' }, { value: 'agenda', label: 'Ajanda' }]} />
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex flex-wrap gap-1.5">
            {ALL_TYPES.filter((t) => t !== 'study' || can('study.view')).filter((t) => t !== 'exam' || can('exams.view')).filter((t) => t !== 'leave' || can(['teachers.view', 'schedule.manage'])).map((t) => (
              <button key={t} type="button" onClick={() => toggleType(t)} aria-pressed={types.includes(t)}
                className={cn('h-7 rounded-full border px-2.5 text-[12.5px] transition-colors', types.includes(t) ? 'border-line-strong bg-surface-2 font-medium text-ink' : 'border-line text-ink-3 hover:text-ink-2')}>
                {TYPE_LABELS[t]}
              </button>
            ))}
          </div>
          <div className="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
            <Select className="w-full sm:w-[170px]" value={classId || ''} onChange={(e) => setP({ sinif: e.target.value || null })} placeholder="Tüm sınıflar" options={(o?.class_groups ?? []).filter((g) => g.is_active).map((g) => ({ value: g.id, label: g.name }))} />
            <Select className="w-full sm:w-[190px]" value={teacherId || ''} onChange={(e) => setP({ ogretmen: e.target.value || null })} placeholder="Tüm öğretmenler" options={(o?.teachers ?? []).filter((t) => t.is_active).map((t) => ({ value: t.id, label: t.name }))} />
            <Select className="w-full sm:w-[150px]" value={roomId || ''} onChange={(e) => setP({ derslik: e.target.value || null })} placeholder="Tüm derslikler" options={(o?.classrooms ?? []).filter((c) => c.is_active).map((c) => ({ value: c.id, label: c.name }))} />
            {(classId || teacherId || roomId || params.get('tur')) ? <Button size="sm" variant="ghost" icon={<X className="size-3.5" />} onClick={() => setP({ sinif: null, ogretmen: null, derslik: null, tur: null })}>Temizle</Button> : null}
          </div>
        </div>
      </div>

      {bare && (
        <Alert tone="info" className="mb-4" title="Takvimde henüz ders yok">
          Dersler, sınıflar ve öğretmenler tanımlanıp ders programı oluşturulunca burada görünür. Tatilleri şimdiden ekleyebilirsiniz.
          <span className="mt-2 flex flex-wrap gap-3">
            {can('academic.view') && <Link to="/siniflar" className="font-medium text-primary hover:underline">Sınıflar</Link>}
            {can('teachers.view') && <Link to="/ogretmenler" className="font-medium text-primary hover:underline">Öğretmenler</Link>}
            <Link to="/ders-programi" className="font-medium text-primary hover:underline">Ders programı</Link>
          </span>
        </Alert>
      )}

      {s && (
        <p className="mb-2 px-1 text-[12.5px] tabular text-ink-3">
          {s.lessons} ders{s.cancelled ? ` · ${s.cancelled} iptal` : ''}{s.studies ? ` · ${s.studies} etüt/birebir` : ''}{s.exams ? ` · ${s.exams} sınav` : ''}{s.leaves ? ` · ${s.leaves} izin` : ''}{s.holidays ? ` · ${s.holidays} tatil` : ''}
        </p>
      )}

      {q.isError ? (
        <Alert tone="danger">{q.error instanceof ApiError ? q.error.message : 'Takvim yüklenemedi.'}</Alert>
      ) : view === 'month' ? (
        <MonthGrid month={month} events={q.data?.events} loading={q.isLoading} onDay={(d) => setP({ gorunum: 'gun', tarih: d })} onEvent={onEvent} />
      ) : view === 'agenda' ? (
        <AgendaList from={range.from} to={range.to} events={q.data?.events} loading={q.isLoading} onEvent={onEvent} />
      ) : (
        <TimeGrid days={view === 'day' ? [date] : Array.from({ length: 7 }, (_, i) => addDays(range.from, i))} events={q.data?.events} loading={q.isLoading} onEvent={onEvent} onDay={view === 'week' ? (d) => setP({ gorunum: 'gun', tarih: d }) : undefined} />
      )}

      <SessionDrawer event={selected} options={o} onClose={() => setSelected(null)} onChanged={refresh} />
      {holidays && <HolidayDrawer onClose={() => setHolidays(false)} onChanged={refresh} />}
      {feed && choices && <FeedModal owner={owner} choices={choices} onClose={() => setFeed(false)} />}
      {pdf && choices && <PdfModal initial={pdfTarget} date={date} choices={choices} onClose={() => setPdf(false)} />}
    </div>
  )
}
