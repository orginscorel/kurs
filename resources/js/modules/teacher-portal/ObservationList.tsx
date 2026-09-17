import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { EyeOff, Trash2, Users } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Badge } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/overlay'
import { observationTone, TP, useTeacherCan, type ObservationRow } from './api'

export function PointsPill({ points }: { points: number }) {
  if (!points) return null
  return (
    <span className={cn('inline-flex h-6 min-w-9 items-center justify-center rounded-full px-2 text-[12.5px] font-semibold tabular',
      points > 0 ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger')}>
      {points > 0 ? `+${points}` : points}
    </span>
  )
}

/** Gözlem notları listesi; öğretmen yalnız kendi notunu silebilir. */
export function ObservationList({ rows, showStudent }: { rows: ObservationRow[]; showStudent?: boolean }) {
  const qc = useQueryClient()
  const can = useTeacherCan()
  const [deleting, setDeleting] = useState<ObservationRow | null>(null)
  const remove = useMutation({
    mutationFn: (id: number) => api.delete<{ message: string }>(`${TP}/observations/${id}`),
    onSuccess: (r) => {
      toast.success(r.message)
      setDeleting(null)
      qc.invalidateQueries({ queryKey: ['teacher-portal'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Silinemedi.'),
  })

  return (
    <>
      <ul className="flex flex-col">
        {rows.map((o) => (
          <li key={o.id} className="flex gap-3 border-t border-line px-4 py-3 first:border-t-0">
            <PointsPill points={o.points} />
            {!o.points && <span className="inline-flex h-6 min-w-9 items-center justify-center rounded-full bg-surface-2 px-2 text-[12.5px] text-ink-3">0</span>}
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                {showStudent && o.student && (
                  <Link to={`/ogretmen/ogrenci/${o.student.id}`} className="text-[14.5px] font-semibold hover:underline">{o.student.full_name}</Link>
                )}
                <Badge tone={observationTone[o.kind] ?? 'neutral'}>{o.kind_label}</Badge>
                <span className="text-[12.5px] text-ink-3">{o.category_label}</span>
              </div>
              <p className="mt-1 whitespace-pre-line text-[14.5px] text-ink-2">{o.body}</p>
              <p className="mt-1 flex flex-wrap items-center gap-x-2 text-[12.5px] text-ink-3">
                <span title={dateTime(o.created_at)}>{relative(o.created_at)}</span>
                {!o.is_mine && o.teacher && <span>· {o.teacher}</span>}
                {o.subject && <span>· {o.subject}</span>}
                {o.visible_to_guardian || o.visible_to_student ? (
                  <span className="inline-flex items-center gap-1">· <Users className="size-3" />{[o.visible_to_student && 'öğrenci', o.visible_to_guardian && 'veli'].filter(Boolean).join(' + ')} görür</span>
                ) : (
                  <span className="inline-flex items-center gap-1">· <EyeOff className="size-3" />yalnız kurum içi</span>
                )}
              </p>
            </div>
            {o.is_mine && can('observations') && (
              <Button variant="ghost" size="icon-sm" onClick={() => setDeleting(o)} aria-label="Notu sil" title="Notu sil"><Trash2 className="size-4" /></Button>
            )}
          </li>
        ))}
      </ul>
      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        title="Gözlem notu silinsin mi?"
        description="Not öğrenci ve veli portalından da kalkar."
        confirmLabel="Sil"
        danger
        loading={remove.isPending}
      />
    </>
  )
}
