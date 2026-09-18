import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { KeyRound, Pencil, Plus, Power, Search, ShieldAlert, Users as UsersIcon } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { useAuth, useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Alert, Avatar, Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { ConfirmDialog, Drawer, Menu } from '@/components/ui/overlay'
import type { AdminUserDetail, AdminUserRow, UserOptions } from './types'
import { UserFormDrawer } from './UserFormDrawer'
import { MailText, PhoneText } from '@/components/ui/contact'
import { SessionList } from '@/modules/core/SessionList'

export default function UserList() {
  const can = useCan()
  const me = useAuth((s) => s.me)
  const qc = useQueryClient()
  const list = useListState({ sort: 'name', filters: { status: 'active' } })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<AdminUserRow | undefined>()
  const [detailUser, setDetailUser] = useState<AdminUserRow | null>(null)
  const [resetTarget, setResetTarget] = useState<AdminUserRow | null>(null)
  const [resetResult, setResetResult] = useState<string | null>(null)

  useMemo(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const options = useQuery({ queryKey: ['admin-users', 'options'], queryFn: () => api.get<UserOptions>('/admin-users/options'), staleTime: 60_000 })
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['admin-users', 'list', list.query],
    queryFn: () => api.get<Paginated<AdminUserRow>>('/admin-users', list.query),
    placeholderData: keepPreviousData,
  })

  const toggleActive = useMutation({
    mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => api.post(`/admin-users/${id}/active`, { is_active }),
    onSuccess: () => { toast.success('Kullanıcı durumu güncellendi.'); qc.invalidateQueries({ queryKey: ['admin-users'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Güncellenemedi.'),
  })

  const resetPassword = useMutation({
    mutationFn: (id: number) => api.post<{ temp_password: string }>(`/admin-users/${id}/reset-password`),
    onSuccess: (r) => { setResetResult(r.temp_password); setResetTarget(null) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Sıfırlanamadı.'),
  })

  const columns = useMemo<Column<AdminUserRow>[]>(
    () => [
      {
        key: 'name', header: 'Kullanıcı', sortKey: 'name',
        cell: (u) => (
          <div className="flex items-center gap-3 min-w-0">
            <Avatar name={u.name} size={32} />
            <div className="min-w-0">
              <p className="font-medium text-ink truncate">{u.name}</p>
              <p className="text-[12px] text-ink-3">Kullanıcı adı: {u.username}</p>
            </div>
          </div>
        ),
      },
      { key: 'type', priority: 3, header: 'Hesap türü', cell: (u) => <Badge>{u.user_type_label}</Badge> },
      { key: 'roles', priority: 3, header: 'Roller', hideable: true, cell: (u) => <div className="flex flex-wrap gap-1">{u.roles.length ? u.roles.map((r) => <Badge key={r}>{options.data?.role_labels?.[r] ?? r}</Badge>) : <span className="text-ink-3">—</span>}</div> },
      { key: 'contact', priority: 3, header: 'İletişim', hideable: true, cell: (u) => u.phone || u.email ? <div className="flex flex-col gap-0.5"><PhoneText value={u.phone} />{u.email && <MailText value={u.email} />}</div> : <span className="text-ink-3">—</span> },
      { key: 'status', header: 'Durum', cell: (u) => <Badge tone={u.is_active ? 'success' : 'neutral'} dot>{u.is_active ? 'Aktif' : 'Pasif'}</Badge> },
      { key: 'last_login', priority: 4, header: 'Son giriş zamanı', hideable: true, cell: (u) => <span className="text-ink-2">{u.last_login_at ? relative(u.last_login_at) : '—'}</span> },
      { key: 'last_ip', priority: 4, header: 'Son giriş IP adresi', hideable: true, cell: (u) => <span className="text-ink-2 tabular">{u.last_login_ip ?? '—'}</span> },
      {
        key: 'actions', header: '', align: 'right',
        cell: (u) =>
          can('users.manage') ? (
            <Menu
              trigger={<Button size="xs" variant="ghost" aria-label="İşlemler" title="İşlemler" onClick={(e: any) => e.stopPropagation()}>…</Button>}
              items={[
                { label: 'Düzenle', icon: <Pencil />, onClick: () => { setEditing(u); setFormOpen(true) } },
                { label: 'Giriş ve oturumlar', icon: <ShieldAlert />, onClick: () => setDetailUser(u) },
                { label: 'Şifreyi sıfırla', icon: <KeyRound />, onClick: () => setResetTarget(u) },
                'divider',
                {
                  label: u.is_active ? 'Pasife al' : 'Aktif et', icon: <Power />, danger: u.is_active,
                  disabled: u.is_active && u.id === me?.user.id,
                  onClick: () => toggleActive.mutate({ id: u.id, is_active: !u.is_active }),
                },
              ]}
            />
          ) : null,
      },
    ],
    [can, me, toggleActive, options.data],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Kullanıcılar"
        description={data ? `${data.meta.total.toLocaleString('tr-TR')} kullanıcı listeleniyor` : 'Sistem kullanıcıları ve erişimleri'}
        actions={can('users.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => { setEditing(undefined); setFormOpen(true) }}>Yeni kullanıcı</Button>}
      />

      <DataTable
        storageKey="admin-users"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(u) => setDetailUser(u)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ad, kullanıcı adı ya da e-posta" leading={<Search />} className="w-full sm:w-[300px]" />
            <Select value={list.filters.user_type ?? ''} onChange={(e) => list.update({ filters: { user_type: e.target.value } })} placeholder="Tüm türler" options={Object.entries(options.data?.user_types ?? {}).map(([value, label]) => ({ value, label }))} className="w-[160px]" />
            <Select value={list.filters.role ?? ''} onChange={(e) => list.update({ filters: { role: e.target.value } })} placeholder="Tüm roller" options={(options.data?.roles ?? []).map((r) => ({ value: r, label: r }))} className="w-[160px]" />
          </div>
        }
        empty={<EmptyState icon={<UsersIcon />} title="Kullanıcı bulunamadı" description="Öğretmen hesapları öğretmen kaydından, öğrenci hesapları öğrenci kaydından otomatik açılır." action={can('users.manage') ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => { setEditing(undefined); setFormOpen(true) }}>Yeni kullanıcı</Button> : undefined} />}
      />

      <UserFormDrawer open={formOpen} user={editing} onClose={() => setFormOpen(false)} onSaved={() => { qc.invalidateQueries({ queryKey: ['admin-users'] }); setFormOpen(false) }} />

      <UserDetailDrawer user={detailUser} onClose={() => setDetailUser(null)} />

      <ConfirmDialog
        open={!!resetTarget}
        onClose={() => setResetTarget(null)}
        title="Şifreyi sıfırla"
        description={resetTarget ? `${resetTarget.name} için yeni bir geçici şifre üretilecek; açık oturumları kapanacak.` : ''}
        confirmLabel="Sıfırla"
        loading={resetPassword.isPending}
        onConfirm={() => resetTarget && resetPassword.mutate(resetTarget.id)}
      />

      <ConfirmDialog open={!!resetResult} onClose={() => setResetResult(null)} onConfirm={() => setResetResult(null)} title="Yeni geçici şifre" confirmLabel="Tamam">
        {resetResult && (
          <Alert tone="warning" title="Bu şifre yalnızca bir kez gösterilir">
            <div className="mt-2 rounded-[var(--radius-md)] bg-surface-2 px-3 py-2 font-mono text-[15px] tracking-wide">{resetResult}</div>
          </Alert>
        )}
      </ConfirmDialog>
    </div>
  )
}

function UserDetailDrawer({ user, onClose }: { user: AdminUserRow | null; onClose: () => void }) {
  const qc = useQueryClient()
  const [confirmAll, setConfirmAll] = useState(false)
  const { data } = useQuery({ queryKey: ['admin-users', user?.id], queryFn: () => api.get<AdminUserDetail>(`/admin-users/${user!.id}`), enabled: !!user })
  const refresh = () => qc.invalidateQueries({ queryKey: ['admin-users', user?.id] })

  const revoke = useMutation({
    mutationFn: (id: string) => api.delete<{ message: string }>(`/admin-users/${user!.id}/sessions/${encodeURIComponent(id)}`),
    onSuccess: (r) => { toast.success(r?.message || 'Oturum kapatıldı.'); refresh() },
    onError: (e) => { toast.error(e instanceof ApiError ? e.firstError() : 'Oturum kapatılamadı.'); refresh() },
  })
  const revokeAll = useMutation({
    mutationFn: () => api.delete<{ message: string }>(`/admin-users/${user!.id}/sessions`),
    onSuccess: (r) => { toast.success(r?.message || 'Oturumlar kapatıldı.'); setConfirmAll(false); refresh() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Oturumlar kapatılamadı.'),
  })
  const open = (data?.sessions ?? []).filter((s) => !s.is_current && s.status !== 'closing')

  return (
    <Drawer open={!!user} onClose={onClose} width={520} title={user?.name} description="Giriş denemeleri ve oturumlar">
      {!data ? null : (
        <div className="flex flex-col gap-5">
          <div>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">Açık oturumlar ve cihazlar</h3>
              {open.length > 0 && (
                <Button size="xs" variant="danger-soft" onClick={() => setConfirmAll(true)}>Tümünü kapat</Button>
              )}
            </div>
            {data.sessions_notice && <Alert tone="info" className="mb-2">{data.sessions_notice}</Alert>}
            <div className="-mx-5 border-b border-line">
              <SessionList rows={data.sessions} pendingId={revoke.isPending ? revoke.variables : null} onRevoke={(s) => revoke.mutate(s.id)} />
            </div>
          </div>
          <div>
            <h3 className="mb-2 text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">Son giriş denemeleri</h3>
            {data.recent_logins.length === 0 ? <p className="text-[13px] text-ink-3">Kayıt yok.</p> : (
              <div className="flex flex-col divide-y divide-line">
                {data.recent_logins.map((l) => (
                  <div key={l.id} className="flex items-center justify-between py-2 text-[13px]">
                    <div>
                      <Badge tone={l.successful ? 'success' : 'danger'}>{l.successful ? 'Başarılı' : 'Başarısız'}</Badge>
                      <span className="ml-2 text-ink-3 tabular">{l.ip_address ?? '—'}</span>
                    </div>
                    <span className="text-ink-3">{dateTime(l.created_at)}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
      <ConfirmDialog
        open={confirmAll}
        onClose={() => setConfirmAll(false)}
        onConfirm={() => revokeAll.mutate()}
        loading={revokeAll.isPending}
        danger
        title="Tüm oturumlar kapatılsın mı?"
        confirmLabel="Hepsini kapat"
        description={`${user?.name ?? 'Kullanıcı'} için tarayıcı, Mac uygulaması ve mobil uygulama oturumlarının hepsi kapanır. Çevrimdışı Mac'teki oturum, cihaz bağlandığında kapanır.`}
      />
    </Drawer>
  )
}
