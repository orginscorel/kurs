/**
 * Tema: "Sistem" (bilgisayarın açık/koyu ayarını izler), "Açık", "Koyu".
 * Seçim localStorage'da 'ebe-theme' anahtarında durur; yokluğu = sistem.
 * İlk boyama öncesi uygulama resources/views/app.blade.php içindeki küçük betikte yapılır.
 */
export type ThemeMode = 'system' | 'light' | 'dark'

const KEY = 'ebe-theme'
const media = () => window.matchMedia('(prefers-color-scheme: dark)')

export function readMode(): ThemeMode {
  try {
    const v = localStorage.getItem(KEY)
    return v === 'dark' || v === 'light' ? v : 'system'
  } catch {
    return 'system'
  }
}

/** Seçime göre ekranda görünen tema */
export function resolve(mode: ThemeMode): 'light' | 'dark' {
  return mode === 'system' ? (media().matches ? 'dark' : 'light') : mode
}

export function apply(mode: ThemeMode): 'light' | 'dark' {
  const theme = resolve(mode)
  if (theme === 'dark') document.documentElement.dataset.theme = 'dark'
  else delete document.documentElement.dataset.theme
  return theme
}

export function saveMode(mode: ThemeMode) {
  try {
    if (mode === 'system') localStorage.removeItem(KEY)
    else localStorage.setItem(KEY, mode)
  } catch {
    /* yok say */
  }
  apply(mode)
}

/** Sistem ayarı değişince (macOS/Windows gece kipine geçince) seçim "Sistem" ise anında uygula. */
export function watchSystem(onChange?: (theme: 'light' | 'dark') => void) {
  const m = media()
  const handler = () => {
    if (readMode() !== 'system') return
    onChange?.(apply('system'))
  }
  m.addEventListener('change', handler)
  return () => m.removeEventListener('change', handler)
}
