import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { ClipboardList, Plus, Search, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { date } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Segmented, Select } from '@/components/ui/form'
import { net, type ExamRow } from './types'
import { ExamStatusBadge, useExamOptions } from './shared'
import { ExamFormDrawer } from './ExamFormDrawer'

type ListResponse = Paginated<ExamRow> & { meta: { status_counts: Record<string, number> } }

export default function ExamList() {
  const can = useCan()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const list = useListState({ sort: '-exam_date', filters: { status: '' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const formOpen = params.get('yeni') === '1'
  const options = useExamOptions()

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['exams', 'list', list.query],
    queryFn: () => api.get<ListResponse>('/exams', list.query),
    placeholderData: keepPreviousData,
  })
  const counts = data?.meta.status_counts ?? {}
  const total = Object.values(counts).reduce((a, b) => a + b, 0)

  const columns = useMemo<Column<ExamRow>[]>(
    () => [
      {
        key: 'name',
        header: 'Deneme',
        sortKey: 'name',
        cell: (e) => (
          <div className="min-w-[220px]">
            <p className="font-medium text-ink truncate">{e.name}</p>
            <p className="text-[12.5px] text-ink-3">
              {e.publisher ? `Yayınevi: ${e.publisher}` : 'Kurum denemesi'} · {e.scope === 'national' ? 'Türkiye geneli' : 'Kurum içi'} · Kitapçık: {e.booklets.join(', ')}
            </p>
          </div>
        ),
      },
      { key: 'type', header: 'Sınav türü', cell: (e) => <Badge tone="primary">{e.type?.code.replace('_', ' ') ?? '—'}</Badge> },
      { key: 'date', header: 'Sınav tarihi', sortKey: 'exam_date', cell: (e) => <span className="tabular text-ink-2 whitespace-nowrap">{date(e.exam_date)}</span> },
      { key: 'term', header: 'Eğitim dönemi', hideable: true, defaultHidden: true, cell: (e) => <span className="text-ink-2 whitespace-nowrap">{e.term ?? '—'}</span> },
      {
        key: 'status',
        header: 'Durum',
        // İkinci satır sonuçların yayın tarihi
        cell: (e) => (
          <div>
            <ExamStatusBadge status={e.status} />
            {e.published_at && <p className="mt-0.5 text-[12px] text-ink-3 tabular whitespace-nowrap">Sonuç yayını: {date(e.published_at)}</p>}
          </div>
        ),
      },
      {
        key: 'key',
        header: 'Cevap anahtarı',
        hideable: true,
        cell: (e) =>
          e.question_total ? (
            <span className={e.key_count === e.question_total ? 'text-success tabular' : 'text-ink-3 tabular'}>
              {e.key_count}/{e.question_total} soru
            </span>
          ) : (
            <span className="text-ink-3">—</span>
          ),
      },
      {
        key: 'participants',
        header: 'Sonucu girilen',
        sortKey: 'participant_count',
        align: 'right',
        // Sonucu girilen / beklenen katılımcı (farklıysa ikinci satırda beklenen)
        cell: (e) => (
          <div>
            <p className="tabular">{e.result_count ?? e.participant_count}</p>
            {e.result_count !== null && e.participant_count > 0 && e.result_count !== e.participant_count && (
              <p className="text-[12px] text-ink-3 tabular whitespace-nowrap">Beklenen: {e.participant_count}</p>
            )}
          </div>
        ),
      },
      { key: 'avg', header: 'Ortalama net', sortKey: 'avg_net', align: 'right', cell: (e) => <span className="tabular font-medium">{e.avg_net !== null ? net(e.avg_net) : '—'}</span> },
      { key: 'max', header: 'En yüksek net', align: 'right', hideable: true, cell: (e) => <span className="tabular text-ink-2">{e.max_net !== null && e.max_net !== undefined ? net(e.max_net) : '—'}</span> },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Denemeler"
        description={data ? `${data.meta.total} deneme` : 'Deneme sınavları, cevap anahtarları ve sonuç yayını'}
        actions={
          can('exams.manage') && (
            <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>
              Deneme oluştur
            </Button>
          )
        }
      />

      <DataTable
        storageKey="exams"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/sinavlar/${r.id}`)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Deneme adı ya da yayınevi"
              leading={<Search />}
              trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
              className="w-full sm:w-[280px]"
            />
            <Segmented
              size="sm"
              value={list.filters.status ?? ''}
              onChange={(status) => list.update({ filters: { status } })}
              options={[
                { value: '', label: `Tümü ${total || ''}` },
                { value: 'results_published', label: `Yayımlanan ${counts.results_published ?? ''}` },
                { value: 'answer_key_ready', label: `Anahtarı hazır ${counts.answer_key_ready ?? ''}` },
                { value: 'draft', label: `Taslak ${counts.draft ?? ''}` },
                { value: 'upcoming', label: 'Yaklaşan' },
              ]}
              className="overflow-x-auto max-w-full"
            />
            <Select value={list.filters.exam_type_id ?? ''} onChange={(e) => list.update({ filters: { exam_type_id: e.target.value } })} placeholder="Tüm sınav türleri" aria-label="Sınav türü" className="w-full sm:w-[180px]" options={(options.data?.types ?? []).map((t) => ({ value: t.id, label: t.name }))} />
          </div>
        }
        empty={
          <EmptyState
            icon={<ClipboardList />}
            title={list.q || list.filters.status ? 'Aramanıza uyan deneme yok' : 'Henüz deneme oluşturulmadı'}
            description={list.q || list.filters.status ? 'Filtreleri değiştirerek tekrar deneyin.' : 'TYT, AYT ya da LGS şablonundan ilk denemenizi oluşturun.'}
            action={can('exams.manage') && !list.q ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setParams((p) => { p.set('yeni', '1'); return p })}>Deneme oluştur</Button> : undefined}
          />
        }
      />

      <ExamFormDrawer
        open={formOpen}
        options={options.data}
        onClose={() => setParams((p) => { p.delete('yeni'); return p })}
        onSaved={(id) => {
          qc.invalidateQueries({ queryKey: ['exams'] })
          navigate(`/sinavlar/${id}/cevap-anahtari`)
        }}
      />
    </div>
  )
}
