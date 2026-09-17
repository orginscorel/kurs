<?php

namespace App\Services\Academic\Timetable;

/**
 * Deterministik rastgele sayı üreteci (xorshift32). Aynı tohum → aynı dizi;
 * PHP'nin global mt_rand durumuna dokunmaz (paralel iş/testlerde tekrarlanabilirlik).
 */
final class Rng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
    }

    public function nextInt32(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;

        return $this->state;
    }

    /** [0, 1) */
    public function float(): float
    {
        return $this->nextInt32() / 4294967296.0;
    }

    /** [0, n) */
    public function int(int $n): int
    {
        return $n <= 1 ? 0 : $this->nextInt32() % $n;
    }

    /** @template T @param list<T> $list @return T */
    public function pick(array $list): mixed
    {
        return $list[$this->int(count($list))];
    }
}
