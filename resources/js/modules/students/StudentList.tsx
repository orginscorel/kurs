import { useEffect, useMemo, useState } from 'react'
import { PersonText, PhoneText } from '@/components/ui/contact'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, FileUp, Filter, Plus, Printer, Radio, Search, Tag as TagIcon, Users, X } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Avatar, Badge, EmptyState, StatusDot } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Input, Select, Segmented, Switch } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { riskMeta, statusTone, tagColor, type StudentOptions, type StudentRow } from './types'
import { StudentFormDrawer } from './StudentFormDrawer'
import { ExcelImportDialog } from '@/components/import/ExcelImportDialog'
import { SendMessageDialog } from '@/modules/communication/SendMessageDialog'

type ListResponse = Paginated<StudentRow> & { meta: { status_counts: Record<string, number> } }

export default function StudentList() {
  const can = useCan()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: 'full_name', filters: { status: 'current' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [selected, setSelected] = useState<Set<string | number>>(new Set())
  const [showFilters, setShowFilters] = useState(false)
  const [bulk, setBulk] = useState<null | 'tag_add' | 'class_change' | 'status'>(null)
  const [bulkValue, setBulkValue] = useState('')
  const [messageOpen, setMessageOpen] = useState(false)
  const formOpen = params.get('yeni') === '1'

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const options = useQuery({ queryKey: ['students', 'options'], queryFn: () => api.get<StudentOptions>('/students/options'), staleTime: 5 * 60_000 })
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['students', 'list', list.query],
    queryFn: () => api.get<ListResponse>('/students', list.query),
    placeholderData: keepPreviousData,
  })

  const bulkMutation = useMutation({
    mutationFn: () =>
      api.post<{ message: string; errors: string[] }>('/students-bulk', {
        ids: [...selected],
        action: bulk,
        tag_id: bulk === 'tag_add' ? Number(bulkValue) : undefined,
        class_group_id: bulk === 'class_change' ? Number(bulkValue) : undefined,
        status: bulk === 'status' ? bulkValue : undefined,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      res.errors.slice(0, 3).forEach((e) => toast.warning(e))
      setBulk(null)
      setBulkValue('')
      setSelected(new Set())
      qc.invalidateQueries({ queryKey: ['students'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.'),
  })

  const counts = data?.meta.status_counts ?? {}
  const currentCount = ['active', 'enrolled', 'frozen', 'pending'].reduce((a, k) => a + (counts[k] ?? 0), 0)
  const activeFilterCount = ['class_group_id', 'program_id', 'tag_id', 'risk', 'overdue', 'inside', 'field'].filter((k) => list.filters[k]).length

  const columns = useMemo<Column<StudentRow>[]>(
    () => [
      {
        key: 'name',
        header: 'Öğrenci',
        sortKey: 'full_name',
        maxWidth: 380,
        cell: (s) => {
          const sub = [`Öğrenci no ${s.student_no}`, s.class_groups.map((g) => g.name).join(', ') || 'Sınıfsız', s.program].filter(Boolean).join(' · ')
          return (
            <div className="flex items-center gap-3">
              <div className="relative shrink-0">
                <Avatar name={s.full_name} src={s.photo_url} size={34} tinted />
                {s.is_inside && <span className="absolute -right-0.5 -bottom-0.5 size-3 rounded-full bg-success ring-2 ring-surface" title="Şu an kurumda" />}
              </div>
              <div className="min-w-0">
                <p className="flex items-center gap-1.5 font-medium text-ink">
                  <span className="truncate">{s.full_name}</span>
                  {s.risk_level && s.risk_level !== 'low' && <Badge tone={riskMeta[s.risk_level].tone} className="h-[20px] px-1.5">{riskMeta[s.risk_level].label} risk</Badge>}
                </p>
                <p className="truncate text-[12px] text-ink-3 tabular" title={sub}>{sub}</p>
              </div>
            </div>
          )
        },
      },
      { key: 'status', header: 'Durum', cell: (s) => <Badge tone={statusTone[s.status] ?? 'neutral'} dot>{s.status_label}</Badge> },
      {
        key: 'attendance',
        header: 'Devam oranı (30 gün)',
        sortKey: 'attendance',
        hideable: true,
        cell: (s) =>
          s.attendance_30 === null || s.attendance_30 === undefined ? (
            <span className="text-ink-3">—</span>
          ) : (
            <div className="flex items-center gap-2" title="Son 30 günün yoklaması">
              <span className="h-1.5 w-12 overflow-hidden rounded-full bg-surface-3">
                <span className={`block h-full rounded-full ${s.attendance_30 < 75 ? 'bg-danger' : s.attendance_30 < 90 ? 'bg-warning' : 'bg-success'}`} style={{ width: `${s.attendance_30}%` }} />
              </span>
              <span className={`tabular text-[13px] font-medium ${s.attendance_30 < 75 ? 'text-danger' : s.attendance_30 < 90 ? 'text-warning' : 'text-ink-2'}`}>%{s.attendance_30}</span>
            </div>
          ),
      },
      {
        key: 'last_exam', priority: 4,
        header: 'Son deneme neti',
        sortKey: 'last_exam',
        align: 'right',
        hideable: true,
        cell: (s) =>
          s.last_exam ? (
            <span className="tabular font-medium text-ink" title={date(s.last_exam.date)}>{Number(s.last_exam.net).toLocaleString('tr-TR', { minimumFractionDigits: 1, maximumFractionDigits: 2 })}</span>
          ) : (
            <span className="text-ink-3">—</span>
          ),
      },
      { key: 'class', header: 'Sınıf', hideable: true, defaultHidden: true, cell: (s) => (s.class_groups.length ? <span className="text-ink-2">{s.class_groups.map((g) => g.name).join(', ')}</span> : <span className="text-ink-3">—</span>) },
      { key: 'program', header: 'Kayıtlı program', hideable: true, defaultHidden: true, cell: (s) => <span className="text-ink-2">{s.program ?? '—'}</span> },
      {
        key: 'guardian', priority: 2,
        header: 'Veli',
        hideable: true,
        cell: (s) =>
          s.guardian ? (
            <div className="min-w-0">
              <PersonText className="text-ink-2">{s.guardian.name}</PersonText>
              <p><PhoneText value={s.guardian.phone} muted /></p>
            </div>
          ) : (
            '—'
          ),
      },
      { key: 'phone', header: 'Öğrenci telefonu', hideable: true, defaultHidden: true, cell: (s) => <PhoneText value={s.phone} whatsapp /> },
      { key: 'grade', header: 'Sınıf seviyesi / alan', hideable: true, defaultHidden: true, cell: (s) => <span className="text-ink-2">{s.school_grade ?? '—'}{s.field ? ` · ${s.field}` : ''}</span> },
      { key: 'registered', header: 'Kayıt tarihi', sortKey: 'registered_on', hideable: true, defaultHidden: true, cell: (s) => <span className="tabular text-ink-2 whitespace-nowrap">{s.registered_on ? date(s.registered_on) : '—'}</span> },
      {
        key: 'tags',
        header: 'Etiketler',
        hideable: true,
        defaultHidden: true,
        cell: (s) => (
          <div className="flex flex-wrap gap-1 max-w-[220px]">
            {s.tags.filter((t) => t.name !== 'Demo').slice(0, 3).map((t) => (
              <Badge key={t.id} tone={(tagColor[t.color] as any) ?? 'neutral'}>{t.name}</Badge>
            ))}
          </div>
        ),
      },
      {
        key: 'risk',
        header: 'Risk düzeyi',
        sortKey: 'risk',
        hideable: true,
        defaultHidden: true,
        // Yalnız dikkat gerektiren risk rozetlenir; düşük risk sade metin (satırlar renk kalabalığına dönmesin)
        cell: (s) =>
          s.risk_level && s.risk_level !== 'low' ? (
            <Badge tone={riskMeta[s.risk_level].tone}>{riskMeta[s.risk_level].label}</Badge>
          ) : (
            <span className="text-[12.5px] text-ink-3">{s.risk_level ? 'Düşük' : '—'}</span>
          ),
      },
    ],
    [can],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Öğrenciler"
        description={data ? `${data.meta.total.toLocaleString('tr-TR')} öğrenci listeleniyor` : 'Öğrenci kayıtları ve 360° profiller'}
        actions={
          <>
            <ButtonLink to="/ogrenciler/liste-ciktisi" icon={<Printer className="size-4" />}>Liste çıktısı al</ButtonLink>
            {can('students.export') && (
              <Button icon={<Download className="size-4" />} onClick={() => api.download('/students/export', list.query, 'ogrenciler.xlsx').catch((e) => toast.error(e.message))}>
                Excel
              </Button>
            )}
            {can('students.create') && (
              <Button icon={<FileUp className="size-4" />} onClick={() => setParams((p) => { p.set('ice-aktar', '1'); return p })}>
                Excel'den içe aktar
              </Button>
            )}
            {can('students.create') && (
              <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>
                Yeni öğrenci
              </Button>
            )}
          </>
        }
      />

      <DataTable
        storageKey="students-v3"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/ogrenciler/${r.id}`)}
        selectable={can('students.bulk')}
        selected={selected}
        onSelectedChange={setSelected}
        bulkActions={
          <>
            {can('messages.send') && <Button size="sm" variant="soft" onClick={() => setMessageOpen(true)}>Mesaj gönder</Button>}
            <Button size="sm" icon={<TagIcon className="size-3.5" />} onClick={() => setBulk('tag_add')}>Etiket ekle</Button>
            <Button size="sm" onClick={() => setBulk('class_change')}>Sınıf değiştir</Button>
            <Button size="sm" onClick={() => setBulk('status')}>Durum değiştir</Button>
          </>
        }
        toolbar={
          <div className="flex w-full flex-col gap-2.5">
            <div className="flex flex-wrap items-center gap-2">
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Ad, öğrenci no, telefon ya da TC ile ara"
                leading={<Search />}
                trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
                className="w-full sm:w-[320px]"
              />
              <Segmented
                size="sm"
                value={list.filters.status ?? 'current'}
                onChange={(status) => list.update({ filters: { status } })}
                options={[
                  { value: 'current', label: `Kayıtlı ${currentCount || ''}` },
                  { value: 'active', label: `Aktif ${counts.active ?? ''}` },
                  { value: 'frozen', label: `Dondurulan ${counts.frozen ?? ''}` },
                  { value: 'withdrawn', label: `Ayrılan ${counts.withdrawn ?? ''}` },
                  { value: 'graduated', label: `Mezun ${counts.graduated ?? ''}` },
                  { value: 'all', label: 'Tümü' },
                ]}
                className="max-w-full"
              />
              <Button size="sm" variant={activeFilterCount ? 'soft' : 'ghost'} icon={<Filter className="size-4" />} onClick={() => setShowFilters((v) => !v)}>
                Filtre{activeFilterCount ? ` (${activeFilterCount})` : ''}
              </Button>
              {activeFilterCount > 0 && (
                <Button size="sm" variant="ghost" onClick={() => list.update({ filters: { class_group_id: null, program_id: null, tag_id: null, risk: null, overdue: null, inside: null, field: null } })}>
                  Temizle
                </Button>
              )}
            </div>
            {showFilters && (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-2 animate-fade-in">
                <Select value={list.filters.class_group_id ?? ''} onChange={(e) => list.update({ filters: { class_group_id: e.target.value } })} placeholder="Tüm sınıflar" options={(options.data?.class_groups ?? []).map((g) => ({ value: g.id, label: g.name }))} />
                <Select value={list.filters.program_id ?? ''} onChange={(e) => list.update({ filters: { program_id: e.target.value } })} placeholder="Tüm programlar" options={(options.data?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
                <Select value={list.filters.tag_id ?? ''} onChange={(e) => list.update({ filters: { tag_id: e.target.value } })} placeholder="Tüm etiketler" options={(options.data?.tags ?? []).map((t) => ({ value: t.id, label: t.name }))} />
                <Select value={list.filters.risk ?? ''} onChange={(e) => list.update({ filters: { risk: e.target.value } })} placeholder="Tüm risk düzeyleri" options={[{ value: 'high', label: 'Yüksek risk' }, { value: 'medium', label: 'Orta risk' }, { value: 'low', label: 'Düşük risk' }]} />
                <div className="flex items-center gap-2 px-1">
                  <Switch checked={list.filters.inside === '1'} onChange={(v) => list.update({ filters: { inside: v ? '1' : null } })} label={<span className="inline-flex items-center gap-1.5"><Radio className="size-3.5" /> Şu an kurumda</span>} />
                </div>
              </div>
            )}
          </div>
        }
        empty={
          <EmptyState
            icon={<Users />}
            title={list.q || activeFilterCount ? 'Aramanıza uyan öğrenci yok' : 'Henüz öğrenci kaydı yok'}
            description={list.q || activeFilterCount ? 'Filtreleri değiştirerek tekrar deneyin.' : 'İlk öğrencinizi ekleyerek başlayın.'}
            action={can('students.create') && !list.q ? <div className="flex flex-wrap justify-center gap-2"><Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Yeni öğrenci</Button><Button icon={<FileUp className="size-4" />} onClick={() => setParams((p) => { p.set('ice-aktar', '1'); return p })}>Excel'den içe aktar</Button></div> : undefined}
          />
        }
      />

      {can('messages.send') && (
        <SendMessageDialog open={messageOpen} onClose={() => setMessageOpen(false)} studentIds={[...selected].map(Number)} studentsLabel={`${selected.size} öğrenci`} />
      )}

      {can('students.create') && <ExcelImportDialog entity="students" open={params.get('ice-aktar') === '1'} onClose={() => setParams((p) => { p.delete('ice-aktar'); return p })} />}

      <StudentFormDrawer
        open={formOpen}
        options={options.data}
        onClose={() => setParams((p) => { p.delete('yeni'); return p })}
        onSaved={(id) => {
          qc.invalidateQueries({ queryKey: ['students'] })
          navigate(`/ogrenciler/${id}`)
        }}
      />

      <Modal
        open={bulk !== null}
        onClose={() => setBulk(null)}
        size="sm"
        title={bulk === 'tag_add' ? 'Etiket ekle' : bulk === 'class_change' ? 'Sınıf değiştir' : 'Durum değiştir'}
        description={`${selected.size} öğrenci seçili`}
        footer={
          <>
            <Button variant="ghost" onClick={() => setBulk(null)}>Vazgeç</Button>
            <Button variant="primary" disabled={!bulkValue} loading={bulkMutation.isPending} onClick={() => bulkMutation.mutate()}>Uygula</Button>
          </>
        }
      >
        {bulk === 'tag_add' && <Select value={bulkValue} onChange={(e) => setBulkValue(e.target.value)} placeholder="Etiket seçin" options={(options.data?.tags ?? []).map((t) => ({ value: t.id, label: t.name }))} />}
        {bulk === 'class_change' && (
          <>
            <Select value={bulkValue} onChange={(e) => setBulkValue(e.target.value)} placeholder="Yeni sınıf" options={(options.data?.class_groups ?? []).map((g) => ({ value: g.id, label: g.name }))} />
            <p className="mt-2 text-[12.5px] text-ink-3">Öğrencinin aynı dönemdeki önceki sınıf üyeliği bugünkü tarihle kapatılır; geçmiş korunur.</p>
          </>
        )}
        {bulk === 'status' && (
          <Select value={bulkValue} onChange={(e) => setBulkValue(e.target.value)} placeholder="Yeni durum" options={Object.entries(options.data?.statuses ?? {}).map(([value, label]) => ({ value, label }))} />
        )}
      </Modal>
    </div>
  )
}

export { StatusDot }
