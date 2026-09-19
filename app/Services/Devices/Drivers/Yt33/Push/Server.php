<?php

namespace App\Services\Devices\Drivers\Yt33\Push;

use App\Services\Devices\Terminal\PushListener;

/**
 * YT33 "Server / Push" kipi: cihaz köprüye (bu Mac, varsayılan 7005) bağlanır.
 * Davranış ortak push dinleyicisindedir (App\Services\Devices\Terminal\PushListener): ham saklama, HTTP'ye boş 200,
 * isteğe bağlı aktarma (şeffaf köprü). YT33 push çerçevesi doğrulanınca ayrıştırma buraya eklenecek.
 */
class Server extends PushListener
{
    // Waiting for verified YT33 packet capture (push çerçevesi ayrıştırması)
}
