<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Öğrencinin sınıfı değişti (veliye bilgilendirme için). DB::afterCommit ile atılır.
 *
 * - fromClassGroupId null → sınıfsızdan yerleşti; toClassGroupId null → sınıftan çıktı / bekleme listesine düştü
 * - source: change (tekil değişim) | swap (karşılıklı takas) | placement (otomatik yerleştirme)
 *           | promotion (seviye atlatma) | restructure (demo dönüşümü) | revert (geri alma)
 */
class StudentClassChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $studentId,
        public readonly ?int $fromClassGroupId,
        public readonly ?int $toClassGroupId,
        public readonly string $source,
        public readonly string $effectiveOn,
        public readonly ?string $reason = null,
    ) {}
}
