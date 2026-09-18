import { useEffect, useState } from 'react'

/** Ekran genişliği sorgusu (ör. '(min-width: 1024px)') — panel tek yerde bağlansın diye */
export function useMedia(query: string): boolean {
  const [match, setMatch] = useState(() => (typeof window !== 'undefined' ? window.matchMedia(query).matches : true))
  useEffect(() => {
    const m = window.matchMedia(query)
    const on = () => setMatch(m.matches)
    on()
    m.addEventListener('change', on)
    return () => m.removeEventListener('change', on)
  }, [query])
  return match
}
