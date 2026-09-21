import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'

/**
 * Liste ekranı durumu URL'de tutulur (arama, filtre, sayfa, sıralama):
 * bağlantı paylaşılabilir, geri tuşu filtreyi korur.
 */
/** Pencere / sekme gibi yalnız ARAYÜZ durumu taşıyan adres parametreleri: liste süzgeci değildir, API'ye gitmez.
 *  (Eskiden "Yeni öğrenci" penceresi açılınca `yeni=1` liste isteğine süzgeç olarak ekleniyordu.) */
const ARAYUZ_PARAMLARI = new Set(['yeni', 'ice-aktar', 'sekme', 'detay', 'tab', 'aktarim', 'kip', 'gorunum', 'adim'])

export function useListState(defaults: { sort?: string; per_page?: number; filters?: Record<string, string> } = {}) {
  const [params, setParams] = useSearchParams()

  const state = useMemo(() => {
    const filters: Record<string, string> = { ...(defaults.filters ?? {}) }
    params.forEach((v, k) => {
      if (!['q', 'page', 'sort', 'per_page'].includes(k) && !ARAYUZ_PARAMLARI.has(k)) filters[k] = v
    })
    return {
      q: params.get('q') ?? '',
      page: Number(params.get('page') ?? 1),
      sort: params.get('sort') ?? defaults.sort ?? '',
      per_page: Number(params.get('per_page') ?? defaults.per_page ?? 25),
      filters,
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params])

  const update = useCallback(
    (patch: Partial<{ q: string; page: number; sort: string; per_page: number }> & { filters?: Record<string, string | null> }) => {
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          const set = (k: string, v: string | number | null | undefined) => {
            if (v === null || v === undefined || v === '') next.delete(k)
            else next.set(k, String(v))
          }
          if ('q' in patch) set('q', patch.q)
          if ('sort' in patch) set('sort', patch.sort)
          if ('per_page' in patch) set('per_page', patch.per_page)
          if (patch.filters) Object.entries(patch.filters).forEach(([k, v]) => set(k, v))
          // Filtre değişince ilk sayfaya dön
          if ('page' in patch) set('page', patch.page === 1 ? null : patch.page)
          else if ('q' in patch || patch.filters || 'sort' in patch) next.delete('page')
          return next
        },
        { replace: true },
      )
    },
    [setParams],
  )

  return { ...state, update, query: { q: state.q, page: state.page, sort: state.sort, per_page: state.per_page, ...state.filters } }
}

export function useDebounced<T>(value: T, delay = 250): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(t)
  }, [value, delay])
  return debounced
}
