import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle, CalendarDays, ChevronLeft, ChevronRight, Plus, Search } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, todayISO } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Input, Segmented, Select } from '@/components/ui/form'
import { Alert, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Modal } from '@/components/ui/overlay'
import { useAcademicOptions } from './hooks'
import { addDays, toTime, type Conflict, type ScheduleView, type SessionRow, type WeekData, type WeekItem } from './types'
import { WeekGrid } from './schedule/WeekGrid'
import { ScheduleFormDrawer } from './schedule/ScheduleFormDrawer'
import { LessonSheet, type SheetTarget } from './schedule/LessonSheet'
import { DayView } from './schedule/DayView'
import { MonthView, monthCells } from './schedule/MonthView'
import { StudyDetailDrawer } from './StudyDetailDrawer'

type Mode = 'day' | 'week' | 'month'

function weekStartOf(iso: string) {
  const [y, m, d] = iso.split('-').map(Number)
  const dt = new Date(y!, m! - 1, d!)
  return addDays(iso, -((dt.getDay() + 6) % 7))
}

export default function SchedulePage() {
  const can = useCan()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const options = useAcademicOptions()

  const mode = (params.get('gorunum') as Mode) || 'week'
  const view = (params.get('tur') as ScheduleView | 'all') || 'class_group'
  const idParam = params.get('id')
  const dateParam = params.get('tarih') || todayISO()
  const formOpen = params.get('yeni') === '1'

  const setP = (patch: Record<string, string | null>) =>
    setParams((p) => {
      Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? p.delete(k) : p.set(k, v)))
      return p
    }, { replace: true })

  // Varsayılan kayıt: ilk sınıf (öğretmen kullanıcıysa kendi programı)
  useEffect(() => {
    if (!options.data || idParam || view === 'all') return
    if (options.data.my_teacher_id && !params.get('tur')) setP({ tur: 'teacher', id: String(options.data.my_teacher_id) })
    else if (view === 'class_group' && options.data.class_groups[0]) setP({ id: String(options.data.class_groups[0].id) })
    else if (view === 'teacher' && options.data.teachers[0]) setP({ id: String(options.data.teachers[0].id) })
    else if (view === 'classroom' && options.data.classrooms[0]) setP({ id: String(options.data.classrooms[0].id) })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [options.data, view, idParam])

  const id = Number(idParam) || 0
  const weekStart = weekStartOf(dateParam)
  const month = dateParam.slice(0, 7)
  const range = mode === 'day' ? { from: dateParam, to: dateParam } : mode === 'month' ? { from: monthCells(month)[0]!.date, to: monthCells(month)[41]!.date } : { from: weekStart, to: addDays(weekStart, 6) }

  const week = useQuery({
    queryKey: ['schedule', 'week', view, id, weekStart],
    queryFn: () => api.get<WeekData>('/schedule/week', { view, id, date: weekStart }),
    enabled: mode === 'week' && view !== 'all' && id > 0,
    placeholderData: keepPreviousData,
  })
  const sessions = useQuery({
    queryKey: ['schedule', 'sessions', view, id, range.from, range.to],
    queryFn: () => api.get<{ data: SessionRow[] }>('/schedule/sessions', { view, id: view === 'all' ? undefined : id, from: range.from, to: range.to }),
    enabled: mode !== 'week' && (view === 'all' || id > 0),
    placeholderData: keepPreviousData,
  })

  const [sheet, setSheet] = useState<SheetTarget | null>(null)
  const [editItem, setEditItem] = useState<WeekItem | null>(null)
  const [preset, setPreset] = useState<{ weekday?: number; starts_at?: string } | undefined>()
  const [conflictModal, setConflictModal] = useState<Conflict[] | null>(null)
  const [studyId, setStudyId] = useState<number | null>(null)

  const invalidate = () => qc.invalidateQueries({ queryKey: ['schedule'] })

  const shift = (n: number) => setP({ tarih: mode === 'month' ? `${addDays(`${month}-15`, n * 30).slice(0, 7)}-01` : addDays(dateParam, mode === 'day' ? n : n * 7) })

  const title = useMemo(() => {
    if (view === 'all') return 'Tüm kurum'
    const o = options.data
    if (!o) return ''
    if (view === 'class_group') return o.class_groups.find((g) => g.id === id)?.name ?? ''
    if (view === 'teacher') return o.teachers.find((t) => t.id === id)?.name ?? ''
    if (view === 'classroom') return o.classrooms.find((c) => c.id === id)?.name ?? ''
    return ''
  }, [view, id, options.data])

  const onMove = async (item: WeekItem, weekday: number, startMin: number) => {
    const dur = (Number(item.ends_at.slice(0, 2)) * 60 + Number(item.ends_at.slice(3))) - (Number(item.starts_at.slice(0, 2)) * 60 + Number(item.starts_at.slice(3)))
    try {
      const r = await api.put<{ message: string }>(`/schedule/${item.id}`, { weekday, starts_at: toTime(startMin), ends_at: toTime(startMin + dur) })
      toast.success(r.message)
      invalidate()
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) setConflictModal((e.context.conflicts as Conflict[]) ?? [{ type: 'x', message: e.message }])
      else toast.error(e instanceof ApiError ? e.firstError() : 'Ders taşınamadı.')
      invalidate()
    }
  }

  const entityOptions = useMemo(() => {
    const o = options.data
    if (!o) return []
    if (view === 'class_group') return o.class_groups.filter((g) => g.is_active).map((g) => ({ value: g.id, label: `${g.name}${g.program ? ` · ${g.program}` : ''}` }))
    if (view === 'teacher') return o.teachers.filter((t) => t.is_active).map((t) => ({ value: t.id, label: t.name }))
    if (view === 'classroom') return o.classrooms.filter((c) => c.is_active).map((c) => ({ value: c.id, label: c.name }))
    return []
  }, [options.data, view])

  const targetFromItem = (it: WeekItem): SheetTarget => ({
    scheduleId: it.id, session: it.session, weekday: it.weekday, starts_at: it.starts_at, ends_at: it.ends_at,
    subject: it.subject, teacher: it.teacher, classroom: it.classroom, class_group: it.class_group, valid_from: it.valid_from, valid_until: it.valid_until,
    is_locked: it.is_locked,
  })
  const targetFromSession = (s: SessionRow): SheetTarget => ({
    scheduleId: s.schedule_id, weekday: ((new Date(`${s.date}T12:00:00`).getDay() + 6) % 7) + 1, starts_at: s.starts_at, ends_at: s.ends_at,
    session: { id: s.id, date: s.date, status: s.status, cancel_reason: s.cancel_reason, topic_note: s.topic_note, topic_id: s.topic_id, attendance_taken: s.attendance_taken },
    subject: s.subject, teacher: s.teacher, classroom: s.classroom, class_group: s.class_group,
  })

  const manage = can('schedule.manage')

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Ders programı"
        description="Haftalık şablonu sürükleyerek taşıyın; boş bir saate tıklayarak ders ekleyin. Çakışmalar kaydedilmez."
        actions={manage && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => { setPreset(undefined); setEditItem(null); setP({ yeni: '1' }) }}>Ders ekle</Button>}
      />

      <div className="mb-4 flex flex-col gap-3 rounded-[var(--radius-lg)] bg-surface ring-1 ring-line p-3">
        <div className="flex flex-wrap items-center gap-2">
          <Segmented
            size="sm"
            value={view}
            onChange={(v) => setP({ tur: v, id: null })}
            options={[
              { value: 'class_group', label: 'Sınıf' }, { value: 'teacher', label: 'Öğretmen' }, { value: 'classroom', label: 'Derslik' },
              ...(can('students.view') ? [{ value: 'student' as const, label: 'Öğrenci' }] : []),
              ...(mode !== 'week' ? [{ value: 'all' as const, label: 'Tüm kurum' }] : []),
            ]}
          />
          {view === 'student' ? (
            <StudentSelect value={id} onChange={(v) => setP({ id: String(v) })} />
          ) : view !== 'all' ? (
            <Select value={id || ''} onChange={(e) => setP({ id: e.target.value })} placeholder="Seçin" options={entityOptions} className="w-full sm:w-[260px]" />
          ) : null}
          <div className="ml-auto flex items-center gap-2">
            <Segmented size="sm" value={mode} onChange={(m) => setP({ gorunum: m, ...(m === 'week' && view === 'all' ? { tur: 'class_group', id: null } : {}) })} options={[{ value: 'day', label: 'Gün' }, { value: 'week', label: 'Hafta' }, { value: 'month', label: 'Ay' }]} />
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button size="icon-sm" variant="ghost" onClick={() => shift(-1)} aria-label="Önceki"><ChevronLeft className="size-4" /></Button>
          <Button size="icon-sm" variant="ghost" onClick={() => shift(1)} aria-label="Sonraki"><ChevronRight className="size-4" /></Button>
          <Button size="sm" variant="ghost" onClick={() => setP({ tarih: null })}>Bugün</Button>
          <p className="text-[13.5px] font-medium tabular">
            {mode === 'day' ? date(dateParam, 'day') : mode === 'month' ? new Date(`${month}-01T12:00:00`).toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' }) : `${date(weekStart)} – ${date(addDays(weekStart, 6))}`}
          </p>
          <div className="w-[160px] shrink-0"><Input type="date" value={dateParam} onChange={(e) => e.target.value && setP({ tarih: e.target.value })} /></div>
          {title && <span className="ml-auto text-[12.5px] text-ink-3">{title}</span>}
        </div>
      </div>

      {view !== 'all' && !id ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          {options.data && view !== 'student' && ((view === 'class_group' && options.data.class_groups.length === 0) || (view === 'teacher' && options.data.teachers.length === 0) || (view === 'classroom' && options.data.classrooms.length === 0)) ? (
            <EmptyState
              icon={<CalendarDays />}
              title={view === 'teacher' ? 'Henüz öğretmen yok' : view === 'classroom' ? 'Henüz derslik yok' : 'Henüz sınıf yok'}
              description="Ders programı; sınıflar, öğretmenler ve derslikler tanımlandıktan sonra program botuyla ya da elle oluşturulur."
              action={
                <div className="flex flex-wrap justify-center gap-2">
                  {view === 'class_group' && can('academic.view') && <ButtonLink variant="primary" to="/program-botu/sinif-yapisi">Sınıf yapısını kur</ButtonLink>}
                  {view === 'teacher' && can('teachers.manage') && <ButtonLink variant="primary" to="/ogretmenler?yeni=1">Öğretmen ekle</ButtonLink>}
                  {view === 'classroom' && can('academic.manage') && <ButtonLink variant="primary" to="/akademik?sekme=derslikler&yeni=1">Derslik ekle</ButtonLink>}
                  {can('schedule.manage') && <ButtonLink to="/program-botu">Program botu</ButtonLink>}
                </div>
              }
            />
          ) : (
            <EmptyState icon={<CalendarDays />} title="Bir kayıt seçin" description={view === 'student' ? 'Öğrenci arayarak sınıflarının birleşik programını görün.' : 'Program görüntülemek için listeden seçim yapın.'} />
          )}
        </div>
      ) : mode === 'week' ? (
        week.data ? (
          <>
            {week.data.items.length === 0 && week.data.studies.length === 0 && (
              <Alert tone="info" className="mb-3">Bu haftada tanımlı ders yok.{manage ? ' Boş bir saate tıklayarak ders ekleyebilirsiniz.' : ''}</Alert>
            )}
            <WeekGrid
              data={week.data}
              canManage={manage}
              onMove={onMove}
              onSelect={(it) => setSheet(targetFromItem(it))}
              onStudySelect={(st) => setStudyId(st.id)}
              onEmptyClick={(weekday, start) => {
                setEditItem(null)
                setPreset({ weekday, starts_at: toTime(start) })
                setP({ yeni: '1' })
              }}
            />
            <p className="mt-2 text-[12px] text-ink-3">Kesik çerçeveli bloklar etüt/birebir kayıtlarıdır. Yoklaması alınmış ya da iptal edilmiş dersler taşınamaz.</p>
          </>
        ) : week.isError ? (
          <Alert tone="danger">{week.error instanceof ApiError ? week.error.message : 'Program yüklenemedi.'}</Alert>
        ) : (
          <Skeleton className="h-[520px] rounded-[var(--radius-lg)]" />
        )
      ) : mode === 'day' ? (
        <DayView rows={sessions.data?.data} loading={sessions.isLoading} onSelect={(s) => setSheet(targetFromSession(s))} showGroup={view !== 'class_group'} />
      ) : (
        <MonthView month={month} rows={sessions.data?.data} loading={sessions.isLoading} onDay={(d) => setP({ gorunum: 'day', tarih: d })} />
      )}

      <ScheduleFormDrawer
        open={formOpen || !!editItem}
        onClose={() => { setEditItem(null); setP({ yeni: null }) }}
        onSaved={invalidate}
        options={options.data}
        item={editItem}
        preset={{ ...preset, ...(view === 'class_group' ? { class_group_id: id } : view === 'teacher' ? { teacher_id: id } : view === 'classroom' ? { classroom_id: id } : {}) }}
      />

      <LessonSheet
        target={sheet}
        onClose={() => setSheet(null)}
        onChanged={invalidate}
        options={options.data}
        onEdit={sheet?.scheduleId && week.data ? () => { const it = week.data!.items.find((x) => x.id === sheet.scheduleId); if (it) { setSheet(null); setEditItem(it) } } : undefined}
      />

      <StudyDetailDrawer id={studyId} onClose={() => setStudyId(null)} onChanged={invalidate} options={options.data} />

      <Modal
        open={!!conflictModal}
        onClose={() => setConflictModal(null)}
        size="sm"
        title="Taşınamadı — çakışma var"
        description="Ders eski yerinde bırakıldı."
        footer={<Button variant="primary" onClick={() => setConflictModal(null)}>Tamam</Button>}
      >
        <ul className="flex flex-col gap-2">
          {conflictModal?.map((c, i) => (
            <li key={i} className="flex items-start gap-2 rounded-[var(--radius-sm)] bg-danger-soft px-3 py-2 text-[13px] text-danger"><AlertTriangle className="mt-0.5 size-4 shrink-0" />{c.message}</li>
          ))}
        </ul>
      </Modal>
    </div>
  )
}

/** Öğrenci bazlı görünüm için arama kutusu */
function StudentSelect({ value, onChange }: { value: number; onChange: (id: number) => void }) {
  const [q, setQ] = useState('')
  const debounced = useDebounced(q, 250)
  const hits = useQuery({
    queryKey: ['students', 'pick', debounced],
    queryFn: () => api.get<{ data: { id: number; full_name: string; student_no: string; class_groups: { name: string }[] }[] }>('/students', { q: debounced, per_page: 8, status: 'current' }),
    enabled: debounced.trim().length >= 2,
  })
  const current = useQuery({ queryKey: ['students', 'name', value], queryFn: () => api.get<{ student: { full_name: string } }>(`/students/${value}`), enabled: value > 0 && !q })
  return (
    <div className="relative w-full sm:w-[300px]">
      <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={value && current.data ? current.data.student.full_name : 'Öğrenci ara (ad / no)'} leading={<Search />} />
      {debounced.trim().length >= 2 && (
        <div className="absolute left-0 right-0 top-full z-30 mt-1 rounded-[var(--radius-md)] bg-surface p-1 ring-1 ring-line shadow-[var(--shadow-pop)]">
          {(hits.data?.data ?? []).map((h) => (
            <button key={h.id} type="button" className="flex w-full flex-col rounded-[6px] px-2.5 py-1.5 text-left hover:bg-surface-2" onClick={() => { onChange(h.id); setQ('') }}>
              <span className="text-[13px] font-medium">{h.full_name}</span>
              <span className="text-[12px] text-ink-3 tabular">Öğrenci no: {h.student_no}{h.class_groups.length ? ` · ${h.class_groups.map((g) => g.name).join(', ')}` : ''}</span>
            </button>
          ))}
          {hits.data && hits.data.data.length === 0 && <p className="px-2.5 py-2 text-[12.5px] text-ink-3">Eşleşen öğrenci yok.</p>}
        </div>
      )}
    </div>
  )
}
