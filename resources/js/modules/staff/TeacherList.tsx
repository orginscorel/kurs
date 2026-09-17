import { useMemo, useState } from 'react'
import { MailText, PhoneText } from '@/components/ui/contact'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, FileUp, GraduationCap, Plus, Search } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { date } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Avatar, Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select, Segmented } from '@/components/ui/form'
import type { TeacherOptions, TeacherRow } from './types'
import { TeacherFormDrawer } from './TeacherFormDrawer'
import { ExcelImportDialog } from '@/components/import/ExcelImportDialog'

type ListResponse = Paginated<TeacherRow> & { meta: { active_count: number; inactive_count: number } }

export default function TeacherList() {
  const can = useCan()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: 'full_name', filters: { status: 'active' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const formOpen = params.get('yeni') === '1'

  useMemo(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const options = useQuery({ queryKey: ['teachers', 'options'], queryFn: () => api.get<TeacherOptions>('/teachers/options'), staleTime: 5 * 60_000 })
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['teachers', 'list', list.query],
    queryFn: () => api.get<ListResponse>('/teachers', list.query),
    placeholderData: keepPreviousData,
  })

  const columns = useMemo<Column<TeacherRow>[]>(
    () => [
      {
        key: 'name',
        header: 'Öğretmen',
        sortKey: 'full_name',
        cell: (t) => (
          <div className="flex items-center gap-3 min-w-[200px]">
            <Avatar name={t.full_name} src={t.avatar_url} size={34} />
            <div className="min-w-0">
              <p className="font-medium text-ink truncate">{t.full_name}</p>
              {(t.title || t.specialty) && <p className="text-[12px] text-ink-3 truncate">{t.title ?? t.specialty}</p>}
            </div>
          </div>
        ),
      },
      {
        key: 'subjects',
        header: 'Branşlar',
        hideable: true,
        cell: (t) => (
          <div className="flex flex-wrap gap-1 max-w-[220px]">
            {t.subjects.slice(0, 3).map((s) => (
              <Badge key={s.id}>{s.name}</Badge>
            ))}
            {t.subjects.length === 0 && <span className="text-ink-3">—</span>}
          </div>
        ),
      },
      { key: 'employment', header: 'Çalışma türü', hideable: true, cell: (t) => <span className="text-ink-2 whitespace-nowrap">{t.employment_type_label}</span> },
      {
        key: 'classes',
        header: 'Girdiği sınıf',
        align: 'right',
        hideable: true,
        cell: (t) => (t.class_count === null || t.class_count === undefined ? <span className="text-ink-3">—</span> : <span className="tabular text-ink">{t.class_count}</span>),
      },
      {
        key: 'hours',
        header: 'Bu hafta ders saati',
        sortKey: 'weekly_hours',
        align: 'right',
        // Saat / hedef; hedef aşıldıysa uyarı rengi. İkinci satır bu haftaki ders sayısı.
        cell: (t) => {
          const hours = Number(t.weekly_hours)
          const target = t.target_weekly_hours ?? null
          const over = target !== null && target > 0 && hours > target
          return (
            <div>
              <p className={`tabular whitespace-nowrap ${over ? 'text-warning font-medium' : 'text-ink'}`}>
                {hours.toLocaleString("tr-TR", { maximumFractionDigits: 1 })} saat{target ? <span className="text-ink-3"> / hedef {target}</span> : null}
              </p>
              {t.week_lessons !== null && t.week_lessons !== undefined && <p className="text-[12px] text-ink-3 tabular whitespace-nowrap">{t.week_lessons} ders oturumu</p>}
            </div>
          )
        },
      },
      { key: 'phone', header: 'Cep telefonu', hideable: true, cell: (t) => <PhoneText value={t.phone} whatsapp /> },
      { key: 'email', header: 'E-posta', hideable: true, defaultHidden: true, cell: (t) => <MailText value={t.email} /> },
      { key: 'hired', header: 'İşe başlama', sortKey: 'hired_on', hideable: true, defaultHidden: true, cell: (t) => <span className="text-ink-2 tabular whitespace-nowrap">{t.hired_on ? date(t.hired_on) : '—'}</span> },
      { key: 'user', header: 'Portal hesabı', hideable: true, cell: (t) => <Badge tone={t.has_user ? 'success' : 'neutral'}>{t.has_user ? 'Var' : 'Yok'}</Badge> },
      { key: 'status', header: 'Durum', cell: (t) => <Badge tone={t.is_active ? 'success' : 'neutral'} dot>{t.is_active ? 'Aktif' : 'Pasif'}</Badge> },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Öğretmenler"
        description={data ? `${data.meta.total.toLocaleString('tr-TR')} öğretmen listeleniyor` : 'Öğretmen kadrosu ve ders programları'}
        actions={
          <>
            {can('teachers.view') && (
              <Button icon={<Download className="size-4" />} onClick={() => api.download('/teachers/export', list.query, 'ogretmenler.xlsx').catch((e) => toast.error(e.message))}>
                Excel
              </Button>
            )}
            {can('teachers.manage') && (
              <Button icon={<FileUp className="size-4" />} onClick={() => setParams((p) => { p.set('ice-aktar', '1'); return p })}>
                Excel'den içe aktar
              </Button>
            )}
            {can('teachers.manage') && (
              <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>
                Yeni öğretmen
              </Button>
            )}
          </>
        }
      />

      <DataTable
        storageKey="teachers"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/ogretmenler/${r.id}`)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ad, telefon ya da e-posta ile ara" leading={<Search />} className="w-full sm:w-[300px]" />
            <Segmented
              size="sm"
              value={list.filters.status ?? 'active'}
              onChange={(status) => list.update({ filters: { status } })}
              options={[
                { value: 'active', label: `Aktif ${data?.meta.active_count ?? ''}` },
                { value: 'inactive', label: `Pasif ${data?.meta.inactive_count ?? ''}` },
                { value: 'all', label: 'Tümü' },
              ]}
            />
            <Select
              value={list.filters.subject_id ?? ''}
              onChange={(e) => list.update({ filters: { subject_id: e.target.value } })}
              placeholder="Tüm branşlar"
              options={(options.data?.subjects ?? []).map((s) => ({ value: s.id, label: s.name }))}
              className="w-[180px]"
            />
          </div>
        }
        empty={
          <EmptyState
            icon={<GraduationCap />}
            title={list.q ? 'Aramanıza uyan öğretmen yok' : 'Henüz öğretmen kaydı yok'}
            description={list.q ? 'Filtreleri değiştirerek tekrar deneyin.' : 'İlk öğretmeninizi ekleyerek başlayın.'}
            action={can('teachers.manage') && !list.q ? <div className="flex flex-wrap justify-center gap-2"><Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Yeni öğretmen</Button><Button icon={<FileUp className="size-4" />} onClick={() => setParams((p) => { p.set('ice-aktar', '1'); return p })}>Excel'den içe aktar</Button></div> : undefined}
          />
        }
      />

      {can('teachers.manage') && <ExcelImportDialog entity="teachers" open={params.get('ice-aktar') === '1'} onClose={() => setParams((p) => { p.delete('ice-aktar'); return p })} />}

      <TeacherFormDrawer
        open={formOpen}
        options={options.data}
        onClose={() => setParams((p) => { p.delete('yeni'); return p })}
        onSaved={(id) => {
          qc.invalidateQueries({ queryKey: ['teachers'] })
          navigate(`/ogretmenler/${id}`)
        }}
      />
    </div>
  )
}
