<?php

namespace App\Support\Staff;

/**
 * "Sistemde en az bir Sistem Yöneticisi kalmalı" iş kuralının saf mantığı.
 * DB'den bağımsız, test edilebilir. Servisler bu kararı verip kendileri sorgulayıp uygular.
 */
final class LastSuperAdminGuard
{
    /**
     * @param  int  $activeSuperAdminCount  şu an aktif ve super-admin rolüne sahip kullanıcı sayısı (bu kullanıcı dahil)
     * @param  bool  $wasSuperAdmin  işlemden önce kullanıcı super-admin miydi
     * @param  bool  $staysSuperAdmin  işlemden sonra kullanıcı super-admin (ve aktif) kalacak mı
     */
    public static function blocksChange(int $activeSuperAdminCount, bool $wasSuperAdmin, bool $staysSuperAdmin): bool
    {
        if (! $wasSuperAdmin || $staysSuperAdmin) {
            return false;
        }

        return $activeSuperAdminCount <= 1;
    }
}
