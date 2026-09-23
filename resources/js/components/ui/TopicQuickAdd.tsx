import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Plus, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/form'

/**
 * Bir dersin müfredatına satır-içi (hızlı) konu ekler.
 * Konu seçicilerin yanında kullanılır: liste boşsa bile kullanıcı burada yeni konu oluşturabilir.
 * Backend: POST /subjects/{subjectId}/topics  → { id, data }
 * Oluşturunca onCreated(id) çağrılır; ilgili konu sorgusunu tazelemek ve yeni konuyu seçmek çağırana aittir.
 */
export function TopicQuickAdd({
  subjectId,
  onCreated,
  disabled,
  label = 'Yeni konu ekle',
  className,
}: {
  subjectId: number | null | undefined
  onCreated: (topic: { id: number; name: string }) => void
  disabled?: boolean
  label?: string
  className?: string
}) {
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')

  const m = useMutation({
    mutationFn: (n: string) => api.post<{ id: number; data: { id: number; name: string } }>(`/subjects/${subjectId}/topics`, { name: n }),
    onSuccess: (r) => {
      toast.success('Konu eklendi.')
      onCreated({ id: r.id, name: name.trim() })
      setName('')
      setOpen(false)
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Konu eklenemedi.'),
  })

  const submit = () => {
    const n = name.trim()
    if (!n) return
    m.mutate(n)
  }

  if (!subjectId) return null

  if (!open) {
    return (
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen(true)}
        className={`inline-flex items-center gap-1 text-[12px] font-medium text-accent hover:underline disabled:opacity-50 ${className ?? ''}`}
      >
        <Plus className="size-3" /> {label}
      </button>
    )
  }

  return (
    <div className={`flex items-center gap-1.5 ${className ?? ''}`}>
      <Input
        autoFocus
        value={name}
        onChange={(e) => setName(e.target.value)}
        placeholder="Konu adı"
        className="h-8 text-[13px]"
        onKeyDown={(e) => {
          if (e.key === 'Enter') { e.preventDefault(); submit() }
          if (e.key === 'Escape') { setOpen(false); setName('') }
        }}
      />
      <Button size="sm" onClick={submit} loading={m.isPending} disabled={!name.trim()}>Ekle</Button>
      <button type="button" onClick={() => { setOpen(false); setName('') }} className="text-ink-3 hover:text-ink" aria-label="İptal">
        <X className="size-4" />
      </button>
    </div>
  )
}
