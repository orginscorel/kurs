<?php

/*
| ADMS / iclock — cihazın KENDİSİNİN kayıt gönderdiği (push) yol.
|
| Neden: bazı yeni aygıt yazılımları 4370 portunu kapatıyor; o cihazlara biz bağlanamayız,
| onlar bize bağlanır. Uçlar oturumsuzdur (terminalin çerezi/jetonu yoktur); kimlik cihazın
| SERİ NUMARASIDIR ve seri no ÖNCEDEN kayıtlı olmalıdır (devices.serial_no + protocol='adms').
|
| Varsayılan olarak yalnız YEREL DÜĞÜMDE (KURS_NODE=local, kurumdaki bilgisayar) açıktır:
| web sunucusunda oturumsuz bir uç açık tutmanın gereği yoktur.
*/

// config() burada KULLANILAMAZ: yapılandırma dosyaları alfabetik yüklenir ve kurs.php
// bu dosyadan SONRA gelir. Bu yüzden düğüm doğrudan ortam değişkeninden okunur.
$isLocalNode = env('KURS_NODE', 'server') === 'local';

return [
    'enabled' => (bool) env('ADMS_ENABLED', $isLocalNode),

    // Cihaza verilecek yapılandırma (handshake yanıtı).
    'delay' => (int) env('ADMS_DELAY', 10),             // saniye: cihaz kaç saniyede bir sorar
    'error_delay' => (int) env('ADMS_ERROR_DELAY', 30), // hata sonrası bekleme
    'trans_interval' => (int) env('ADMS_TRANS_INTERVAL', 1),
    'realtime' => (int) env('ADMS_REALTIME', 1),        // 1: okutma anında gönderilsin
    'timezone_offset' => (int) env('ADMS_TZ', 3),       // Türkiye: UTC+3

    // Tek istekte en çok kaç satır işlenir (cihaz belleğini boşaltırken taşmasın).
    'max_rows' => (int) env('ADMS_MAX_ROWS', 2000),

    // Kayıtlı olmayan seri numaraları ne kadar hatırlansın? (ekranda "tanıtıldı ama ekli değil")
    'unknown_ttl_minutes' => (int) env('ADMS_UNKNOWN_TTL', 120),
];
