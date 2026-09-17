<?php

/*
| Sahte biyometrik terminal çalıştırıcısı (yalnız testlerde kullanılır).
|
| Kullanım: php tests/Support/fake-zk-device.php <senaryo.json>
| Dinlediği portu "PORT=<n>" satırıyla bildirir, tek istemciye hizmet edip çıkar.
| Laravel önyüklenmez; yalnız composer otomatik yükleyicisi gerekir.
*/

require __DIR__.'/../../vendor/autoload.php';

date_default_timezone_set('Europe/Istanbul');

$options = [];
if (isset($argv[1]) && is_file($argv[1])) {
    $options = json_decode((string) file_get_contents($argv[1]), true) ?: [];
    @unlink($argv[1]);
}

(new Tests\Support\FakeZkDevice($options))->serve();
