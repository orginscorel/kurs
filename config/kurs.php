<?php

/*
| Kurum uygulaması genel ayarları.
|
| node: 'server' → web sunucusu (tek doğruluk kaynağı). 'local' → masaüstü yerel kurulum
| (SQLite, çevrimdışı çalışır, kurs:sync ile sunucuyla iki yönlü eşitlenir).
| Yerel düğümde mesaj gönderimi, otomasyon, yedek ve sunucu zamanlayıcıları KAPALIDIR.
*/

$node = env('KURS_NODE', 'server') === 'local' ? 'local' : 'server';

return [
    'node' => $node,

    // true iken olay dinleyicileri mesaj/otomasyon üretmez (demo seed, yerel düğüm).
    'silent_events' => (bool) env('KURS_SILENT_EVENTS', $node === 'local'),

    // Kurum veri anahtarı: TC gibi şifreli alanlar (boşsa APP_KEY kullanılır). Yerel kuruluma
    // eşleştirmede mühürlü paketle iletilir; APP_KEY cihaza verilmez (bkz. docs/SYNC.md › Güvenlik).
    'data_key' => env('KURS_DATA_KEY'),

    // Yerel düğümde yalnız bu zamanlayıcı dosyaları yüklenir (routes/schedules/*.php).
    'local_schedule_files' => ['sync.php'],
];
