import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, ApiError } from '@/lib/api'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Switch } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'
import type { EmployeeRow } from './types'

type Props = { open: boolean; onClose: () => void; onSaved: () => void; employee?: EmployeeRow }

export function EmployeeFormDrawer({ open, onClose, onSaved, employee }: Props) {
  const editing = !!employee
  const [form, setForm] = useState<Record<string, any>>({})
  const [createUser, setCreateUser] = useState(false)
  const [username, setUsername] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [tempResult, setTempResult] = useState<{ username: string; password: string } | null>(null)

  const options = useQuery({ queryKey: ['employees', 'options'], queryFn: () => api.get<{ linkable_users: { id: number; name: string; username: string }[] }>('/employees/options'), staleTime: 60_000, enabled: open })

  useEffect(() => {
    if (!open) return
    setErrors({})
    setTempResult(null)
    setCreateUser(false)
    setUsername('')
    if (employee) {
      setForm({ first_name: employee.full_name.split(' ')[0] ?? '', last_name: employee.full_name.split(' ').slice(1).join(' '), position: employee.position, phone: employee.phone ?? '', email: employee.email ?? '', hired_on: employee.hired_on ?? '', is_active: employee.is_active })
    } else {
      setForm({ is_active: true })
    }
  }, [open, employee])

  const set = (key: string, value: unknown) => setForm((f) => ({ ...f, [key]: value }))
  const err = (key: string) => errors[key]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, create_user: createUser, username: username || undefined }
      return editing ? api.put<{ employee: EmployeeRow }>(`/employees/${employee!.id}`, payload) : api.post<{ employee: EmployeeRow; temp_password: string | null }>('/employees', payload)
    },
    onSuccess: (res: any) => {
      if (res.temp_password) {
        setTempResult({ username: username || res.employee.full_name, password: res.temp_password })
        return
      }
      toast.success(editing ? 'Personel güncellendi.' : 'Personel eklendi.')
      onClose()
      onSaved()
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast.error(e.firstError()) } },
  })

  if (tempResult) {
    const finish = () => { onClose(); onSaved() }
    return (
      <Drawer open={open} onClose={finish} width={440} title="Sistem kullanıcısı oluşturuldu">
        <Alert tone="warning" title="Bu şifre yalnızca bir kez gösterilir">
          Kullanıcı adı: <strong>{tempResult.username}</strong>
          <div className="mt-2 rounded-[var(--radius-md)] bg-surface-2 px-3 py-2 font-mono text-[15px] tracking-wide">{tempResult.password}</div>
        </Alert>
        <div className="mt-4 flex justify-end"><Button variant="primary" onClick={finish}>Tamam</Button></div>
      </Drawer>
    )
  }

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={480}
      title={editing ? 'Personeli düzenle' : 'Yeni personel'}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{editing ? 'Kaydet' : 'Personel ekle'}</Button></>}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Ad" required error={err('first_name')}><Input value={form.first_name ?? ''} onChange={(e) => set('first_name', e.target.value)} autoFocus /></Field>
        <Field label="Soyad" required error={err('last_name')}><Input value={form.last_name ?? ''} onChange={(e) => set('last_name', e.target.value)} /></Field>
        <Field label="Görevi" required error={err('position')} className="sm:col-span-2"><Input value={form.position ?? ''} onChange={(e) => set('position', e.target.value)} placeholder="Muhasebe, Kayıt Danışmanı…" /></Field>
        <Field label="Cep telefonu" optional error={err('phone')}><Input type="tel" value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} placeholder="05xx xxx xx xx" /></Field>
        <Field label="E-posta" optional error={err('email')}><Input type="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} /></Field>
        <Field label="İşe başlama tarihi" optional error={err('hired_on')}><Input type="date" value={form.hired_on ?? ''} onChange={(e) => set('hired_on', e.target.value)} /></Field>
        {editing && <Field label="Durum"><Switch checked={!!form.is_active} onChange={(v) => set('is_active', v)} label={form.is_active ? 'Aktif' : 'Pasif'} /></Field>}
      </div>

      {!employee?.has_user && (
        <div className="mt-5 rounded-[var(--radius-lg)] bg-surface-2/60 ring-1 ring-line p-4">
          <Switch checked={createUser} onChange={setCreateUser} label={<span className="font-medium">Sistem hesabı oluştur <span className="font-normal text-ink-3">(isteğe bağlı)</span></span>} />
          {createUser && (
            <div className="mt-3">
              <Field label="Kullanıcı adı" optional error={err('username')} hint="Boş bırakılırsa isimden üretilir"><Input value={username} onChange={(e) => setUsername(e.target.value)} /></Field>
              {(options.data?.linkable_users.length ?? 0) > 0 && <p className="mt-2 text-[12px] text-ink-3">Ya da mevcut bir kullanıcıyı Ayarlar &gt; Kullanıcılar ekranından bu personele bağlayabilirsiniz.</p>}
            </div>
          )}
        </div>
      )}
    </Drawer>
  )
}
