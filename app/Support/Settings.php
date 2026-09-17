<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Kurum ayarları. Şube değeri > kurum geneli değeri > kod varsayılanı.
 */
class Settings
{
    public const DEFAULTS = [
        'institution' => [
            'name' => 'Erbaa Bilgi Eğitim',
            'short_name' => 'Erbaa Bilgi',
            'phone' => null,
            'email' => null,
            'website' => null,
            'address' => 'Erbaa / Tokat',
            'tax_office' => null,
            'tax_number' => null,
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'logo_path' => null,
            'onboarding_completed' => false,
        ],
        'attendance' => [
            'late_after_minutes' => 15,          // bu süreden sonra gelen: GEÇ
            'absent_after_minutes' => 30,        // bu süreye kadar giriş yoksa: GELMEDİ
            'auto_absence_enabled' => true,
            'notify_guardian_entry' => true,
            'notify_guardian_exit' => true,
            'notify_guardian_absent_delay_minutes' => 15,
            'min_minutes_between_events' => 2,   // aynı kişinin art arda okutması tek olay sayılır
            // Cihaz verisi yokken (tüm aktif cihazlar bu süredir sessiz ya da bugün hiç giriş olayı yok)
            // otomatik GELMEDİ / "bugün gelmedi" üretilmez; yöneticiye günde bir uyarı gider.
            'pause_when_no_device_data' => true,
            'device_stale_minutes' => 30,
        ],
        'finance' => [
            'receipt_prefix' => 'MKB',
            'enrollment_prefix' => 'KYT',
            'reminder_offsets' => [-5, -2, 0, 3, 7], // vadeye göre gün (negatif = önce)
            'reminders_enabled' => true,
            'reminder_hour' => '10:00',
        ],
        'retention' => [
            'attendance_events_days' => 730,
            'outbound_messages_days' => 730,
            'login_events_days' => 365,
            'withdrawn_student_anonymize_after_days' => 3650,
        ],
        'portal' => [
            // Veli portalından öğretmene mesaj / görüşme talebi (kayda düşer; mesaj gönderilmez)
            'guardian_requests_enabled' => true,
            // Öğrenci / veli portalında gecikmiş ödeme uyarısı (Finans ayarlarıyla aynı anahtarlar)
            'show_student_overdue_alert' => true,
            'show_guardian_overdue_alert' => true,
        ],
        'backup' => [
            'daily_enabled' => true,
            'daily_keep' => 14,
            'weekly_keep' => 8,
        ],
    ];

    public static function get(string $key, mixed $default = null, ?int $branchId = null): mixed
    {
        [$group, $name] = explode('.', $key, 2);
        $values = self::group($group, $branchId);

        return Arr::get($values, $name, $default);
    }

    /**
     * Şube bağlamı olmayan (zamanlayıcı/komut) okumalarda ana şubenin (ilk aktif şube) değeri kullanılır:
     * ayar ekranı değerleri oturumdaki şubeye yazar, kurum geneli (branch_id NULL) satır çoğu zaman yoktur.
     *
     * @return array<string, mixed>
     */
    public static function group(string $group, ?int $branchId = null): array
    {
        $branchId ??= app(BranchContext::class)->id() ?? self::primaryBranchId();

        return Cache::remember("settings:{$branchId}:{$group}", 300, function () use ($group, $branchId) {
            $rows = Setting::query()
                ->where('group', $group)
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
                ->orderByRaw('branch_id IS NULL DESC')
                ->get();

            $values = self::DEFAULTS[$group] ?? [];
            foreach ($rows as $row) {
                $values[$row->key] = $row->value;
            }

            return $values;
        });
    }

    /** İlk aktif şube (tek şubeli kurumda kurumun kendisi). */
    public static function primaryBranchId(): ?int
    {
        static $cached = null;
        if ($cached === null) {
            try {
                $cached = (int) (Branch::query()->where('is_active', true)->orderBy('id')->value('id') ?? 0);
            } catch (\Throwable) {
                return null;
            }
        }

        return $cached ?: null;
    }

    public static function put(string $group, array $values, ?int $branchId = null): void
    {
        $branchId ??= app(BranchContext::class)->id();

        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(
                ['branch_id' => $branchId, 'group' => $group, 'key' => $key],
                ['value' => $value],
            );
        }

        Cache::forget("settings:{$branchId}:{$group}");
    }
}
