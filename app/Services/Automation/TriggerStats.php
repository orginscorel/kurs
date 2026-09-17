<?php

namespace App\Services\Automation;

use Illuminate\Support\Facades\Cache;

/**
 * Tetikleyici sayacı: kural aktif olsun olmasın her `AutomationEngine::fire()` saatlik kovaya yazılır
 * (önbellek = veritabanı sürücüsü, 26 saat ömür). Otomasyonlar ekranı "son 24 saatte kaç kez
 * tetiklendi" bilgisini buradan okur — pasif kurallarda da zincirin canlı olduğu görülür.
 */
class TriggerStats
{
    private const TTL_HOURS = 26;

    public static function key(int $branchId, string $trigger, \DateTimeInterface $hour): string
    {
        return "automation:fired:{$branchId}:{$trigger}:".$hour->format('YmdH');
    }

    public static function record(int $branchId, string $trigger): void
    {
        try {
            $key = self::key($branchId, $trigger, now());
            Cache::add($key, 0, now()->addHours(self::TTL_HOURS));
            Cache::increment($key);
        } catch (\Throwable) {
            // sayaç yardımcı bilgidir; otomasyonu asla durdurmaz
        }
    }

    /** @param list<string> $triggers @return array<string,int> */
    public static function last24h(int $branchId, array $triggers): array
    {
        $now = now();
        $keys = [];
        foreach ($triggers as $t) {
            for ($h = 0; $h < 24; $h++) {
                $keys[self::key($branchId, $t, $now->copy()->subHours($h))] = $t;
            }
        }

        $values = Cache::many(array_keys($keys));
        $out = array_fill_keys($triggers, 0);
        foreach ($values as $key => $value) {
            $out[$keys[$key]] += (int) $value;
        }

        return $out;
    }
}
