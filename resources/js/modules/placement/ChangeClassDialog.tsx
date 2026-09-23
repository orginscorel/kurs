import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { num, todayISO } from '@/lib/format'
import { Button } from '@/components/ui/Button'
import { Field, Input, Textarea } from '@/components/ui/form'
import { Alert, Badge, Skeleton } from '@/components/ui/feedback'
import { Modal } from '@/components/ui/overlay'
import type { ChangePreview, ClassOption } from './types'

type Choice = { kind: 'swap'; studentId: number } | { kind: 'waitlist' } | null

/**
 * Sınıf değiştirme penceresi (yerleştirme ekranı ve öğrenci profili ortak kullanır).
 * Açılışta kurulmalı (koşullu render): durum her açılışta sıfırdan başlar.
 */
export function ChangeClassDialog({
  studentId,
  studentName,
  currentName,
  options,
  initialTargetId,
  onClose,
}: {
  studentId: number
  studentName: string
  currentName?: string | null
  options: ClassOption[]
  initialTargetId?: number | null
  onClose: () => void
}) {
  const qc = useQueryClient()
  const selectable = options.filter((o) => !o.is_current)
  const [targetId, setTargetId] = useState<number | null>(initialTargetId ?? (selectable.length === 1 ? selectable[0]!.class_group_id : null))
  const [reason, setReason] = useState('')
  const [effectiveOn, setEffectiveOn] = useState(todayISO())
  const [choice, setChoice] = useState<Choice>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const preview = useQuery({
    queryKey: ['placement', 'change-preview', studentId, targetId],
    queryFn: () => api.post<ChangePreview>(`/placement/students/${studentId}/change/preview`, { class_group_id: targetId }),
    enabled: targetId !== null,
    retry: false,
    staleTime: 0,
  })
  const p = preview.data
  const full = p?.full ?? false

  const submit = useMutation({
    mutationFn: () =>
      api.post<{ message: string }>(`/placement/students/${studentId}/change`, {
        class_group_id: targetId,
        reason: reason.trim(),
        effective_on: effectiveOn,
        swap_with_student_id: choice?.kind === 'swap' ? choice.studentId : null,
        to_waitlist: choice?.kind === 'waitlist',
      }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['placement'] })
      qc.invalidateQueries({ queryKey: ['class-groups'] })
      qc.invalidateQueries({ queryKey: ['class-group'] })
      qc.invalidateQueries({ queryKey: ['student', String(studentId)] })
      onClose()
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? e.errors : {})
      toast.error(e instanceof ApiError ? e.firstError() : 'Sınıf değiştirilemedi.')
      if (e instanceof ApiError && e.status === 409) preview.refetch()
    },
  })

  const ready = targetId !== null && !!p && reason.trim().length >= 3 && (!full || choice !== null)
  const submitLabel = !full ? (p?.current ? 'Sınıfı değiştir' : 'Şubeye yerleştir') : choice?.kind === 'waitlist' ? 'Bekleme listesine ekle' : 'Takası uygula'

  return (
    <Modal
      open
      onClose={onClose}
      title={currentName ? 'Sınıf değiştir' : 'Şubeye yerleştir'}
      description={`${studentName}${currentName ? ` · Şu anki şubesi: ${currentName}` : ' · Henüz şubesi yok'}`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" disabled={!ready} loading={submit.isPending} onClick={() => submit.mutate()}>{submitLabel}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {selectable.length === 0 ? (
          <Alert tone="info">Bu seviyede geçilebilecek başka şube yok.</Alert>
        ) : (
          <Field label="Yeni şube" required>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {selectable.map((o) => (
                <button
                  key={o.class_group_id}
                  type="button"
                  aria-pressed={targetId === o.class_group_id}
                  onClick={() => { setTargetId(o.class_group_id); setChoice(null) }}
                  className={cn(
                    'flex items-center justify-between gap-2 rounded-[var(--radius-sm)] px-3 py-2.5 text-left ring-1 transition-shadow',
                    targetId === o.class_group_id ? 'ring-2 ring-ink bg-surface' : 'ring-line bg-surface hover:ring-line-strong',
                  )}
                >
                  <span className="text-[14px] font-semibold">{o.name}</span>
                  <span className="flex items-center gap-1.5 text-[12px] text-ink-3 tabular">
                    {o.count}/{o.capacity} öğrenci
                    {o.full && <Badge tone="warning">Dolu</Badge>}
                  </span>
                </button>
              ))}
            </div>
          </Field>
        )}

        {targetId !== null && preview.isLoading && <Skeleton className="h-16" />}
        {preview.error && <Alert tone="danger">{preview.error instanceof ApiError ? preview.error.firstError() : 'Önizleme alınamadı.'}</Alert>}

        {p && full && (
          <div className="flex flex-col gap-2">
            <Alert tone="warning" title={`${p.target.name} dolu (${p.target.count}/${p.target.capacity})`}>
              {p.current ? 'Hedef şubeden bir öğrenciyle karşılıklı yer değiştirin ya da bekleme listesine ekleyin.' : 'Şubesi olmayan öğrenci için takas yapılamaz; bekleme listesine ekleyebilirsiniz.'}
            </Alert>
            {p.suggestions.map((s) => {
              const active = choice?.kind === 'swap' && choice.studentId === s.student_id
              return (
                <button
                  key={s.student_id}
                  type="button"
                  aria-pressed={active}
                  onClick={() => setChoice({ kind: 'swap', studentId: s.student_id })}
                  className={cn('flex items-center gap-3 rounded-[var(--radius-sm)] px-3 py-2 text-left ring-1', active ? 'ring-2 ring-ink' : 'ring-line hover:ring-line-strong')}
                >
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13px] font-medium">Takas: {s.full_name} → {p.current?.name}</span>
                    <span className="block text-[12px] text-ink-3 tabular">
                      {s.avg_net === null ? 'Deneme sonucu yok' : `Deneme ort.: ${num(s.avg_net, 1)} net`}{s.risk === 'high' ? ' · Yüksek risk' : ''} · {s.effect}
                    </span>
                  </span>
                </button>
              )
            })}
            <button
              type="button"
              aria-pressed={choice?.kind === 'waitlist'}
              onClick={() => setChoice({ kind: 'waitlist' })}
              className={cn('rounded-[var(--radius-sm)] px-3 py-2 text-left ring-1', choice?.kind === 'waitlist' ? 'ring-2 ring-ink' : 'ring-line hover:ring-line-strong')}
            >
              <span className="block text-[13px] font-medium">{p.target.name} için bekleme listesine ekle</span>
              <span className="block text-[12px] text-ink-3">{p.current ? `Öğrenci ${p.current.name} şubesinde kalır; yer açılınca yerleştirilir.` : 'Yer açılınca şubeye yerleştirilir.'}</span>
            </button>
          </div>
        )}

        <Field label="Gerekçe" required error={errors.reason?.[0]} hint="En az 3 karakter. Sınıf geçmişinde ve denetim kaydında görünür.">
          <Textarea rows={2} value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} placeholder="Ör. veli talebi, arkadaş grubu, ders saatleri uyumu" />
        </Field>
        {choice?.kind !== 'waitlist' && (
          <Field label="Geçerlilik tarihi" required error={errors.effective_on?.[0]} hint="Bu tarihten itibaren öğrenci yeni şubenin derslerine ve yoklamasına girer.">
            <Input type="date" value={effectiveOn} min={p?.term.starts_on ?? undefined} max={todayISO() > (p?.term.starts_on ?? '') ? todayISO() : (p?.term.starts_on ?? undefined)} onChange={(e) => setEffectiveOn(e.target.value)} />
          </Field>
        )}
      </div>
    </Modal>
  )
}
