<?php

namespace App\Services\Devices\Terminal;

use RuntimeException;

/** Dinleyici açılamadı (port kullanımda, izin yok …). Mesaj Türkçe ve kullanıcıya gösterilebilir. */
class PushListenerException extends RuntimeException
{
    public function __construct(string $message, public readonly string $hint = '', public readonly int $errno = 0)
    {
        parent::__construct($message);
    }
}
