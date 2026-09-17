<?php

/**
 * Masaüstü paketinde her PHP sürecinin başında çalışır (`-d auto_prepend_file=…`): hem yerel sunucu hem artisan.
 *
 * Paket içindeki Laravel kopyası salt okunurdur; yazılabilir yollar ortam değişkenleriyle uygulama veri
 * klasörüne yönlendirilir (Rust tarafı verir):
 *   LARAVEL_STORAGE_PATH            → …/Application Support/<kimlik>/storage
 *   APP_SERVICES_CACHE, APP_PACKAGES_CACHE, APP_ROUTES_CACHE, APP_EVENTS_CACHE → …/cache/*.php
 *   KURS_PUBLIC_PATH                → paket içindeki public/ (bootstrap/app.php bu sabiti okur)
 * Laravel yapılandırması önbelleğe ALINMAZ (config:cache sırları diske düz yazardı).
 */

if (! defined('KURS_PUBLIC_PATH') && ($kursPublic = getenv('KURS_PUBLIC_PATH'))) {
    define('KURS_PUBLIC_PATH', $kursPublic);
}

foreach (['LARAVEL_STORAGE_PATH', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $kursKey) {
    if (! isset($_ENV[$kursKey]) && ($kursValue = getenv($kursKey)) !== false) {
        $_ENV[$kursKey] = $kursValue;
        $_SERVER[$kursKey] = $kursValue;
    }
}

unset($kursPublic, $kursKey, $kursValue);
