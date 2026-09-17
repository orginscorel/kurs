import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Clock3, Plus, Wand2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useCan } from '@/app/auth'
import { PageHeader } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { WEEKDAY_SHORT } from '../types'
import { BotTabs } from './BotTabs'
import { TemplateFormDrawer } from './TemplateFormDrawer'
import type { TemplateClass, TimeTemplateRow } from './types'

type Data = { data: TimeTemplateRow[]; classes: TemplateClass[] }

export default function TimeTemplatesPage() {
  const can = useCan()
  const manage = can('schedule.manage')
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['time-templates'], queryFn: () => api.get<Data>('/time-templates') })
  const [editing, setEditing] = useState<TimeTemplateRow | 'new' | null>(null)

  const auto = useMutation({
    mutationFn: () => api.post<{ message: string }>('/time-templates/auto-assign'),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['time-templates'] })
      qc.invalidateQueries({ queryKey: ['timetable'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Atama yapılamadı.'),
  })

  const unassigned = q.data?.classes.filter((c) => c.template_ids.length === 0) ?? []

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Zaman şablonları"
        description="Sınıf ve saatlere göre ders dilimleri. Bir sınıfa birden fazla şablon atanabilir (ör. hafta içi akşam + cumartesi). Program botu yalnızca bu dilimlere ders yerleştirir."
        actions={manage && (
          <>
            <Button icon={<Wand2 className="size-4" />} loading={auto.isPending} onClick={() => auto.mutate()}>Seviyeye göre ata</Button>
            <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Yeni şablon</Button>
          </>
        )}
      />
      <BotTabs active="templates" />

      {unassigned.length > 0 && (
        <Alert tone="warning" className="mb-4" title={`${unassigned.length} sınıfın zaman şablonu yok`}>
          {unassigned.map((c) => c.name).join(', ')}. Şablona seviye tanımlayıp “Seviyeye göre ata” ile toplu bağlayabilirsiniz.
        </Alert>
      )}

      {q.isLoading ? (
        <div className="flex flex-col gap-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-28 rounded-[var(--radius-lg)]" />)}</div>
      ) : !q.data?.data.length ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<Clock3 />} title="Henüz zaman şablonu yok" description="Örneğin “11-12. sınıf hafta içi akşam” (16:30’dan itibaren) ve “Cumartesi tam gün” şablonlarını oluşturun." action={manage && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Şablon oluştur</Button>} />
        </div>
      ) : (
        <div className="grid gap-3 lg:grid-cols-2">
          {q.data.data.map((t) => (
            <button key={t.id} type="button" onClick={() => manage && setEditing(t)} className="flex flex-col gap-3 rounded-[var(--radius-lg)] bg-surface p-4 text-left ring-1 ring-line transition-shadow hover:ring-line-strong disabled:cursor-default" disabled={!manage}>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="flex items-center gap-2 text-[14px] font-semibold">{t.name}{!t.is_active && <Badge>Pasif</Badge>}</p>
                  {t.description && <p className="mt-0.5 text-[12.5px] text-ink-3">{t.description}</p>}
                </div>
                <span className="shrink-0 text-[12px] tabular text-ink-3">{t.slot_count} ders saati</span>
              </div>
              <WeekStrip days={t.days} />
              <div className="flex flex-wrap items-center gap-1.5 text-[12px]">
                {t.levels.length > 0 && <Badge>{t.levels.join(', ')}. sınıf</Badge>}
                {t.classes.length === 0 ? <span className="text-ink-3">Hiçbir sınıfa atanmadı</span> : t.classes.map((c) => <span key={c.id} className="rounded-full border border-line px-2 py-0.5 text-ink-2">{c.name}</span>)}
              </div>
            </button>
          ))}
        </div>
      )}

      {editing && (
        <TemplateFormDrawer
          template={editing === 'new' ? null : editing}
          classes={q.data?.classes ?? []}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            qc.invalidateQueries({ queryKey: ['time-templates'] })
            qc.invalidateQueries({ queryKey: ['timetable'] })
          }}
        />
      )}
    </div>
  )
}

/** 7 günlük özet şerit: gün başına dilim sayısı ve saat aralığı */
function WeekStrip({ days }: { days: TimeTemplateRow['days'] }) {
  return (
    <div className="grid grid-cols-7 gap-1">
      {[1, 2, 3, 4, 5, 6, 7].map((d) => {
        const slots = days[String(d)] ?? []
        return (
          <div key={d} className={slots.length ? 'rounded-[6px] bg-surface-2 px-1.5 py-1.5' : 'rounded-[6px] border border-dashed border-line px-1.5 py-1.5'}>
            <p className="text-[12px] font-medium text-ink-3">{WEEKDAY_SHORT[d]}</p>
            {slots.length ? (
              <>
                <p className="text-[12.5px] font-semibold tabular">{slots.length}</p>
                <p className="truncate text-[11.5px] tabular text-ink-3">{slots[0]![0]}–{slots[slots.length - 1]![1]}</p>
              </>
            ) : (
              <p className="text-[12.5px] text-ink-3">—</p>
            )}
          </div>
        )
      })}
    </div>
  )
}
