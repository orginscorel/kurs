import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { fileURLToPath } from 'node:url'

/**
 * Kurulum / karşılama ön yüzü (Tauri penceresinin yerel sayfaları).
 * İki giriş: index.html (kip seçimi, kurulum, başlatma, hata ekranları) ve update.html (güncelleme penceresi).
 * Çıktı: dist/ (tauri.conf.json › build.frontendDist).
 */
export default defineConfig({
  plugins: [react()],
  clearScreen: false,
  server: { port: 5183, strictPort: true },
  envPrefix: ['VITE_', 'TAURI_ENV_'],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    target: 'safari15',
    rollupOptions: {
      input: {
        main: fileURLToPath(new URL('./index.html', import.meta.url)),
        update: fileURLToPath(new URL('./update.html', import.meta.url)),
      },
    },
  },
})
