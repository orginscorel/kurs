<?php

/*
| Masaüstü uygulaması (macOS; sonra Windows). Proje: desktop/ (Tauri 2), belge: desktop/docs/DESKTOP.md
|
| Sunucu tarafı: GitHub Releases'teki `desktop-v*` sürümlerini web köküne (public/desktop) çeker;
| masaüstü güncelleyicisi https://<alan>/desktop/latest.json adresini okur, /uygulamalar sayfası indirme bağlantısını gösterir.
| Yerel düğüm (masaüstü paketi): yerel sunucu jeton özeti ve açık ayarları.
*/

return [
    // --- Sunucu: sürüm çekme (kurs:desktop-release-sync) ---
    'github_repo' => env('DESKTOP_GITHUB_REPO', 'orginscorel/kurs'),
    // İnce ayarlı erişim anahtarı: yalnız bu depo, Contents: Read-only (özel depo sürüm dosyaları için)
    'github_token' => env('DESKTOP_GITHUB_TOKEN'),
    'tag_prefix' => env('DESKTOP_TAG_PREFIX', 'desktop-v'),
    // Web kökü altındaki klasör (public_path('desktop')). Dosyalar statik sunulur.
    'public_dir' => env('DESKTOP_PUBLIC_DIR', 'desktop'),
    // Saklanan eski sürüm sayısı (en yeni hariç)
    'keep_versions' => (int) env('DESKTOP_KEEP_VERSIONS', 2),

    // --- Yerel düğüm: masaüstü paketinin yerel sunucusu ---
    // Uygulamanın her açılışta ürettiği jetonun SHA-256 özeti (jetonun kendisi yalnız masaüstü sürecinde).
    'token_hash' => env('KURS_DESKTOP_TOKEN_HASH'),
    'cookie' => 'kurs_desktop',
    'header' => 'X-Kurs-Desktop',
];
