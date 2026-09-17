import { useState, type FormEvent } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Eye, EyeOff } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { Field, Input } from '@/components/ui/form'
import { Button } from '@/components/ui/Button'
import { cn } from '@/lib/cn'

/** Öğrenci/veli şifre değiştirme formu (profil ve ilk giriş adımında ortak). */
export function PasswordForm({
  currentLabel = 'Mevcut şifre',
  submitLabel = 'Şifreyi değiştir',
  onDone,
  autoFocus,
  className,
  fullWidth,
}: {
  currentLabel?: string
  submitLabel?: string
  onDone?: () => void
  autoFocus?: boolean
  className?: string
  fullWidth?: boolean
}) {
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [show, setShow] = useState(false)

  const change = useMutation({
    mutationFn: () => api.post<{ message: string }>('/auth/change-password', form),
    onSuccess: (r) => {
      toast.success(r.message)
      setForm({ current_password: '', password: '', password_confirmation: '' })
      setErrors({})
      onDone?.()
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        if (!Object.keys(e.errors).length) toast.error(e.message)
      } else {
        toast.error('Şifre değiştirilemedi.')
      }
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (form.password !== form.password_confirmation) {
      setErrors({ password_confirmation: ['Yeni şifre ile tekrarı aynı değil.'] })
      return
    }
    change.mutate()
  }

  const type = show ? 'text' : 'password'
  const toggle = (
    <button type="button" onClick={() => setShow((v) => !v)} className="grid size-7 place-items-center rounded text-ink-3 hover:text-ink" aria-label={show ? 'Şifreleri gizle' : 'Şifreleri göster'}>
      {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
    </button>
  )

  return (
    <form onSubmit={submit} className={cn('flex flex-col gap-3', className)}>
      <Field label={currentLabel} htmlFor="pw-cur" error={errors.current_password?.[0]}>
        <Input id="pw-cur" type={type} autoComplete="current-password" autoFocus={autoFocus} value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} trailing={toggle} required />
      </Field>
      <Field label="Yeni şifre" htmlFor="pw-new" hint="En az 8 karakter; harf ve rakam içermeli." error={errors.password?.[0]}>
        <Input id="pw-new" type={type} autoComplete="new-password" minLength={8} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required />
      </Field>
      <Field label="Yeni şifre (tekrar)" htmlFor="pw-new2" error={errors.password_confirmation?.[0]}>
        <Input id="pw-new2" type={type} autoComplete="new-password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} required />
      </Field>
      <Button type="submit" variant="primary" size={fullWidth ? 'lg' : undefined} loading={change.isPending} className={fullWidth ? 'mt-1 w-full' : 'self-start'}>{submitLabel}</Button>
    </form>
  )
}
