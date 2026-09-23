import { useMemo, useState, useEffect } from 'react'
import { PhoneText } from '@/components/ui/contact'
import { useNavigate } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Search, Users, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { date as fmtDate, num } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { Panel, Stat } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { BarList, ExportButton, ReportFrame, reportPage, useUrlFilters } from './shared'
import type { ReportOptions, StudentRow, StudentSummary } from './types'

type Resp = Paginated<StudentRow> & { meta: { summary: StudentSummary } }

function StudentsReport() {
  const can = useCan()
  const navigate = useNavigate()
  const [f, setF] = useUrlFilters({ q: '', status: '', program_id: '', class_group_id: '', from: '', to: '', page: '1' })
  const [search, setSearch] = useState(f.q)
  const q = useDebounced(search, 300)
  useEffect(() => {
    if (q !== f.q) setF({ q })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q])

  const options = useQuery({ queryKey: ['reports', 'options'], queryFn: () => api.get<ReportOptions>('/reports/options'), staleTime: 5 * 60_000 })
  const query = { q: f.q, status: f.status, program_id: f.program_id, class_group_id: f.class_group_id, from: f.from, to: f.to }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['reports', 'students', query, f.page],
    queryFn: () => api.get<Resp>('/reports/students', { ...query, page: Number(f.page) || 1, per_page: 50 }),
    placeholderData: keepPreviousData,
  })
  const s = data?.meta.summary
  const filtered = Object.values(query).some(Boolean)
  const active = s?.by_status.find((x) => x.key === 'active')?.total ?? 0

  const columns = useMemo<Column<StudentRow>[]>(() => [
    { key: 'no', header: 'Öğrenci no', cell: (r) => <span className="tabular text-ink-3">{r.student_no}</span> },
    { key: 'name', header: 'Ad soyad', truncate: true, title: (r) => r.full_name, cell: (r) => <span className="font-medium text-ink">{r.full_name}</span> },
    { key: 'status', header: 'Durum', cell: (r) => <Badge tone={r.status === 'active' ? 'success' : r.status === 'withdrawn' ? 'danger' : r.status === 'frozen' ? 'warning' : 'neutral'}>{r.status_label}</Badge> },
    { key: 'class', header: 'Sınıf', cell: (r) => r.class_names ?? <span className="text-ink-3">Sınıfsız</span> },
    { key: 'program', header: 'Program', hideable: true, cell: (r) => r.program_names ?? '—' },
    { key: 'school', header: 'Okulu / okul sınıfı', hideable: true, truncate: true, maxWidth: 240, title: (r) => [r.school_name, r.school_grade].filter(Boolean).join(' · ') || undefined, cell: (r) => <span className="text-ink-2">{[r.school_name, r.school_grade].filter(Boolean).join(' · ') || '—'}</span> },
    { key: 'phone', header: 'Telefon', hideable: true, defaultHidden: true, cell: (r) => <PhoneText value={r.phone} /> },
    { key: 'registered', header: 'Kayıt tarihi', align: 'right', cell: (r) => <span className="tabular text-ink-2">{fmtDate(r.registered_on)}</span> },
  ], [])

  return (
    <ReportFrame
      reportKey="students"
      actions={<ExportButton path="/reports/students/export" query={query} name="ogrenci-raporu.xlsx" disabled={!s?.total} />}
      filters={
        <>
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ad ya da numara" leading={<Search />} className="w-full sm:w-[200px]" />
          <Select className="w-full sm:w-[150px]" value={f.status} onChange={(e) => setF({ status: e.target.value })} placeholder="Tüm durumlar"
            options={Object.entries(options.data?.student_statuses ?? {}).map(([value, label]) => ({ value, label }))} />
          <Select className="w-full sm:w-[160px]" value={f.program_id} onChange={(e) => setF({ program_id: e.target.value })} placeholder="Tüm programlar"
            options={(options.data?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
          <Select className="w-full sm:w-[150px]" value={f.class_group_id} onChange={(e) => setF({ class_group_id: e.target.value })} placeholder="Tüm sınıflar"
            options={(options.data?.class_groups ?? []).map((c) => ({ value: c.id, label: c.is_active ? c.name : `${c.name} (pasif)` }))} />
          <div className="flex w-full items-center gap-1.5 sm:w-auto">
            <Input type="date" aria-label="Kayıt başlangıç" title="Kayıt tarihi (başlangıç)" value={f.from} max={f.to || undefined} onChange={(e) => setF({ from: e.target.value })} className="min-w-0 flex-1 sm:w-[145px] sm:flex-none" />
            <span className="text-ink-3">–</span>
            <Input type="date" aria-label="Kayıt bitiş" title="Kayıt tarihi (bitiş)" value={f.to} min={f.from || undefined} onChange={(e) => setF({ to: e.target.value })} className="min-w-0 flex-1 sm:w-[145px] sm:flex-none" />
          </div>
          {filtered && <Button size="sm" variant="ghost" icon={<X className="size-3.5" />} onClick={() => { setSearch(''); setF({ q: null, status: null, program_id: null, class_group_id: null, from: null, to: null }) }}>Temizle</Button>}
        </>
      }
    >
      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Listelenen öğrenci" icon={<Users />} loading={isLoading} value={num(s?.total)} sub={filtered ? 'filtreye uyan' : 'tüm kayıtlar'} />
        <Stat label="Aktif" loading={isLoading} value={num(active)} />
        <Stat label="Sınıfı olmayan" loading={isLoading} value={num(s?.without_class)} tone={s?.without_class ? 'warning' : undefined} sub="aktif sınıf üyeliği yok" />
        <Stat label="Sınıf seviyesi" loading={isLoading} value={num(s?.by_grade.length)} sub="farklı seviye" />
      </div>

      {!!s?.total && (
        <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Panel title="Durum dağılımı">{isLoading ? <Skeleton className="h-24" /> : <BarList items={s.by_status.map((x) => ({ label: x.label, value: x.total }))} />}</Panel>
          <Panel title="Sınıf seviyesi">{isLoading ? <Skeleton className="h-24" /> : <BarList items={s.by_grade.map((x) => ({ label: x.label, value: x.total }))} />}</Panel>
        </div>
      )}

      <DataTable
        storageKey="reports-students"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => setF({ page: String(page) })}
        onRowClick={(r) => navigate(`/ogrenciler/${r.id}`)}
        empty={filtered
          ? <EmptyState icon={<Users />} title="Filtreye uyan öğrenci yok" description="Filtreleri değiştirip yeniden deneyin." />
          : <EmptyState icon={<Users />} title="Henüz öğrenci kaydı yok" description="Öğrenci eklendikçe bu rapor dolacak."
              action={can('students.create') ? <ButtonLink to="/ogrenciler?yeni=1" variant="primary">Öğrenci ekle</ButtonLink> : undefined} />}
      />
    </ReportFrame>
  )
}

export default reportPage('students', StudentsReport)
