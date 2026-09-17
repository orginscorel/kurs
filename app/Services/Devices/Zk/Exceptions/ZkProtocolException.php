<?php

namespace App\Services\Devices\Zk\Exceptions;

/** Cihaz konuştu ama beklenmedik/bozuk yanıt verdi (sağlama hatası, tanınmayan komut, eksik paket). */
class ZkProtocolException extends ZkException
{
    public function code(): string
    {
        return 'protokol';
    }
}
