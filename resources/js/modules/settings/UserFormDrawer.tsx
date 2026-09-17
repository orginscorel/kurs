import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, ApiError } from '@/lib/api'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/form'
import { Alert, Badge } from '@/components/ui/feedback'
import type { AdminUserRow, UserOptions } from './types'

type Props = { open: boolean; onClose: () => void; onSaved: () => void; user?: AdminUserRow }

export function UserFormDrawer({ open, onClose, onSaved, user }: Props) {
  const editing = !!user
  const [form, setForm] = useState<Record<string, any>>({})
  const [roles, setRoles] = useState<string[]>([])
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [tempPassword, setTempPassword] = useState<string | null>(null)

  const options = useQuery({ queryKey: ['admin-users', 'options'], queryFn: () => api.get<UserOptions>('/admin-users/options'), staleTime: 60_000, enabled: open })

  useEffect(() => {
    if (!open) return
    setErrors({})
    setTempPassword(null)
    if (user) {
      setForm({ name: user.name, username: user.username, phone: user.phone ?? '', email: user.email ?? '', user_type: user.user_type, branch_id: user.branch?.id ?? '' })
      setRoles(user.roles)
    } else {
      setForm({ user_type: 'staff' })
      setRoles([])
    }
  }, [open, user])

  const set = (key: string, value: unknown) => setForm((f) => ({ ...f, [key]: value }))
  const err = (key: string) => errors[key]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, roles, branch_id: form.branch_id || null }
      return editing
        ? api.put<{ user: AdminUserRow }>(`/admin-users/${user!.id}`, payload)
        : api.post<{ user: AdminUserRow; temp_password: string | null }>('/admin-users', payload)
    },
    onSuccess: (res: any) => {
      if (res.temp_password) { setTempPassword(res.temp_password); return }
      toast.success(editing ? 'Kullanıcı güncellendi.' : 'Kullanıcı oluşturuldu.')
      onClose()
      onSaved()
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  if (tempPassword) {
    const finish = () => { onClose(); onSaved() }
    return (
      <Drawer open={open} onClose={finish} width={440} title="Kullanıcı oluşturuldu">
        <Alert tone="warning" title="Bu şifre yalnızca bir kez gösterilir">
          Kullanıcı adı: <strong>{form.username}</strong>
          <div className="mt-2 rounded-[var(--radius-md)] bg-surface-2 px-3 py-2 font-mono text-[15px] tracking-wide">{tempPassword}</div>
          <p className="mt-2 text-[12.5px] text-ink-3">Kullanıcı ilk girişte bu şifreyi değiştirmek zorunda kalacak.</p>
        </Alert>
        <div className="mt-4 flex justify-end"><Button variant="primary" onClick={finish}>Tamam</Button></div>
      </Drawer>
    )
  }

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={520}
      title={editing ? 'Kullanıcıyı düzenle' : 'Yeni kullanıcı'}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{editing ? 'Kaydet' : 'Kullanıcı oluştur'}</Button></>}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Ad soyad" required error={err('name')} className="sm:col-span-2"><Input value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} autoFocus /></Field>
        <Field label="Kullanıcı adı" required hint="Girişte kullanılır" error={err('username')}><Input value={form.username ?? ''} onChange={(e) => set('username', e.target.value)} autoCapitalize="none" autoComplete="off" /></Field>
         <Field label="E-posta" optional error={err('email')}><Input type="email" inputMode="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} /></Field>
        <Field label="Telefon" optional error={err('phone')}><Input value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} inputMode="tel" placeholder="05xx xxx xx xx" /></Field>
        <Field label="Hesap türü" required error={err('user_type')}><Select value={form.user_type ?? 'staff'} onChange={(e) => set('user_type', e.target.value)} options={Object.entries(options.data?.user_types ?? {}).map(([value, label]) => ({ value, label }))} /></Field>
        <Field label="Şube" optional error={err('branch_id')} className="sm:col-span-2"><Select value={form.branch_id ?? ''} onChange={(e) => set('branch_id', e.target.value)} placeholder="Atanmadı" options={(options.data?.branches ?? []).map((b) => ({ value: b.id, label: b.name }))} /></Field>
      </div>

      <div className="mt-5">
        <h3 className="mb-1 text-[13.5px] font-semibold text-ink">Roller <span className="font-normal text-ink-3">(yetkileri belirler; birden fazla seçilebilir)</span></h3>
        {err('roles') && <p className="mb-2 text-xs text-danger">{err('roles')}</p>}
        <div className="flex flex-wrap gap-2">
          {(options.data?.roles ?? []).map((r) => {
            const on = roles.includes(r)
            return (
              <button
                key={r}
                type="button"
                onClick={() => setRoles((list) => (on ? list.filter((x) => x !== r) : [...list, r]))}
                className={on ? 'h-7 rounded-full px-3 text-[12.5px] font-medium bg-primary-soft text-primary-ink ring-1 ring-primary/20' : 'h-7 rounded-full px-3 text-[12.5px] text-ink-2 ring-1 ring-line hover:bg-surface-2'}
              >
                {options.data?.role_labels?.[r] ?? r}
              </button>
            )
          })}
        </div>
        {roles.length === 0 && <p className="mt-2 text-[12px] text-ink-3">Rol seçilmezse kullanıcının hiçbir yetkisi olmaz.</p>}
      </div>

      {editing && user?.must_change_password && (
        <div className="mt-4"><Badge tone="warning">Kullanıcı ilk girişte şifre değiştirmeyi bekliyor</Badge></div>
      )}
    </Drawer>
  )
}
