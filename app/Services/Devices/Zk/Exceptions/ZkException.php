<?php

namespace App\Services\Devices\Zk\Exceptions;

use RuntimeException;

/**
 * Terminal köprüsü hatalarının kökü. Mesaj her zaman TÜRKÇE ve kullanıcıya gösterilebilir.
 * `hint` alanı kullanıcının cihaz menüsünde ne yapması gerektiğini anlatır (komut çıktısında basılır).
 */
abstract class ZkException extends RuntimeException
{
    public function __construct(string $message, public readonly string $hint = '', public readonly array $context = [])
    {
        parent::__construct($message);
    }

    /** Hata kodu: 'baglanti' | 'kimlik' | 'protokol' | 'zaman_asimi' */
    abstract public function code(): string;

    public function toArray(): array
    {
        return ['kod' => $this->code(), 'mesaj' => $this->getMessage(), 'oneri' => $this->hint, 'ayrinti' => $this->context];
    }
}
