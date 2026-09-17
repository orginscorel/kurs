import { lazy, type ComponentType } from 'react'

/**
 * Tembel yükleme + yeni sürüm dayanıklılığı: dağıtımdan sonra eski sekmede
 * artık var olmayan bir parça istenirse sayfa bir kez yenilenir.
 */
export function lazyPage<T extends ComponentType<any>>(factory: () => Promise<{ default: T }>) {
  return lazy(async () => {
    try {
      const mod = await factory()
      sessionStorage.removeItem('ebe-chunk-reload')
      return mod
    } catch (error) {
      if (!sessionStorage.getItem('ebe-chunk-reload')) {
        sessionStorage.setItem('ebe-chunk-reload', '1')
        window.location.reload()
        return new Promise<{ default: T }>(() => {})
      }
      throw error
    }
  })
}
