import { useMemo, useState } from 'react'
import { Search } from 'lucide-react'
import { CATALOG, CATEGORY_LABELS, gltfModels, type CatalogCategory, type CatalogItem } from '../../catalog'
import { addFurniture } from '../../state/actions'
import { useDrag } from '../../state/dragStore'
import { useCatalogThumbnails } from '../../three/thumbnails'
import { Input } from '@/components/ui/form'
import { cn } from '@/lib/cn'

/**
 * NESNE KÜTÜPHANESİ (sağ panel). Kartı tutup plana/3D sahneye sürükleyin (fare ya da parmak);
 * tıklayınca odada ilk boş yere eklenir. Önizlemeler ekran dışında bir kez çizilip önbelleğe alınır.
 */
export function FurnitureLibrary({ disabled }: { disabled?: boolean }) {
  const [q, setQ] = useState('')
  const thumbs = useCatalogThumbnails()
  const items = useMemo(() => {
    const all: CatalogItem[] = [...CATALOG, ...(gltfModels() as unknown as CatalogItem[])]
    const s = q.trim().toLocaleLowerCase('tr')
    return s ? all.filter((c) => `${c.label} ${c.hint} ${(c.keywords ?? []).join(' ')}`.toLocaleLowerCase('tr').includes(s)) : all
  }, [q])
  const groups = useMemo(() => {
    const m = new Map<CatalogCategory, CatalogItem[]>()
    for (const c of items) m.set(c.category, [...(m.get(c.category) ?? []), c])
    return [...m.entries()]
  }, [items])

  const onDown = (c: CatalogItem, e: React.PointerEvent) => {
    if (disabled || e.button !== 0) return
    e.preventDefault()
    const sx = e.clientX
    const sy = e.clientY
    let started = false
    const move = (m: PointerEvent) => {
      if (!started && Math.hypot(m.clientX - sx, m.clientY - sy) > 6) {
        started = true
        useDrag.getState().start({ kind: 'furniture', type: c.type, label: c.label }, m.clientX, m.clientY)
      }
    }
    const up = () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
      window.removeEventListener('pointercancel', up)
      if (!started) addFurniture(c.type)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
    window.addEventListener('pointercancel', up)
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="border-b border-line p-3">
        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nesne ara (masa, dolap, priz…)" leading={<Search />} aria-label="Nesne ara" />
        <p className="mt-2 text-[11.5px] leading-snug text-ink-3">Kartı tutup sahneye sürükleyin ya da tıklayın (ilk boş yere eklenir).</p>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto scroll-thin p-3">
        {groups.length === 0 && <p className="py-8 text-center text-[13px] text-ink-3">Eşleşen nesne yok.</p>}
        {groups.map(([cat, list]) => (
          <section key={cat} className="mb-4">
            <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-3">{CATEGORY_LABELS[cat]}</h3>
            <div className="grid grid-cols-2 gap-2">
              {list.map((c) => (
                <button
                  key={c.type}
                  type="button"
                  disabled={disabled}
                  onPointerDown={(e) => onDown(c, e)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault()
                      addFurniture(c.type)
                    }
                  }}
                  className={cn(
                    'group flex touch-none select-none flex-col overflow-hidden rounded-[var(--radius-sm)] border border-line bg-surface text-left transition-[border,box-shadow]',
                    'hover:border-primary/40 hover:shadow-[var(--shadow-soft)] focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-primary/20 disabled:opacity-50',
                    'cursor-grab active:cursor-grabbing',
                  )}
                  data-testid={`lib-${c.type}`}
                  title={`${c.label} — ${c.hint}`}
                >
                  <div className="grid aspect-[4/3] place-items-center bg-surface-2">
                    {thumbs[c.type] ? (
                      <img src={thumbs[c.type]} alt="" draggable={false} className="h-full w-full object-contain p-1" />
                    ) : (
                      <span className="size-6 animate-pulse rounded bg-surface-3" />
                    )}
                  </div>
                  <div className="border-t border-line px-2 py-1.5">
                    <div className="truncate text-[12.5px] font-medium text-ink">{c.label}</div>
                    <div className="truncate text-[11px] text-ink-3">{c.hint}</div>
                  </div>
                </button>
              ))}
            </div>
          </section>
        ))}
      </div>
    </div>
  )
}
