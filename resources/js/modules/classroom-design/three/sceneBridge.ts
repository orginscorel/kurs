/**
 * 3D sahne ile React dışı kod (kaydet, araç çubuğu) arasındaki köprü: küçük görsel yakalama ve görünümü odaya sığdırma.
 * Sahne bağlanınca kaydeder; yoksa işlevler null döner.
 */
export type SceneBridge = {
  capture: (width?: number, height?: number) => string | null
  frame: () => void
  /** saniyedeki kare (son 1 sn) — tanılama */
  fps: () => number
  /** zemin noktasının ekran (client) koordinatı — tanılama / otomatik test */
  project: (x: number, z: number, y?: number) => { x: number; y: number } | null
  /** WebGL bağlam bilgisi — tanılama */
  info: () => { webgl2: boolean; calls: number; triangles: number; geometries: number; textures: number }
  /** n kare art arda çizip ortalama kare süresini (ms) döndürür — tanılama */
  bench: (n?: number) => number
}

let bridge: SceneBridge | null = null

export function setSceneBridge(b: SceneBridge | null) {
  bridge = b
  // tanılama: tarayıcı konsolundan / uçtan uca testten erişim (window.__cdScene.fps())
  ;(window as unknown as { __cdScene?: SceneBridge | null }).__cdScene = b
}

export function sceneBridge(): SceneBridge | null {
  return bridge
}
