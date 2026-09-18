import { useEffect, useState } from 'react'

/** Uygulama teması (açık/koyu) — `[data-theme=dark]` ve sistem ayarını izler; 3D sahne renkleri buna göre */
function isDark(): boolean {
  if (typeof document === 'undefined') return false
  return document.documentElement.dataset.theme === 'dark'
}

export type SceneTheme = {
  dark: boolean
  background: string
  ground: string
  gridCell: string
  gridSection: string
  accent: string
  danger: string
  hover: string
  ink: string
}

export function themeColors(dark: boolean): SceneTheme {
  return dark
    ? { dark, background: '#0f1318', ground: '#141920', gridCell: '#232b36', gridSection: '#33404f', accent: '#3fb3a5', danger: '#ef5b6d', hover: '#8fb4de', ink: '#e9ecf1' }
    : { dark, background: '#e4e8ed', ground: '#dfe3e8', gridCell: '#c9d0d9', gridSection: '#aeb8c4', accent: '#0f8073', danger: '#d23347', hover: '#4f7fb8', ink: '#161a21' }
}

export function useSceneTheme(): SceneTheme {
  const [dark, setDark] = useState(isDark)
  useEffect(() => {
    const obs = new MutationObserver(() => setDark(isDark()))
    obs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] })
    return () => obs.disconnect()
  }, [])
  return themeColors(dark)
}
