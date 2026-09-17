import { useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, PenLine, Search, Trophy, X } from 'lucide-react'
import { api } from '@/lib/api'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { net, type ResultRow, type ResultsMeta } from './types'
import { Dyb } from './shared'
import { ResultDrawer } from './ResultDrawer'
import { ManualEntryModal } from './ManualEntryModal'

type Response = { data: ResultRow[]; meta: ResultsMeta }

/** Sınav sonuç tablosu: D/Y/B, ders netleri, net, puan, sıralar; sınıf filtresi; XLSX. */
export function ResultsTable({ examId, storageKey = 'exam-results' }: { examId: number; storageKey?: string }) {
  const can = useCan()
  const qc = useQueryClient()
  const list = useListState({ sort: 'institution_rank', per_page: 50 })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [openId, setOpenId] = useState<number | null>(null)
  const [manualOpen, setManualOpen] = useState(false)

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['exam', examId, 'results', list.query],
    queryFn: () => api.get<Response>(`/exams/${examId}/results`, list.query),
    placeholderData: keepPreviousData,
  })
  const sections = data?.meta.sections ?? []
  const status = data?.meta.exam.status

  const columns = useMemo<Column<ResultRow>[]>(
    () => [
      { key: 'rank', header: 'Kurum sırası', mobileLabel: 'Kurum sırası', sortKey: 'institution_rank', align: 'center', width: 72, cell: (r) => <RankPill rank={r.institution_rank} /> },
      {
        key: 'student',
        header: 'Öğrenci',
        sortKey: 'name',
        cell: (r) => (
          <div className="min-w-[180px]">
            <p className="font-medium text-ink truncate">{r.student_name}</p>
            <p className="text-[12px] text-ink-3 tabular">Öğrenci no: {r.student_no}{r.class_group ? ` · Sınıf: ${r.class_group}` : ''}</p>
          </div>
        ),
      },
      { key: 'booklet', header: 'Kitapçık', align: 'center', hideable: true, cell: (r) => <span className="text-ink-2">{r.booklet}</span> },
      ...sections.map<Column<ResultRow>>((s) => ({
        key: `sec-${s.code}`,
        header: <span title={`${s.name} neti (doğru/yanlış/boş)`}>{s.name}</span>,
        mobileLabel: `${s.name} neti`,
        align: 'right',
        hideable: true,
        cell: (r) => {
          const v = r.sections[s.code]
          return v ? (
            <div className="leading-tight">
              <p className="tabular font-medium">{net(v.net)}</p>
              <Dyb correct={v.correct} wrong={v.wrong} blank={v.blank} />
            </div>
          ) : <span className="text-ink-3">—</span>
        },
      })),
      { key: 'dyb', header: 'Doğru / Yanlış / Boş', align: 'right', hideable: true, cell: (r) => <Dyb correct={r.correct} wrong={r.wrong} blank={r.blank} /> },
      { key: 'net', header: 'Toplam net', sortKey: 'net', align: 'right', cell: (r) => <span className="tabular font-semibold text-ink">{net(r.net)}</span> },
      { key: 'score', header: 'Puan', sortKey: 'score', align: 'right', cell: (r) => <span className="tabular">{r.score !== null ? net(r.score) : '—'}</span> },
      { key: 'class_rank', header: 'Sınıf sırası', sortKey: 'class_rank', align: 'right', hideable: true, cell: (r) => <span className="tabular text-ink-2">{r.class_rank ?? '—'}</span> },
      { key: 'national_rank', header: 'Türkiye sırası', sortKey: 'national_rank', align: 'right', hideable: true, defaultHidden: data?.meta.exam.scope !== 'national', cell: (r) => <span className="tabular text-ink-2">{r.national_rank ? r.national_rank.toLocaleString('tr-TR') : '—'}</span> },
    ],
    [sections, data?.meta.exam.scope],
  )

  const summary = data?.meta.summary
  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['exam', examId] })
    qc.invalidateQueries({ queryKey: ['exams'] })
  }

  return (
    <>
      <DataTable
        storageKey={storageKey}
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => setOpenId(r.id)}
        dense
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Öğrenci adı ya da no"
              leading={<Search />}
              trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
              className="w-full sm:w-[240px]"
            />
            <Select value={list.filters.class_group_id ?? ''} onChange={(e) => list.update({ filters: { class_group_id: e.target.value } })} placeholder="Tüm sınıflar" aria-label="Sınıf" className="w-full sm:w-[170px]" options={(data?.meta.class_groups ?? []).map((g) => ({ value: g.id, label: `${g.name} (${g.n})` }))} />
            {summary && summary.participants > 0 && (
              <span className="hidden md:inline-flex items-center gap-2 text-[12.5px] text-ink-3 ml-1">
                <Badge tone="neutral">{summary.participants} öğrenci</Badge>
                <span>Ortalama net: <b className="text-ink tabular">{net(summary.avg_net)}</b></span>
                <span>· En yüksek net: <b className="text-ink tabular">{net(summary.max_net)}</b></span>
              </span>
            )}
            <div className="ml-auto flex items-center gap-1.5">
              {can('exams.import') && status !== 'draft' && (
                <Button size="sm" icon={<PenLine className="size-3.5" />} onClick={() => setManualOpen(true)}>Elle sonuç gir</Button>
              )}
              <Button size="sm" icon={<Download className="size-3.5" />} onClick={() => api.download(`/exams/${examId}/results/export`, { class_group_id: list.filters.class_group_id, q: list.q }, 'sonuclar.xlsx').catch((e) => toast.error(e.message))}>
                Excel
              </Button>
            </div>
          </div>
        }
        empty={
          <EmptyState
            compact
            icon={<Trophy />}
            title={list.q || list.filters.class_group_id ? 'Filtreye uyan sonuç yok' : 'Henüz sonuç yok'}
            description={list.q || list.filters.class_group_id ? 'Filtreyi değiştirin.' : status === 'draft' ? 'Önce cevap anahtarını girin, ardından optik okuma dosyasını içe aktarın.' : 'Optik okuma dosyasını içe aktarın ya da elle sonuç girin.'}
          />
        }
      />

      <ResultDrawer examId={examId} resultId={openId} onClose={() => setOpenId(null)} onChanged={invalidate} />
      <ManualEntryModal open={manualOpen} examId={examId} sections={sections} booklets={data?.meta.exam.booklets ?? ['A']} onClose={() => setManualOpen(false)} onSaved={invalidate} />
    </>
  )
}

export function RankPill({ rank }: { rank: number | null }) {
  if (!rank) return <span className="text-ink-3">—</span>
  const tone = rank === 1 ? 'bg-warning-soft text-warning ring-warning/20' : rank <= 3 ? 'bg-primary-soft text-primary-ink ring-primary/15' : 'bg-surface-2 text-ink-2 ring-line'
  return <span className={`inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-[12px] font-semibold tabular ring-1 ring-inset ${tone}`}>{rank}</span>
}
