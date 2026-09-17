import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowRight, GraduationCap } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date } from '@/lib/format'
import { Button } from '@/components/ui/Button'
import { Field, Select } from '@/components/ui/form'
import { Alert, EmptyState } from '@/components/ui/feedback'
import { ConfirmDialog, Drawer } from '@/components/ui/overlay'
import type { PromotionPreview, RunRow, TermOption } from './types'

/** Dönem sonu seviye atlatma: 9→10, 10→11, 11→12 (şube korunur), 12 → Mezun. */
export function PromotionDrawer({ terms, fromTermId, onClose }: { terms: TermOption[]; fromTermId: number; onClose: () => void }) {
  const qc = useQueryClient()
  const [from, setFrom] = useState(String(fromTermId))
  const later = terms.filter((t) => t.id !== Number(from) && (t.starts_on ?? '') > (terms.find((x) => x.id === Number(from))?.starts_on ?? ''))
  const [to, setTo] = useState(later[later.length - 1] ? String(later[later.length - 1]!.id) : '')
  const [preview, setPreview] = useState<PromotionPreview | null>(null)
  const [confirm, setConfirm] = useState(false)

  const previewMut = useMutation({
    mutationFn: () => api.post<PromotionPreview>('/placement/promotion/preview', { from_term_id: Number(from), to_term_id: Number(to) }),
    onSuccess: setPreview,
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Önizleme alınamadı.'),
  })
  const applyMut = useMutation({
    mutationFn: () => api.post<{ message: string; run: RunRow }>('/placement/promotion/apply', { from_term_id: Number(from), to_term_id: Number(to), hash: preview?.hash }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['placement'] })
      qc.invalidateQueries({ queryKey: ['students'] })
      qc.invalidateQueries({ queryKey: ['class-groups'] })
      onClose()
    },
    onError: (e) => {
      setConfirm(false)
      toast.error(e instanceof ApiError ? e.firstError() : 'Seviye atlatma uygulanamadı.')
    },
  })

  const termOptions = terms.map((t) => ({ value: t.id, label: `${t.name}${t.is_current ? ' (güncel)' : ''}` }))

  return (
    <Drawer
      open
      onClose={onClose}
      width={560}
      title="Seviye atlat"
      description="Aktif öğrenciler hedef dönemde bir üst sınıfa geçer (şube harfi korunur); 12. sınıflar mezun olur ve sınıf üyelikleri kapanır."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Kapat</Button>
          {preview ? (
            <Button variant="primary" onClick={() => setConfirm(true)}>Onayla ve uygula</Button>
          ) : (
            <Button variant="primary" disabled={!to} loading={previewMut.isPending} onClick={() => previewMut.mutate()}>Önizle</Button>
          )}
        </>
      }
    >
      <div className="flex flex-col gap-5">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Biten dönem">
            <Select value={from} options={termOptions} onChange={(e) => { setFrom(e.target.value); setPreview(null) }} />
          </Field>
          <Field label="Yeni dönem">
            <Select value={to} placeholder="Seçin" options={termOptions.filter((o) => o.value !== Number(from))} onChange={(e) => { setTo(e.target.value); setPreview(null) }} />
          </Field>
        </div>
        {terms.length < 2 && (
          <EmptyState compact title="Yeni dönem tanımlı değil" description="Seviye atlatma için önce akademik ayarlardan yeni dönemi (ör. 2027-2028) oluşturun." />
        )}

        {preview && (
          <div className="flex flex-col gap-4 border-t border-line pt-4">
            {preview.missing_classes.length > 0 && (
              <Alert tone="info">{preview.to.name} döneminde eksik sınıflar uygulamada otomatik açılır: {preview.missing_classes.join(', ')}.</Alert>
            )}
            <ul className="divide-y divide-line rounded-[var(--radius-lg)] ring-1 ring-line">
              {preview.levels.map((l) => (
                <li key={l.from} className="flex items-center justify-between gap-3 px-4 py-2.5 text-[13.5px]">
                  <span className="flex items-center gap-2 font-medium">{l.from}. sınıf <ArrowRight className="size-3.5 text-ink-3" /> {l.to}. sınıf</span>
                  <span className="text-ink-2 tabular">{l.count} öğrenci{l.unplaced ? ` · ${l.unplaced} şubesiz` : ''}</span>
                </li>
              ))}
              <li className="flex items-center justify-between gap-3 px-4 py-2.5 text-[13.5px]">
                <span className="flex items-center gap-2 font-medium">{preview.graduates.level}. sınıf <ArrowRight className="size-3.5 text-ink-3" /> <GraduationCap className="size-4" /> Mezun</span>
                <span className="text-ink-2 tabular">{preview.graduates.count} öğrenci</span>
              </li>
            </ul>
            {preview.overflow.length > 0 && (
              <Alert tone="warning" title="Hedef şube dolu">{preview.overflow.map((o) => `${o.full_name} (${o.class})`).join(', ')} seviye atlar ama şubesiz kalır; sonra otomatik yerleştirme ile dağıtın.</Alert>
            )}
            {preview.skipped.count > 0 && (
              <p className="text-[12.5px] text-ink-3">{preview.skipped.count} öğrenci atlanacak (aktif değil ya da seviyesi tanımsız).</p>
            )}
            {preview.graduates.count > 0 && (
              <details className="rounded-[var(--radius-sm)] ring-1 ring-line px-3 py-2 text-[13px]">
                <summary className="cursor-pointer text-ink-2">Mezun olacak öğrenciler ({preview.graduates.count})</summary>
                <ul className="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-2">
                  {preview.graduates.students.map((s) => <li key={s.id} className="truncate">{s.full_name}{s.class ? <span className="text-ink-3"> · {s.class}</span> : null}</li>)}
                </ul>
              </details>
            )}
          </div>
        )}
      </div>

      <ConfirmDialog
        open={confirm}
        onClose={() => setConfirm(false)}
        onConfirm={() => applyMut.mutate()}
        loading={applyMut.isPending}
        title="Seviye atlatma uygulansın mı?"
        confirmLabel="Uygula"
        description={preview ? `${preview.levels.reduce((a, l) => a + l.count, 0)} öğrenci ${preview.to.name} döneminde üst sınıfa geçecek, ${preview.graduates.count} öğrenci mezun olacak${preview.from.ends_on ? ` (üyelikler en geç ${date(preview.from.ends_on)} tarihinde kapanır)` : ''}. Son işlem olduğu sürece geri alınabilir.` : undefined}
      />
    </Drawer>
  )
}
