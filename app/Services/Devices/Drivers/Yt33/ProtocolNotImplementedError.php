<?php

namespace App\Services\Devices\Drivers\Yt33;

use RuntimeException;

/**
 * YT33 protokolünün bu işlemi henüz DOĞRULANMADI. Tahmini paket gönderilmez, sahte veri üretilmez.
 * Doğrulama için gereken: gerçek cihaz ↔ üretici yazılımı arasındaki trafik kaydı (docs/CIHAZ-KOPRUSU.md › YT33).
 */
class ProtocolNotImplementedError extends RuntimeException
{
    public function __construct(public readonly string $operation)
    {
        parent::__construct("Protokol verisi bekleniyor: YT33 \"{$operation}\" işlemi henüz doğrulanmış paket kaydına dayanmıyor.");
    }
}
