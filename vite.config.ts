import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'
import fs from 'node:fs'

/**
 * Derlenen varlıklar doğrudan web kök dizinine yazılır;
 * uygulama kaynak kodu web kökünün dışında kalır.
 */
const PUBLIC_DIR = path.resolve(__dirname, '../kurs.bogahostdeveloper.com.tr')

/**
 * Sürüm: değişiklik günlüğünün (resources/changelog.json) en üstündeki sürüm.
 * Derleme kimliği her derlemede değişir; açık ekranlar build/version.json'ı yoklayıp güncellemeyi fark eder.
 */
const CHANGELOG = JSON.parse(fs.readFileSync(path.resolve(__dirname, 'resources/changelog.json'), 'utf8')) as { version: string }[]
const APP_VERSION = CHANGELOG[0]?.version ?? '0.0.0'
const APP_BUILD = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14)

function versionFile() {
    return {
        name: 'kurs-version-file',
        apply: 'build' as const,
        closeBundle() {
            const out = path.join(PUBLIC_DIR, 'build')
            fs.writeFileSync(path.join(out, 'version.json'), JSON.stringify({ version: APP_VERSION, build: APP_BUILD, built_at: new Date().toISOString() }))
            fs.copyFileSync(path.resolve(__dirname, 'resources/changelog.json'), path.join(out, 'changelog.json'))
        },
    }
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/main.tsx'],
            publicDirectory: '../kurs.bogahostdeveloper.com.tr',
            buildDirectory: 'build',
            refresh: true,
        }),
        react(),
        tailwindcss(),
        versionFile(),
    ],
    define: {
        __APP_VERSION__: JSON.stringify(APP_VERSION),
        __APP_BUILD__: JSON.stringify(APP_BUILD),
    },
    resolve: {
        alias: { '@': path.resolve(__dirname, 'resources/js') },
    },
    build: {
        outDir: path.join(PUBLIC_DIR, 'build'),
        emptyOutDir: false,
        chunkSizeWarningLimit: 900,
        rollupOptions: {
            output: {
                manualChunks(id: string) {
                    if (!id.includes('node_modules')) return undefined
                    // 3D derslik tasarımı: three + @react-three (+ iç bağımlılıkları) ayrı parça — yalnız o rotada iner
                    // ortak küçük bağımlılıklar ana vendor parçasında kalsın (3D parçası bunları içine çekmesin)
                    if (/[\\/]node_modules[\\/]zustand[\\/]/.test(id) && !/traditional/.test(id) && !id.includes('@react-three')) return 'react'
                    if (id.includes('@react-three') || /[\\/]node_modules[\\/](three|three-stdlib|three-mesh-bvh|troika-[^\\/]+|camera-controls|maath|meshline|stats-gl|its-fine|suspend-react|@monogrid|@use-gesture|hls\.js|detect-gpu|tunnel-rat)[\\/]/.test(id)) return 'three3d'
                    if (/[\\/](react|react-dom|react-router|react-router-dom|scheduler)[\\/]/.test(id)) return 'react'
                    if (/[\\/](recharts|d3-[^\\/]+|victory-vendor)[\\/]/.test(id)) return 'charts'
                    if (id.includes('@tanstack')) return 'query'
                    return undefined
                },
            },
        },
    },
})
