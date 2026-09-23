import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle, Download, FileWarning, UserX, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useListState } from '@/hooks/useListState'
import { PageHeader, Panel } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Avatar, Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { StudentSearch, type StudentHit } from './LivePresence'
import { STATUS_LABEL, STATUS_TONE, type AbsenceRow, type AbsenceSummaryRow, type OverThresholdRow } from './types'

type Options = { class_groups: { id: number; name: string }[]; statuses: Record<string, string> }
type ListResponse = Paginated<AbsenceRow> & { meta: { summary: AbsenceSummaryRow[]; over_threshold: OverThresholdRow[]; threshold: number; window_days: number } }

export default function Absences() {
  const can = useCan()
  const qc = useQueryClient()
  const list = useListState({ sort: '-date', filters: { from: new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10), to: new Date().toISOString().slice(0, 10) } })
  const [leaveOpen, setLeaveOpen] = useState(false)
  const [leaveStudent, setLeaveStudent] = useState<StudentHit | null>(null)
  const [leaveFrom, setLeaveFrom] = useState(list.filters.from ?? '')
  const [leaveTo, setLeaveTo] = useState(list.filters.to ?? '')
  const [leaveStatus, setLeaveStatus] = useState<'excused' | 'medical'>('excused')
  const [leaveNote, setLeaveNote] = useState('')
  const [leaveErrors, setLeaveErrors] = useState<Record<string, string[]>>({})

  const options = useQuery({ queryKey: ['attendance', 'absence-options'], queryFn: () => api.get<Options>('/attendance/absences/options'), staleTime: 5 * 60_000 })
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['attendance', 'absences', list.query],
    queryFn: () => api.get<ListResponse>('/attendance/absences', list.query),
    placeholderData: keepPreviousData,
  })

  const leaveMutation = useMutation({
    mutationFn: () => api.post<{ message: string }>('/attendance/absences/leave', { student_id: leaveStudent!.id, from: leaveFrom, to: leaveTo, status: leaveStatus, note: leaveNote || null }),
    onSuccess: (res) => {
      toast.success(res.message)
      setLeaveErrors({})
      setLeaveOpen(false)
      setLeaveStudent(null)
      setLeaveNote('')
      qc.invalidateQueries({ queryKey: ['attendance', 'absences'] })
    },
    onError: (e) => {
      setLeaveErrors(e instanceof ApiError ? e.errors ?? {} : {})
      toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')
    },
  })
  const leaveErr = (k: string) => leaveErrors[k]?.[0] ?? null

  const columns: Column<AbsenceRow>[] = [
    { key: 'date', header: 'Tarih', sortKey: 'date', cell: (r) => <span className="tabular">{date(r.date)}</span> },
    {
      key: 'student',
      header: 'Öğrenci',
      cell: (r) => (
        <Link to={`/ogrenciler/${r.student_id}`} className="flex items-center gap-2.5 min-w-0 hover:text-primary">
          <Avatar name={r.full_name} size={28} />
          <span className="min-w-0">
            <span className="block truncate text-[13.5px] font-medium">{r.full_name}</span>
            <span className="block text-[12px] text-ink-3 tabular">Öğrenci no: {r.student_no}</span>
          </span>
        </Link>
      ),
    },
    { key: 'class', header: 'Sınıf', cell: (r) => r.class_group || <span className="text-ink-3">—</span> },
    { key: 'subject', priority: 3, header: 'Ders', hideable: true, truncate: true, title: (r) => r.subject ?? undefined, cell: (r) => r.subject },
    { key: 'status', priority: 1, header: 'Durum', cell: (r) => <Badge tone={STATUS_TONE[r.status]}>{STATUS_LABEL[r.status]}{r.status === 'late' && r.late_minutes ? ` (${r.late_minutes} dk)` : ''}</Badge> },
    { key: 'method', priority: 4, header: 'Yoklama yöntemi', hideable: true, defaultHidden: true, cell: (r) => <span className="text-ink-3">{r.method}</span> },
    { key: 'note', priority: 4, header: 'Not', hideable: true, truncate: true, maxWidth: 280, title: (r) => r.note ?? undefined, cell: (r) => r.note || <span className="text-ink-3">—</span> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Devamsızlık"
        description={data
          ? `${data.meta.total.toLocaleString('tr-TR')} kayıt${list.filters.status ? '' : ' · gelmedi, geç, izinli ve raporlu'}`
          : 'Tarih aralığı ve sınıfa göre devamsızlık raporu'}
        actions={
          <>
            <Button icon={<Download className="size-4" />} onClick={() => api.download('/attendance/absences/export', list.query, 'devamsizlik.xlsx').catch((e) => toast.error(e.message))}>Excel</Button>
            {can('attendance.override') && (
              <Button variant="primary" icon={<FileWarning className="size-4" />} onClick={() => setLeaveOpen(true)}>İzin / rapor gir</Button>
            )}
          </>
        }
      />

      {!!data?.meta.over_threshold.length && (
        <Panel className="mb-4" title={<span className="inline-flex items-center gap-1.5"><AlertTriangle className="size-4 text-danger" /> Eşik aşan öğrenciler</span>} description={`Son ${data.meta.window_days} günde ${data.meta.threshold} ve üzeri devamsızlığı olanlar`}>
          <div className="flex flex-wrap gap-2">
            {data.meta.over_threshold.slice(0, 20).map((o) => (
              <Link key={o.student_id} to={`/ogrenciler/${o.student_id}`}>
                <Badge tone="danger">{o.full_name} · {o.absent_count} devamsızlık</Badge>
              </Link>
            ))}
          </div>
        </Panel>
      )}

      <DataTable
        storageKey="absences"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <div className="flex w-full items-center gap-2 sm:w-auto">
              <Input type="date" aria-label="Başlangıç tarihi" title="Başlangıç tarihi" value={list.filters.from ?? ''} onChange={(e) => list.update({ filters: { from: e.target.value } })} className="min-w-0 flex-1 sm:w-[160px] sm:flex-none" />
              <span className="text-ink-3 text-[12.5px]">–</span>
              <Input type="date" aria-label="Bitiş tarihi" title="Bitiş tarihi" value={list.filters.to ?? ''} onChange={(e) => list.update({ filters: { to: e.target.value } })} className="min-w-0 flex-1 sm:w-[160px] sm:flex-none" />
            </div>
            <Select aria-label="Sınıf" value={list.filters.class_group_id ?? ''} onChange={(e) => list.update({ filters: { class_group_id: e.target.value } })} placeholder="Tüm sınıflar" options={(options.data?.class_groups ?? []).map((g) => ({ value: g.id, label: g.name }))} className="min-w-0 flex-1 sm:w-[160px] sm:flex-none" />
            <Select
              value={list.filters.status ?? ''}
              onChange={(e) => list.update({ filters: { status: e.target.value } })}
              placeholder="Devamsızlıklar"
              title="Varsayılan: gelmedi, geç, izinli ve raporlu kayıtlar"
              options={[
                ...Object.entries(options.data?.statuses ?? {}).map(([value, label]) => ({ value, label })),
                { value: 'all', label: 'Tümü (var dahil)' },
              ]}
              aria-label="Durum"
              className="min-w-0 flex-1 sm:w-[160px] sm:flex-none"
            />
          </div>
        }
        empty={<EmptyState icon={<UserX />} title="Kayıt bulunamadı" description="Seçili filtrelere uyan devamsızlık kaydı yok." />}
      />

      <Modal
        open={leaveOpen}
        onClose={() => setLeaveOpen(false)}
        title="İzin / rapor girişi"
        description="Seçilen tarih aralığındaki tüm dersler bu durumla işaretlenir."
        footer={
          <>
            <Button variant="ghost" onClick={() => setLeaveOpen(false)}>Vazgeç</Button>
            <Button variant="primary" disabled={!leaveStudent || !leaveFrom || !leaveTo} loading={leaveMutation.isPending} onClick={() => leaveMutation.mutate()}>Kaydet</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label="Öğrenci" required error={leaveErr('student_id')}>
            {leaveStudent ? (
              <div className="flex items-center gap-3 rounded-[var(--radius-md)] ring-1 ring-line px-3 py-2.5">
                <Avatar name={leaveStudent.full_name} src={leaveStudent.photo_url} size={32} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13.5px] font-medium">{leaveStudent.full_name}</p>
                  <p className="text-[12px] text-ink-3 tabular">Öğrenci no: {leaveStudent.student_no}</p>
                </div>
                <Button size="icon-sm" variant="ghost" onClick={() => setLeaveStudent(null)} aria-label="Öğrenciyi değiştir"><X className="size-4" /></Button>
              </div>
            ) : (
              <StudentSearch onPick={setLeaveStudent} />
            )}
          </Field>
          <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
            <Field label="Başlangıç tarihi" required error={leaveErr('from')}>
              <Input type="date" value={leaveFrom} onChange={(e) => setLeaveFrom(e.target.value)} />
            </Field>
            <Field label="Bitiş tarihi" required error={leaveErr('to')}>
              <Input type="date" value={leaveTo} min={leaveFrom || undefined} onChange={(e) => setLeaveTo(e.target.value)} />
            </Field>
          </div>
          <Field label="Durum" required error={leaveErr('status')}>
            <Select
              value={leaveStatus}
              onChange={(e) => setLeaveStatus(e.target.value as 'excused' | 'medical')}
              options={[
                { value: 'excused', label: 'İzinli' },
                { value: 'medical', label: 'Raporlu' },
              ]}
            />
          </Field>
          <Field label="Belge notu" optional error={leaveErr('note')}>
            <Textarea value={leaveNote} onChange={(e) => setLeaveNote(e.target.value)} placeholder="ör. rapor tarihi ve numarası" rows={2} />
          </Field>
        </div>
      </Modal>
    </div>
  )
}
