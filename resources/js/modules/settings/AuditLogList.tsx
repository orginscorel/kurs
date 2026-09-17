import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ClipboardList, Download, ExternalLink, Search, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { Drawer } from '@/components/ui/overlay'
import type { AuditLogDetail, AuditLogRow } from './types'

type Options = { actions: string[]; subject_types: string[]; subject_type_labels?: Record<string, string>; users: { id: number; name: string; username: string }[] }

export default function AuditLogList() {
  const list = useListState({ sort: '-created_at' })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [detailId, setDetailId] = useState<number | null>(null)

  useMemo(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const options = useQuery({ queryKey: ['audit-logs', 'options'], queryFn: () => api.get<Options>('/audit-logs/options'), staleTime: 60_000 })
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['audit-logs', 'list', list.query],
    queryFn: () => api.get<Paginated<AuditLogRow>>('/audit-logs', list.query),
    placeholderData: keepPreviousData,
  })

  const columns = useMemo<Column<AuditLogRow>[]>(
    () => [
      { key: 'date', header: 'Zaman', sortKey: 'created_at', width: 160, cell: (a) => <span className="tabular text-ink-2">{dateTime(a.created_at)}</span> },
      { key: 'user', header: 'İşlemi yapan', cell: (a) => <span className="text-ink">{a.user?.name ?? 'Sistem'}</span> },
      { key: 'action', header: 'İşlem kodu', hideable: true, cell: (a) => <span className="text-ink-3 font-mono text-[12px]">{a.action}</span> },
      { key: 'description', header: 'Açıklama', cell: (a) => <span className="text-ink-2 line-clamp-1">{a.description}</span> },
      {
        key: 'subject',
        header: 'İlgili kayıt',
        hideable: true,
        cell: (a) => a.subject_label ? (
          a.subject_url ? (
            <Link to={a.subject_url} onClick={(e) => e.stopPropagation()} className="inline-flex items-center gap-1 whitespace-nowrap text-primary hover:underline">
              {a.subject_label}{a.subject_id ? ` #${a.subject_id}` : ''}<ExternalLink className="size-3" />
            </Link>
          ) : <span className="whitespace-nowrap text-ink-3">{a.subject_label}{a.subject_id ? ` #${a.subject_id}` : ''}</span>
        ) : <span className="text-ink-3">—</span>,
      },
      { key: 'ip', header: 'IP adresi', hideable: true, cell: (a) => <span className="text-ink-3 tabular">{a.ip_address ?? '—'}</span> },
    ],
    [],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Denetim kayıtları"
        description={data ? `${data.meta.total.toLocaleString('tr-TR')} kayıt` : 'Sistemdeki kritik işlemler (salt okunur)'}
        actions={<Button icon={<Download className="size-4" />} onClick={() => api.download('/audit-logs/export', list.query, 'denetim-kayitlari.xlsx').catch((e) => toast.error(e.message))}>Excel</Button>}
      />

      <DataTable
        storageKey="audit-logs"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(a) => setDetailId(a.id)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Açıklamada ara" leading={<Search />} className="w-full sm:w-[280px]" />
            <Select value={list.filters.user_id ?? ''} onChange={(e) => list.update({ filters: { user_id: e.target.value } })} placeholder="Tüm kullanıcılar" options={(options.data?.users ?? []).map((u) => ({ value: u.id, label: u.name }))} className="w-[170px]" />
            <Select value={list.filters.action ?? ''} onChange={(e) => list.update({ filters: { action: e.target.value } })} placeholder="Tüm eylemler" options={(options.data?.actions ?? []).map((a) => ({ value: a, label: a }))} className="w-[170px]" />
            <Select value={list.filters.subject_type ?? ''} onChange={(e) => list.update({ filters: { subject_type: e.target.value, subject_id: '' } })} placeholder="Tüm konu türleri" options={(options.data?.subject_types ?? []).map((s) => ({ value: s, label: options.data?.subject_type_labels?.[s] ?? s }))} className="w-[170px]" />
            {list.filters.subject_id && (
              <Button size="sm" variant="ghost" icon={<X className="size-3.5" />} onClick={() => list.update({ filters: { subject_id: '' } })}>Yalnız #{list.filters.subject_id}</Button>
            )}
            <div className="w-[160px]"><Input type="date" value={list.filters.from ?? ''} onChange={(e) => list.update({ filters: { from: e.target.value } })} /></div>
            <div className="w-[160px]"><Input type="date" value={list.filters.to ?? ''} onChange={(e) => list.update({ filters: { to: e.target.value } })} /></div>
          </div>
        }
        empty={<EmptyState icon={<ClipboardList />} title="Kayıt bulunamadı" />}
      />

      <AuditDetailDrawer id={detailId} onClose={() => setDetailId(null)} />
    </div>
  )
}

function AuditDetailDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { data } = useQuery({ queryKey: ['audit-logs', id], queryFn: () => api.get<AuditLogDetail>(`/audit-logs/${id}`), enabled: !!id })

  return (
    <Drawer open={!!id} onClose={onClose} width={520} title="Denetim kaydı" description={data?.description}>
      {data && (
        <div className="flex flex-col gap-4">
          <div className="text-[13px] text-ink-3 grid grid-cols-2 gap-2">
            <span>Tarih: <span className="text-ink">{dateTime(data.created_at)}</span></span>
            <span>Kullanıcı: <span className="text-ink">{data.user?.name ?? 'Sistem'}</span></span>
            <span>Eylem: <span className="text-ink font-mono text-[12px]">{data.action}</span></span>
            <span>IP: <span className="text-ink tabular">{data.ip_address ?? '—'}</span></span>
            {data.subject_label && (
              <span className="col-span-2">Konu:{' '}
                {data.subject_url
                  ? <Link to={data.subject_url} className="text-primary hover:underline">{data.subject_label}{data.subject_id ? ` #${data.subject_id}` : ''}</Link>
                  : <span className="text-ink">{data.subject_label}{data.subject_id ? ` #${data.subject_id}` : ''}</span>}
                {data.subject_type && data.subject_id && (
                  <Link to={`?subject_type=${encodeURIComponent(data.subject_type)}&subject_id=${data.subject_id}`} onClick={onClose} className="ml-2 text-[12px] text-ink-3 hover:underline">bu konunun tüm kayıtları</Link>
                )}
              </span>
            )}
          </div>
          {data.changes && (data.changes.before || data.changes.after) ? (
            <div>
              <h3 className="mb-2 text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">Önce / sonra</h3>
              <div className="overflow-x-auto scroll-thin rounded-[var(--radius-md)] ring-1 ring-line">
                <table className="tbl w-full text-[12.5px]">
                  <thead><tr className="bg-surface-2 text-left text-ink-3"><th className="px-3 py-2 font-medium text-left">Alan</th><th className="fill px-3 py-2 font-medium text-center">Önce</th><th className="fill px-3 py-2 font-medium text-center">Sonra</th></tr></thead>
                  <tbody>
                    {Array.from(new Set([...Object.keys(data.changes.before ?? {}), ...Object.keys(data.changes.after ?? {})])).map((key) => (
                      <tr key={key} className="border-t border-line">
                        <td className="px-3 py-2 text-ink-3 text-left">{key}</td>
                        <td className="fill break-words px-3 py-2 text-danger/80 text-center">{formatVal((data.changes!.before ?? {})[key])}</td>
                        <td className="fill break-words px-3 py-2 text-success text-center">{formatVal((data.changes!.after ?? {})[key])}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          ) : (
            <p className="text-[13px] text-ink-3">Bu kayıt için önce/sonra farkı yok.</p>
          )}
        </div>
      )}
    </Drawer>
  )
}

function formatVal(v: unknown): string {
  if (v === null || v === undefined) return '—'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}
