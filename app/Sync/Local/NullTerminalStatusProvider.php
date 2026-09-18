<?php

namespace App\Sync\Local;

use App\Sync\Contracts\TerminalStatusProvider;

/** Varsayılan: ek teşhis alanı yok (terminal katmanı kendi sağlayıcısını bağlayınca dolar). */
class NullTerminalStatusProvider implements TerminalStatusProvider
{
    public function details(object $device): array
    {
        return [];
    }
}
