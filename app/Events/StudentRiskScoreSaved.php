<?php

namespace App\Events;

use App\Models\StudentRiskScore;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * StudentRiskScore modeli kaydedildi (`$dispatchesEvents['saved']`). Model olayı olduğu için
 * `saved` anında `getOriginal('level')` hâlâ ESKİ seviyeyi verir → geçiş algılaması buna dayanır.
 * Hangi yoldan hesaplanırsa hesaplansın (gece komutu, profil yenileme, sınav sonrası iş) aynı kanca çalışır.
 */
class StudentRiskScoreSaved
{
    use Dispatchable;

    public readonly ?string $previousLevel;

    public readonly ?int $previousScore;

    public function __construct(public readonly StudentRiskScore $score)
    {
        // Oluşturmada original boştur (null); güncellemede kaydetmeden önceki değer.
        $this->previousLevel = $score->getOriginal('level');
        $this->previousScore = $score->getOriginal('score') !== null ? (int) $score->getOriginal('score') : null;
    }
}
