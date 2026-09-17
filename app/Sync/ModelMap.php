<?php

namespace App\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Tablo → Eloquent model sınıfı (morph haritasından). Modeli olmayan tablolar DB::table ile yazılır. */
final class ModelMap
{
    /** @var array<string, class-string<Model>>|null */
    private static ?array $map = null;

    /** @return class-string<Model>|null */
    public static function classFor(string $table): ?string
    {
        if (self::$map === null) {
            self::$map = [];
            foreach (Relation::morphMap() as $class) {
                if (is_subclass_of($class, Model::class)) {
                    self::$map[(new $class)->getTable()] ??= $class;
                }
            }
        }

        return self::$map[$table] ?? null;
    }

    public static function softDeletes(string $class): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($class), true);
    }
}
