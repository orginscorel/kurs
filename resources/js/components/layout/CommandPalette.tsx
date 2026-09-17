import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import { Command } from 'cmdk'
import { useQuery } from '@tanstack/react-query'
import { ArrowRight, CornerDownLeft, Search } from 'lucide-react'
import { api } from '@/lib/api'
import { useCan } from '@/app/auth'
import { commandActions } from '@/app/commands'
import { useDebounced } from '@/hooks/useListState'
import { Avatar, Badge, Kbd, Spinner } from '@/components/ui/feedback'

export type SearchHit = {
  type: 'student' | 'guardian' | 'teacher' | 'payment' | 'class_group' | 'exam' | 'lead' | 'subject'
  id: number
  title: string
  subtitle?: string | null
  url: string
  badge?: string | null
}

const typeLabels: Record<SearchHit['type'], string> = {
  student: 'Öğrenciler',
  guardian: 'Veliler',
  teacher: 'Öğretmenler',
  payment: 'Tahsilatlar',
  class_group: 'Sınıflar',
  exam: 'Sınavlar',
  lead: 'Adaylar',
  subject: 'Dersler',
}

export function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [query, setQuery] = useState('')
  const debounced = useDebounced(query.trim(), 180)
  const navigate = useNavigate()
  const can = useCan()

  useEffect(() => {
    if (!open) setQuery('')
  }, [open])

  useEffect(() => {
    if (!open) return
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', esc)
    return () => window.removeEventListener('keydown', esc)
  }, [open, onClose])

  const search = useQuery({
    queryKey: ['global-search', debounced],
    queryFn: () => api.get<{ data: SearchHit[] }>('/search', { q: debounced }),
    enabled: open && debounced.length >= 2 && can('search.global'),
    staleTime: 15_000,
    placeholderData: (prev) => prev,
  })

  if (!open) return null

  const go = (url: string) => {
    onClose()
    navigate(url)
  }

  const actions = commandActions.filter((a) => can(a.permission))
  const hits = debounced.length >= 2 ? (search.data?.data ?? []) : []
  const grouped = hits.reduce<Record<string, SearchHit[]>>((acc, h) => {
    ;(acc[h.type] ??= []).push(h)
    return acc
  }, {})

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-start justify-center px-3 pt-[12vh]">
      <div className="absolute inset-0 bg-black/30 backdrop-blur-[2px] animate-fade-in" onClick={onClose} />
      <Command
        shouldFilter={false}
        loop
        className="relative w-full max-w-[640px] overflow-hidden rounded-[var(--radius-xl)] bg-surface ring-1 ring-line shadow-[var(--shadow-pop)] animate-slide-up"
      >
        <div className="flex items-center gap-2.5 border-b border-line px-4">
          <Search className="size-[18px] text-ink-3" />
          <Command.Input
            autoFocus
            value={query}
            onValueChange={setQuery}
            placeholder="Ahmet Yılmaz, 1024, 12-SAY-A… ya da bir işlem"
            className="h-14 flex-1 bg-transparent text-[15px] outline-none placeholder:text-ink-3"
          />
          {search.isFetching && <Spinner />}
          <Kbd>Esc</Kbd>
        </div>

        <Command.List className="max-h-[min(460px,60vh)] overflow-y-auto scroll-thin p-2">
          {debounced.length >= 2 && !search.isFetching && hits.length === 0 && (
            <Command.Empty className="px-3 py-10 text-center text-[13.5px] text-ink-3">“{debounced}” için sonuç bulunamadı.</Command.Empty>
          )}

          {Object.entries(grouped).map(([type, items]) => (
            <Command.Group key={type} heading={typeLabels[type as SearchHit['type']]} className="[&_[cmdk-group-heading]]:px-2.5 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-[11.5px] [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-ink-3">
              {items.map((hit) => (
                <Command.Item
                  key={`${hit.type}-${hit.id}`}
                  value={`${hit.type}-${hit.id}`}
                  onSelect={() => go(hit.url)}
                  className="group flex cursor-pointer items-center gap-3 rounded-[var(--radius-sm)] px-2.5 py-2 data-[selected=true]:bg-surface-2"
                >
                  {['student', 'guardian', 'teacher', 'lead'].includes(hit.type) ? (
                    <Avatar name={hit.title} size={28} />
                  ) : (
                    <span className="grid size-7 place-items-center rounded-[var(--radius-xs)] bg-surface-2 text-ink-3">
                      <ArrowRight className="size-3.5" />
                    </span>
                  )}
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13.5px] font-medium text-ink">{hit.title}</p>
                    {hit.subtitle && <p className="truncate text-[12px] text-ink-3">{hit.subtitle}</p>}
                  </div>
                  {hit.badge && <Badge>{hit.badge}</Badge>}
                  <CornerDownLeft className="size-3.5 text-ink-3 opacity-0 group-data-[selected=true]:opacity-100" />
                </Command.Item>
              ))}
            </Command.Group>
          ))}

          {actions.length > 0 && (
            <Command.Group heading="Hızlı işlemler" className="[&_[cmdk-group-heading]]:px-2.5 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-[11.5px] [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-ink-3">
              {actions
                .filter((a) => !query || a.label.toLocaleLowerCase('tr-TR').includes(query.toLocaleLowerCase('tr-TR')) || a.keywords?.some((k) => k.includes(query.toLocaleLowerCase('tr-TR'))))
                .map((a) => (
                  <Command.Item
                    key={a.id}
                    value={a.id}
                    onSelect={() => go(a.to)}
                    className="flex cursor-pointer items-center gap-3 rounded-[var(--radius-sm)] px-2.5 py-2 data-[selected=true]:bg-surface-2"
                  >
                    <span className="grid size-7 place-items-center rounded-[var(--radius-xs)] bg-primary-soft text-primary-ink">
                      <a.icon className="size-4" />
                    </span>
                    <span className="flex-1 text-[13.5px] text-ink">{a.label}</span>
                    {a.hint && <span className="text-[12px] text-ink-3">{a.hint}</span>}
                  </Command.Item>
                ))}
            </Command.Group>
          )}
        </Command.List>
      </Command>
    </div>,
    document.body,
  )
}
