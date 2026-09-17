<?php

namespace App\Services\Automation;

use Carbon\CarbonImmutable;

/**
 * Taksit hatırlatma kuralları saf gün farkına göre belirlenir: fark = BUGÜN - VADE
 * (negatif = vadeden önce, 0 = vade günü, pozitif = gecikme).
 *   before_5 (vadeye 5 gün kala), before_2, due (vade günü), after_3 / after_7 (gecikme).
 * Ofsetler kurum ayarından (`finance.reminder_offsets`) gelir; ayar yoksa RULES kullanılır.
 * `installment_reminders` tablosu (installment_id, rule_key) ile aynı kural iki kez tetiklenmez.
 */
class InstallmentReminderPlanner
{
    public const RULES = [
        -5 => 'before_5',
        -2 => 'before_2',
        0 => 'due',
        3 => 'after_3',
        7 => 'after_7',
    ];

    public const MAX_OFFSET = 30;

    /** Gönderim penceresinin kapandığı saat (akşam geç saatte veliye mesaj gitmesin). */
    public const WINDOW_END = '21:00';

    /**
     * @param  int  $daysDiff  bugün - vade tarihi (negatif = vadeden önce, pozitif = gecikme)
     * @param  array<int,string>|null  $rules  ofset → kural anahtarı (null = varsayılan RULES)
     * @return string|null eşleşen kural anahtarı, yoksa null
     */
    public static function ruleFor(int $daysDiff, ?array $rules = null): ?string
    {
        return ($rules ?? self::RULES)[$daysDiff] ?? null;
    }

    /** Tek ofsetin kural anahtarı: -5 → before_5, 0 → due, 3 → after_3. */
    public static function ruleKeyForOffset(int $offset): string
    {
        return match (true) {
            $offset < 0 => 'before_'.abs($offset),
            $offset > 0 => 'after_'.$offset,
            default => 'due',
        };
    }

    /**
     * Ayardaki ofset listesinden kural haritası. Geçersiz/boş ayar → varsayılan RULES.
     *
     * @return array<int,string>
     */
    public static function rulesFromOffsets(mixed $offsets): array
    {
        if (! is_array($offsets)) {
            return self::RULES;
        }
        $rules = [];
        foreach ($offsets as $o) {
            if (! is_numeric($o) || (float) $o !== (float) (int) $o) {
                continue;
            }
            $o = (int) $o;
            if (abs($o) > self::MAX_OFFSET) {
                continue;
            }
            $rules[$o] = self::ruleKeyForOffset($o);
        }
        if ($rules === []) {
            return self::RULES;
        }
        ksort($rules);

        return $rules;
    }

    /** İşaretli gün farkı: bugün - vade (saat bilgisi yok sayılır). */
    public static function daysDiff(CarbonImmutable $today, CarbonImmutable $due): int
    {
        // Carbon 3: $a->diffInDays($b) = $b - $a (işaretli). Burada vade → bugün.
        return (int) round($due->startOfDay()->diffInDays($today->startOfDay()));
    }

    /**
     * Bugün hatırlatılacak taksitlerin vade tarihleri: vade = bugün - fark.
     *
     * @param  array<int,string>  $rules
     * @return list<string> Y-m-d
     */
    public static function candidateDates(CarbonImmutable $today, array $rules): array
    {
        return array_values(array_map(fn (int $d) => $today->subDays($d)->toDateString(), array_keys($rules)));
    }

    /**
     * Gönderim penceresi: ayar saatinden (HH:MM) 21:00'e kadar. Ayar 21:00 ve sonrası ise gün sonuna kadar.
     * Komut 5 dakikada bir çalışır; pencere açıldığı ilk çalışmada gönderir, dedupe tekrarı engeller.
     */
    public static function withinSendWindow(CarbonImmutable $now, mixed $hour): bool
    {
        $hour = is_string($hour) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hour) ? $hour : '10:00';
        $current = $now->format('H:i');
        if ($current < $hour) {
            return false;
        }

        return $hour >= self::WINDOW_END || $current < self::WINDOW_END;
    }

    /** Kural anahtarı → otomasyon tetikleyicisi. */
    public static function triggerFor(string $ruleKey): string
    {
        return match (true) {
            $ruleKey === 'due' => 'installment.due',
            str_starts_with($ruleKey, 'before_') => 'installment.upcoming',
            str_starts_with($ruleKey, 'after_') => 'installment.overdue',
            default => 'installment.due',
        };
    }

    /** Otomasyon kuralının conditions.days alanına karşılık gelen gün sayısı (mutlak değer). */
    public static function daysFromRuleKey(string $ruleKey): int
    {
        return (int) str_replace(['before_', 'after_'], '', $ruleKey === 'due' ? '0' : $ruleKey);
    }
}
