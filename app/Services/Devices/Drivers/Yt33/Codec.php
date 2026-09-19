<?php

namespace App\Services\Devices\Drivers\Yt33;

/**
 * YT33 paket kodlayıcı/çözücü — İSKELET.
 *
 * Paket çerçevesi (başlık, uzunluk alanı, bayt sırası, son ek) doğrulanmış yakalama olmadan YAZILMAZ.
 * Doldurma sırası: Terminal Teşhis › Protokol analizi ile en az 3 işlem × 2 yakalama → alanlar doğrulanır →
 * burada encode/decode + birim testi (gerçek yakalanmış baytlarla) → ancak sonra Yt33Driver kullanır.
 */
final class Codec
{
    public function encode(int $command, string $payload = ''): string
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('paket kodlama');
    }

    public function decode(string $bytes): array
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('paket çözme');
    }
}
