<?php

namespace App\Services\Devices\Drivers\Yt33;

/**
 * YT33 sağlama toplamı — İSKELET.
 *
 * Hangi algoritma olduğu bilinmiyor. Protokol analizi aracı (App\Services\Devices\Analysis\PacketAnalyzer)
 * yaygın algoritmaları DENEYİP tutanları ÖNERİR; öneri doğrulanmadan buraya taşınmaz.
 */
final class Checksum
{
    public function compute(string $bytes): string
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('sağlama toplamı');
    }
}
