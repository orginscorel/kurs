<?php

namespace App\Services\Discipline;

use App\Support\Discipline\DisciplineCatalog;
use App\Support\Settings;

/** Kurum disiplin ayarları (Settings grubu 'discipline'); kayıt yoksa katalog varsayılanları. */
final class DisciplineSettings
{
    /** @return array<string, mixed> */
    public static function all(?int $branchId = null): array
    {
        $values = Settings::group('discipline', $branchId) + DisciplineCatalog::SETTINGS;

        $out = [];
        foreach (DisciplineCatalog::SETTINGS as $key => $default) {
            $v = $values[$key] ?? $default;
            $out[$key] = match (true) {
                is_bool($default) => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                is_int($default) => (int) $v,
                default => (string) $v,
            };
        }

        return $out;
    }

    public static function get(string $key, ?int $branchId = null): mixed
    {
        return self::all($branchId)[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public static function put(array $values, ?int $branchId = null): void
    {
        Settings::put('discipline', array_intersect_key($values, DisciplineCatalog::SETTINGS), $branchId);
    }
}
