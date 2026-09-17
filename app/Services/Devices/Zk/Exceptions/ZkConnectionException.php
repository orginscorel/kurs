<?php

namespace App\Services\Devices\Zk\Exceptions;

/** Cihaza hiç ulaşılamadı: kapalı, farklı IP'de, ağ/güvenlik duvarı engeli. */
class ZkConnectionException extends ZkException
{
    public function code(): string
    {
        return 'baglanti';
    }
}
