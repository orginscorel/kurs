import { useMemo, useState } from 'react'
import { MailText, PhoneText } from '@/components/ui/contact'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Pencil, Plus, Search, Trash2, UserCog } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { date, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Segmented } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import type { EmployeeRow } from './types'
import { EmployeeFormDrawer } from './EmployeeFormDrawer'

export default function EmployeeList() {
  const can = useCan()
  const qc = useQueryClient()
  const list = useListState({ sort: 'full_name', filters: { status: 'active' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<EmployeeRow | undefined>()
  const [deleting, setDeleting] = useState<EmployeeRow | null>(null)

  useMemo(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['employees', 'list', list.query],
    queryFn: () => api.get<Paginated<EmployeeRow>>('/employees', list.query),
    placeholderData: keepPreviousData,
  })

  const del = useMutation({ mutationFn: (id: number) => api.delete(`/employees/${id}`) })

  const columns = useMemo<Column<EmployeeRow>[]>(
    () => [
      {
        key: 'name',
        header: 'Ad Soyad',
        sortKey: 'full_name',
        cell: (e) => (
          <div className="min-w-0">
            <p className="font-medium text-ink truncate">{e.full_name}</p>
            {e.username && <p className="text-[12px] text-ink-3 truncate">Kullanıcı adı: {e.username}</p>}
          </div>
        ),
      },
      { key: 'position', header: 'Görevi', cell: (e) => <span className="text-ink-2">{e.position}</span> },
      { key: 'phone', header: 'Cep telefonu', hideable: true, cell: (e) => <PhoneText value={e.phone} /> },
      { key: 'email', header: 'E-posta', hideable: true, cell: (e) => <MailText value={e.email} /> },
      {
        key: 'hired_on',
        header: 'İşe başlama',
        sortKey: 'hired_on',
        hideable: true,
        // İkinci satır kıdem: yıl / ay
        cell: (e) => {
          if (!e.hired_on) return <span className="text-ink-3">—</span>
          const months = Math.max(0, Math.floor((Date.now() - new Date(e.hired_on).getTime()) / (30.44 * 86400000)))
          const tenure = months >= 12 ? `${Math.floor(months / 12)} yıl${months % 12 ? ` ${months % 12} ay` : ''}` : `${months} ay`
          return (
            <div>
              <p className="text-ink-2 tabular whitespace-nowrap">{date(e.hired_on)}</p>
              <p className="text-[12px] text-ink-3 whitespace-nowrap">Kıdem: {tenure}</p>
            </div>
          )
        },
      },
      {
        key: 'user',
        header: 'Sistem hesabı',
        cell: (e) =>
          e.has_user ? (
            <div>
              <Badge tone={e.user_active === false ? 'warning' : 'success'} dot>{e.user_active === false ? 'Pasif' : 'Var'}</Badge>
              <p className="mt-0.5 text-[12px] text-ink-3 whitespace-nowrap">{e.last_login_at ? `Son giriş: ${relative(e.last_login_at)}` : 'Hiç giriş yapmadı'}</p>
            </div>
          ) : (
            <Badge tone="neutral">Yok</Badge>
          ),
      },
      { key: 'status', header: 'Durum', cell: (e) => <Badge tone={e.is_active ? 'success' : 'neutral'} dot>{e.is_active ? 'Aktif' : 'Pasif'}</Badge> },
      ...(can('employees.manage')
        ? [{
            key: 'actions', header: '', align: 'right' as const,
            cell: (e: EmployeeRow) => (
              <div className="flex justify-end gap-1">
                <Button size="xs" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={(ev: any) => { ev.stopPropagation(); setEditing(e); setFormOpen(true) }} />
                <Button size="xs" variant="ghost" className="text-danger" icon={<Trash2 className="size-3.5" />} onClick={(ev: any) => { ev.stopPropagation(); setDeleting(e) }} />
              </div>
            ),
          }]
        : []),
    ],
    [can],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Personel"
        description={data ? `${data.meta.total.toLocaleString('tr-TR')} personel listeleniyor` : 'İdari personel kayıtları'}
        actions={can('employees.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => { setEditing(undefined); setFormOpen(true) }}>Yeni personel</Button>}
      />

      <DataTable
        storageKey="employees"
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
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ad ya da görev ile ara" leading={<Search />} className="w-full sm:w-[300px]" />
            <Segmented size="sm" value={list.filters.status ?? 'active'} onChange={(status) => list.update({ filters: { status } })} options={[{ value: 'active', label: 'Aktif' }, { value: 'inactive', label: 'Pasif' }, { value: 'all', label: 'Tümü' }]} />
          </div>
        }
        empty={<EmptyState icon={<UserCog />} title="Henüz personel kaydı yok" action={can('employees.manage') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => { setEditing(undefined); setFormOpen(true) }}>Yeni personel</Button> : undefined} />}
      />

      <EmployeeFormDrawer open={formOpen} employee={editing} onClose={() => setFormOpen(false)} onSaved={() => { qc.invalidateQueries({ queryKey: ['employees'] }); setFormOpen(false) }} />

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        title="Personeli sil"
        description={deleting ? `${deleting.full_name} kaydı silinecek.` : ''}
        danger
        confirmLabel="Sil"
        loading={del.isPending}
        onConfirm={() => deleting && del.mutate(deleting.id, { onSuccess: () => { toast.success('Personel silindi.'); qc.invalidateQueries({ queryKey: ['employees'] }); setDeleting(null) }, onError: (e: any) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.') })}
      />
    </div>
  )
}
