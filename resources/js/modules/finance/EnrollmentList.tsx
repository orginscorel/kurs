import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { AlertTriangle, FileCheck2, FileClock, Scale, Search, UserPlus, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { date, money, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState, ProgressBar } from '@/components/ui/feedback'
import { Tooltip } from '@/components/ui/overlay'
import { ButtonLink } from '@/components/ui/Button'
import { Input, Segmented, Select } from '@/components/ui/form'

export type EnrollmentRow = {
  id: number
  enrollment_no: string
  status: string
  status_label: string
  student: { id: number; full_name: string; student_no: string } | null
  program: string | null
  term: string | null
  enrolled_on: string
  net_price: string
  paid: string
  remaining: string
  overdue: string
  installment_count: number
  plan_matches: boolean
  contract: { contract_no: string; signed_at: string | null } | null
  class_group: string | null
  package: string | null
  guardian: { id: number; name: string } | null
  paid_count: number
  /** Ödenmemiş ilk taksitin vadesi; tümü ödendiyse null */
  next_due_date: string | null
}

export const enrollmentStatusTone: Record<string, 'success' | 'warning' | 'neutral' | 'danger' | 'info' | 'primary'> = { active: 'success', pending: 'warning', frozen: 'info', withdrawn: 'danger', completed: 'primary' }

type Options = { programs: { id: number; name: string }[]; terms: { id: number; name: string; is_current: boolean }[] }

export default function EnrollmentList() {
  const can = useCan()
  const navigate = useNavigate()
  const list = useListState({ sort: '-enrolled_on' })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const options = useQuery({ queryKey: ['finance', 'enrollment-options'], queryFn: () => api.get<Options>('/finance/enrollment-options'), staleTime: 5 * 60_000 })
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['finance', 'enrollments', list.query],
    queryFn: () => api.get<Paginated<EnrollmentRow>>('/finance/enrollments', list.query),
    placeholderData: keepPreviousData,
  })

  const columns = useMemo<Column<EnrollmentRow>[]>(
    () => [
      {
        key: 'student',
        header: 'Öğrenci',
        cell: (e) => (
          <div className="min-w-[170px]">
            <p className="font-medium text-ink">{e.student?.full_name}</p>
            <p className="text-[12px] text-ink-3 tabular">Kayıt no: {e.enrollment_no}</p>
          </div>
        ),
      },
      {
        key: 'program',
        header: 'Program / dönem',
        cell: (e) => (
          <div className="min-w-[140px]">
            <p className="text-ink-2">{e.program}</p>
            <p className="text-[12px] text-ink-3">{e.term} · Kayıt: {date(e.enrolled_on)}</p>
          </div>
        ),
      },
      {
        key: 'class_group',
        header: 'Sınıf',
        hideable: true,
        cell: (e) => (e.class_group ? <span className="text-ink-2 whitespace-nowrap">{e.class_group}</span> : <span className="text-ink-3">—</span>),
      },
      { key: 'package', header: 'Eğitim paketi', hideable: true, defaultHidden: true, cell: (e) => <span className="text-ink-2 truncate">{e.package ?? '—'}</span> },
      { key: 'status', header: 'Durum', cell: (e) => <Badge tone={enrollmentStatusTone[e.status] ?? 'neutral'} dot>{e.status_label}</Badge> },
      {
        key: 'progress',
        header: 'Ödeme durumu',
        cell: (e) => {
          const pct = Number(e.net_price) > 0 ? (Number(e.paid) / Number(e.net_price)) * 100 : 100
          return (
            <div className="w-[140px]">
              <ProgressBar value={pct} tone={Number(e.overdue) > 0 ? 'danger' : 'success'} />
              <p className="mt-1 text-[12px] text-ink-3 tabular">
                Ödenen {money(e.paid, { short: true })} / {money(e.net_price, { short: true })}
              </p>
            </div>
          )
        },
      },
      {
        key: 'installments',
        header: 'Ödenen taksit',
        align: 'right',
        hideable: true,
        // Ödenen / toplam taksit; ikinci satır sıradaki vade (geçmişse tehlike rengi)
        cell: (e) => {
          const overdueNext = !!e.next_due_date && e.next_due_date < todayISO()
          return (
            <div>
              <p className="tabular text-ink whitespace-nowrap">{e.paid_count} / {e.installment_count} taksit</p>
              <p className={cn('text-[12px] tabular whitespace-nowrap', overdueNext ? 'text-danger' : 'text-ink-3')}>
                {e.next_due_date ? `Sıradaki vade: ${date(e.next_due_date)}` : e.installment_count ? 'Tamamlandı' : 'Plan yok'}
              </p>
            </div>
          )
        },
      },
      {
        key: 'guardian',
        header: 'Ödeme sorumlusu veli',
        hideable: true,
        defaultHidden: true,
        cell: (e) => (e.guardian ? <span className="text-ink-2 truncate">{e.guardian.name}</span> : <span className="text-ink-3">—</span>),
      },
      {
        key: 'contract',
        header: 'Sözleşme',
        hideable: true,
        cell: (e) =>
          e.contract?.signed_at ? (
            <Badge tone="success"><FileCheck2 className="size-3" /> İmzalı</Badge>
          ) : e.contract ? (
            <Badge tone="warning"><FileClock className="size-3" /> Taslak</Badge>
          ) : (
            <span className="text-ink-3 text-[12.5px]">Hazırlanmadı</span>
          ),
      },
      { key: 'net', header: 'Net tutar', sortKey: 'net_price', align: 'right', hideable: true, cell: (e) => <span className="text-ink-2">{money(e.net_price)}</span> },
      {
        key: 'remaining',
        header: 'Kalan borç',
        align: 'right',
        cell: (e) => (
          <div>
            <p className="font-semibold">{money(e.remaining)}</p>
            {Number(e.overdue) > 0 && <p className="text-[12px] text-danger">{money(e.overdue, { short: true })} gecikmiş</p>}
            {!e.plan_matches && (
              <Tooltip content="Plan toplamı net bedelle eşleşmiyor">
                <span className={cn('inline-flex items-center gap-1 text-[12px] text-warning')}><AlertTriangle className="size-3" /> Plan farkı</span>
              </Tooltip>
            )}
          </div>
        ),
      },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Kayıtlar ve ödeme planları"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Kayıt ve planlar' }]}
        description="Dönem/program kayıtları, net bedel, plan ve sözleşme durumu"
        actions={can('enrollments.create') && <ButtonLink to="/finans/kayitlar/yeni" variant="primary" icon={<UserPlus className="size-4" />}>Yeni kayıt</ButtonLink>}
      />

      <DataTable
        storageKey="finance-enrollments"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/finans/kayitlar/${r.id}`)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Öğrenci adı ya da kayıt no"
              leading={<Search />}
              trailing={search ? <button onClick={() => setSearch('')} className="grid size-6 place-items-center text-ink-3 hover:text-ink"><X className="size-3.5" /></button> : undefined}
              className="w-full sm:w-[260px]"
            />
            <Segmented
              size="sm"
              value={list.filters.status ?? 'all'}
              onChange={(v) => list.update({ filters: { status: v === 'all' ? null : v } })}
              options={[{ value: 'all', label: 'Tümü' }, { value: 'active', label: 'Aktif' }, { value: 'frozen', label: 'Donduruldu' }, { value: 'withdrawn', label: 'Ayrıldı' }]}
            />
            <Select className="w-full sm:w-44" value={list.filters.program_id ?? ''} onChange={(e) => list.update({ filters: { program_id: e.target.value } })} placeholder="Tüm programlar" options={(options.data?.programs ?? []).map((p) => ({ value: p.id, label: p.name }))} />
            <Select className="w-full sm:w-36" value={list.filters.term_id ?? ''} onChange={(e) => list.update({ filters: { term_id: e.target.value } })} placeholder="Tüm dönemler" options={(options.data?.terms ?? []).map((t) => ({ value: t.id, label: t.name }))} />
            <Select className="w-full sm:w-40" value={list.filters.contract ?? ''} onChange={(e) => list.update({ filters: { contract: e.target.value } })} placeholder="Sözleşme: tümü" options={[{ value: 'unsigned', label: 'İmzasız olanlar' }]} />
          </div>
        }
        empty={
          <EmptyState
            icon={<Scale />}
            title={list.q ? 'Aramanıza uyan kayıt yok' : 'Henüz kayıt yok'}
            action={can('enrollments.create') ? <ButtonLink to="/finans/kayitlar/yeni" variant="primary" icon={<UserPlus className="size-4" />}>Yeni kayıt</ButtonLink> : undefined}
          />
        }
      />
    </div>
  )
}
