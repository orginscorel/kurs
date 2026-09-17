<?php

namespace App\Services\Attendance;

use App\Services\Notifications\NotificationService;
use App\Services\Notifications\StaffRecipients;
use App\Support\Attendance\DeviceDataGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Otomatik yoklama öncesi "giriş verisi akıyor mu?" kontrolü + yöneticiye günde bir uyarı.
 * Kullananlar: AutoAttendanceService (ders bazlı GELMEDİ), NoShowDetectionService (bugün kuruma gelmedi).
 */
class DeviceDataMonitor
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @param array<string,mixed> $settings attendance ayar grubu */
    public function pauseReason(int $branchId, CarbonImmutable $now, array $settings): ?string
    {
        if (! filter_var($settings['pause_when_no_device_data'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }
        $stale = (int) ($settings['device_stale_minutes'] ?? 30);

        $lastSeen = DB::table('devices')->where('branch_id', $branchId)->where('is_active', true)->whereNull('deleted_at')
            ->pluck('last_seen_at')->map(fn ($v) => $v ? CarbonImmutable::parse($v) : null)->all();

        $entries = DB::table('attendance_events')->where('branch_id', $branchId)->where('event_type', 'ENTRY')
            ->whereBetween('occurred_at', [$now->startOfDay(), $now])
            ->selectRaw('COUNT(*) AS c, MAX(occurred_at) AS last_at')->first();

        return DeviceDataGuard::pauseReason(
            $lastSeen, (int) ($entries->c ?? 0), $entries?->last_at ? CarbonImmutable::parse($entries->last_at) : null, $now, $stale,
        );
    }

    /** Yöneticilere (yoklama düzeltme / cihaz yönetimi yetkilileri) günde bir kez uygulama bildirimi. */
    public function warn(int $branchId, string $reason, CarbonImmutable $now, int $staleMinutes, int $heldCount): int
    {
        if (! DeviceDataGuard::shouldNotify($reason)) {
            return 0;
        }
        $users = array_values(array_unique(array_merge(
            StaffRecipients::withPermission($branchId, 'attendance.override'),
            StaffRecipients::withPermission($branchId, 'devices.manage'),
        )));

        return $this->notifications->notify($users, 'warning', 'Cihaz verisi yok: otomatik yoklama durduruldu',
            DeviceDataGuard::message($reason, $staleMinutes).'. Veri gelmediği sürece otomatik "gelmedi" yazılmıyor ve veliye devamsızlık bildirimi gitmiyor'
            .($heldCount > 0 ? " ({$heldCount} öğrenci-ders bekletildi)" : '').'. Cihaz köprüsünü kontrol edin ya da yoklamayı elle alın.',
            '/yoklama/cihazlar',
            ['kind' => 'auto_attendance_paused', 'reason' => $reason, 'date' => $now->toDateString(), 'once' => "auto_attendance_paused:{$branchId}:".$now->toDateString()],
        );
    }
}
