<?php

namespace App\Sync\Server;

/** Cihaz değişikliği sunucu kurallarına uymadı (paketin geri kalanı işlenir). */
class SyncReject extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'rejected')
    {
        parent::__construct($message);
    }
}
