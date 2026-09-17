<?php

namespace App\Services\Devices\Zk\Exceptions;

/** Cihaz bağlantıyı kabul etti ama iletişim şifresini reddetti (CMD_ACK_UNAUTH). */
class ZkAuthException extends ZkException
{
    public function code(): string
    {
        return 'kimlik';
    }
}
