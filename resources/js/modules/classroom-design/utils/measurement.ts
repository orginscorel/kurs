import type { Vec2 } from '../types'

/** Ölçü biçimleri (Türkçe ondalık virgül): 7,20 m · 80 cm */

export function dist(a: Vec2, b: Vec2): number {
  return Math.hypot(b.x - a.x, b.z - a.z)
}

export function formatMeters(m: number, digits = 2): string {
  return `${m.toFixed(digits).replace('.', ',')} m`
}

export function formatCm(m: number): string {
  return `${Math.round(m * 100).toLocaleString('tr-TR')} cm`
}

/** 1 m altı cm, üstü m */
export function formatLength(m: number): string {
  return m < 1 ? formatCm(m) : formatMeters(m)
}

export function formatArea(m2: number): string {
  return `${m2.toFixed(1).replace('.', ',')} m²`
}

/** Sayı girişini (virgül/nokta) metreye çevirir; cm girildiyse ölçek verilir */
export function parseNumber(v: string): number | null {
  const n = Number(String(v).trim().replace(',', '.'))
  return Number.isFinite(n) ? n : null
}

export function degrees(rad: number): number {
  return Math.round(((rad * 180) / Math.PI) * 10) / 10
}

export function radians(deg: number): number {
  return (deg * Math.PI) / 180
}
