import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Copy, Lock, Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Badge, EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input } from '@/components/ui/form'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import type { PermissionCatalog, RoleRow } from './types'

type RolesResponse = { data: RoleRow[]; catalog: PermissionCatalog }

export default function RolesMatrix() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['roles'], queryFn: () => api.get<RolesResponse>('/roles') })
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [permissions, setPermissions] = useState<Set<string>>(new Set())
  const [createOpen, setCreateOpen] = useState(false)
  const [copyRole, setCopyRole] = useState<RoleRow | null>(null)
  const [deleteRole, setDeleteRole] = useState<RoleRow | null>(null)
  const [newName, setNewName] = useState('')

  const selected = data?.data.find((r) => r.id === selectedId) ?? data?.data[0]

  useEffect(() => {
    if (selected) setPermissions(new Set(selected.permissions))
  }, [selected?.id])

  const save = useMutation({
    mutationFn: () => api.put(`/roles/${selected!.id}`, { permissions: [...permissions] }),
    onSuccess: () => { toast.success('Yetkiler güncellendi.'); qc.invalidateQueries({ queryKey: ['roles'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Güncellenemedi.'),
  })

  const create = useMutation({
    mutationFn: () => api.post<RoleRow>('/roles', { name: newName }),
    onSuccess: (r) => { toast.success('Rol oluşturuldu.'); qc.invalidateQueries({ queryKey: ['roles'] }); setSelectedId(r.id); setCreateOpen(false); setNewName('') },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Oluşturulamadı.'),
  })

  const copy = useMutation({
    mutationFn: () => api.post<RoleRow>(`/roles/${copyRole!.id}/copy`, { name: newName }),
    onSuccess: (r) => { toast.success('Rol kopyalandı.'); qc.invalidateQueries({ queryKey: ['roles'] }); setSelectedId(r.id); setCopyRole(null); setNewName('') },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kopyalanamadı.'),
  })

  const remove = useMutation({
    mutationFn: () => api.delete(`/roles/${deleteRole!.id}`),
    onSuccess: () => { toast.success('Rol silindi.'); qc.invalidateQueries({ queryKey: ['roles'] }); setSelectedId(null); setDeleteRole(null) },
    onError: (e) => { toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'); setDeleteRole(null) },
  })

  const toggle = (key: string) => setPermissions((s) => { const n = new Set(s); n.has(key) ? n.delete(key) : n.add(key); return n })
  const groupAllOn = (items: Record<string, string>) => Object.keys(items).every((k) => permissions.has(k))
  const toggleGroup = (items: Record<string, string>) => {
    const allOn = groupAllOn(items)
    setPermissions((s) => {
      const n = new Set(s)
      Object.keys(items).forEach((k) => (allOn ? n.delete(k) : n.add(k)))
      return n
    })
  }

  if (isLoading || !data) return null

  return (
    <div className="animate-fade-in">
      <PageHeader title="Roller ve yetkiler" description="Rol bazlı yetki matrisi" actions={<Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setCreateOpen(true)}>Yeni rol</Button>} />

      <div className="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-4">
        <Panel bodyClassName="p-1.5" flush>
          <div className="flex flex-col">
            {data.data.map((r) => (
              <button
                key={r.id}
                onClick={() => setSelectedId(r.id)}
                className={`flex items-center justify-between rounded-[var(--radius-md)] px-3 py-2.5 text-left text-[13.5px] transition-colors ${selected?.id === r.id ? 'bg-primary-soft text-primary-ink' : 'text-ink-2 hover:bg-surface-2'}`}
              >
                <span className="flex items-center gap-1.5 min-w-0 truncate">
                  {r.is_protected && <Lock className="size-3.5 shrink-0" />}
                  {r.label}
                </span>
                <Badge tone="neutral">{r.user_count}</Badge>
              </button>
            ))}
          </div>
        </Panel>

        {selected && (
          <Panel
            title={selected.label}
            description={selected.is_protected ? 'Sistem rolü — düzenlenemez veya silinemez' : `${selected.user_count} kullanıcı`}
            actions={
              !selected.is_protected && (
                <>
                  <Button size="sm" icon={<Copy className="size-3.5" />} onClick={() => { setCopyRole(selected); setNewName(`${selected.name}-kopya`) }}>Kopyala</Button>
                  <Button size="sm" variant="danger-soft" icon={<Trash2 className="size-3.5" />} onClick={() => setDeleteRole(selected)}>Sil</Button>
                </>
              )
            }
          >
            {selected.is_protected ? (
              <EmptyState icon={<Lock />} title="Sistem Yöneticisi tüm yetkilere sahiptir" description="Bu rol her zaman tüm ekranlara erişebilir; değiştirilemez." />
            ) : (
              <>
                <div className="flex flex-col gap-5">
                  {Object.entries(data.catalog).map(([key, group]) => (
                    <div key={key}>
                      <div className="mb-2 flex items-center justify-between">
                        <h4 className="text-[13px] font-semibold text-ink">{group.label}</h4>
                        <button className="text-[12px] text-primary hover:underline" onClick={() => toggleGroup(group.items)}>
                          {groupAllOn(group.items) ? 'Tümünü kaldır' : 'Tümünü seç'}
                        </button>
                      </div>
                      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-2">
                        {Object.entries(group.items).map(([permKey, label]) => (
                          <Checkbox key={permKey} checked={permissions.has(permKey)} onChange={() => toggle(permKey)} label={label} />
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
                <div className="mt-5 flex justify-end"><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Yetkileri kaydet</Button></div>
              </>
            )}
          </Panel>
        )}
      </div>

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Yeni rol" size="sm" footer={<><Button variant="ghost" onClick={() => setCreateOpen(false)}>Vazgeç</Button><Button variant="primary" loading={create.isPending} onClick={() => create.mutate()}>Oluştur</Button></>}>
        <Field label="Rol adı" required><Input value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="Örn. Nöbetçi Personel" autoFocus /></Field>
      </Modal>

      <Modal open={!!copyRole} onClose={() => setCopyRole(null)} title={`"${copyRole?.label}" rolünü kopyala`} size="sm" footer={<><Button variant="ghost" onClick={() => setCopyRole(null)}>Vazgeç</Button><Button variant="primary" loading={copy.isPending} onClick={() => copy.mutate()}>Kopyala</Button></>}>
        <Field label="Yeni rol adı" required><Input value={newName} onChange={(e) => setNewName(e.target.value)} autoFocus /></Field>
      </Modal>

      <ConfirmDialog open={!!deleteRole} onClose={() => setDeleteRole(null)} onConfirm={() => remove.mutate()} title="Rolü sil" description={`"${deleteRole?.label}" rolü silinecek. Bu role atanmış kullanıcı varsa işlem engellenir.`} danger confirmLabel="Sil" loading={remove.isPending} />
    </div>
  )
}
