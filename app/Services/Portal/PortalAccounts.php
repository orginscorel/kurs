<?php

namespace App\Services\Portal;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Öğrenci ve veli portal hesaplarının ortak kuralları: hangi öğrenci durumları hesabı kapatır,
 * oturum/jeton kapatma, aktiflik değişimi.
 */
final class PortalAccounts
{
    /** Bu durumlardaki öğrencinin portal hesabı kapalıdır (veli için: TÜM çocukları bu durumdaysa). */
    public const CLOSED_STATUSES = ['withdrawn', 'graduated'];

    public static function isClosedStatus(?string $status): bool
    {
        return in_array($status, self::CLOSED_STATUSES, true);
    }

    /** Kullanıcının tüm web oturumlarını ve mobil jetonlarını kapatır. */
    public static function revokeSessions(int $userId): void
    {
        DB::table('sessions')->where('user_id', $userId)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->where('tokenable_id', $userId)->delete();
    }

    /**
     * Hesabı açar/kapatır (yalnız verilen türdeki kullanıcıda). Kapatılınca oturumlar da kapanır.
     *
     * @return bool durum değişti mi
     */
    public static function setActive(int $userId, string $userType, bool $active): bool
    {
        $changed = User::query()->whereKey($userId)->where('user_type', $userType)
            ->where('is_active', ! $active)->update(['is_active' => $active, 'updated_at' => now()]) > 0;

        if (! $active) {
            self::revokeSessions($userId);
        }

        return $changed;
    }
}
