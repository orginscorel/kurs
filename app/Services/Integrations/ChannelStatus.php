<?php

namespace App\Services\Integrations;

use App\Models\AutomationRule;
use App\Models\Integration;
use App\Support\BranchContext;

/**
 * Mesaj kanallarının kullanılabilirliği (Entegrasyonlar: etkin + bağlantı testi başarılı).
 * Otomasyon kuralı, kullandığı kanal bağlı değilken AÇILAMAZ (mesajlar kuyrukta başarısız olurdu).
 */
final class ChannelStatus
{
    /** Kural eylem türü → entegrasyon türü ("app" bildirimi dış bağlantı gerektirmez). */
    public const ACTION_CHANNELS = ['whatsapp' => 'whatsapp', 'sms' => 'sms', 'email' => 'email'];

    public const LABELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-posta'];

    /** Önerilen veli bildirimleri: devamsızlık, geç kalma, taksit hatırlatmaları, sınav sonucu, sınıf değişikliği. */
    public const RECOMMENDED_GUARDIAN_TRIGGERS = [
        'attendance.absent', 'attendance.late', 'installment.upcoming', 'installment.overdue', 'exam.result_published', 'student.class_changed',
    ];

    /** @return array<string, bool> */
    public static function all(?int $branchId = null): array
    {
        $branchId ??= app(BranchContext::class)->id();
        $rows = Integration::query()->where('branch_id', $branchId)->whereIn('kind', array_values(self::ACTION_CHANNELS))->get()->keyBy('kind');

        return collect(self::ACTION_CHANNELS)->unique()->mapWithKeys(fn ($kind) => [
            $kind => (bool) ($rows[$kind]?->is_enabled ?? false) && ($rows[$kind]?->status ?? null) === 'connected',
        ])->all();
    }

    /** Kuralın kullandığı ama bağlı olmayan kanallar. @return list<string> */
    public static function missingFor(AutomationRule $rule, ?array $status = null): array
    {
        $status ??= self::all($rule->branch_id);

        return collect($rule->actions ?? [])->pluck('type')->unique()
            ->map(fn ($type) => self::ACTION_CHANNELS[$type] ?? null)->filter()
            ->reject(fn ($kind) => $status[$kind] ?? false)->unique()->values()->all();
    }

    /** Önerilen (veliye giden) kurallar. */
    public static function recommendedRules()
    {
        return AutomationRule::query()->whereIn('trigger', self::RECOMMENDED_GUARDIAN_TRIGGERS)->orderBy('id')->get()
            ->filter(fn (AutomationRule $r) => collect($r->actions ?? [])->contains(fn ($a) => ($a['to'] ?? null) === 'guardian'))
            ->values();
    }
}
