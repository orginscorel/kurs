<?php

namespace App\Services\Devices\Zk\Exceptions;

/** Süre doldu: cihaz yanıt vermedi. Hiçbir çağrı süresiz beklemez. */
class ZkTimeoutException extends ZkException
{
    public function code(): string
    {
        return 'zaman_asimi';
    }
}
