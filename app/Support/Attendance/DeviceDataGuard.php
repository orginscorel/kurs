<?php

namespace App\Support\Attendance;

use Carbon\CarbonInterface;

/**
 * Giriş verisi güvenilir mi? (saf mantık, test edilebilir)
 *
 * Otomatik "GELMEDİ" / "bugün gelmedi" yalnız giriş verisi akıyorsa yazılır. Durdurma nedenleri:
 *  - no_devices:     aktif cihaz yok ve bugün hiç giriş olayı (kiosk/QR dahil) yok → otomatik yoklama uygulanamaz
 *  - devices_silent: aktif cihazların hiçbiri son X dakikadır nabız vermedi ve bu sürede giriş olayı da gelmedi
 *                    (köprü/internet/elektrik kesintisi)
 *  - no_entries:     cihaz nabız veriyor ama bugün tek bir giriş olayı yok (okuyucu/eşleme arızası)
 */
final class DeviceDataGuard
{
    public const REASONS = [
        'no_devices' => 'Şubede aktif giriş cihazı yok ve bugün hiç giriş kaydı gelmedi',
        'devices_silent' => 'Giriş cihazlarından %d dakikadır veri gelmiyor',
        'no_entries' => 'Cihazlar çalışıyor görünüyor ama bugün hiç giriş kaydı gelmedi',
    ];

    /**
     * @param  list<CarbonInterface|null>  $lastSeen  aktif cihazların son nabız zamanları
     * @param  CarbonInterface|null  $lastEntryAt  bugünkü son giriş olayının zamanı (herhangi kaynak)
     * @return string|null durdurma nedeni kodu; null = veri akıyor, otomatik yoklama güvenli
     */
    public static function pauseReason(array $lastSeen, int $entryEventsToday, ?CarbonInterface $lastEntryAt, CarbonInterface $now, int $staleMinutes = 30): ?string
    {
        $threshold = $now->copy()->subMinutes(max(1, $staleMinutes));
        $deviceAlive = false;
        foreach ($lastSeen as $seen) {
            if ($seen !== null && $seen->greaterThanOrEqualTo($threshold)) {
                $deviceAlive = true;
                break;
            }
        }

        if ($entryEventsToday <= 0) {
            if ($lastSeen === []) {
                return 'no_devices';
            }

            return $deviceAlive ? 'no_entries' : 'devices_silent';
        }

        if ($lastSeen === [] || $deviceAlive) {
            return null; // cihaz yok ama kiosk/QR girişleri akıyor ya da cihaz canlı
        }

        return $lastEntryAt !== null && $lastEntryAt->greaterThanOrEqualTo($threshold) ? null : 'devices_silent';
    }

    public static function message(string $reason, int $staleMinutes = 30): string
    {
        return sprintf(self::REASONS[$reason] ?? $reason, $staleMinutes);
    }

    /** Yöneticiye uyarı gerekir mi? (cihazı olmayan şubede her gün uyarı üretme) */
    public static function shouldNotify(string $reason): bool
    {
        return $reason !== 'no_devices';
    }
}
