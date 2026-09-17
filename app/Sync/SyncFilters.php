<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Kayıt defterindeki satır süzgeçleri: yalnız personel kullanıcıları (öğrenci/veli portal hesapları
 * ve parola özetleri cihaza inmez) ve onların rol atamaları.
 */
final class SyncFilters
{
    public const STAFF_TYPES = ['staff', 'teacher'];

    /** Cihaza inmeyen ayar grupları (eşleştirme kodu vb.) */
    public const PRIVATE_SETTING_GROUPS = ['sync'];

    /** @var array<int, bool> */
    private static array $staffUsers = [];

    public static function allows(SyncTable $def, array $attrs): bool
    {
        return match ($def->filter) {
            'staff_users' => in_array($attrs['user_type'] ?? 'staff', self::STAFF_TYPES, true),
            'staff_model' => ($attrs['model_type'] ?? null) === 'user' && self::isStaffUser((int) ($attrs['model_id'] ?? 0)),
            'public_settings' => ! in_array($attrs['group'] ?? null, self::PRIVATE_SETTING_GROUPS, true),
            default => true,
        };
    }

    /** Sorgu düzeyinde süzgeç (anlık görüntü). */
    public static function applyToQuery(SyncTable $def, $query): void
    {
        match ($def->filter) {
            'staff_users' => $query->whereIn('user_type', self::STAFF_TYPES),
            'staff_model' => $query->where('model_type', 'user')
                ->whereIn('model_id', DB::table('users')->whereIn('user_type', self::STAFF_TYPES)->select('id')),
            'public_settings' => $query->whereNotIn('group', self::PRIVATE_SETTING_GROUPS),
            default => null,
        };
    }

    public static function isStaffUser(int $id): bool
    {
        if (! array_key_exists($id, self::$staffUsers)) {
            self::$staffUsers[$id] = in_array(DB::table('users')->where('id', $id)->value('user_type'), self::STAFF_TYPES, true);
        }

        return self::$staffUsers[$id];
    }

    public static function forget(): void
    {
        self::$staffUsers = [];
    }
}
