import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Armchair, Box, Plus, Search, Trash2 } from 'lucide-react'
import { useCan } from '@/app/auth'
import { ApiError } from '@/lib/api'
import { relative } from '@/lib/format'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Input, Select } from '@/components/ui/form'
import { PageHeader } from '@/components/ui/layout'
import { ConfirmDialog } from '@/components/ui/overlay'
import { useClassroomOptions, useDeleteLayout, useLayouts } from '../api'
import type { LayoutListItem } from '../types'
import { formatArea } from '../utils/measurement'

/**
 * DERSLİK TASARIMLARI — liste: 3D küçük görsel, ad, derslik/kat, kapasite, yerleşen masa, atanan öğrenci.
 * İlk açılışta (yazma yetkisiyle) örnek derslik sunucuda bir kez oluşturulur; silinirse geri gelmez.
 */
export default function LayoutListPage() {
  const can = useCan()
  const canManage = can('classroom_layouts.manage')
  const [params, setParams] = useSearchParams()
  const classroomFilter = params.get('derslik') ? Number(params.get('derslik')) : null
  const [q, setQ] = useState('')
  const list = useLayouts({ classroom_id: classroomFilter, q: q.trim() || undefined })
  const classrooms = useClassroomOptions()
  const del = useDeleteLayout()
  const [toDelete, setToDelete] = useState<LayoutListItem | null>(null)

  const rows = list.data?.data ?? []
  const designed = useMemo(() => new Set(rows.map((r) => r.classroom?.id).filter(Boolean)), [rows])
  const undesigned = (classrooms.data ?? []).filter((c) => c.is_active && !designed.has(c.id))

  return (
    <div>
      <PageHeader
        title="Derslik tasarımı"
        description="Derslikleri 3D modelleyin, masa düzenini sürükle-bırakla kurun, öğrencileri masalara yerleştirin."
        actions={
          canManage && (
            <ButtonLink to={classroomFilter ? `/derslik-tasarimi/yeni?derslik=${classroomFilter}` : '/derslik-tasarimi/yeni'} variant="primary" icon={<Plus className="size-4" />}>
              Yeni tasarım
            </ButtonLink>
          )
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Input className="w-full sm:w-72" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Tasarım ya da derslik ara" leading={<Search />} aria-label="Ara" />
        <Select
          className="w-full sm:w-60"
          aria-label="Derslik"
          value={classroomFilter ?? ''}
          placeholder="Tüm derslikler"
          onChange={(e) => {
            const next = new URLSearchParams(params)
            if (e.target.value) next.set('derslik', e.target.value)
            else next.delete('derslik')
            setParams(next, { replace: true })
          }}
          options={(classrooms.data ?? []).map((c) => ({ value: c.id, label: `${c.name}${c.floor ? ` · ${c.floor}` : ''}` }))}
        />
      </div>

      {list.isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-72 rounded-[var(--radius-lg)]" />)}
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<Box />}
          title={q || classroomFilter ? 'Eşleşen tasarım yok' : 'Henüz derslik tasarımı yok'}
          description={canManage ? 'Yeni tasarım oluşturup derslik planını çizin; hazır şablonlar ve akıllı yerleşim yardımcı olur.' : 'Tasarımları düzenleme yetkisi yöneticidedir.'}
          action={canManage && <ButtonLink to="/derslik-tasarimi/yeni" variant="primary" icon={<Plus className="size-4" />}>Yeni tasarım</ButtonLink>}
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-testid="layout-grid">
          {rows.map((l) => (
            <LayoutCard key={l.id} l={l} canManage={canManage} onDelete={() => setToDelete(l)} />
          ))}
        </div>
      )}

      {canManage && undesigned.length > 0 && !classroomFilter && rows.length > 0 && (
        <section className="mt-8">
          <h2 className="mb-2 text-[13.5px] font-semibold text-ink">Tasarımı olmayan derslikler</h2>
          <div className="flex flex-wrap gap-2">
            {undesigned.map((c) => (
              <Link key={c.id} to={`/derslik-tasarimi/yeni?derslik=${c.id}`} className="inline-flex h-8 items-center gap-1.5 rounded-[6px] border border-line bg-surface px-2.5 text-[12.5px] text-ink-2 hover:border-primary/40 hover:text-ink">
                <Plus className="size-3.5" />
                {c.name}
                {c.floor && <span className="text-ink-3">· {c.floor}</span>}
              </Link>
            ))}
          </div>
        </section>
      )}

      <ConfirmDialog
        open={!!toDelete}
        onClose={() => setToDelete(null)}
        loading={del.isPending}
        onConfirm={() =>
          toDelete &&
          del.mutate(toDelete.id, {
            onSuccess: () => {
              toast.success('Tasarım silindi.')
              setToDelete(null)
            },
            onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
          })
        }
        title="Tasarım silinsin mi?"
        description={`"${toDelete?.name}" ve tüm sürümleri listeden kaldırılacak. Öğrenci ve derslik kayıtları etkilenmez.`}
        confirmLabel="Sil"
        danger
      />
    </div>
  )
}

function LayoutCard({ l, canManage, onDelete }: { l: LayoutListItem; canManage: boolean; onDelete: () => void }) {
  const s = l.stats ?? {}
  const cap = s.capacity ?? 0
  const roomCap = l.classroom?.capacity ?? null
  return (
    <article className="group overflow-hidden rounded-[var(--radius-lg)] bg-surface ring-1 ring-line transition-shadow hover:shadow-[var(--shadow-soft)] hover:ring-line-strong" data-testid={`layout-card-${l.id}`}>
      <Link to={`/derslik-tasarimi/${l.id}`} className="block">
        <div className="relative aspect-[16/10] bg-surface-2">
          {l.thumbnail_url ? (
            <img src={l.thumbnail_url} alt={`${l.name} 3D görünüm`} loading="lazy" className="h-full w-full object-cover" />
          ) : (
            <div className="grid h-full place-items-center text-center text-[12px] text-ink-3">
              <div>
                <Armchair className="mx-auto mb-1 size-6" />
                Önizleme ilk açılışta oluşur
              </div>
            </div>
          )}
          {l.is_demo && <Badge tone="info" className="absolute left-2 top-2">Örnek</Badge>}
        </div>
      </Link>
      <div className="space-y-2.5 p-3.5">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <Link to={`/derslik-tasarimi/${l.id}`} className="block truncate text-[14px] font-semibold text-ink hover:underline">{l.name}</Link>
            <p className="truncate text-[12px] text-ink-3">
              {l.classroom ? `${l.classroom.name}${l.classroom.floor ? ` · ${l.classroom.floor}` : ''}` : 'Dersliğe bağlı değil'}
              {' · '}V{l.version}
            </p>
          </div>
          {canManage && (
            <Button size="icon-sm" variant="ghost" aria-label="Sil" title="Sil" onClick={onDelete}>
              <Trash2 className="size-4" />
            </Button>
          )}
        </div>
        <dl className="grid grid-cols-4 gap-2 text-center">
          {[
            ['Kapasite', roomCap ? `${cap}/${roomCap}` : String(cap)],
            ['Masa', String(s.desks ?? 0)],
            ['Atanan', String(s.assigned ?? 0)],
            ['Alan', s.area ? formatArea(s.area) : '—'],
          ].map(([k, v]) => (
            <div key={k} className="rounded-[6px] bg-surface-2 px-1 py-1.5">
              <dt className="text-[10.5px] text-ink-3">{k}</dt>
              <dd className="text-[13px] font-semibold tabular-nums text-ink">{v}</dd>
            </div>
          ))}
        </dl>
        <p className="text-[11.5px] text-ink-3">{l.updated_at ? `Güncellendi ${relative(l.updated_at)}` : ''}{l.updated_by ? ` · ${l.updated_by}` : ''}</p>
      </div>
    </article>
  )
}
