<?php

/*
| Çevrimdışı yerel kurulum ↔ web eşitlemesi. Mimari: docs/SYNC.md
*/

return [
    // Değişiklik günlüğü yazılsın mı (sunucu ve yerel). Testlerde tablo yoksa kendiliğinden devre dışıdır.
    'record' => (bool) env('SYNC_RECORD', true),

    // Sunucu: pull sayfa boyutu üst sınırı, push paketi üst sınırı
    'pull_limit' => (int) env('SYNC_PULL_LIMIT', 500),
    'push_limit' => (int) env('SYNC_PUSH_LIMIT', 1000),
    'snapshot_limit' => (int) env('SYNC_SNAPSHOT_LIMIT', 1000),

    // Değişiklik günlüğü saklama (gün). Daha eski imleçle gelen cihaz yeniden anlık görüntü alır.
    'retention_days' => (int) env('SYNC_RETENTION_DAYS', 90),

    // Sunucu: pull öncesi süpürücü (DB::table yazmalarını yakalar) en sık bu kadar saniyede bir çalışır
    'sweep_before_pull_seconds' => (int) env('SYNC_SWEEP_BEFORE_PULL', 60),

    // Numara blokları (öğrenci no gibi yalın sayaçlar): blok boyutu ve yenileme eşiği
    'number_block_size' => (int) env('SYNC_NUMBER_BLOCK', 50),
    'number_block_refill_below' => (int) env('SYNC_NUMBER_REFILL', 10),

    // KURS_DATA_KEY yokken APP_KEY'in cihaza mühürlü paketle verilmesine izin (önerilmez; varsayılan kapalı)
    'share_app_key' => (bool) env('SYNC_SHARE_APP_KEY', false),

    // Dosya eşitlemesi (öğrenci fotoğrafı, belgeler, ödev dosyaları, disiplin ekleri, logolar)
    'file_max_mb' => (int) env('SYNC_FILE_MAX_MB', 20),                 // tek dosya üst sınırı (yükleme ve indirme)
    'files_per_cycle' => (int) env('SYNC_FILES_PER_CYCLE', 40),          // yerel: tur başına en çok indirme/yükleme
    'files_index_seconds' => (int) env('SYNC_FILES_INDEX_SECONDS', 60),  // sunucu: manifest öncesi dizin tazeleme aralığı

    // SQLite üzerinde sunucu kipi (yalnız deneme/CI): MySQL uyumluluk katmanını aç
    'sqlite_mysql_compat' => (bool) env('SYNC_SQLITE_MYSQL_COMPAT', false),

    // Yerel düğüm (KURS_NODE=local) istemci ayarları
    'server_url' => env('SYNC_SERVER_URL'),
    'device_token' => env('SYNC_DEVICE_TOKEN'),
    'device_code' => env('SYNC_DEVICE_CODE'),
    'interval_seconds' => (int) env('SYNC_INTERVAL', 30),
    'timeout_seconds' => (int) env('SYNC_TIMEOUT', 30),
    'state_file' => env('SYNC_STATE_FILE', 'sync/state.json'),     // storage/app/private altında
    'max_backoff_seconds' => (int) env('SYNC_MAX_BACKOFF', 600),   // sunucunun YANIT VERDİĞİ hatalarda üstel geri çekilme tavanı
    // Sunucuya hiç ulaşılamıyorken (çevrimdışı) sabit yeniden deneme aralığı: bağlantı gelince kuyruk
    // dakikalarca beklemesin. Denemenin maliyeti yok (bağlantı zaten anında reddediliyor).
    'offline_retry_seconds' => (int) env('SYNC_OFFLINE_RETRY', 20),
];
