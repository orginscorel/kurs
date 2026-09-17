<?php

namespace App\Services\Placement\Core;

/**
 * Yerleştirme çekirdeğinin tek girdisi: veritabanından bağımsız, saf değer nesnesi.
 *
 * - score: son 3 deneme net ortalaması (yoksa null → nötr kabul edilir)
 * - family: aynı veliyi paylaşan öğrencilerin ortak anahtarı (kardeş)
 * - current: şu anki şube anahtarı ("A"/"B") ya da null (sınıfsız)
 * - priority: küçük olan önce yer bulur (kayıt tarihi; bekleme listesine en son düşer)
 */
final class Candidate
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $gender = null,
        public readonly ?float $score = null,
        public readonly bool $highRisk = false,
        public readonly ?int $family = null,
        public readonly ?string $current = null,
        public readonly bool $pinned = false,
        public readonly int $priority = 0,
    ) {}
}
