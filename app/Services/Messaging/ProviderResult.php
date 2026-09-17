<?php

namespace App\Services\Messaging;

/** Sağlayıcıya gönderim sonucu. */
class ProviderResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
    ) {}

    public static function ok(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, $error);
    }
}
