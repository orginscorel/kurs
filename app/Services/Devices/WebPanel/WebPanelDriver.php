<?php

namespace App\Services\Devices\WebPanel;

use App\Models\Device;
use App\Services\Devices\Terminal\Data\AttendanceEvent;
use App\Services\Devices\Terminal\Data\DeviceUser;
use App\Services\Devices\Terminal\DriverResult;
use Carbon\CarbonImmutable;

/**
 * WEB PANEL (HTTP) SÜRÜCÜSÜ — İSKELET.
 *
 * Amaç: cihazın web paneli (Perkotek YT33 "Dynamic Face") kullanıcı listesini ve kayıtları HTTP ile veriyorsa,
 * köprü bu uçlardan OTOMATİK (periyodik, artımlı) çeksin — insan eli değmeden.
 *
 * Uç noktalar UYDURULMAZ. PanelCrawler keşfi + kullanıcının onayıyla GERÇEK okuma uçları doğrulandığında
 * (config/devices_webpanel.php ya da devices tablosunda saklanan doğrulanmış uç haritası) burada doldurulur.
 * `verified()` false olduğu sürece zamanlayıcı bu sürücüyü ÇALIŞTIRMAZ; okuma yöntemleri boş sonuç döner.
 */
class WebPanelDriver
{
    public function key(): string
    {
        return 'webpanel';
    }

    /** Doğrulanmış okuma uçları var mı? (keşif sonucu insan onayıyla kaydedilince true olur) */
    public function verified(Device $device): bool
    {
        // Waiting for verified panel capture — keşif uçları onaylanınca burada denetlenecek.
        return false;
    }

    /** @return DriverResult<list<DeviceUser>> */
    public function fetchUsers(Device $device): DriverResult
    {
        // Waiting for verified panel capture (GET kullanıcı listesi ucu + yanıt ayrıştırma)
        return DriverResult::notImplemented('Web paneli kullanıcı listesi ucu henüz doğrulanmadı.', 'Terminal Teşhis › Web paneli keşfi çalıştırılıp uçlar doğrulanınca etkinleşecek.');
    }

    /** @return DriverResult<list<AttendanceEvent>> */
    public function fetchAttendanceLogs(Device $device, ?CarbonImmutable $since = null): DriverResult
    {
        // Waiting for verified panel capture (GET kayıt/log ucu + artımlı sorgu + yanıt ayrıştırma)
        return DriverResult::notImplemented('Web paneli kayıt ucu henüz doğrulanmadı.', 'Keşif sonrası doğrulanmış uçla artımlı çekme yapılacak.');
    }
}
