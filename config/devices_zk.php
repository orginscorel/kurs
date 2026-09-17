<?php

/*
| Biyometrik yoklama terminali köprüsü (ZKTeco protokolü — Perkotek YT-33 dahil).
|
| Cihaz kurumun yerel ağındadır; web sunucusu ona ULAŞAMAZ. Bu ayarlar köprüyü çalıştıran
| düğümde (Mac masaüstü uygulaması, KURS_NODE=local) geçerlidir. Cihaz başına değerler
| `devices` tablosunda (zk_ip, zk_port, zk_transport, zk_comm_key_encrypted) tutulur;
| burada yalnız varsayılanlar ve zaman aşımları vardır.
*/

return [
    // Cihazın dinlediği varsayılan port (ZKTeco ailesinde fabrika değeri 4370).
    'port' => (int) env('ZK_PORT', 4370),

    // 'tcp' önerilir. Bazı eski aygıt yazılımları yalnız 'udp' konuşur.
    'transport' => env('ZK_TRANSPORT', 'tcp') === 'udp' ? 'udp' : 'tcp',

    // Zaman aşımları (saniye). Hiçbir çağrı süresiz beklemez.
    'connect_timeout' => (float) env('ZK_CONNECT_TIMEOUT', 3),
    'read_timeout' => (float) env('ZK_READ_TIMEOUT', 10),

    // Tek çekmede en fazla kaç ham kayıt işlenir (cihaz belleği dolup taşarsa koruma).
    'max_records' => (int) env('ZK_MAX_RECORDS', 20000),

    // İlk çekmede (imleç boşken) kaç gün geriye bakılır. --tam ile bu sınır kalkar.
    'first_pull_days' => (int) env('ZK_FIRST_PULL_DAYS', 7),

    // Büyük okuma sırasında cihaz kilitlensin mi? (CMD_DISABLEDEVICE) Okuma bitince açılır.
    'disable_during_read' => (bool) env('ZK_DISABLE_DURING_READ', true),

    /*
     | Cihazın doğrulama tipi (verify mode) → uygulamadaki kaynak adı.
     | attendance_events.source sütununa yazılır.
     */
    'verify_sources' => [
        0 => 'password',
        1 => 'fingerprint',
        2 => 'rfid',
        3 => 'manual',
        4 => 'rfid',
        15 => 'face',
    ],

    /*
     | Cihazın punch (durum) kodu → giriş/çıkış yönü.
     | 0 = giriş, 1 = çıkış; mola/fazla mesai kodları öğrencide kullanılmaz, AUTO'ya düşer.
     | AUTO: öğrenci içerideyse ÇIKIŞ, değilse GİRİŞ (PresenceService karar verir).
     */
    'punch_directions' => [
        0 => 'ENTRY',
        1 => 'EXIT',
        2 => 'EXIT',    // mola başlangıcı
        3 => 'ENTRY',   // mola bitişi
        4 => 'ENTRY',   // fazla mesai giriş
        5 => 'EXIT',    // fazla mesai çıkış
    ],
];
